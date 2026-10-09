<?php

namespace dokuwiki\plugin\aichat\Conversation;

/**
 * Server-side storage for a pending clarification, bound to the user's PHP session
 * and a per-tab conversation id. Browser-supplied ids are only lookup keys: the
 * stored data (original need, grounded options) never comes from the client.
 *
 * Every write is a per-conversation compare-and-swap on the pending token id:
 *  - consume(): delete only if the stored token is still the one presented (single use)
 *  - put():     store a new token only if the slot still holds what this request saw
 *  - clear():   delete only if the slot still holds what this request saw
 * The persist callback performs the same check against the FRESH session data, so
 * overlapping requests cannot double-use a token, delete a newer one or resurrect a consumed one.
 *
 * The local array is this request's snapshot (tests use a plain array; production
 * uses SessionBridge, see helper_plugin_aichat::getPendingStore()).
 */
class PendingStore
{
    public const TTL = 900;              // seconds
    public const MAX_CONVERSATIONS = 10; // per session
    public const MAX_NEED_LEN = 1000;
    public const MAX_OPTIONS = 5;
    public const MAX_LABEL_LEN = 80;
    public const MAX_PAGES_PER_OPTION = 10;

    /** @var array */
    protected $store;
    /**
     * @var callable|null persist(string $conversation, ?array $item, ?string $expectedId): bool
     *      applies the change only if the fresh stored token id equals $expectedId (null = empty slot)
     */
    protected $persist;
    /** @var callable returns current unix time */
    protected $clock;

    public function __construct(array &$store, ?callable $persist = null, ?callable $clock = null)
    {
        $this->store = &$store;
        $this->persist = $persist;
        $this->clock = $clock ?: static fn() => time();
    }

    public static function isValidConversationId(string $id): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9_-]{8,64}$/', $id);
    }

    /** token id currently held in this request's snapshot (null if none) */
    public function currentId(string $conversation): ?string
    {
        $item = $this->store[$conversation] ?? null;
        return is_array($item) && isset($item['id']) ? (string)$item['id'] : null;
    }

    /**
     * @return array|null pending state if it exists, is fresh and matches the presented token id
     */
    public function get(string $conversation, string $pendingId): ?array
    {
        if (!self::isValidConversationId($conversation) || $pendingId === '') return null;
        $item = $this->store[$conversation] ?? null;
        if (!is_array($item)) return null;
        if (($this->clock)() - (int)$item['created'] > self::TTL) {
            $this->clear($conversation);
            return null;
        }
        if (!hash_equals((string)$item['id'], $pendingId)) return null;
        return $item;
    }

    /**
     * Atomically use up a token. Returns false if it was already consumed or replaced meanwhile.
     */
    public function consume(string $conversation, string $pendingId): bool
    {
        if ($this->currentId($conversation) !== $pendingId) return false;
        return $this->write($conversation, null, $pendingId);
    }

    /**
     * @param string $need original (redacted) information need
     * @param array $options [['label'=>string,'pages'=>string[]], ...]
     * @return string the new opaque pending id, '' on a concurrent-modification conflict
     */
    public function put(string $conversation, string $need, array $options, int $rounds): string
    {
        if (!self::isValidConversationId($conversation)) {
            throw new \InvalidArgumentException('invalid conversation id');
        }
        $clean = [];
        foreach (array_slice($options, 0, self::MAX_OPTIONS) as $opt) {
            $clean[] = [
                'label' => mb_substr((string)$opt['label'], 0, self::MAX_LABEL_LEN),
                'pages' => array_slice(array_values(array_map('strval', $opt['pages'])), 0, self::MAX_PAGES_PER_OPTION),
            ];
        }
        $id = bin2hex(random_bytes(12));
        $item = [
            'id' => $id,
            'need' => mb_substr($need, 0, self::MAX_NEED_LEN),
            'options' => $clean,
            'rounds' => $rounds,
            'created' => ($this->clock)(),
        ];
        return $this->write($conversation, $item, $this->currentId($conversation)) ? $id : '';
    }

    /** delete this conversation's token if it is still the one this request saw */
    public function clear(string $conversation): void
    {
        $current = $this->currentId($conversation);
        if ($current !== null) $this->write($conversation, null, $current);
    }

    protected function write(string $conversation, ?array $item, ?string $expectedId): bool
    {
        if ($this->persist) {
            if (!($this->persist)($conversation, $item, $expectedId)) return false;
        } elseif ($this->currentId($conversation) !== $expectedId) {
            return false;
        }
        unset($this->store[$conversation]); // re-insert at end for LRU ordering
        if ($item !== null) $this->store[$conversation] = $item;
        while (count($this->store) > self::MAX_CONVERSATIONS) array_shift($this->store);
        return true;
    }
}
