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
    /** @var callable(): bool */
    protected $canOpen;

    public function __construct(string $user, ?string $sessionId = null, ?callable $canOpen = null)
    {
        $sessionId ??= (string)session_id();
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

    /**
     * @return bool true if the data was persisted to session storage
     */
    public function write(array $pending): bool
    {
        $opened = false;
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (session_id() === '' || !($this->canOpen)()) {
                // no session available (e.g. CLI, or output already sent): memory only
                $this->apply($pending);
                return false;
            }
            $opened = @session_start(); // reloads fresh stored data, replacing the stale snapshot
            if (!$opened) {
                $this->apply($pending);
                return false;
            }
        }
        $this->apply($pending);
        if ($opened) session_write_close();
        return $opened;
    }

    protected function apply(array $pending): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) $_SESSION = [];
        $own = $_SESSION[self::SESSION_KEY] ?? [];
        if (!is_array($own)) $own = [];
        $own['pending'] = $pending ? [$this->identity => $pending] : [];
        $_SESSION[self::SESSION_KEY] = $own;
    }
}
