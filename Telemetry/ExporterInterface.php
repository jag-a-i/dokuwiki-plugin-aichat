<?php

namespace dokuwiki\plugin\aichat\Telemetry;

/**
 * A trace export backend. Implementations must be bounded (timeouts, retries) and must not
 * throw for transport problems; they return false instead. Chat logic never depends on them.
 */
interface ExporterInterface
{
    /**
     * @param array $trace TraceRecorder::toArray() plus 'sessionId' and 'release'
     * @return bool true if the backend accepted the trace (or it was spooled for later)
     */
    public function export(array $trace): bool;

    /** short identifier for logs/diagnostics, e.g. "langfuse" */
    public function getName(): string;
}
