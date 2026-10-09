<?php

namespace dokuwiki\plugin\aichat\Telemetry;

/**
 * Generic OTLP/HTTP JSON trace exporter (works with any OpenTelemetry collector).
 * Langfuse support builds on this, see LangfuseExporter.
 *
 * Bounded behaviour: one attempt plus $retries retries with a short timeout each; on failure
 * the payload goes to a bounded spool; after a later successful export a few spooled payloads
 * are sent as well. Never throws.
 */
class OtlpHttpExporter implements ExporterInterface
{
    public const SERVICE_NAME = 'dokuwiki-aichat';
    public const FLUSH_PER_EXPORT = 2;

    protected string $url;
    protected array $headers;
    /** @var callable(string $url, array $headers, string $body, int $timeout): int  HTTP status, 0 on transport error */
    protected $http;
    protected int $timeout;
    protected int $retries;
    protected ?Spool $spool;
    /** @var array diagnostic of the last export, for tests and the local log */
    public array $last = [];

    public function __construct(string $url, array $headers, callable $http, int $timeout = 2, int $retries = 1, ?Spool $spool = null)
    {
        $this->url = $url;
        $this->headers = $headers;
        $this->http = $http;
        $this->timeout = max(1, min(10, $timeout));
        $this->retries = max(0, min(2, $retries));
        $this->spool = $spool;
    }

    public function getName(): string
    {
        return 'otlp';
    }

    public function export(array $trace): bool
    {
        try {
            $payload = json_encode($this->toOtlp($trace), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            $this->last = ['result' => 'encode_error'];
            return false;
        }
        if ($this->send($payload)) {
            $this->last = ['result' => 'sent', 'flushed' => $this->flush()];
            return true;
        }
        $spooled = $this->spool ? $this->spool->push($payload) : false;
        $this->last = ['result' => $spooled ? 'spooled' : 'dropped'];
        return $spooled;
    }

    /** send up to FLUSH_PER_EXPORT spooled payloads; stops at the first failure */
    public function flush(int $max = self::FLUSH_PER_EXPORT): int
    {
        if (!$this->spool) return 0;
        $sent = 0;
        foreach ($this->spool->oldest($max) as $file) {
            $payload = $this->spool->read($file);
            if ($payload === null) continue;
            if (!$this->send($payload, 0)) break;
            $this->spool->remove($file);
            $sent++;
        }
        return $sent;
    }

    protected function send(string $payload, ?int $retries = null): bool
    {
        $headers = array_merge(['Content-Type' => 'application/json'], $this->headers);
        $attempts = 1 + ($retries ?? $this->retries);
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $status = (int)($this->http)($this->url, $headers, $payload, $this->timeout);
            } catch (\Throwable $e) {
                $status = 0;
            }
            if ($status >= 200 && $status < 300) return true;
            if ($status >= 400 && $status < 500 && $status !== 429) return false; // not retryable
        }
        return false;
    }

    /** attributes added to every span (trace level attributes must be propagated) */
    protected function traceAttributes(array $trace): array
    {
        return [
            'session.id' => (string)($trace['sessionId'] ?? ''),
            'service.version' => (string)($trace['release'] ?? ''),
        ];
    }

    /** extra per span attributes for a given span (backend specific mapping) */
    protected function spanAttributes(array $span, array $trace): array
    {
        return [];
    }

    /** attributes for the root span */
    protected function rootAttributes(array $trace): array
    {
        $attrs = [];
        foreach ($trace['rootAttrs'] as $k => $v) $attrs['aichat.' . $k] = $v;
        foreach ($trace['content'] ?? [] as $kind => $text) $attrs['aichat.content.' . $kind] = $text;
        return $attrs;
    }

    public function toOtlp(array $trace): array
    {
        $common = $this->traceAttributes($trace);
        $spans = [[
            'traceId' => $trace['traceId'],
            'spanId' => $trace['rootId'],
            'name' => 'aichat.turn',
            'kind' => 2, // SERVER
            'startTimeUnixNano' => (string)$trace['start'],
            'endTimeUnixNano' => (string)$trace['end'],
            'attributes' => self::attributes(array_merge($common, $this->rootAttributes($trace))),
            'status' => ['code' => $trace['status'] === 'error' ? 2 : 1],
        ]];
        foreach ($trace['spans'] as $s) {
            $attrs = [];
            foreach ($s['attrs'] as $k => $v) $attrs[str_contains($k, '.') ? $k : 'aichat.' . $k] = $v;
            $spans[] = [
                'traceId' => $trace['traceId'],
                'spanId' => $s['id'],
                'parentSpanId' => $trace['rootId'],
                'name' => 'aichat.' . $s['name'],
                'kind' => 1, // INTERNAL
                'startTimeUnixNano' => (string)$s['start'],
                'endTimeUnixNano' => (string)($s['end'] ?? $trace['end']),
                'attributes' => self::attributes(array_merge($common, $attrs, $this->spanAttributes($s, $trace))),
                'status' => ['code' => $s['status'] === 'error' ? 2 : 1],
            ];
        }
        return ['resourceSpans' => [[
            'resource' => ['attributes' => self::attributes(['service.name' => self::SERVICE_NAME])],
            'scopeSpans' => [[
                'scope' => ['name' => 'dokuwiki.plugin.aichat', 'version' => (string)($trace['release'] ?? '')],
                'spans' => $spans,
            ]],
        ]]];
    }

    /** OTLP JSON attribute list */
    public static function attributes(array $kv): array
    {
        $out = [];
        foreach ($kv as $k => $v) {
            if ($v === '' || $v === null) continue;
            if (is_bool($v)) $val = ['boolValue' => $v];
            elseif (is_int($v)) $val = ['intValue' => (string)$v];
            elseif (is_float($v)) $val = ['doubleValue' => $v];
            else $val = ['stringValue' => (string)$v];
            $out[] = ['key' => (string)$k, 'value' => $val];
        }
        return $out;
    }
}
