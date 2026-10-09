<?php

namespace dokuwiki\plugin\aichat\Conversation;

/**
 * Deterministically interprets a user's reply to a pending clarification.
 * Only resolves against the options stored server-side for the pending turn.
 */
class FollowupResolver
{
    public const CHOICE = 'choice';
    public const NONE = 'none';
    public const UNKNOWN = 'unknown';
    public const CANCEL = 'cancel';
    public const NEW_TOPIC = 'new_topic';
    public const FREETEXT = 'freetext';

    protected const ORDINALS = [
        'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5,
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
    ];

    /**
     * @param string $reply the (redacted) user reply
     * @param array $options [['label'=>..., 'pages'=>[...]], ...]
     * @return array ['type'=>string, 'index'=>int|null]
     */
    public function resolve(string $reply, array $options): array
    {
        $r = $this->norm($reply);
        $words = $r === '' ? 0 : count(explode(' ', $r));
        $count = count($options);

        if (preg_match('/^(cancel|stop|never ?mind|forget it|abort)\b/', $r)) {
            return ['type' => self::CANCEL, 'index' => null];
        }
        if (preg_match('/\b(i (do ?n.?t|dont) know|not sure|no idea|unsure|i.?m not sure)\b/', $r) && $words <= 8) {
            return ['type' => self::UNKNOWN, 'index' => null];
        }
        if (preg_match('/^(none( of (these|them|the above|those))?|neither( of (these|them))?|something else|other)\b/', $r)) {
            return ['type' => self::NONE, 'index' => null];
        }

        // exact label match first (handles clicked buttons, which send the label)
        foreach ($options as $i => $opt) {
            if ($r === $this->norm($opt['label'])) return ['type' => self::CHOICE, 'index' => $i];
        }

        if ($count && $words <= 5) {
            // "2", "#2", "option 2", "2.", "number two", "the second one", "the last one"
            if (preg_match('/^(?:option|number|no\.?|nr\.?|#)?\s*(\d{1,2})\s*[.)]?$/', $r, $m)) {
                $n = (int)$m[1];
                if ($n >= 1 && $n <= $count) return ['type' => self::CHOICE, 'index' => $n - 1];
            }
            if (preg_match('/^(?:the\s+)?(?:option\s+|number\s+)?(first|second|third|fourth|fifth|last|one|two|three|four|five)(?:\s+(?:one|option))?$/', $r, $m)) {
                $n = $m[1] === 'last' ? $count : self::ORDINALS[$m[1]];
                if ($n >= 1 && $n <= $count) return ['type' => self::CHOICE, 'index' => $n - 1];
            }
        }

        // label contained in reply (or reply in label), unique match only
        $hits = [];
        foreach ($options as $i => $opt) {
            $label = $this->norm($opt['label']);
            if ($label === '') continue;
            if ($this->containsWords($r, $label) || ($words <= 4 && mb_strlen($r) >= 3 && $this->containsWords($label, $r))) {
                $hits[] = $i;
            }
        }
        if (count($hits) === 1 && !$this->looksLikeNewQuestion($r)) {
            return ['type' => self::CHOICE, 'index' => $hits[0]];
        }

        if ($this->looksLikeNewQuestion($r) && !$hits) {
            return ['type' => self::NEW_TOPIC, 'index' => null];
        }
        return ['type' => self::FREETEXT, 'index' => null];
    }

    /** true for replies that only make sense as a reference to a pending option list */
    public static function isBareChoice(string $reply): bool
    {
        $r = trim(mb_strtolower($reply), " \t\n.!?");
        return (bool)preg_match(
            '/^(?:(?:option|number|no\.?|nr\.?|#)\s*)?\d{1,2}[.)]?$|^(?:the\s+)?(?:first|second|third|fourth|fifth|last)(?:\s+(?:one|option))?$/',
            $r
        );
    }

    protected function looksLikeNewQuestion(string $r): bool
    {
        return (bool)preg_match('/^(how|what|where|when|why|who|which|can|could|is|are|do|does|should|tell me|explain)\b.{12,}/', $r);
    }

    protected function containsWords(string $haystack, string $needle): bool
    {
        return (bool)preg_match('/(^|\s)' . preg_quote($needle, '/') . '(\s|$)/u', $haystack);
    }

    protected function norm(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^\p{L}\p{N}#.\']+/u', ' ', $s);
        $s = preg_replace('/(?<!\d)\.|\.(?!\d)/', ' ', $s); // drop sentence dots, keep "1.5"
        return trim(preg_replace('/\s+/', ' ', $s));
    }
}
