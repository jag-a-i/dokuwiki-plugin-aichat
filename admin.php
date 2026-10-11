<?php

use dokuwiki\Extension\AdminPlugin;
use dokuwiki\plugin\aichat\Telemetry\ResponseLog;
use dokuwiki\plugin\aichat\Telemetry\Summary;

/**
 * DokuWiki Plugin aichat (Admin Component): small, read-only usage summary for administrators.
 *
 * Shows aggregates from the local metadata-only response records. Feedback is self-reported by
 * users who chose to vote and is not a measure of objective accuracy; timings are server-side
 * processing times, not staff time savings.
 */
class admin_plugin_aichat extends AdminPlugin
{
    public const PERIODS = [7, 30, 90];

    /** @inheritdoc */
    public function forAdminOnly()
    {
        return true;
    }

    /** @inheritdoc */
    public function getMenuText($language)
    {
        return 'AI Chat: usage summary';
    }

    /** server-side check, used in addition to DokuWiki's own admin dispatch check */
    public function mayView(): bool
    {
        global $INPUT;
        return $INPUT->server->str('REMOTE_USER') !== '' && auth_isadmin();
    }

    protected function period(): int
    {
        global $INPUT;
        $days = $INPUT->int('days', 30);
        return in_array($days, self::PERIODS, true) ? $days : 30;
    }

    public function getSummary(?int $now = null): array
    {
        $now ??= time();
        $since = $now - $this->period() * 86400;
        $since -= $since % 86400;
        global $conf;
        $log = new ResponseLog($conf['metadir'] . '/aichat/responses', (int)$this->getConf('diagnostics_retention'));
        return Summary::build($log->since($since), $since, $now);
    }

    /** @inheritdoc */
    public function handle()
    {
        global $INPUT;
        if ($INPUT->str('export') !== 'csv') return;
        if (!$this->mayView() || !checkSecurityToken()) {
            http_status(403);
            return;
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="aichat-summary-' . $this->period() . 'd.csv"');
        echo Summary::csv($this->getSummary());
        exit;
    }

    /** @inheritdoc */
    public function html()
    {
        if (!$this->mayView()) return;
        $s = $this->getSummary();
        $pct = static fn(?float $r) => $r === null ? 'n/a' : sprintf('%.1f %%', $r * 100);
        $ms = static fn(?float $v) => $v === null ? 'n/a' : sprintf('%.0f ms', $v);

        echo '<div class="plugin_aichat_summary">';
        echo '<h1>AI Chat: usage summary</h1>';
        echo '<p>Last ' . $this->period() . ' days: ';
        foreach (self::PERIODS as $d) {
            echo '<a href="' . wl('', ['do' => 'admin', 'page' => 'aichat', 'days' => $d]) . '">' . $d . ' days</a> ';
        }
        echo '</p>';
        echo '<p>Source: local metadata-only response records (retention ' . (int)$this->getConf('diagnostics_retention') .
            ' days). ' . ($this->getConf('diagnostics') ? '' : '<strong>Diagnostics are disabled; timings are not recorded.</strong>') . '</p>';

        echo '<h2>Outcomes</h2><table class="inline"><tr><th>Outcome</th><th>Count</th></tr>';
        foreach ($s['outcomes'] as $o => $n) echo '<tr><td>' . hsc($o) . '</td><td>' . (int)$n . '</td></tr>';
        echo '<tr><th>Total responses</th><th>' . (int)$s['total'] . '</th></tr></table>';
        echo '<p>Clarification rate: ' . $pct($s['clarification_rate']) . ' of ' . (int)$s['clarification_denominator'] .
            ' question turns (NOTICE turns excluded).</p>';

        $f = $s['feedback'];
        echo '<h2>Feedback</h2>';
        echo '<p>' . (int)$f['voted'] . ' of ' . (int)$f['votable'] . ' answers received a vote (' . $pct($f['response_rate']) . '). ';
        echo 'Helpful: ' . (int)$f['helpful'] . ', not helpful: ' . (int)$f['not_helpful'] . ' (helpful ' . $pct($f['helpful_rate']) .
            ' of votes).</p>';
        echo '<table class="inline"><tr><th>Not helpful reason</th><th>Count</th></tr>';
        foreach ($f['categories'] as $c => $n) echo '<tr><td>' . hsc($c) . '</td><td>' . (int)$n . '</td></tr>';
        echo '</table>';
        echo '<p><em>Votes are voluntary and self-selected. They are not a measure of answer accuracy.</em></p>';

        $l = $s['latency_ms'];
        echo '<h2>Server processing time</h2><p>p50 ' . $ms($l['p50']) . ', p90 ' . $ms($l['p90']) . ', max ' . $ms($l['max']) .
            ' (n = ' . (int)$l['n'] . '). <em>Measured inside the plugin; this is not a measure of staff time saved.</em></p>';

        echo '<h2>Errors</h2>';
        if (!$s['errors']) {
            echo '<p>No errors recorded.</p>';
        } else {
            echo '<table class="inline"><tr><th>Category</th><th>Count</th></tr>';
            foreach ($s['errors'] as $c => $n) echo '<tr><td>' . hsc($c) . '</td><td>' . (int)$n . '</td></tr>';
            echo '</table>';
        }

        echo '<h2>Per day (UTC)</h2><table class="inline"><tr><th>Day</th>';
        foreach (Summary::OUTCOMES as $o) echo '<th>' . hsc($o) . '</th>';
        echo '</tr>';
        foreach (array_reverse($s['days'], true) as $day => $counts) {
            echo '<tr><td>' . hsc($day) . '</td>';
            foreach ($counts as $n) echo '<td>' . (int)$n . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '<p><a href="' . wl('', ['do' => 'admin', 'page' => 'aichat', 'days' => $this->period(),
                'export' => 'csv', 'sectok' => getSecurityToken()]) . '">Download per-day CSV (aggregates only)</a></p>';
        echo '</div>';
    }
}
