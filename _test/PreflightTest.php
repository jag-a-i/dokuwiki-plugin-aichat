<?php

namespace dokuwiki\plugin\aichat\test;

use dokuwiki\plugin\aichat\Telemetry\LangfuseExporter;
use dokuwiki\plugin\aichat\Telemetry\Preflight;
use dokuwiki\plugin\aichat\Telemetry\TraceRecorder;

/**
 * Protocol contract and operator preflight for trace export. No network: mocked transport.
 * These tests pin what the plugin SENDS; they cannot show what a given Langfuse version accepts.
 *
 * @group plugin_aichat
 * @group plugins
 */
class PreflightTest extends \DokuWikiTest
{
    protected array $sent = [];

    protected function conf(): array
    {
        return ['telemetry' => 'langfuse', 'telemetry_endpoint' => 'https://langfuse.example.invalid',
            'telemetry_langfuse_public' => 'pk-lf-placeholder', 'telemetry_langfuse_secret' => 'sk-lf-placeholder',
            'telemetry_retries' => 2];
    }

    protected function http(int $status): callable
    {
        return function ($url, $headers, $body) use ($status) {
            $this->sent[] = compact('url', 'headers', 'body');
            return $status;
        };
    }

    public function provideStatuses(): array
    {
        return [
            'accepted' => [200, true, 'acceptance alone does not prove that the attributes are mapped', 1],
            'json not accepted (400)' => [400, false, 'does not accept OTLP over HTTP with a JSON body', 1],
            'json not accepted (415)' => [415, false, 'only decoded protobuf - upgrade', 1],
            'no otlp endpoint' => [404, false, '/api/public/otel/v1/traces', 1],
            'bad keys' => [401, false, 'Authentication failed', 1],
            'redirect' => [308, false, 'Redirect (HTTP 308) refused', 1],
            'unreachable' => [0, false, 'unreachable', 3],
        ];
    }

    /** @dataProvider provideStatuses */
    public function testPreflightExplainsTheResponse(int $status, bool $ok, string $hint, int $attempts)
    {
        $r = Preflight::run($this->conf(), $this->http($status));
        $this->assertSame($ok, $r['ok']);
        $this->assertSame($status, $r['status']);
        $this->assertStringContainsString($hint, $r['message']);
        $this->assertCount($attempts, $this->sent, 'bounded attempts (4xx/3xx not retried)');
    }

    public function testPreflightReportsPartialSuccess()
    {
        $r = Preflight::run($this->conf(), function ($url, $headers, $body) {
            $this->sent[] = compact('url', 'headers', 'body');
            return ['status' => 200, 'body' => '{"partialSuccess":{"rejectedSpans":"2","errorMessage":"x"}}'];
        });
        $this->assertFalse($r['ok']);
        $this->assertSame('partial', $r['result']);
        $this->assertStringContainsString('rejected 2 span(s)', $r['message']);
        $this->assertCount(1, $this->sent);
    }

    public function testPreflightFlagsUnverifiableSuccessBody()
    {
        $r = Preflight::run($this->conf(), fn() => ['status' => 200, 'body' => 'not json']);
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('partial acceptance could not be checked', $r['message']);
    }

    public function testPreflightSendsOnlySyntheticMetadata()
    {
        Preflight::run($this->conf(), $this->http(200));
        $body = $this->sent[0]['body'];
        $this->assertStringContainsString('aichat-preflight', $body);
        $this->assertStringNotContainsString('langfuse.observation.input', $body);
        $this->assertStringNotContainsString('langfuse.user.id', $body);
    }

    public function testPreflightNeverSpools()
    {
        global $conf;
        $dir = $conf['metadir'] . '/aichat/spool';
        $before = count(glob("$dir/*.json") ?: []);
        Preflight::run($this->conf(), $this->http(503));
        $this->assertCount($before, glob("$dir/*.json") ?: []);
    }

    public function testNotConfigured()
    {
        $r = Preflight::run(['telemetry' => 'langfuse'], $this->http(200));
        $this->assertSame('not_configured', $r['result']);
        $this->assertSame([], $this->sent);
    }

    /** the wire contract the documentation describes: OTLP/HTTP, JSON body (never protobuf), Langfuse headers */
    public function testWireContractIsOtlpJson()
    {
        $e = LangfuseExporter::create('https://langfuse.example.invalid/', 'pk', 'sk', $this->http(200));
        $t = new TraceRecorder();
        $s = $t->start('model_call', ['model' => 'm']); $t->end($s);
        $t->finish(['outcome' => 'ANSWER']);
        $e->export($t->toArray() + ['sessionId' => 'c1', 'release' => 'r']);
        $req = $this->sent[0];
        $this->assertSame('https://langfuse.example.invalid/api/public/otel/v1/traces', $req['url']);
        $this->assertSame('application/json', $req['headers']['Content-Type']);
        $this->assertSame('4', $req['headers']['x-langfuse-ingestion-version']);
        $this->assertStringStartsWith('Basic ', $req['headers']['Authorization']);
        $json = json_decode($req['body'], true);
        $this->assertIsArray($json, 'body is JSON, not protobuf');
        $span = $json['resourceSpans'][0]['scopeSpans'][0]['spans'][1];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $span['traceId'], 'OTLP/JSON uses hex ids');
        $this->assertIsString($span['startTimeUnixNano'], 'OTLP/JSON encodes 64-bit ints as strings');
        $keys = array_column($span['attributes'], 'key');
        $this->assertContains('langfuse.observation.type', $keys);
        $this->assertContains('langfuse.session.id', $keys);
    }

    public function testDocumentationDoesNotClaimAVerifiedMinimumVersion()
    {
        $docs = file_get_contents(__DIR__ . '/../UPGRADE-NOTES.md') . file_get_contents(__DIR__ . '/../Telemetry/LangfuseExporter.php');
        $this->assertDoesNotMatchRegularExpression('/>=\s*v?3\.22/', $docs);
        $this->assertStringContainsString('NOT verified', $docs);
    }
}
