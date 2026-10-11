<?php

namespace dokuwiki\plugin\aichat\test;

use dokuwiki\plugin\aichat\Telemetry\ExporterFactory;
use dokuwiki\plugin\aichat\Telemetry\ExporterInterface;
use dokuwiki\plugin\aichat\Telemetry\LangfuseExporter;
use dokuwiki\plugin\aichat\Telemetry\OtlpHttpExporter;
use dokuwiki\plugin\aichat\Telemetry\Spool;
use dokuwiki\plugin\aichat\Telemetry\TraceRecorder;

/**
 * Trace recorder, OTLP mapping, Langfuse exporter, retries and spool - with a mocked HTTP transport.
 * No network: nothing here can reach a real endpoint.
 *
 * @group plugin_aichat
 * @group plugins
 */
class TelemetryTest extends \DokuWikiTest
{
    protected array $sent = [];
    protected array $statuses = [];
    protected string $spoolDir;

    public function setUp(): void
    {
        parent::setUp();
        $this->sent = [];
        $this->statuses = [];
        $this->spoolDir = sys_get_temp_dir() . '/aichat_spool_' . bin2hex(random_bytes(4));
    }

    public function tearDown(): void
    {
        array_map('unlink', glob($this->spoolDir . '/*') ?: []);
        @rmdir($this->spoolDir);
        parent::tearDown();
    }

