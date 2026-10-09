<?php
// Subprocess: exports one trace through the REAL default transport (DokuHTTPClient) and prints the result.
// usage: php [-d openssl.cafile=...] redirect_client.php BACKEND URL
if (!defined('DOKU_INC')) define('DOKU_INC', realpath(__DIR__ . '/../../../../../') . '/');
define('NOSESSION', true);
require_once DOKU_INC . 'inc/init.php';
use dokuwiki\plugin\aichat\Telemetry\ExporterFactory;
use dokuwiki\plugin\aichat\Telemetry\TraceRecorder;
[$backend, $url] = [$argv[1], $argv[2]];
$conf = ['telemetry' => $backend, 'telemetry_endpoint' => $url, 'telemetry_timeout' => 2, 'telemetry_retries' => 2,
    'telemetry_langfuse_public' => 'pk-lf-placeholder', 'telemetry_langfuse_secret' => 'sk-lf-REDIRECT-CANARY',
    'telemetry_otlp_authorization' => 'Bearer OTLP-REDIRECT-CANARY'];
$e = ExporterFactory::create($conf, ExporterFactory::dokuHttp());
$t = new TraceRecorder();
$s = $t->start('retrieval'); $t->end($s, ['chunks' => 1]);
$t->finish(['outcome' => 'ANSWER', 'correlation_id' => 'BODY-CANARY-0123']);
$ok = $e->export($t->toArray() + ['sessionId' => 'c1', 'release' => 'x']);
echo json_encode(['ok' => $ok, 'last' => $e->last, 'status' => $e->lastStatus]);
