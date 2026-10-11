<?php

namespace dokuwiki\plugin\aichat\Conversation;

/**
 * Persists pending clarification state in the PHP session.
 *
 * DokuWiki's lib/exe/ajax.php calls session_write_close() before plugins run. To write we
 * briefly reopen the session: session_start() reloads the CURRENT stored session data
 * (taking the session lock), we change only our own key and close again immediately.
 * The stale in-memory $_SESSION snapshot from request start is never written back as a whole.
 * The session is never held open across model or vector store calls.
 *
 * State is bound to an identity key derived from the authenticated user (or "guest") and the
 * session id, so login, logout or a user switch makes old state unreachable. On write all
 * entries for other identities are dropped.
 */
class SessionBridge
{
    public const SESSION_KEY = 'plugin_aichat';

    protected string $identity;
    protected string $user;
    /** session login seen at request start (from the in-memory snapshot) */
    protected string $startAuth;
    /** @var callable(): bool */
    protected $canOpen;

    public function __construct(string $user, ?string $sessionId = null, ?callable $canOpen = null)
    {
        $sessionId ??= (string)session_id();
        $this->user = $user;
        $this->startAuth = self::sessionAuthUser();
        $this->identity = hash('sha256', 'aichat|' . ($user !== '' ? 'u:' . $user : 'guest') . '|' . $sessionId);
        $this->canOpen = $canOpen ?: static fn() => !headers_sent();
    }

    public function getIdentity(): string
    {
        return $this->identity;
    }

    /** @return array the pending map for the current identity */
    public function read(): array
    {
        $data = $_SESSION[self::SESSION_KEY]['pending'][$this->identity] ?? [];
        return is_array($data) ? $data : [];
    }

    /** @var bool whether the last successful write reached session storage (false = memory only) */
    public bool $lastWritePersisted = false;

    /**
     * Compare-and-swap one conversation's pending state (null deletes it).
     *
     * Under the session lock the FRESH stored data is reloaded; the change is applied only if the
     * stored token id for this conversation still equals $expectedId (null = slot must be empty).
     * Only this conversation's entry changes, so overlapping requests for other conversations are
     * kept, a consumed token cannot be resurrected and a newer token cannot be deleted by a stale
     * request. Entries of other identities are dropped. If the session login changed since this
     * request started (logout, re-login, user switch), nothing is written.
     *
     * @return bool true if the change was applied
     */
    public function writeConversation(string $conversation, ?array $item, ?string $expectedId): bool
    {
        $this->lastWritePersisted = false;
        $opened = false;
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (session_id() !== '' && ($this->canOpen)()) {
                $opened = @session_start(); // reloads fresh stored data, replacing the stale snapshot
            }
            // otherwise: no session available (CLI, output sent) - memory only, same CAS rules
        }
        $applied = !$this->authChanged() && $this->apply($conversation, $item, $expectedId);
        if ($opened) {
            session_write_close();
            $this->lastWritePersisted = $applied;
        }
        return $applied;
    }

    /**
     * true if the session login changed since this request started (logout, re-login, user switch).
     * Compares fresh vs. start-of-request session data, so setups where the login is not kept in
     * the session (SSO, HTTP auth) are unaffected.
     */
    protected function authChanged(): bool
    {
        return self::sessionAuthUser() !== $this->startAuth;
    }

    protected static function sessionAuthUser(): string
    {
        if (!defined('DOKU_COOKIE')) return '';
        return (string)($_SESSION[DOKU_COOKIE]['auth']['user'] ?? '');
    }

    protected function apply(string $conversation, ?array $item, ?string $expectedId): bool
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) $_SESSION = [];
        $own = $_SESSION[self::SESSION_KEY] ?? [];
        if (!is_array($own)) $own = [];
        $map = $own['pending'][$this->identity] ?? [];
        if (!is_array($map)) $map = [];
        $current = isset($map[$conversation]['id']) ? (string)$map[$conversation]['id'] : null;
        if ($current !== $expectedId) return false; // concurrent modification: refuse
        unset($map[$conversation]);
        if ($item !== null) $map[$conversation] = $item;
        // bound size: keep the newest entries
        uasort($map, static fn($a, $b) => ((int)($a['created'] ?? 0)) <=> ((int)($b['created'] ?? 0)));
        while (count($map) > PendingStore::MAX_CONVERSATIONS) array_shift($map);
        $own['pending'] = $map ? [$this->identity => $map] : [];
        $_SESSION[self::SESSION_KEY] = $own;
        return true;
    }
}
