<?php

namespace dokuwiki\plugin\aichat\Telemetry;

/**
 * Operator preflight for the configured trace backend: sends ONE synthetic, metadata-only trace
 * (no wiki content, no user data) through the normal exporter and explains the result.
 *
 * Compatibility note: the plugin sends OTLP over HTTP with a JSON body and langfuse.* attributes.
 * Langfuse's documentation (Oct 2026) describes this for current versions; the minimum self-hosted
 * version that accepts OTLP/JSON with these mappings is NOT verified (early OTel endpoint versions
 * only decoded protobuf). Run this preflight against your own instance before relying on export.
 */
class Preflight
{
    /** @return array ['ok' => bool, 'status' => int, 'result' => string, 'message' => string] */
    public static function run(array $conf, callable $http): array
    {
        $exporter = ExporterFactory::create($conf, $http, null); // no spool: a preflight must not queue anything
        if (!$exporter) {
            return ['ok' => false, 'status' => 0, 'result' => 'not_configured',
                'message' => 'Export is off or incompletely configured (telemetry, telemetry_endpoint and keys).'];
        }
        $t = new TraceRecorder([]);
        $s = $t->start('preflight', ['synthetic' => true]);
        $t->end($s);
        $t->finish(['outcome' => 'PREFLIGHT', 'correlation_id' => 'preflight']);
        $ok = $exporter->export($t->toArray() + ['sessionId' => 'aichat-preflight', 'release' => 'preflight']);
        $status = $exporter instanceof OtlpHttpExporter ? $exporter->lastStatus : ($ok ? 200 : 0);
        $last = $exporter instanceof OtlpHttpExporter ? $exporter->last : [];
        if ($ok && ($last['result'] ?? '') === 'partial') {
            return ['ok' => false, 'status' => $status, 'result' => 'partial',
                'message' => 'Partially accepted (HTTP ' . $status . '): the endpoint rejected ' . (int)$last['rejected_spans'] .
                    ' span(s) (OTLP partialSuccess). Check the backend version, attribute limits and its logs.'];
        }
        $message = self::explain($status, $exporter->getName());
        if ($ok && ($last['response'] ?? 'ok') === 'malformed') {
            $message .= ' Note: the response body was not an OTLP/JSON response, so partial acceptance could not be checked.';
        }
        return ['ok' => $ok, 'status' => $status, 'result' => $ok ? 'accepted' : 'rejected', 'message' => $message];
    }

    public static function explain(int $status, string $backend): string
    {
        $lf = $backend === 'langfuse';
        if ($status >= 200 && $status < 300) {
            return 'Accepted (HTTP ' . $status . '). ' . ($lf
                ? 'Open Langfuse and check that a trace "aichat.turn" with session "aichat-preflight" appears; '
                . 'acceptance alone does not prove that the attributes are mapped as intended.'
                : 'Check your collector for a span "aichat.preflight".');
        }
        if ($status >= 300 && $status < 400) {
            return 'Redirect (HTTP ' . $status . ') refused: configure the final endpoint URL (no redirects are followed).';
        }
        switch ($status) {
            case 0:
                return 'No HTTP response: endpoint unreachable, timeout, TLS problem or non-http(s) URL.';
            case 401:
            case 403:
                return 'Authentication failed (HTTP ' . $status . '): check the public/secret key (Langfuse) or Authorization value.';
            case 404:
                return 'Not found (HTTP 404): wrong base URL, or the instance has no OTLP traces endpoint ' .
                    ($lf ? '(/api/public/otel/v1/traces) - upgrade Langfuse.' : '(…/v1/traces).');
            case 400:
            case 415:
                return 'Request rejected (HTTP ' . $status . '): the endpoint probably does not accept OTLP over HTTP with a JSON body. ' .
                    ($lf ? 'Older Langfuse versions only decoded protobuf - upgrade to a current version.' : 'Enable OTLP/HTTP JSON on the collector.');
            default:
                return 'Unexpected response (HTTP ' . $status . ').';
        }
    }
}
