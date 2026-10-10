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
    /**
     * @var callable(string $url, array $headers, string $body, int $timeout): (int|array)
     *      HTTP status (0 on transport error) or ['status' => int, 'body' => string]
     */
    protected $http;
    protected int $timeout;
    protected int $retries;
    protected ?Spool $spool;
    /** @var array diagnostic of the last export, for tests and the local log */
    public array $last = [];
    /** HTTP status of the last attempt (0 = transport error) */
    public int $lastStatus = 0;
    /** body of the last response (bounded by the transport) */
    protected string $lastBody = '';
    /** @var array sanitized OTLP partial success info of the last accepted request */
    public array $lastAcceptance = [];
    /** @var array counts from the last flush(): partially accepted spooled batches and their rejected spans */
    public array $flushStats = ['partial_batches' => 0, 'rejected_spans' => 0];

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
            $acceptance = $this->lastAcceptance;
            $this->last = ['result' => $acceptance['partial'] ? 'partial' : 'sent'];
            if ($acceptance['partial']) $this->last['rejected_spans'] = $acceptance['rejected_spans'];
            if ($acceptance['response'] !== 'ok') $this->last['response'] = $acceptance['response'];
            $this->last['flushed'] = $this->flush();
            if ($this->flushStats['partial_batches'] > 0) {
                $this->last['flushed_partial_batches'] = $this->flushStats['partial_batches'];
                $this->last['flushed_rejected_spans'] = $this->flushStats['rejected_spans'];
            }
            return true; // accepted (fully or partially): never resent, a retry would duplicate accepted spans
        }
        $spooled = $this->spool ? $this->spool->push($payload) : false;
        $this->last = ['result' => $spooled ? 'spooled' : 'dropped'];
        if ($this->lastStatus >= 300 && $this->lastStatus < 400) $this->last['reason'] = 'redirect_refused';
        return $spooled;
    }

    /** send up to FLUSH_PER_EXPORT spooled payloads; stops at the first failure */
    public function flush(int $max = self::FLUSH_PER_EXPORT): int
    {
        $this->flushStats = ['partial_batches' => 0, 'rejected_spans' => 0];
        if (!$this->spool) return 0;
        $sent = 0;
        foreach ($this->spool->oldest($max) as $file) {
            $payload = $this->spool->read($file);
            if ($payload === null) continue;
            if (!$this->send($payload, 0)) break;
            $this->spool->remove($file); // also on partial success: no resend of accepted spans
            if ($this->lastAcceptance['partial'] ?? false) {
                $this->flushStats['partial_batches']++;
                $this->flushStats['rejected_spans'] += (int)$this->lastAcceptance['rejected_spans'];
            }
            $sent++;
        }
        return $sent;
    }

    protected function send(string $payload, ?int $retries = null): bool
    {
        $headers = array_merge(['Content-Type' => 'application/json'], $this->headers);
        $attempts = 1 + ($retries ?? $this->retries);
        for ($i = 0; $i < $attempts; $i++) {
            $body = '';
            try {
                $resp = ($this->http)($this->url, $headers, $payload, $this->timeout);
                if (is_array($resp)) {
                    $status = (int)($resp['status'] ?? 0);
                    $body = (string)($resp['body'] ?? '');
                } else {
                    $status = (int)$resp;
                }
            } catch (\Throwable $e) {
                $status = 0;
            }
            $this->lastStatus = $status;
            $this->lastBody = $body;
            if ($status >= 200 && $status < 300) {
                $this->lastAcceptance = self::parseAcceptance($body);
                return true;
            }
            // redirects are never followed (credentials/body must not reach other hosts): fail closed
            if ($status >= 300 && $status < 400) return false;
            if ($status >= 400 && $status < 500 && $status !== 429) return false; // not retryable
        }
        return false;
    }

    /**
     * Interpret a 2xx body per OTLP/HTTP (ExportTraceServiceResponse, JSON): partialSuccess with
     * rejectedSpans > 0 means some spans were dropped by the server. Accepts lowerCamelCase (spec) and
     * snake_case keys; int64 may be a JSON string. The server's errorMessage is NOT stored (it may echo
     * payload content); only whether one was present.
     *
     * @return array ['partial' => bool, 'rejected_spans' => int, 'error_message' => bool,
     *                'response' => ok|empty|malformed]
     */
    public static function parseAcceptance(string $body): array
    {
        $out = ['partial' => false, 'rejected_spans' => 0, 'error_message' => false, 'response' => 'ok'];
        if (trim($body) === '') {
            $out['response'] = 'empty';
            return $out;
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            $out['response'] = 'malformed'; // e.g. protobuf or HTML: accepted (2xx) but not verifiable
            return $out;
        }
        $ps = $json['partialSuccess'] ?? $json['partial_success'] ?? null;
        if ($ps === null) return $out;
        if (!is_array($ps)) {
            $out['response'] = 'malformed';
            return $out;
        }
        $rej = $ps['rejectedSpans'] ?? $ps['rejected_spans'] ?? 0;
        $rej = is_numeric($rej) ? max(0, (int)$rej) : 0;
        $msg = $ps['errorMessage'] ?? $ps['error_message'] ?? '';
        $out['rejected_spans'] = $rej;
        $out['partial'] = $rej > 0;
        $out['error_message'] = is_string($msg) && $msg !== '';
        return $out;
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
