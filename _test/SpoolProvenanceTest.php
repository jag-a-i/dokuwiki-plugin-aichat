<?php

namespace dokuwiki\plugin\aichat\test;

use dokuwiki\plugin\aichat\Telemetry\ExporterFactory;
use dokuwiki\plugin\aichat\Telemetry\Spool;
use dokuwiki\plugin\aichat\Telemetry\TurnTelemetry;

/**
 * Queued (spooled) traces are bound to their destination, credentials and capture policy and are
 * never flushed to another configuration. Mocked transport, no network.
 *
 * @group plugin_aichat
 * @group plugins
 */
class SpoolProvenanceTest extends \DokuWikiTest
{
    protected string $meta;
    protected array $sent = [];
    protected array $statuses = [];

    public const MARKER = 'PRIVATE-MARKER-7f3a';

    public function setUp(): void
    {
        parent::setUp();
        $this->meta = sys_get_temp_dir() . '/aichat_prov_' . bin2hex(random_bytes(4));
        mkdir($this->meta);
        $this->sent = [];
        $this->statuses = [];
    }

    public function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->meta));
        parent::tearDown();
    }

    protected function conf(array $over = []): array
    {
        return array_merge([
            'telemetry' => 'langfuse', 'telemetry_endpoint' => 'https://langfuse-a.example.invalid',
            'telemetry_langfuse_public' => 'pk-lf-A', 'telemetry_langfuse_secret' => 'sk-lf-A-SECRET',
            'telemetry_capture' => 'question', 'telemetry_retries' => 0, 'telemetry_spool_max' => 10, 'telemetry_spool_days' => 3,
        ], $over);
    }

    /** one export with the given configuration; returns the requests sent during it */
    protected function turn(array $conf, string $question, int $status): array
    {
        $before = count($this->sent);
        $this->statuses[] = $status;
        $tt = new TurnTelemetry($conf, $this->meta, function ($url, $headers, $body) {
            $this->sent[] = ['url' => $url, 'auth' => $headers['Authorization'] ?? '', 'body' => $body];
            return array_shift($this->statuses) ?? 200;
        });
        $trace = $tt->newRecorder();
        $s = $trace->start('retrieval'); $trace->end($s);
        $trace->finish(['outcome' => 'ANSWER', 'correlation_id' => 'c']);
        $tt->captureContent($trace, ['question' => $question, 'answer' => 'a', 'outcome' => 'ANSWER']);
        $tt->export($trace, 'conv1', 'r');
        return array_slice($this->sent, $before);
    }

    /** queue one captured payload with the private marker under configuration A */
    protected function queueUnderA(): void
    {
        $sent = $this->turn($this->conf(), 'question with ' . self::MARKER, 503);
        $this->assertCount(1, $sent);
        $this->assertStringContainsString(self::MARKER, $sent[0]['body'], 'capture really was on for A');
        $this->assertSame(1, $this->spooledFiles(), 'A backlog queued');
    }

    protected function spooledFiles(): int
    {
        return count(glob($this->meta . '/aichat/spool/*/*.json') ?: []);
    }

    public function provideOtherDestinations(): array
    {
        return [
            'other endpoint, capture off' => [['telemetry_endpoint' => 'https://langfuse-b.example.invalid', 'telemetry_capture' => '',
                'telemetry_langfuse_public' => 'pk-lf-B', 'telemetry_langfuse_secret' => 'sk-lf-B']],
            'same endpoint, other project keys' => [['telemetry_langfuse_public' => 'pk-lf-B', 'telemetry_langfuse_secret' => 'sk-lf-B']],
            'same endpoint and keys, capture narrowed' => [['telemetry_capture' => '']],
            'same endpoint and keys, capture changed' => [['telemetry_capture' => 'answer']],
            'other backend (generic OTLP)' => [['telemetry' => 'otlp', 'telemetry_endpoint' => 'https://langfuse-a.example.invalid/api/public/otel/v1/traces',
                'telemetry_capture' => '']],
            'endpoint path changed' => [['telemetry_endpoint' => 'https://langfuse-a.example.invalid/other']],
        ];
    }

    /** @dataProvider provideOtherDestinations */
    public function testBacklogNeverReachesAnotherConfiguration(array $changes)
    {
        $this->queueUnderA();
        $sent = $this->turn($this->conf($changes), 'metadata only now', 200);
        $this->assertCount(1, $sent, 'only the current trace is sent - no backlog flush');
        $this->assertStringNotContainsString(self::MARKER, $sent[0]['body']);
        $this->assertSame(1, $this->spooledFiles(), 'A backlog stays quarantined (not sent, not migrated)');
        // a second successful turn still does not flush it
        $sent = $this->turn($this->conf($changes), 'again', 200);
        $this->assertCount(1, $sent);
        $this->assertStringNotContainsString(self::MARKER, json_encode($this->sent ? array_slice($this->sent, 1) : []));
    }

    public function provideCaseSensitiveParts(): array
    {
        return [
            'path case' => ['https://collector.example.invalid/TenantA/v1/traces', 'https://collector.example.invalid/tenanta/v1/traces'],
            'query value case' => ['https://collector.example.invalid/v1/traces?project=ProjectA', 'https://collector.example.invalid/v1/traces?project=projecta'],
            'query key case' => ['https://collector.example.invalid/v1/traces?Project=A', 'https://collector.example.invalid/v1/traces?project=A'],
            'generic OTLP trailing slash' => ['https://collector.example.invalid/v1/traces', 'https://collector.example.invalid/v1/traces/'],
        ];
    }

    /**
     * Same backend, same credentials: only the case of a path or query part differs -> different destination.
     * @dataProvider provideCaseSensitiveParts
     */
    public function testCaseSensitivePathAndQueryNeverShareABacklog(string $a, string $b)
    {
        $confA = $this->conf(['telemetry' => 'otlp', 'telemetry_endpoint' => $a, 'telemetry_otlp_authorization' => 'Bearer same']);
        $confB = $this->conf(['telemetry' => 'otlp', 'telemetry_endpoint' => $b, 'telemetry_otlp_authorization' => 'Bearer same']);
        $this->assertNotSame(ExporterFactory::spoolNamespace($confA), ExporterFactory::spoolNamespace($confB));
        $sent = $this->turn($confA, 'question with ' . self::MARKER, 503);
        $this->assertStringContainsString(self::MARKER, $sent[0]['body']);
        $sent = $this->turn($confB, 'metadata only', 200);
        $this->assertCount(1, $sent, 'no backlog replay to the case-different destination');
        $this->assertSame($b, $sent[0]['url']);
        $this->assertStringNotContainsString(self::MARKER, $sent[0]['body']);
        $this->assertSame(1, $this->spooledFiles());
    }

    public function testOnlySchemeAndHostAreCaseNormalized()
    {
        $n = fn($u) => ExporterFactory::spoolNamespace($this->conf(['telemetry_endpoint' => $u]));
        $this->assertSame($n('https://langfuse-a.example.invalid'), $n('HTTPS://Langfuse-A.Example.INVALID/'));
        $this->assertSame('https://lf.example.invalid:8443/Tenant/x?P=Q', ExporterFactory::normalizeEndpoint('HTTPS://LF.Example.invalid:8443/Tenant/x?P=Q'));
        // Langfuse: base URLs that yield the SAME effective ingest URL share a namespace, others do not
        $this->assertSame(ExporterFactory::effectiveUrl($this->conf(['telemetry_endpoint' => 'https://lf.example.invalid/a'])),
            ExporterFactory::effectiveUrl($this->conf(['telemetry_endpoint' => 'https://lf.example.invalid/a/'])));
        $this->assertSame($n('https://lf.example.invalid/a'), $n('https://lf.example.invalid/a/'), 'same effective ingest URL');
        $this->assertSame($n('https://lf.example.invalid'), $n('https://lf.example.invalid/api/public/otel/v1/traces'), 'same effective ingest URL');
        $this->assertNotSame($n('https://lf.example.invalid/A'), $n('https://lf.example.invalid/a'));
        // generic OTLP: the URL is used as-is, so a trailing slash is a different request URL
        $o = fn($u) => ExporterFactory::spoolNamespace($this->conf(['telemetry' => 'otlp', 'telemetry_endpoint' => $u]));
        $this->assertNotSame($o('https://c.example.invalid/v1/traces'), $o('https://c.example.invalid/v1/traces/'));
        $this->assertSame($o('https://c.example.invalid/v1/traces'), $o('HTTPS://C.Example.Invalid/v1/traces'));
        $this->assertNotSame($n('https://lf.example.invalid:443'), $n('https://lf.example.invalid:8443'));
    }

    /** the namespace is derived from the exact URL the exporter really posts to */
    public function testNamespaceUsesTheExactEffectiveRequestUrl()
    {
        foreach ([
            $this->conf(),
            $this->conf(['telemetry_endpoint' => 'https://lf.example.invalid/sub/']),
            $this->conf(['telemetry' => 'otlp', 'telemetry_endpoint' => 'https://c.example.invalid/v1/traces/?t=X']),
        ] as $conf) {
            $urls = [];
            $e = ExporterFactory::create($conf, function ($url) use (&$urls) { $urls[] = $url; return 200; });
            $t = new \dokuwiki\plugin\aichat\Telemetry\TraceRecorder();
            $t->finish();
            $e->export($t->toArray() + ['sessionId' => 's', 'release' => 'r']);
            $this->assertSame(ExporterFactory::effectiveUrl($conf), $urls[0], 'namespace input == request URL');
        }
    }

    public function provideUserinfoUrls(): array
    {
        return [['https://user:secret@lf.example.invalid'], ['https://user@lf.example.invalid'], ['https://:secret@lf.example.invalid']];
    }

    /** credentials embedded in the URL are refused, nothing is sent or spooled @dataProvider provideUserinfoUrls */
    public function testUserinfoInEndpointIsRejected(string $url)
    {
        foreach (['langfuse', 'otlp'] as $backend) {
            $conf = $this->conf(['telemetry' => $backend, 'telemetry_endpoint' => $url]);
            $this->assertSame('credentials_in_url', ExporterFactory::configError($conf));
            $this->assertNull(ExporterFactory::create($conf, fn() => 200));
            $r = \dokuwiki\plugin\aichat\Telemetry\Preflight::run($conf, function () { $this->fail('no request'); });
            $this->assertStringContainsString('contains credentials', $r['message']);
            $this->assertSame([], $this->turn($conf, 'q ' . self::MARKER, 200), 'nothing sent');
            $this->assertSame(0, $this->spooledFiles(), 'nothing spooled');
        }
    }

    /** positive control: the backlog IS delivered to exactly the same configuration */
    public function testBacklogIsFlushedToTheSameConfiguration()
    {
        $this->queueUnderA();
        $sent = $this->turn($this->conf(), 'next', 200);
        $this->assertCount(2, $sent, 'current trace + flushed backlog');
        $this->assertStringContainsString(self::MARKER, $sent[1]['body']);
        $this->assertSame($sent[0]['url'], $sent[1]['url']);
        $this->assertSame($sent[0]['auth'], $sent[1]['auth']);
        $this->assertSame(0, $this->spooledFiles());
    }

    public function testNoRawKeysOrUrlsOnDisk()
    {
        $this->queueUnderA();
        $all = '';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->meta . '/aichat/spool', \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) $all .= $f->getPathname() . "\n" . ($f->isFile() ? file_get_contents($f->getPathname()) : '');
        foreach (['sk-lf-A-SECRET', 'pk-lf-A', 'langfuse-a.example.invalid', base64_encode('pk-lf-A:sk-lf-A-SECRET')] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
        $this->assertMatchesRegularExpression('#/aichat/spool/[0-9a-f]{32}/#', $all);
    }

    public function testNamespaceIsDeterministicAndPolicySensitive()
    {
        $a = ExporterFactory::spoolNamespace($this->conf());
        $this->assertSame($a, ExporterFactory::spoolNamespace($this->conf(['telemetry_capture' => ' question '])));
        $this->assertSame($a, ExporterFactory::spoolNamespace($this->conf(['telemetry_endpoint' => 'https://langfuse-a.example.invalid/'])));
        $this->assertNotSame($a, ExporterFactory::spoolNamespace($this->conf(['telemetry_langfuse_secret' => 'other'])));
        $this->assertNotSame($a, ExporterFactory::spoolNamespace($this->conf(['telemetry_capture' => 'question,answer'])));
    }

    public function testObsoleteNamespacesAreCleanedBoundedAndLegacyFilesNeverSent()
    {
        $root = $this->meta . '/aichat/spool';
        $now = 2_000_000_000;
        @mkdir($root, 0770, true);
        // legacy flat file (pre-namespace version, no provenance) with captured content
        file_put_contents(sprintf('%s/%013d-legacy.json', $root, ($now - 10) * 1000), self::MARKER);
        // 7 obsolete namespaces, fresh
        for ($i = 0; $i < 7; $i++) {
            $d = $root . '/' . str_pad(dechex($i + 1), 32, '0', STR_PAD_LEFT);
            mkdir($d);
            file_put_contents(sprintf('%s/%013d-x.json', $d, ($now - 100 + $i) * 1000), '{}');
        }
        // current namespace untouched
        $cur = str_repeat('c', 32);
        mkdir("$root/$cur");
        file_put_contents(sprintf('%s/%013d-x.json', "$root/$cur", ($now - 999999) * 1000), '{}');

        $clock = fn() => $now;
        Spool::cleanupObsolete($root, $cur, 3600, 5, $clock);
        $this->assertCount(5, array_diff(array_map('basename', glob("$root/*", GLOB_ONLYDIR)), [$cur]), 'at most 5 obsolete namespaces');
        $this->assertFileExists(glob("$root/$cur/*.json")[0], 'current namespace is not touched by obsolete cleanup');
        $this->assertCount(1, glob("$root/*.json"), 'fresh legacy file kept until max age (never sent)');

        $later = fn() => $now + 3601;
        Spool::cleanupObsolete($root, $cur, 3600, 5, $later);
        $this->assertSame([], glob("$root/*.json"), 'legacy file expired');
        $this->assertSame([$cur], array_map('basename', glob("$root/*", GLOB_ONLYDIR)), 'expired namespaces removed');
    }

    public function testLegacyFlatSpoolIsNeverFlushed()
    {
        $root = $this->meta . '/aichat/spool';
        @mkdir($root, 0770, true);
        file_put_contents(sprintf('%s/%013d-legacy.json', $root, time() * 1000), '{"legacy":"' . self::MARKER . '"}');
        $sent = $this->turn($this->conf(['telemetry_capture' => '']), 'q', 200);
        $this->assertCount(1, $sent);
        $this->assertStringNotContainsString(self::MARKER, json_encode($sent));
    }

    public function testDisabledTelemetryStillAppliesRetention()
    {
        $this->queueUnderA();
        $file = glob($this->meta . '/aichat/spool/*/*.json')[0];
        rename($file, dirname($file) . sprintf('/%013d-old.json', (time() - 4 * 86400) * 1000));
        $this->turn(['telemetry' => 'off', 'telemetry_spool_days' => 3], 'q', 200);
        $this->assertSame(0, $this->spooledFiles(), 'expired backlog deleted even with export off');
        $this->assertSame(1, count($this->sent), 'nothing sent while off');
    }
}
