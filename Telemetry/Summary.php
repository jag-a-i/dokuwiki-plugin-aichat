<?php

namespace dokuwiki\plugin\aichat\Telemetry;

/**
 * Aggregates local response records for the admin summary. Output contains only counts, rates,
 * latency percentiles and sanitized error categories - never owner hashes, trace ids or response ids.
 */
class Summary
{
    public const OUTCOMES = ['ANSWER', 'CLARIFY', 'NO_INFORMATION', 'NOTICE', 'ERROR'];

    /**
     * @param array[] $records ResponseLog records
     * @param int $since unix time; only records at or after it are counted
     * @param int $now unix time (for the per-day table)
     */
    public static function build(array $records, int $since, int $now): array
    {
        $outcomes = array_fill_keys(self::OUTCOMES, 0);
        $errors = [];
        $days = [];
        $latency = [];
        $fb = ['votable' => 0, 'voted' => 0, 'helpful' => 0, 'not_helpful' => 0,
            'categories' => array_fill_keys(ResponseLog::CATEGORIES, 0)];
        $total = 0;
        for ($t = $since - $since % 86400; $t <= $now; $t += 86400) {
            $days[gmdate('Y-m-d', $t)] = array_fill_keys(self::OUTCOMES, 0);
        }
        foreach ($records as $r) {
            $ts = (int)($r['ts'] ?? 0);
            if ($ts < $since || $ts > $now) continue;
            $o = in_array($r['outcome'] ?? '', self::OUTCOMES, true) ? $r['outcome'] : 'ERROR';
            $total++;
            $outcomes[$o]++;
            $day = gmdate('Y-m-d', $ts);
            if (isset($days[$day])) $days[$day][$o]++;
            if ($o === 'ERROR') {
                $cat = preg_match('/^[a-z_]{1,40}$/', (string)($r['error_category'] ?? '')) ? $r['error_category'] : 'unknown';
                $errors[$cat] = ($errors[$cat] ?? 0) + 1;
            }
            if (isset($r['timings']['total']) && is_numeric($r['timings']['total'])) $latency[] = (float)$r['timings']['total'];
            if ($o === 'ANSWER') {
                $fb['votable']++;
                $vote = $r['feedback']['vote'] ?? '';
                if (in_array($vote, ResponseLog::VOTES, true)) {
                    $fb['voted']++;
                    $fb[$vote]++;
                    $cat = $r['feedback']['category'] ?? '';
                    if (isset($fb['categories'][$cat])) $fb['categories'][$cat]++;
                }
            }
        }
        ksort($errors);
        $questionTurns = $total - $outcomes['NOTICE'];
        return [
            'since' => $since,
            'until' => $now,
            'total' => $total,
            'outcomes' => $outcomes,
            'clarification_rate' => self::rate($outcomes['CLARIFY'], $questionTurns),
            'clarification_denominator' => $questionTurns,
            'feedback' => $fb + [
                'helpful_rate' => self::rate($fb['helpful'], $fb['voted']),
                'response_rate' => self::rate($fb['voted'], $fb['votable']),
            ],
            'latency_ms' => [
                'n' => count($latency),
                'p50' => self::percentile($latency, 50),
                'p90' => self::percentile($latency, 90),
                'max' => $latency ? max($latency) : null,
            ],
            'errors' => $errors,
            'days' => $days,
        ];
    }

    /** null when the denominator is zero (shown as "n/a", never as 0 %) */
    public static function rate(int $num, int $den): ?float
    {
        return $den > 0 ? round($num / $den, 4) : null;
    }

    /** nearest-rank percentile */
    public static function percentile(array $values, int $p): ?float
    {
        if (!$values) return null;
        sort($values);
        $rank = (int)ceil($p / 100 * count($values));
        return $values[max(0, $rank - 1)];
    }

    /** per-day CSV: aggregates only */
    public static function csv(array $summary): string
    {
        $out = fopen('php://temp', 'w+');
        fputcsv($out, array_merge(['day'], self::OUTCOMES), ',', '"', '\\');
        foreach ($summary['days'] as $day => $counts) {
            fputcsv($out, array_merge([$day], array_values($counts)), ',', '"', '\\');
        }
        rewind($out);
        return stream_get_contents($out);
    }
}
