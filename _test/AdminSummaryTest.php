<?php

namespace dokuwiki\plugin\aichat\test;

use dokuwiki\Input\Input;
use dokuwiki\plugin\aichat\Telemetry\ResponseLog;
use dokuwiki\plugin\aichat\Telemetry\Summary;

/**
 * Admin-only usage summary.
 *
 * @group plugin_aichat
 * @group plugins
 */
class AdminSummaryTest extends \DokuWikiTest
{
    protected $pluginsEnabled = ['aichat', 'sqlite'];
    protected string $owner;

    public function setUp(): void
    {
        parent::setUp();
        global $conf;
        $conf['superuser'] = 'testadmin';
        $conf['useacl'] = 1;
        $this->owner = hash('sha256', 'secret-owner');
        $log = new ResponseLog($conf['metadir'] . '/aichat/responses', 30);
        array_map('unlink', glob($conf['metadir'] . '/aichat/responses/*.json') ?: []);
        $now = time();
        $recs = [
            ['ANSWER', 1200.0, 'helpful', ''], ['ANSWER', 800.0, 'not_helpful', 'wrong_source'], ['ANSWER', 1000.0, '', ''],
            ['CLARIFY', 600.0, '', ''], ['NO_INFORMATION', 300.0, '', ''], ['NOTICE', 10.0, '', ''],
            ['ERROR', 5000.0, '', '', 'model_error'],
        ];
        foreach ($recs as $i => $r) {
            $id = str_pad(dechex($i + 1), 24, 'a', STR_PAD_LEFT);
            $log->record(['id' => $id, 'ts' => $now - 3600, 'owner' => $this->owner, 'outcome' => $r[0],
                'timings' => ['total' => $r[1]], 'error_category' => $r[4] ?? '', 'trace_id' => str_repeat('f', 32)]);
            if ($r[2]) $log->setFeedback($id, $this->owner, $r[2], $r[3]);
        }
    }

    protected function asUser(string $user, callable $fn)
    {
        global $INPUT;
        [$i, $s] = [$INPUT, $_SERVER];
        if ($user === '') unset($_SERVER['REMOTE_USER']); else $_SERVER['REMOTE_USER'] = $user;
        $INPUT = new Input();
        try {
            return $fn();
        } finally {
            [$INPUT, $_SERVER] = [$i, $s];
        }
    }

    public function testSummaryNumbersAndDenominators()
    {
        $s = $this->asUser('testadmin', fn() => plugin_load('admin', 'aichat')->getSummary());
        $this->assertSame(7, $s['total']);
        $this->assertSame(['ANSWER' => 3, 'CLARIFY' => 1, 'NO_INFORMATION' => 1, 'NOTICE' => 1, 'ERROR' => 1], $s['outcomes']);
        $this->assertSame(6, $s['clarification_denominator']);
        $this->assertSame(round(1 / 6, 4), $s['clarification_rate']);
        $this->assertSame(3, $s['feedback']['votable']);
        $this->assertSame(2, $s['feedback']['voted']);
        $this->assertSame(0.5, $s['feedback']['helpful_rate']);
        $this->assertSame(1, $s['feedback']['categories']['wrong_source']);
        $this->assertSame(['model_error' => 1], $s['errors']);
        $this->assertSame(7, $s['latency_ms']['n']);
        $this->assertSame(800.0, $s['latency_ms']['p50']);
        $this->assertSame(5000.0, $s['latency_ms']['p90']);
    }

    public function testEmptyDataShowsNotApplicableNotZero()
    {
        $s = Summary::build([], 0, 86400);
        $this->assertNull($s['clarification_rate']);
        $this->assertNull($s['feedback']['helpful_rate']);
        $this->assertNull($s['latency_ms']['p50']);
    }

