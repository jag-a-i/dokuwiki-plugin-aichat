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
    /** one or more offered options were explicitly rejected ("not VPN") */
    public const REJECT = 'reject';

    /** negation words that, shortly before an option reference, turn it into a rejection */
    protected const NEGATORS = '(?:neither|not|no|isn\'?t|is not|aren\'?t|without|except|rather than|instead of|nicht|kein|keine)';
    /** words that carry no information once negated parts are removed */
    protected const FILLER = '/^(?:but|and|or|it|its|it\'s|is|was|the|one|that|this|i|i\'m|im|mean|meant|actually|sorry|rather|instead|please|no|so|well|just|said|say)$/';

    protected const ORDINALS = [
        'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5,
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
    ];

    /**
     * @param string $reply the (redacted) user reply
     * @param array $options [['label'=>..., 'pages'=>[...]], ...]
     * @return array ['type'=>string, 'index'=>int|null, 'rejected'=>int[], 'remainder'=>string]
     */
    public function resolve(string $reply, array $options): array
    {
        return array_merge(['index' => null, 'rejected' => [], 'remainder' => ''], $this->classify($reply, $options));
    }

    protected function classify(string $reply, array $options): array
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
        if (preg_match('/^(no|nope|nah)$/', $r)) return ['type' => self::NONE];
        if (preg_match('/^(none( of (these|them|the above|those))?|neither( of (these|them))?|something else|other)\b/', $r)
            && !$this->mentionsLabel($r, $options)) {
            return ['type' => self::NONE, 'index' => null];
        }

        // "No. 2" means number 2 (the dot is lost by normalization)
        if (preg_match('/^\s*(?:no|nr)\.\s*(\d{1,2})\s*$/i', $reply, $m)) {
            $n = (int)$m[1];
            if ($n >= 1 && $n <= $count) return ['type' => self::CHOICE, 'index' => $n - 1];
        }

        // explicit rejections ("not VPN", "not 2", "the CRM one, not VPN") must never select the rejected option
        [$negated, $positive, $remainder] = $this->negations($r, $options);
        if ($negated) {
            if (count($positive) === 1) return ['type' => self::CHOICE, 'index' => $positive[0]];
            if (!$positive) return ['type' => self::REJECT, 'rejected' => $negated, 'remainder' => $remainder];
            return ['type' => self::FREETEXT];
        }

        // exact label match first (handles clicked buttons, which send the label)
        foreach ($options as $i => $opt) {
            if ($r === $this->norm($opt['label'])) return ['type' => self::CHOICE, 'index' => $i];
        }

        if ($count && $words <= 5) {
            // "2", "#2", "option 2", "2.", "number two", "the second one", "the last one"
            // note: bare "no 2" is a rejection (handled above), only "no. 2" means number 2
            if (preg_match('/^(?:option|number|no\.|nr\.?|#)?\s*(\d{1,2})\s*[.)]?$/', $r, $m)) {
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

    /**
     * Find option references preceded by a negation.
     *
     * @return array [int[] $negatedIndexes, int[] $positiveIndexes, string $remainder]
     */
    protected function negations(string $r, array $options): array
    {
        $neg = self::NEGATORS;
        $negated = [];
        $positive = [];
        $remainder = ' ' . $r . ' ';
        $count = count($options);
        $labels = [];
        foreach ($options as $i => $opt) {
            $label = $this->norm($opt['label']);
            if ($label !== '') $labels[$i] = $label;
        }
        if ($labels) {
            // longest first so "vpn token" wins over "vpn"
            $alts = $labels;
            usort($alts, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            $alt = implode('|', array_map(static fn($l) => preg_quote($l, '/'), $alts));
            $det = '(?:(?:the|my|our|a|an|that|this)\s+)?';
            $item = $det . '(?:' . $alt . ')(?:\s+(?:one|account|system))?';
            // a negated list: "not X", "not X or Y", "neither X nor Y", "not X, Y and Z"
            // commas are removed by norm(), so list items may also be separated by plain spaces
            $span = '/(?:^|\s)' . $neg . '\s+' . $item . '(?:\s+(?:(?:or|nor|and)\s+)?' . $item . ')*(?=\s|$)/u';
            if (preg_match_all($span, $r, $m)) {
                // longest label first, consuming matched text so "vpn token" does not also mark "vpn"
                $byLen = $labels;
                uasort($byLen, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
                foreach ($m[0] as $text) {
                    $text = ' ' . trim($text) . ' ';
                    foreach ($byLen as $i => $label) {
                        $lp = '/\s' . preg_quote($label, '/') . '(?=\s)/u';
                        if (preg_match($lp, $text)) {
                            if (!in_array($i, $negated, true)) $negated[] = $i;
                            $text = preg_replace($lp, ' ', $text);
                        }
                    }
                }
                $remainder = preg_replace($span, ' ', $remainder);
            }
            foreach ($labels as $i => $label) {
                if (!in_array($i, $negated, true) && $this->containsWords($remainder, $label)) $positive[] = $i;
            }
        }
        // "not 2", "not option 2", "not the second one"
        if (preg_match_all('/(?:^|\s)' . $neg . '\s+(?:(?:option|number|the)\s+)?(\d{1,2}|first|second|third|fourth|fifth|last)(?:\s+(?:one|option))?(?=\s|$)/', $r, $m)) {
            foreach ($m[1] as $ref) {
                $n = ctype_digit($ref) ? (int)$ref : ($ref === 'last' ? $count : self::ORDINALS[$ref]);
                if ($n >= 1 && $n <= $count && !in_array($n - 1, $negated, true)) $negated[] = $n - 1;
            }
            $remainder = preg_replace('/(?:^|\s)' . $neg . '\s+(?:(?:option|number|the)\s+)?(?:\d{1,2}|first|second|third|fourth|fifth|last)(?:\s+(?:one|option))?(?=\s|$)/', ' ', $remainder);
        }
        $positive = array_values(array_diff($positive, $negated));
        $words = array_filter(explode(' ', trim(preg_replace('/\s+/', ' ', $remainder))),
            static fn($w) => $w !== '' && !preg_match(self::FILLER, $w));
        return [$negated, $positive, implode(' ', $words)];
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

    protected function mentionsLabel(string $r, array $options): bool
    {
        foreach ($options as $opt) {
            $label = $this->norm($opt['label']);
            if ($label !== '' && $this->containsWords($r, $label)) return true;
        }
        return false;
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
