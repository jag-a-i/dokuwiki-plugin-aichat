<?php

namespace dokuwiki\plugin\aichat\Eval;

use dokuwiki\plugin\aichat\Chunk;
use dokuwiki\plugin\aichat\Conversation\ConversationService;
use dokuwiki\plugin\aichat\Conversation\Outcome;
use dokuwiki\plugin\aichat\Conversation\PendingStore;
use dokuwiki\plugin\aichat\Model\ChatInterface;
use dokuwiki\plugin\aichat\Telemetry\TraceRecorder;

/**
 * Repeatable evaluation of the clarification/answer policy against labeled synthetic fixtures.
 *
 * Retrieval is deterministic (keyword match over the case's synthetic corpus, with per-case ACL),
 * so results reflect the CHAT MODEL's decisions with the real ConversationService and the real
 * decision prompt - not the vector store. Every metric is reported as numerator/denominator.
 */
class EvalRunner
{
    protected array $fixtures;
    /** @var callable(array $vars): string */
    protected $promptBuilder;

    public function __construct(array $fixtures, callable $promptBuilder)
    {
        if (($fixtures['version'] ?? 0) !== 1 || empty($fixtures['cases'])) {
            throw new \InvalidArgumentException('unsupported fixture file');
        }
        $this->fixtures = $fixtures;
        $this->promptBuilder = $promptBuilder;
    }

    public function cases(): array
    {
        return $this->fixtures['cases'];
    }

    /** deterministic retriever over a case corpus, honoring the case's denied pages */
    protected function retriever(array $case): callable
    {
        $corpus = $this->fixtures['corpora'][$case['corpus']] ?? [];
        $deny = array_flip($case['deny'] ?? []);
        return static function (string $query) use ($corpus, $deny): array {
            $terms = array_filter(preg_split('/[^a-z0-9-]+/', strtolower($query)), static fn($t) => strlen($t) >= 4);
            $out = [];
            foreach ($corpus as $i => $doc) {
                if (isset($deny[$doc['page']])) continue;
                $hay = strtolower($doc['title'] . ' ' . $doc['text']);
                $hits = 0;
                foreach ($terms as $t) if (str_contains($hay, $t)) $hits++;
                if ($hits) $out[] = new Chunk($doc['page'], $i + 1, $doc['text'], [], 'en', 1, $hits / max(1, count($terms)));
            }
            usort($out, static fn($a, $b) => $b->getScore() <=> $a->getScore());
            return array_slice($out, 0, 5);
        };
    }

    protected function pageFetcher(array $case): callable
    {
        $corpus = $this->fixtures['corpora'][$case['corpus']] ?? [];
        $deny = array_flip($case['deny'] ?? []);
        return static function (string $page) use ($corpus, $deny): array {
            foreach ($corpus as $i => $doc) {
                if ($doc['page'] === $page && !isset($deny[$page])) return [new Chunk($page, $i + 1, $doc['text'], [], 'en', 1, 1)];
            }
            return [];
        };
    }

    /**
     * @param callable(): ChatInterface $chatFactory fresh model per case run
     * @param callable|null $beforeTurn (array $case, int $turnIndex, array $turn) hook, used by scripted dry runs
     * @return array report
     */
    public function run(string $label, callable $chatFactory, int $repeat = 1, ?callable $beforeTurn = null): array
    {
        $rows = [];
        $titles = [];
        foreach ($this->fixtures['corpora'] as $docs) foreach ($docs as $d) $titles[$d['page']] = $d['title'];
        for ($rep = 1; $rep <= max(1, $repeat); $rep++) {
            foreach ($this->cases() as $case) {
                $session = [];
                $chat = $chatFactory();
                $pendingId = '';
                $conv = 'eval' . substr(md5($case['id'] . $rep), 0, 12);
                foreach ($case['turns'] as $ti => $turn) {
                    if ($beforeTurn) $beforeTurn($case, $ti, $turn, $chat);
                    $trace = new TraceRecorder();
                    $svc = new ConversationService(
                        $this->retriever($case), $chat, $this->promptBuilder, new PendingStore($session),
                        [], [], null, static fn($p) => $titles[$p] ?? $p, null, $trace, $this->pageFetcher($case)
                    );
                    $t0 = hrtime(true);
                    $r = $svc->handle($turn['user'], [], $conv, $pendingId);
                    $ms = (hrtime(true) - $t0) / 1e6;
                    $pendingId = $r['pendingId'];
                    $repairs = 0;
                    foreach ($trace->toArray()['spans'] as $s) {
                        if ($s['name'] === 'model_call' && !empty($s['attrs']['repair'])) $repairs++;
                    }
                    $rows[] = $this->score($case, $ti, $turn['expect'], $r, $ms, $repairs, $rep);
                }
            }
        }
        return $this->aggregate($label, $rows, $repeat);
    }

