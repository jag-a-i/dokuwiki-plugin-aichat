<?php

namespace dokuwiki\plugin\aichat\Conversation;

/**
 * Parses the line-oriented decision format produced by the chat model.
 *
 * We deliberately do not rely on JSON mode / tool calling, since the OpenAI-compatible
 * endpoint (e.g. llama-swap) and small models may not support it reliably.
 *
 * Expected formats:
 *   DECISION: ANSWER
 *   USED: S1, S3
 *   ANSWER:
 *   <markdown>
 *
 *   DECISION: CLARIFY
 *   QUESTION: <one question>
 *   OPTION: <label> | S1, S2
 *
 *   DECISION: NO_INFORMATION
 *
 * Returns null when the output is malformed. Content is NOT trusted: identifiers are
 * returned raw and must be validated by the caller against the permitted set.
 */
class DecisionParser
{
    public const MAX_OPTIONS_PARSED = 8;

    /**
     * @return array|null ['decision'=>string, 'answer'=>string, 'used'=>string[],
     *                     'question'=>string, 'options'=>[['label'=>string,'refs'=>string[]]]]
     */
    public function parse(string $raw): ?array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($raw));
        // tolerate a wrapping code fence around the whole reply
        $text = preg_replace('/^```[a-z]*\n(.*)\n```$/s', '$1', $text);
        $lines = explode("\n", $text);

        $decision = null;
        $start = 0;
        foreach ($lines as $i => $line) {
            if (preg_match('/^[\s*_#>]*DECISION[\s*_]*:[\s*_]*([A-Z_]+)/i', $line, $m)) {
                $decision = strtoupper($m[1]);
                $start = $i + 1;
                break;
            }
            if ($i > 3) break; // decision must be at the top
        }
        if (!in_array($decision, [Outcome::ANSWER, Outcome::CLARIFY, Outcome::NO_INFORMATION], true)) {
            return null;
        }

        $result = ['decision' => $decision, 'answer' => '', 'used' => [], 'question' => '', 'options' => []];
        $rest = array_slice($lines, $start);

        if ($decision === Outcome::ANSWER) {
            $body = [];
            $inAnswer = false;
            foreach ($rest as $line) {
                if (!$inAnswer && preg_match('/^[\s*_]*USED[\s*_]*:(.*)$/i', $line, $m)) {
                    $result['used'] = $this->refs($m[1]);
                    continue;
                }
                if (!$inAnswer && preg_match('/^[\s*_]*ANSWER[\s*_]*:(.*)$/i', $line, $m)) {
                    $inAnswer = true;
                    if (trim($m[1]) !== '') $body[] = trim($m[1]);
                    continue;
                }
                if ($inAnswer) {
                    // a trailing USED line after the answer is accepted as well
                    if (preg_match('/^[\s*_]*USED[\s*_]*:(.*)$/i', $line, $m)) {
                        $result['used'] = $this->refs($m[1]);
                        continue;
                    }
                    $body[] = $line;
                }
            }
            $result['answer'] = trim(implode("\n", $body));
            if ($result['answer'] === '') return null;
        } elseif ($decision === Outcome::CLARIFY) {
            foreach ($rest as $line) {
                if (preg_match('/^[\s*_]*QUESTION[\s*_]*:(.*)$/i', $line, $m)) {
                    $result['question'] = trim($m[1]);
                } elseif (preg_match('/^[\s*_\-]*OPTION[\s*_]*\d*[\s*_]*:(.*)$/i', $line, $m)) {
                    if (count($result['options']) >= self::MAX_OPTIONS_PARSED) continue;
                    $parts = explode('|', $m[1], 2);
                    $result['options'][] = [
                        'label' => trim($parts[0]),
                        'refs' => isset($parts[1]) ? $this->refs($parts[1]) : [],
                    ];
                }
            }
            if ($result['question'] === '' && !$result['options']) return null;
        }
        return $result;
    }

    /** @return string[] normalized refs like S1 */
    protected function refs(string $list): array
    {
        preg_match_all('/\bS\s*(\d{1,3})\b/i', $list, $m);
        return array_values(array_unique(array_map(static fn($n) => 'S' . (int)$n, $m[1])));
    }
}
