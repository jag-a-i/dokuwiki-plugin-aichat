<?php

namespace dokuwiki\plugin\aichat\Telemetry;

/**
 * Langfuse via its OpenTelemetry endpoint (verified against langfuse.com/docs/opentelemetry, 2026-10):
 *  - POST {base}/api/public/otel/v1/traces, OTLP/HTTP JSON (gRPC is not supported by Langfuse)
 *  - Authorization: Basic base64(publicKey:secretKey)
 *  - x-langfuse-ingestion-version: 4 (real-time ingestion on the v4 data model)
 *  - trace level attributes (langfuse.session.id, langfuse.trace.name, langfuse.release,
 *    langfuse.trace.metadata.*) are propagated to every span
 *  - model calls are typed as generations, retrieval as retriever
 * Works with self-hosted Langfuse (>= v3.22) by pointing base URL at the instance.
 * The deprecated /api/public/ingestion endpoint is intentionally not used.
 */
class LangfuseExporter extends OtlpHttpExporter
{
    public const PATH = '/api/public/otel/v1/traces';

    public static function create(string $baseUrl, string $publicKey, string $secretKey, callable $http,
                                  int $timeout = 2, int $retries = 1, ?Spool $spool = null): self
    {
        $url = rtrim($baseUrl, '/');
        if (!str_ends_with($url, self::PATH)) $url .= self::PATH;
        return new self($url, [
            'Authorization' => 'Basic ' . base64_encode($publicKey . ':' . $secretKey),
            'x-langfuse-ingestion-version' => '4',
        ], $http, $timeout, $retries, $spool);
    }

    public function getName(): string
    {
        return 'langfuse';
    }

    protected function traceAttributes(array $trace): array
    {
        $attrs = [
            'langfuse.trace.name' => 'aichat.turn',
            'langfuse.session.id' => (string)($trace['sessionId'] ?? ''),
            'langfuse.release' => (string)($trace['release'] ?? ''),
        ];
        foreach (['outcome', 'correlation_id', 'response_id', 'error_category'] as $k) {
            if (isset($trace['rootAttrs'][$k])) $attrs['langfuse.trace.metadata.' . $k] = $trace['rootAttrs'][$k];
        }
        return $attrs;
    }

    protected function rootAttributes(array $trace): array
    {
        $attrs = parent::rootAttributes($trace);
        if (isset($trace['content']['question'])) $attrs['langfuse.observation.input'] = $trace['content']['question'];
        if (isset($trace['content']['answer'])) $attrs['langfuse.observation.output'] = $trace['content']['answer'];
        return $attrs;
    }

    protected function spanAttributes(array $span, array $trace): array
    {
        $a = $span['attrs'];
        switch ($span['name']) {
            case 'model_call':
                $out = ['langfuse.observation.type' => 'generation'];
                if (!empty($a['model'])) $out['langfuse.observation.model.name'] = $a['model'];
                if (isset($a['usage.total_tokens'])) {
                    $out['langfuse.observation.usage_details'] = json_encode(['total' => (int)$a['usage.total_tokens']]);
                }
                return $out;
            case 'retrieval':
                $out = ['langfuse.observation.type' => 'retriever'];
                if (isset($trace['content']['context'])) $out['langfuse.observation.output'] = $trace['content']['context'];
                return $out;
            case 'error':
                return ['langfuse.observation.type' => 'event', 'langfuse.observation.level' => 'ERROR'];
            default:
                return ['langfuse.observation.type' => 'span'];
        }
    }
}