    protected function score(array $case, int $ti, array $exp, array $r, float $ms, int $repairs, int $rep): array
    {
        $sources = array_map(static fn(Chunk $c) => $c->getPage(), $r['sources']);
        $ok = $r['outcome'] === $exp['outcome'];
        $why = $ok ? [] : ['outcome ' . $r['outcome'] . ' != ' . $exp['outcome']];
        foreach ($exp['options_include'] ?? [] as $o) {
            if (!in_array($o, $r['options'], true)) { $ok = false; $why[] = "missing option $o"; }
        }
        foreach ($exp['options_exclude'] ?? [] as $o) {
            if (in_array($o, $r['options'], true)) { $ok = false; $why[] = "forbidden option $o"; }
        }
        foreach ($exp['sources_include'] ?? [] as $p) {
            if (!in_array($p, $sources, true)) { $ok = false; $why[] = "missing source $p"; }
        }
        foreach ($exp['sources_exclude'] ?? [] as $p) {
            if (in_array($p, $sources, true)) { $ok = false; $why[] = "forbidden source $p"; }
        }
        foreach ($exp['must_not_contain'] ?? [] as $t) {
            if (stripos($r['answer'] . ' ' . implode(' ', $r['options']), $t) !== false) { $ok = false; $why[] = "contains '$t'"; }
        }
        $format = true;
        if ($r['outcome'] === Outcome::ANSWER) {
            $format = substr_count($r['answer'], Outcome::FOOTER_TEXT) === 1 && str_ends_with($r['answer'], Outcome::FOOTER_TEXT);
        } elseif ($r['outcome'] === Outcome::NO_INFORMATION) {
            $format = $r['answer'] === Outcome::NO_INFORMATION_TEXT;
        } elseif ($r['outcome'] === Outcome::CLARIFY) {
            $format = !str_contains($r['answer'], Outcome::FOOTER_TEXT) && !$r['sources'];
        }
        return [
            'case' => $case['id'], 'turn' => $ti, 'rep' => $rep, 'expected' => $exp['outcome'], 'observed' => $r['outcome'],
            'correct' => $ok, 'why' => $why, 'format_ok' => $format, 'repairs' => $repairs,
            'error_category' => $r['errorCategory'] ?? '', 'ms' => round($ms, 1),
        ];
    }

    protected function aggregate(string $label, array $rows, int $repeat): array
    {
        $m = static function (callable $den, callable $num) use ($rows) {
            $d = array_values(array_filter($rows, $den));
            $n = count(array_filter($d, $num));
            return ['num' => $n, 'den' => count($d), 'rate' => count($d) ? round($n / count($d), 3) : null];
        };
        $correct = static fn($r) => $r['correct'];
        $lat = array_column($rows, 'ms');
        sort($lat);
        $pct = static fn($p) => $lat ? $lat[max(0, (int)ceil($p / 100 * count($lat)) - 1)] : null;

        // variability: share of repeats agreeing with the most common outcome, per case turn
        $byTurn = [];
        foreach ($rows as $r) $byTurn[$r['case'] . '#' . $r['turn']][] = $r['observed'];
        $agree = [];
        foreach ($byTurn as $k => $obs) {
            $counts = array_count_values($obs);
            $agree[$k] = round(max($counts) / count($obs), 3);
        }
        return [
            'label' => $label,
            'repeat' => $repeat,
            'cases' => count($this->cases()),
            'turns' => count($rows),
            'metrics' => [
                'direct_answer_correct' => $m(fn($r) => $r['expected'] === 'ANSWER' && $r['turn'] === 0, $correct),
                'clarification_correct' => $m(fn($r) => $r['expected'] === 'CLARIFY', $correct),
                'followup_resolution_correct' => $m(fn($r) => $r['turn'] > 0, $correct),
                'grounded_abstention_correct' => $m(fn($r) => $r['expected'] === 'NO_INFORMATION', $correct),
                'formatting_correct' => $m(fn($r) => $r['observed'] !== 'ERROR', fn($r) => $r['format_ok']),
                'first_try_parse' => $m(fn($r) => true, fn($r) => $r['repairs'] === 0 && $r['error_category'] !== 'model_malformed'),
                'inappropriate_clarification' => $m(fn($r) => $r['expected'] !== 'CLARIFY', fn($r) => $r['observed'] === 'CLARIFY'),
                'inappropriate_refusal' => $m(fn($r) => in_array($r['expected'], ['ANSWER', 'CLARIFY'], true),
                    fn($r) => $r['observed'] === 'NO_INFORMATION'),
                'errors' => $m(fn($r) => true, fn($r) => $r['observed'] === 'ERROR'),
            ],
            'latency_ms' => ['n' => count($lat), 'p50' => $pct(50), 'p90' => $pct(90), 'max' => $lat ? max($lat) : null],
            'repeat_agreement' => $agree,
            'rows' => $rows,
        ];
    }

    /** markdown table for one or more reports */
    public static function markdown(array $reports, string $status): string
    {
        $out = "Status: **$status**\n\n";
        $keys = array_keys($reports[0]['metrics']);
        $out .= '| metric | ' . implode(' | ', array_column($reports, 'label')) . " |\n|---|" . str_repeat('---|', count($reports)) . "\n";
        foreach ($keys as $k) {
            $cells = array_map(static function ($r) use ($k) {
                $x = $r['metrics'][$k];
                return $x['den'] ? sprintf('%d/%d (%.1f%%)', $x['num'], $x['den'], $x['rate'] * 100) : 'n/a';
            }, $reports);
            $out .= "| $k | " . implode(' | ', $cells) . " |\n";
        }
        foreach (['p50', 'p90'] as $p) {
            $out .= "| latency $p (ms, per turn, incl. model) | " . implode(' | ', array_map(
                static fn($r) => $r['latency_ms'][$p] === null ? 'n/a' : (string)$r['latency_ms'][$p], $reports)) . " |\n";
        }
        $out .= '| sample | ' . implode(' | ', array_map(static fn($r) => "{$r['cases']} cases x {$r['repeat']} repeats = {$r['turns']} turns", $reports)) . " |\n";
        return $out;
    }
}
