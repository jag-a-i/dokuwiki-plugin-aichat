<?php

namespace dokuwiki\plugin\aichat\Eval;

use dokuwiki\plugin\aichat\Model\ChatInterface;

/**
 * Scripted stand-in models for DRY RUNS of the evaluation runner (validates fixtures and scoring,
 * says nothing about any real model):
 *  - "oracle": answers exactly what the fixture expects (should score 100 %)
 *  - "naive":  always answers from the first excerpt (should fail clarification/abstention cases)
 */
class ScriptedChat implements ChatInterface
{
    protected string $mode;
    protected array $expect = [];
    protected array $titles;
    public int $calls = 0;

    public function __construct($mode = 'oracle', array $titles = [])
    {
        $this->mode = (string)$mode;
        $this->titles = $titles;
    }

    public function expect(array $expect): void
    {
        $this->expect = $expect;
    }

    public function getAnswer(array $messages): string
    {
        $this->calls++;
        $prompt = (string)end($messages)['content'];
        if ($this->mode === 'naive') return "DECISION: ANSWER\nUSED: S1\nANSWER:\nSynthetic answer.";
        preg_match_all('/^\[(S\d+)\] (.+)$/m', $prompt, $m, PREG_SET_ORDER);
        $labels = [];
        foreach ($m as $x) $labels[$x[1]] = $x[2];
        switch ($this->expect['outcome'] ?? 'NO_INFORMATION') {
            case 'CLARIFY':
                $lines = ["DECISION: CLARIFY", "QUESTION: Which one do you mean?"];
                foreach ($this->expect['options_include'] ?? [] as $opt) {
                    foreach ($labels as $s => $title) {
                        if (stripos($title, $opt) !== false) { $lines[] = "OPTION: $opt | $s"; break; }
                    }
                }
                return implode("\n", $lines);
            case 'ANSWER':
                $used = [];
                foreach ($this->expect['sources_include'] ?? [] as $page) {
                    $t = $this->titles[$page] ?? $page;
                    foreach ($labels as $s => $title) if ($title === $t) $used[] = $s;
                }
                return "DECISION: ANSWER\nUSED: " . implode(', ', $used ?: ['S1']) . "\nANSWER:\nSynthetic answer.";
            default:
                return 'DECISION: NO_INFORMATION';
        }
    }

    public function __toString(): string { return 'scripted-' . $this->mode; }
    public function getModelName() { return 'scripted-' . $this->mode; }
    public function resetUsageStats() {}
    public function getUsageStats() { return ['tokens' => 0]; }
    public function getMaxInputTokenLength(): int { return 0; }
    public function getInputTokenPrice(): float { return 0; }
    public function getMaxOutputTokenLength(): int { return 0; }
    public function getOutputTokenPrice(): float { return 0; }
    public function loadUnknownModelInfo(): array { return []; }
}