    /** mocked transport: records requests, returns queued statuses (default 200), may throw */
    protected function http(): callable
    {
        return function (string $url, array $headers, string $body, int $timeout): int {
            $this->sent[] = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true), 'timeout' => $timeout];
            $s = array_shift($this->statuses) ?? 200;
            if ($s instanceof \Throwable) throw $s;
            return $s;
        };
    }

    protected function trace(array $capture = []): array
    {
        $t = 0;
        $r = new TraceRecorder($capture, function () use (&$t) { return $t += 1_000_000; }, 1_700_000_000_000_000_000);
        $s = $r->start('retrieval', ['acl_filtered' => true]); $r->end($s, ['chunks' => 3]);
        $s = $r->start('clarify_decision'); $r->end($s, ['result' => 'clarify', 'options' => 3]);
        $s = $r->start('model_call', ['model' => 'synthetic-chat', 'repair' => false]); $r->end($s, ['usage.total_tokens' => 42, 'usage.available' => true]);
        $s = $r->start('render'); $r->end($s, ['sources' => 1]);
        $r->content('question', 'How do I change my password?');
        $r->content('answer', 'Run vpnctl.');
        $r->finish(['outcome' => 'ANSWER', 'correlation_id' => 'abcdef0123456789', 'response_id' => str_repeat('a', 24)]);
        return $r->toArray() + ['sessionId' => 'cabc123456789', 'release' => '2026-08-27'];
    }

    protected function spans(array $otlp): array
    {
        return $otlp['resourceSpans'][0]['scopeSpans'][0]['spans'];
    }

    protected function attrs(array $span): array
    {
        $out = [];
        foreach ($span['attributes'] as $a) $out[$a['key']] = array_values($a['value'])[0];
        return $out;
    }

    public function testLangfuseRequestShape()
    {
        $e = LangfuseExporter::create('https://langfuse.example.invalid/', 'pk-lf-placeholder', 'sk-lf-placeholder', $this->http(), 3, 1);
        $this->assertTrue($e->export($this->trace()));
        $this->assertCount(1, $this->sent);
        $req = $this->sent[0];
        $this->assertSame('https://langfuse.example.invalid/api/public/otel/v1/traces', $req['url']);
        $this->assertSame('Basic ' . base64_encode('pk-lf-placeholder:sk-lf-placeholder'), $req['headers']['Authorization']);
        $this->assertSame('4', $req['headers']['x-langfuse-ingestion-version']);
        $this->assertSame('application/json', $req['headers']['Content-Type']);
        $this->assertSame(3, $req['timeout']);

        $spans = $this->spans($req['body']);
        $this->assertSame(['aichat.turn', 'aichat.retrieval', 'aichat.clarify_decision', 'aichat.model_call', 'aichat.render'],
            array_column($spans, 'name'));
        $traceId = $spans[0]['traceId'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $traceId);
        foreach ($spans as $i => $s) {
            $this->assertSame($traceId, $s['traceId'], 'all spans correlated');
            $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $s['spanId']);
            if ($i > 0) $this->assertSame($spans[0]['spanId'], $s['parentSpanId']);
            $a = $this->attrs($s);
            // trace level attributes propagated to every span (required by Langfuse for filtering)
            $this->assertSame('cabc123456789', $a['langfuse.session.id']);
            $this->assertSame('aichat.turn', $a['langfuse.trace.name']);
            $this->assertSame('2026-08-27', $a['langfuse.release']);
            $this->assertSame('ANSWER', $a['langfuse.trace.metadata.outcome']);
            $this->assertSame('abcdef0123456789', $a['langfuse.trace.metadata.correlation_id']);
            $this->assertGreaterThan((int)$s['startTimeUnixNano'] - 1, (int)$s['endTimeUnixNano']);
        }
        $gen = $this->attrs($spans[3]);
        $this->assertSame('generation', $gen['langfuse.observation.type']);
        $this->assertSame('synthetic-chat', $gen['langfuse.observation.model.name']);
        $this->assertSame('{"total":42}', $gen['langfuse.observation.usage_details']);
        $this->assertSame('retriever', $this->attrs($spans[1])['langfuse.observation.type']);
    }

    public function testMetadataOnlyByDefault()
    {
        $e = LangfuseExporter::create('https://lf.example.invalid', 'pk', 'sk', $this->http());
        $e->export($this->trace([])); // no capture configured
        $json = json_encode($this->sent[0]['body']);
        $this->assertStringNotContainsString('change my password', $json);
        $this->assertStringNotContainsString('vpnctl', $json);
        $this->assertStringNotContainsString('langfuse.observation.input', $json);
        $this->assertStringNotContainsString('langfuse.user.id', $json, 'no user identifiers exported');
    }

    public function testExplicitCaptureIsGranular()
    {
        $e = LangfuseExporter::create('https://lf.example.invalid', 'pk', 'sk', $this->http());
        $e->export($this->trace(['question']));
        $root = $this->attrs($this->spans($this->sent[0]['body'])[0]);
        $this->assertSame('How do I change my password?', $root['langfuse.observation.input']);
        $this->assertArrayNotHasKey('langfuse.observation.output', $root, 'answer not enabled');
    }

    public function testRetryThenSpoolThenFlush()
    {
        $spool = new Spool($this->spoolDir, 5);
        $e = LangfuseExporter::create('https://lf.example.invalid', 'pk', 'sk', $this->http(), 2, 1, $spool);
        $this->statuses = [503, new \RuntimeException('connection refused')]; // first attempt + one retry fail
        $this->assertTrue($e->export($this->trace()), 'spooled counts as accepted');
        $this->assertSame('spooled', $e->last['result']);
        $this->assertCount(2, $this->sent, 'exactly 1 + 1 retry');
        $this->assertSame(1, $spool->count());

        $this->statuses = [200, 200];
        $this->assertTrue($e->export($this->trace()));
        $this->assertSame(['result' => 'sent', 'response' => 'empty', 'flushed' => 1], $e->last, 'status-only mock: no body to inspect');
        $this->assertSame(0, $spool->count());
        $this->assertCount(4, $this->sent);
    }

    public function testRedirectFailsClosedNotRetriedAndSpoolStaysBounded()
    {
        $spool = new Spool($this->spoolDir, 2);
        $e = LangfuseExporter::create('https://lf.example.invalid', 'pk', 'sk', $this->http(), 2, 2, $spool);
        foreach ([307, 308, 301] as $code) {
            $this->statuses = [$code];
            $before = count($this->sent);
            $e->export($this->trace());
            $this->assertCount($before + 1, $this->sent, "$code not retried");
            $this->assertSame('redirect_refused', $e->last['reason']);
        }
        $this->assertSame(2, $spool->count(), 'spool bounded');
        // flushing stops at the first refused redirect instead of looping
        $this->statuses = [200, 302];
        $e->export($this->trace());
        $this->assertSame(['result' => 'sent', 'response' => 'empty', 'flushed' => 0], $e->last, 'status-only mock: no body to inspect');
        $this->assertSame(2, $spool->count());
    }

    public function provideAcceptanceBodies(): array
    {
        return [
            'full success, empty object' => ['{}', 'sent', 0, 'ok'],
            'full success, empty body' => ['', 'sent', 0, 'empty'],
            'partial, lowerCamelCase, int64 as string' => ['{"partialSuccess":{"rejectedSpans":"3","errorMessage":"attr too long: SECRET-ECHO"}}', 'partial', 3, 'ok'],
            'partial, snake_case' => ['{"partial_success":{"rejected_spans":2}}', 'partial', 2, 'ok'],
            'warning only (0 rejected)' => ['{"partialSuccess":{"rejectedSpans":0,"errorMessage":"deprecated attr"}}', 'sent', 0, 'ok'],
            'malformed body' => ['<html>ok</html>', 'sent', 0, 'malformed'],
            'malformed partialSuccess' => ['{"partialSuccess":"nope"}', 'sent', 0, 'malformed'],
        ];
    }

    /** OTLP partial success (opentelemetry.io/docs/specs/otlp/#partial-success-1) @dataProvider provideAcceptanceBodies */
    public function testPartialSuccessIsRecordedAndNeverResent(string $body, string $result, int $rejected, string $response)
    {
        $spool = new Spool($this->spoolDir, 5);
        $calls = 0;
        $http = function () use ($body, &$calls) { $calls++; return ['status' => 200, 'body' => $body]; };
        $e = LangfuseExporter::create('https://lf.example.invalid', 'pk', 'sk', $http, 2, 2, $spool);
        $this->assertTrue($e->export($this->trace()));
        $this->assertSame(1, $calls, 'never retried (would duplicate accepted spans)');
        $this->assertSame(0, $spool->count(), 'never spooled');
        $this->assertSame($result, $e->last['result']);
        $this->assertSame($rejected, $e->last['rejected_spans'] ?? 0);
        $this->assertSame($response, $e->last['response'] ?? 'ok');
        $this->assertStringNotContainsString('SECRET-ECHO', json_encode([$e->last, $e->lastAcceptance]), 'server message not stored');
    }

    public function testPartialSuccessDuringFlushRemovesSpooledPayload()
    {
        $spool = new Spool($this->spoolDir, 5);
        $e = LangfuseExporter::create('https://lf.example.invalid', 'pk', 'sk', $this->http(), 2, 0, $spool);
        $this->statuses = [503];
        $e->export($this->trace());
        $this->assertSame(1, $spool->count());
        $responses = [['status' => 200, 'body' => '{}'], ['status' => 200, 'body' => '{"partialSuccess":{"rejectedSpans":1}}']];
        $calls = 0;
        $e2 = LangfuseExporter::create('https://lf.example.invalid', 'pk', 'sk', function () use (&$responses, &$calls) {
            $calls++;
            return array_shift($responses) ?? ['status' => 200, 'body' => '{}'];
        }, 2, 0, $spool);
        $e2->export($this->trace());
        $this->assertSame(2, $calls, 'current trace + one flushed batch, no retry of the partial batch');
        $this->assertSame(1, $e2->last['flushed']);
        $this->assertSame(0, $spool->count(), 'partially accepted backlog is not resent again');
        $this->assertSame('sent', $e2->last['result'], 'the current trace itself was fully accepted');
        $this->assertSame(1, $e2->last['flushed_partial_batches'], 'drop in the flushed batch is surfaced');
        $this->assertSame(1, $e2->last['flushed_rejected_spans']);
        $this->assertSame(['partial_batches' => 1, 'rejected_spans' => 1], $e2->flushStats);
        // a later clean flush resets the counters
        $e2->flush();
        $this->assertSame(['partial_batches' => 0, 'rejected_spans' => 0], $e2->flushStats);
    }

    public function testFlushedPartialDropsAreReportedInTurnStatus()
    {
        $meta = sys_get_temp_dir() . '/aichat_fp_' . bin2hex(random_bytes(4));
        $conf = ['telemetry' => 'langfuse', 'telemetry_endpoint' => 'https://lf.example.invalid',
            'telemetry_langfuse_public' => 'pk', 'telemetry_langfuse_secret' => 'sk', 'telemetry_retries' => 0];
        $replies = [503,
            ['status' => 200, 'body' => '{}'],
            ['status' => 200, 'body' => '{"partialSuccess":{"rejectedSpans":"4","errorMessage":"SECRET-ECHO"}}']];
        $http = function () use (&$replies) { return array_shift($replies) ?? 200; };
        $run = function () use ($conf, $meta, $http) {
            $tt = new \dokuwiki\plugin\aichat\Telemetry\TurnTelemetry($conf, $meta, $http);
            $t = $tt->newRecorder();
            $t->finish();
            return $tt->export($t, 'c', 'r');
        };
        try {
            $this->assertSame('spooled', $run());
            $status = $run();
            $this->assertSame('sent;flushed_partial=1:rejected=4', $status);
            $this->assertStringNotContainsString('SECRET-ECHO', $status);
        } finally {
            exec('rm -rf ' . escapeshellarg($meta));
        }
    }

    public function testLegacyIntTransportStillSupported()
    {
        $e = LangfuseExporter::create('https://lf.example.invalid', 'pk', 'sk', fn() => 204);
        $this->assertTrue($e->export($this->trace()));
        $this->assertSame('sent', $e->last['result']);
        $this->assertSame('empty', $e->last['response']);
    }

    public function testClientErrorIsNotRetried()
    {
        $e = LangfuseExporter::create('https://lf.example.invalid', 'pk', 'sk', $this->http(), 2, 2, new Spool($this->spoolDir, 5));
        $this->statuses = [401];
        $e->export($this->trace());
        $this->assertCount(1, $this->sent);
        $this->assertSame('spooled', $e->last['result']);
    }

    public function testSpoolIsBoundedAndExpires()
    {
        $now = 1_000_000;
        $spool = new Spool($this->spoolDir, 3, 3600, function () use (&$now) { return $now; });
        for ($i = 0; $i < 5; $i++) { $now++; $spool->push("{\"n\":$i}"); }
        $this->assertSame(3, $spool->count(), 'max items');
        $this->assertSame('{"n":2}', $spool->read($spool->oldest(1)[0]), 'oldest dropped first');
        $now += 3601;
        $this->assertSame(0, count($spool->oldest(10)), 'expired entries purged');
        $zero = new Spool($this->spoolDir . '/z', 0);
        $this->assertFalse($zero->push('{}'), 'spool disabled with max 0');
    }

    public function testGenericOtlpAndCustomBackend()
    {
        $e = ExporterFactory::create(['telemetry' => 'otlp', 'telemetry_endpoint' => 'http://collector.example.invalid:4318/v1/traces',
            'telemetry_otlp_authorization' => 'Bearer placeholder'], $this->http());
        $this->assertInstanceOf(OtlpHttpExporter::class, $e);
        $this->assertNotInstanceOf(LangfuseExporter::class, $e);
        $e->export($this->trace());
        $this->assertSame('http://collector.example.invalid:4318/v1/traces', $this->sent[0]['url']);
        $this->assertArrayNotHasKey('x-langfuse-ingestion-version', $this->sent[0]['headers']);
        $root = $this->attrs($this->spans($this->sent[0]['body'])[0]);
        $this->assertSame('cabc123456789', $root['session.id']);

        $captured = new \ArrayObject();
        ExporterFactory::register('memory', fn() => new class($captured) implements ExporterInterface {
            private \ArrayObject $c; public function __construct(\ArrayObject $c) { $this->c = $c; }
            public function export(array $trace): bool { $this->c->append($trace); return true; }
            public function getName(): string { return 'memory'; }
        });
        try {
            $m = ExporterFactory::create(['telemetry' => 'memory'], $this->http());
            $this->assertTrue($m->export($this->trace()));
            $this->assertCount(1, $captured);
        } finally {
            ExporterFactory::unregister('memory');
        }
    }

    public function testIncompleteConfigurationExportsNothing()
    {
        $this->assertNull(ExporterFactory::create(['telemetry' => 'off'], $this->http()));
        $this->assertNull(ExporterFactory::create(['telemetry' => 'langfuse', 'telemetry_endpoint' => 'https://x.invalid'], $this->http()));
        $this->assertNull(ExporterFactory::create(['telemetry' => 'otlp'], $this->http()));
    }

    public function testRecorderKeepsOnlyScalarMetadata()
    {
        $r = new TraceRecorder([]);
        $s = $r->start('retrieval', ['chunks' => 2, 'pages' => ['it:secret'], 'obj' => new \stdClass()]);
        $r->end($s);
        $r->content('question', 'not captured');
        $r->error('model_error');
        $r->finish();
        $a = $r->toArray();
        $this->assertSame(['chunks' => 2], $a['spans'][0]['attrs']);
        $this->assertSame([], $a['content']);
        $this->assertSame('error', $a['status']);
        $this->assertSame(['error.category' => 'model_error'], $a['spans'][1]['attrs']);
    }
}
