<?php

namespace dokuwiki\plugin\aichat\Telemetry;

use dokuwiki\plugin\aichat\Conversation\SecretRedactor;

/**
 * Per-turn glue between chat results, the local response log and the optional trace exporter.
 * Every public method swallows all errors: diagnostics and export can never break the chat.
 */
class TurnTelemetry
{
    protected array $conf;
    protected string $metaDir;
    /** @var callable|null */
    protected $http;
    protected ?ExporterInterface $exporter = null;
    protected bool $exporterBuilt = false;
    public ?ResponseLog $log = null;

    public function __construct(array $conf, string $metaDir, ?callable $http = null)
    {
        $this->conf = $conf;
        $this->metaDir = rtrim($metaDir, '/') . '/aichat';
        $this->http = $http;
        if (!empty($conf['diagnostics']) || !empty($conf['feedback'])) {
            $this->log = new ResponseLog(
                $this->metaDir . '/responses',
                (int)($conf['diagnostics_retention'] ?? 30),
                !empty($conf['diagnostics'])
            );
        }
    }

    public function exportEnabled(): bool
    {
        return ($this->conf['telemetry'] ?? 'off') !== 'off';
    }

    /** a recorder for one turn; content capture only if export is enabled AND capture configured */
    public function newRecorder(): TraceRecorder
    {
        $capture = [];
        if ($this->exportEnabled()) {
            $capture = array_filter(array_map('trim', explode(',', (string)($this->conf['telemetry_capture'] ?? ''))));
        }
        return new TraceRecorder($capture);
    }

    public function getExporter(): ?ExporterInterface
    {
        if (!$this->exporterBuilt) {
            $this->exporterBuilt = true;
            try {
                $spool = new Spool(
                    $this->metaDir . '/spool',
                    (int)($this->conf['telemetry_spool_max'] ?? 100),
                    (int)($this->conf['telemetry_spool_days'] ?? 3) * 86400
                );
                $this->exporter = ExporterFactory::create($this->conf, $this->http ?: ExporterFactory::dokuHttp(), $spool);
            } catch (\Throwable $e) {
                $this->exporter = null;
            }
        }
        return $this->exporter;
    }

    /** keyed hash identifying the owner of a response (user, or guest session); '' if none */
    public static function owner(string $user, string $sessionId, string $salt): string
    {
        if ($user === '' && $sessionId === '') return '';
        return hash_hmac('sha256', $user !== '' ? 'u:' . $user : 'g:' . $sessionId, $salt);
    }

    /** write the local metadata record for a finished turn */
    public function record(array $result, TraceRecorder $trace, string $owner, string $model, string $confRev): bool
    {
        if (!$this->log || empty($result['responseId'])) return false;
        try {
            return $this->log->record([
                'id' => $result['responseId'],
                'ts' => time(),
                'owner' => $owner,
                'outcome' => $result['outcome'],
                'model' => $model,
                'conf_rev' => $confRev,
                'sources' => count($result['sources'] ?? []),
                'options' => count($result['options'] ?? []),
                'timings' => $trace->timingsMs(),
                'error_category' => $result['errorCategory'] ?? '',
                'redacted' => !empty($result['redacted']),
                'trace_id' => $this->exportEnabled() ? $trace->getTraceId() : '',
            ]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** add content to the trace, only for kinds the administrator allowed, always secret-redacted */
    public function captureContent(TraceRecorder $trace, array $result): void
    {
        try {
            $redactor = new SecretRedactor();
            if ($trace->captures('question')) $trace->content('question', $redactor->redact((string)$result['question'])[0]);
            if ($trace->captures('answer') && in_array($result['outcome'], ['ANSWER', 'CLARIFY', 'NOTICE'], true)) {
                $trace->content('answer', $redactor->redact((string)$result['answer'])[0]);
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /** export the finished trace; returns the exporter's status or a reason */
    public function export(TraceRecorder $trace, string $sessionId, string $release): string
    {
        if (!$this->exportEnabled()) return 'disabled';
        try {
            $exporter = $this->getExporter();
            if (!$exporter) return 'not_configured';
            $data = $trace->toArray() + ['sessionId' => $sessionId, 'release' => $release];
            $ok = $exporter->export($data);
            if ($exporter instanceof OtlpHttpExporter) return $exporter->last['result'] ?? ($ok ? 'sent' : 'failed');
            return $ok ? 'sent' : 'failed';
        } catch (\Throwable $e) {
            return 'failed';
        }
    }
}
