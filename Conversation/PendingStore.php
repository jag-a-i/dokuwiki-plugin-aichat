<?php

namespace dokuwiki\plugin\aichat\Conversation;

/**
 * Server-side storage for a pending clarification, bound to the user's PHP session
 * and a per-tab conversation id. Browser-supplied ids are only lookup keys: the
 * stored data (original need, grounded options) never comes from the client.
 *
 * The storage array is injected by reference so tests can use a plain array;
 * production uses $_SESSION (see helper_plugin_aichat::getPendingStore()).
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
    /** @var callable|null persists after write (e.g. reopen + close the session) */
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

    /**
     * @return array|null pending state if it exists, is fresh and matches the expected turn id
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
     * @param string $need original (redacted) information need
     * @param array $options [['label'=>string,'pages'=>string[]], ...]
     * @return string the new opaque pending id
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
        unset($this->store[$conversation]); // re-insert at end for LRU ordering
        $this->store[$conversation] = [
            'id' => $id,
            'need' => mb_substr($need, 0, self::MAX_NEED_LEN),
            'options' => $clean,
            'rounds' => $rounds,
            'created' => ($this->clock)(),
        ];
        while (count($this->store) > self::MAX_CONVERSATIONS) {
            array_shift($this->store);
        }
        $this->save();
        return $id;
    }

    public function clear(string $conversation): void
    {
        if (isset($this->store[$conversation])) {
            unset($this->store[$conversation]);
            $this->save();
        }
    }

    protected function save(): void
    {
        if ($this->persist) ($this->persist)($this->store);
    }
}
