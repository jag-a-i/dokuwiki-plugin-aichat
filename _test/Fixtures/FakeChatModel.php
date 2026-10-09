<?php

namespace dokuwiki\plugin\aichat\test\Fixtures;

use dokuwiki\plugin\aichat\Model\ChatInterface;

/**
 * Scripted chat model: returns queued replies (string or Throwable) and records prompts.
 */
class FakeChatModel implements ChatInterface
{
    public array $replies = [];
    public array $calls = [];

    public function __construct(string $name = 'fake', array $config = []) {}
    public function queue(...$replies): self { array_push($this->replies, ...$replies); return $this; }
    public function getAnswer(array $messages): string
    {
        $this->calls[] = $messages;
        $r = array_shift($this->replies);
        if ($r === null) throw new \LogicException('FakeChatModel: unexpected call');
        if ($r instanceof \Throwable) throw $r;
        return $r;
    }
    public function lastPrompt(): string { return end($this->calls)[count(end($this->calls)) - 1]['content']; }
    public function __toString(): string { return 'fake'; }
    public function getModelName() { return 'fake'; }
    public function resetUsageStats() {}
    public function getUsageStats() { return []; }
    public function getMaxInputTokenLength(): int { return 0; }
    public function getInputTokenPrice(): float { return 0; }
    public function getMaxOutputTokenLength(): int { return 0; }
    public function getOutputTokenPrice(): float { return 0; }
    function loadUnknownModelInfo(): array { return []; }
}
