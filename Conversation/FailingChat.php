<?php

namespace dokuwiki\plugin\aichat\Conversation;

use dokuwiki\plugin\aichat\Model\ChatInterface;

/**
 * Stand-in used when the chat model cannot be constructed (e.g. misconfiguration).
 * Retrieval still runs, so an empty authorized context yields NO_INFORMATION; any
 * attempt to generate an answer rethrows the original error, which becomes an ERROR outcome.
 */
class FailingChat implements ChatInterface
{
    protected \Throwable $error;

    public function __construct($error, array $config = [])
    {
        $this->error = $error instanceof \Throwable ? $error : new \RuntimeException('chat model unavailable');
    }

    public function getAnswer(array $messages): string
    {
        throw $this->error;
    }

    public function __toString(): string { return 'unavailable'; }
    public function getModelName() { return 'unavailable'; }
    public function resetUsageStats() {}
    public function getUsageStats() { return []; }
    public function getMaxInputTokenLength(): int { return 0; }
    public function getInputTokenPrice(): float { return 0; }
    public function getMaxOutputTokenLength(): int { return 0; }
    public function getOutputTokenPrice(): float { return 0; }
    public function loadUnknownModelInfo(): array { return []; }
}
