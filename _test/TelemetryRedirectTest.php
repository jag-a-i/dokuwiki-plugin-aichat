<?php

namespace dokuwiki\plugin\aichat\test;

/**
 * SECURITY regression: telemetry must never follow redirects.
 *
 * Real local servers (127.0.0.1 only) and the REAL default transport (DokuHTTPClient) in a
 * subprocess. The configured endpoint answers with a redirect to a second "receiver" server;
 * the receiver must not get any request - no Authorization header, no trace body.
 *
 * @group plugin_aichat
 * @group plugins
 */
class TelemetryRedirectTest extends \DokuWikiTest
{
    protected array $procs = [];
    protected string $lastRaw = '';
    protected string $dir;

    public function setUp(): void
    {
        parent::setUp();
        if (!trim((string)shell_exec('command -v python3'))) {
            $this->markTestSkipped('python3 not available for the local test servers');
        }
        $this->dir = sys_get_temp_dir() . '/aichat_redirect_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    public function tearDown(): void
    {
        foreach ($this->procs as $p) { proc_terminate($p); proc_close($p); }
        $this->procs = [];
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    protected function freePort(): int
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr(stream_socket_get_name($s, false), ':'), 1);
        fclose($s);
        return $port;
    }

    /** start a local server; returns [port, logfile] */
    protected function server(int $status, ?string $location = null, ?array $tls = null): array
    {
        $port = $this->freePort();
        $log = $this->dir . "/srv-$port.log";
        touch($log);
        $cmd = array_merge(['python3', __DIR__ . '/Fixtures/http_test_server.py', (string)$port, $log, (string)$status, $location ?? '-'],
            $tls ?? []);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $this->dir . "/srv-$port.err", 'w']], $pipes);
        $this->procs[] = $proc;
        $this->assertSame("ready\n", fgets($pipes[1]), 'server started');
        return [$port, $log];
    }

    protected function requests(string $log): array
    {
        return array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)file_get_contents($log))));
    }

    protected function export(string $backend, string $url, array $phpArgs = [], string $debug = ''): array
    {
        $cmd = array_merge([PHP_BINARY], $phpArgs, [__DIR__ . '/Fixtures/redirect_client.php', $backend, $url],
            $debug !== '' ? [$debug] : []);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($proc);
        $this->lastRaw = $out . $err;
        $lines = array_filter(explode("\n", trim($out)));
        $r = json_decode((string)end($lines), true);
        $this->assertIsArray($r, "client output: $out $err");
        return $r;
    }

    public function provideRedirects(): array
    {
        $out = [];
        foreach ([301, 302, 303, 307, 308] as $code) {
            $out["langfuse $code"] = ['langfuse', $code];
            $out["otlp $code"] = ['otlp', $code];
        }
        return $out;
    }

    /** @dataProvider provideRedirects */
    public function testRedirectIsNeverFollowed(string $backend, int $code)
    {
        [$rport, $rlog] = $this->server(200);
        [$eport, $elog] = $this->server($code, "http://127.0.0.1:$rport/stolen");
        $url = $backend === 'langfuse' ? "http://127.0.0.1:$eport" : "http://127.0.0.1:$eport/v1/traces";

        $r = $this->export($backend, $url);

        $this->assertSame([], $this->requests($rlog), 'redirect receiver got NO request (no credentials, no body)');
        $endpoint = $this->requests($elog);
        $this->assertCount(1, $endpoint, '3xx is not retried');
        $this->assertSame($code, $r['status']);
        $this->assertSame('redirect_refused', $r['last']['reason'] ?? '');
        $this->assertFalse($r['ok'], 'no spool configured in this client -> dropped, reported as failure');
        // sanity: the canaries really were in the request that the configured endpoint received
        $this->assertStringContainsString('BODY-CANARY-0123', $endpoint[0]['body']);
        $auth = $endpoint[0]['headers']['authorization'] ?? '';
        $backend === 'langfuse'
            ? $this->assertSame('Basic ' . base64_encode('pk-lf-placeholder:sk-lf-REDIRECT-CANARY'), $auth)
            : $this->assertSame('Bearer OTLP-REDIRECT-CANARY', $auth);
    }

    public function testHttpsEndpointRedirectingToHttpIsNotFollowed()
    {
        if (!trim((string)shell_exec('command -v openssl'))) $this->markTestSkipped('openssl not available');
        $cert = $this->dir . '/cert.pem';
        $key = $this->dir . '/key.pem';
        exec('openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=127.0.0.1" -addext "subjectAltName=IP:127.0.0.1" ' .
            '-keyout ' . escapeshellarg($key) . ' -out ' . escapeshellarg($cert) . ' 2>/dev/null', $o, $rc);
        $this->assertSame(0, $rc, 'test certificate created');
        [$rport, $rlog] = $this->server(200);
        [$eport, $elog] = $this->server(308, "http://127.0.0.1:$rport/downgrade", [$cert, $key]);

        $r = $this->export('langfuse', "https://127.0.0.1:$eport", ['-d', 'openssl.cafile=' . $cert]);

        $this->assertCount(1, $this->requests($elog), 'TLS endpoint reached (test is meaningful)');
        $this->assertSame(308, $r['status']);
        $this->assertSame([], $this->requests($rlog), 'no downgrade to plain HTTP');
    }

    public function testSuccessfulEndpointStillWorks()
    {
        [$eport, $elog] = $this->server(200);
        $r = $this->export('langfuse', "http://127.0.0.1:$eport");
        $this->assertTrue($r['ok']);
        $this->assertSame('sent', $r['last']['result']);
        $this->assertSame('/api/public/otel/v1/traces', $this->requests($elog)[0]['path']);
    }

    public function testNonHttpSchemesAreRefused()
    {
        $http = \dokuwiki\plugin\aichat\Telemetry\ExporterFactory::dokuHttp();
        foreach (['file:///etc/passwd', 'ftp://127.0.0.1/x', 'gopher://127.0.0.1/'] as $url) {
            $this->assertSame(0, $http($url, [], '{}', 1), $url);
        }
    }

    public function provideDebugTriggers(): array
    {
        return ['?httpdebug=1' => ['request'], 'Referer contains httpdebug' => ['referer']];
    }

    /**
     * DokuWiki debug mode (allowdebug=1) must never dump telemetry credentials or payloads.
     * @dataProvider provideDebugTriggers
     */
    public function testDebugModeNeverPrintsCredentialsOrPayload(string $trigger)
    {
        foreach (['langfuse', 'otlp'] as $backend) {
            [$eport, $elog] = $this->server(200);
            $url = $backend === 'langfuse' ? "http://127.0.0.1:$eport" : "http://127.0.0.1:$eport/v1/traces";
            $r = $this->export($backend, $url, [], $trigger);
            $this->assertTrue($r['debugActiveForDokuClient'], 'the trigger really enables DokuHTTPClient debug (test is meaningful)');
            $this->assertTrue($r['allowdebugAtExport'], 'allowdebug still on when the exporter runs');
            $this->assertTrue($r['ok'], 'delivery still works');
            $this->assertSame('', $r['captured'], 'transport produced no output at all');
            foreach (['Authorization', 'sk-lf-REDIRECT-CANARY', base64_encode('pk-lf-placeholder:sk-lf-REDIRECT-CANARY'),
                         'OTLP-REDIRECT-CANARY', 'BODY-CANARY-0123', 'resourceSpans'] as $secret) {
                $this->assertStringNotContainsString($secret, $this->lastRaw, "$backend/$trigger leaked $secret");
            }
            $this->assertStringContainsString('BODY-CANARY-0123', $this->requests($elog)[0]['body'], 'payload did reach the endpoint');
        }
    }

    /** the preflight through the REAL transport against local servers (accepting / rejecting JSON) */
    public function testPreflightOverRealTransport()
    {
        foreach ([[200, true], [415, false]] as [$status, $ok]) {
            [$eport, $elog] = $this->server($status);
            $r = \dokuwiki\plugin\aichat\Telemetry\Preflight::run([
                'telemetry' => 'langfuse', 'telemetry_endpoint' => "http://127.0.0.1:$eport",
                'telemetry_langfuse_public' => 'pk', 'telemetry_langfuse_secret' => 'sk',
            ], \dokuwiki\plugin\aichat\Telemetry\ExporterFactory::dokuHttp());
            $this->assertSame($ok, $r['ok'], (string)$status);
            $this->assertSame($status, $r['status']);
            $req = $this->requests($elog);
            $this->assertCount(1, $req);
            $this->assertSame('application/json', $req[0]['headers']['content-type']);
            $this->assertSame('/api/public/otel/v1/traces', $req[0]['path']);
        }
    }
}
