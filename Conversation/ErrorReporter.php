<?php

namespace dokuwiki\plugin\aichat\Conversation;

use dokuwiki\Logger;
use dokuwiki\plugin\aichat\Model\ModelException;

/**
 * Sanitized error logging: category, correlation id and exception class only.
 * Exception messages are never logged because they can contain endpoints, tokens,
 * HTTP response bodies or retrieved content.
 */
class ErrorReporter
{
    public static function category(\Throwable $e): string
    {
        if ($e instanceof MalformedModelOutputException) return 'model_malformed';
        if ($e instanceof ModelException) return 'model_error';
        return 'backend_error';
    }

    public static function newCorrelationId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /** @return string the line that was logged (for tests) */
    public static function log(string $where, string $category, string $correlation, ?\Throwable $e = null): string
    {
        $class = $e ? (new \ReflectionClass($e))->getShortName() : '-';
        $line = sprintf('aichat %s error: category=%s ref=%s class=%s', $where, $category, $correlation, $class);
        try {
            Logger::error($line);
        } catch (\Throwable $ignored) {
            // logging must never break the chat
        }
        return $line;
    }
}