    public function testAdminPageRendersAggregatesOnly()
    {
        global $conf;
        $html = $this->asUser('testadmin', function () {
            ob_start();
            plugin_load('admin', 'aichat')->html();
            return ob_get_clean();
        });
        $this->assertStringContainsString('2 of 3 answers received a vote', $html);
        $this->assertStringContainsString('not a measure of answer accuracy', $html);
        $this->assertStringContainsString('not a measure of staff time saved', $html);
        $this->assertStringNotContainsString($this->owner, $html);
        $this->assertStringNotContainsString(str_repeat('f', 32), $html, 'no trace ids');
        foreach (glob($conf['metadir'] . '/aichat/responses/*.json') as $f) {
            $this->assertStringNotContainsString(basename($f, '.json'), $html, 'no response ids');
        }
    }

    public function testCsvExportIsAggregateOnly()
    {
        $csv = Summary::csv($this->asUser('testadmin', fn() => plugin_load('admin', 'aichat')->getSummary()));
        $lines = array_filter(explode("\n", trim($csv)));
        $this->assertSame('day,ANSWER,CLARIFY,NO_INFORMATION,NOTICE,ERROR', $lines[0]);
        $this->assertStringContainsString(',3,1,1,1,1', $csv);
        $this->assertStringNotContainsString($this->owner, $csv);
        $this->assertStringNotContainsString('ffffffff', $csv);
        $this->assertStringNotContainsString('aaaa', $csv);
    }

    public function provideNonAdmins(): array
    {
        return ['regular user' => ['alice'], 'guest' => ['']];
    }

    /** @dataProvider provideNonAdmins */
    public function testNonAdminsAreDenied(string $user)
    {
        $admin = plugin_load('admin', 'aichat');
        $this->assertTrue($admin->forAdminOnly());
        $this->assertFalse($this->asUser($user, fn() => $admin->mayView()));
        $html = $this->asUser($user, function () use ($admin) { ob_start(); $admin->html(); return ob_get_clean(); });
        $this->assertSame('', $html, 'html() renders nothing without admin rights');

        // through DokuWiki's real dispatcher, incl. the CSV export parameter
        foreach ([['do' => 'admin', 'page' => 'aichat'], ['do' => 'admin', 'page' => 'aichat', 'export' => 'csv']] as $get) {
            $req = new \TestRequest();
            if ($user !== '') $req->setServer('REMOTE_USER', $user);
            $body = $req->get(array_merge(['id' => 'start'], $get), '/doku.php')->getContent();
            $this->assertStringNotContainsString('usage summary', $body);
            $this->assertStringNotContainsString('day,ANSWER', $body);
            $this->assertStringNotContainsString('answers received a vote', $body);
        }
    }

    /** positive control: the same dispatcher path does show the page to an admin (so the denial test is meaningful) */
    public function testAdminSeesPageThroughDispatcher()
    {
        global $USERINFO;
        $USERINFO = ['name' => 'Test Admin', 'mail' => 'admin@example.invalid', 'grps' => ['admin', 'user']];
        $req = new \TestRequest();
        $req->setServer('REMOTE_USER', 'testadmin');
        $body = $req->get(['id' => 'start', 'do' => 'admin', 'page' => 'aichat'], '/doku.php')->getContent();
        $this->assertStringContainsString('AI Chat: usage summary', $body);
        $this->assertStringContainsString('answers received a vote', $body);
        $this->assertStringNotContainsString($this->owner, $body);
    }

    public function testExportRequiresSecurityToken()
    {
        $this->asUser('testadmin', function () {
            global $INPUT;
            $_GET['export'] = 'csv';
            $_REQUEST['export'] = 'csv';
            $_REQUEST['sectok'] = 'forged';
            $INPUT = new Input();
            ob_start();
            plugin_load('admin', 'aichat')->handle(); // must return (403) instead of exporting and exiting
            $out = ob_get_clean();
            $this->assertStringNotContainsString('day,ANSWER', $out);
            unset($_GET['export'], $_REQUEST['export'], $_REQUEST['sectok']);
        });
    }
}
