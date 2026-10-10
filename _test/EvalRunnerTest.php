<?php

namespace dokuwiki\plugin\aichat\test;

use dokuwiki\plugin\aichat\Eval\EvalRunner;
use dokuwiki\plugin\aichat\Eval\ScriptedChat;

/**
 * The evaluation runner itself (fixtures, scoring, aggregation) with scripted models.
 * These are NOT model evaluations.
 *
 * @group plugin_aichat
 * @group plugins
 */
class EvalRunnerTest extends \DokuWikiTest
{
    protected $pluginsEnabled = ['aichat', 'sqlite'];

    protected function runner(): array
    {
        $fixtures = json_decode(file_get_contents(__DIR__ . '/eval/fixtures.json'), true);
        $titles = [];
        foreach ($fixtures['corpora'] as $docs) foreach ($docs as $d) $titles[$d['page']] = $d['title'];
        $helper = plugin_load('helper', 'aichat');
        return [new EvalRunner($fixtures, fn($v) => $helper->buildDecisionPrompt($v)), $titles];
    }

    protected function evaluate(string $mode, int $repeat = 1): array
    {
        [$runner, $titles] = $this->runner();
        return $runner->run($mode, fn() => new ScriptedChat($mode, $titles), $repeat,
            static function ($case, $ti, $turn, $chat) { $chat->expect($turn['expect']); });
    }

    public function testOracleScoresPerfectly()
    {
        $r = $this->evaluate('oracle', 2);
        $this->assertSame(11, $r['cases']);
        $this->assertSame(26, $r['turns'], '13 turns x 2 repeats');
        foreach (['direct_answer_correct', 'clarification_correct', 'followup_resolution_correct',
                     'grounded_abstention_correct', 'formatting_correct', 'first_try_parse'] as $k) {
            $this->assertSame(1.0, $r['metrics'][$k]['rate'], $k . ' ' . json_encode(array_filter($r['rows'], fn($x) => !$x['correct'])));
        }
        $this->assertSame(0, $r['metrics']['inappropriate_clarification']['num']);
        $this->assertSame(0, $r['metrics']['errors']['num']);
        $this->assertSame(['num' => 6, 'den' => 6, 'rate' => 1.0], $r['metrics']['clarification_correct'], '3 clarify turns x 2');
        $this->assertSame(1.0, min($r['repeat_agreement']));
    }

    public function testNaiveModelIsDetected()
    {
        $r = $this->evaluate('naive');
        $this->assertSame(0.0, $r['metrics']['clarification_correct']['rate'], 'never clarifies');
        $this->assertLessThan(1.0, $r['metrics']['grounded_abstention_correct']['rate'], 'answers irrelevant context');
        $this->assertGreaterThan(0, $r['metrics']['inappropriate_clarification']['den']);
        $failed = array_column(array_filter($r['rows'], fn($x) => !$x['correct']), 'case');
        $this->assertContains('ambiguous-password', $failed);
        $this->assertContains('irrelevant-context', $failed);
        // deterministic pipeline guarantees hold even for a bad model
        $rows = array_column($r['rows'], null, 'case');
        $this->assertTrue($rows['empty-retrieval']['correct'], 'empty retrieval never reaches the model');
        $this->assertTrue($rows['acl-denied-only']['correct'], 'denied-only context never reaches the model');
        // guard against a fixture that silently matches readable pages (found once: "portal" matched VPN)
        [$runner] = $this->runner();
        $f = json_decode(file_get_contents(__DIR__ . '/eval/fixtures.json'), true);
        $case = array_values(array_filter($f['cases'], fn($c) => $c['id'] === 'acl-denied-only'))[0];
        $m = new \ReflectionMethod($runner, 'retriever');
        $m->setAccessible(true);
        $this->assertSame([], ($m->invoke($runner, $case))($case['turns'][0]['user']), 'only the denied page matches');
    }

    public function testMarkdownReportsDenominators()
    {
        $md = EvalRunner::markdown([$this->evaluate('oracle')], 'DRY RUN');
        $this->assertStringContainsString('Status: **DRY RUN**', $md);
        $this->assertStringContainsString('| clarification_correct | 3/3 (100.0%) |', $md);
        $this->assertStringContainsString('| inappropriate_clarification | 0/10 (0.0%) |', $md);
        $this->assertStringContainsString('11 cases x 1 repeats = 13 turns', $md);
    }

    public function testFixturesAreSyntheticAndPlaceholdersOnly()
    {
        $cfg = json_decode(file_get_contents(__DIR__ . '/eval/eval.config.example.json'), true);
        foreach ($cfg['models'] as $m) $this->assertStringContainsString('REPLACE-WITH', $m['chatmodel']);
        $raw = file_get_contents(__DIR__ . '/eval/fixtures.json') . file_get_contents(__DIR__ . '/eval/eval.config.example.json');
        $this->assertDoesNotMatchRegularExpression('/(sk-|pk-lf|Bearer |https?:\/\/(?!vpn\.example\.invalid))/', $raw);
    }
}
