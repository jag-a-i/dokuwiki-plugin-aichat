<?php
// Runs in a separate PHP process with real file based sessions (no DokuWiki), see SessionBridgeTest.
require __DIR__ . '/../../Conversation/SessionBridge.php';
use dokuwiki\plugin\aichat\Conversation\SessionBridge;

$dir = $argv[1];
ini_set('session.save_path', $dir);
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.cache_limiter', '');
session_id('aichattestsid1234567890');

session_start();
$_SESSION = ['other' => 'orig'];
session_write_close();

// request A starts: loads its snapshot and closes the session early, like lib/exe/ajax.php
session_start();
session_write_close();
$snapshot = $_SESSION;

// meanwhile request B (e.g. login in another tab) changes the stored session
session_start();
$_SESSION['other'] = 'changed-by-request-b';
$_SESSION['login'] = 'b';
session_write_close();

// request A still holds a stale in-memory snapshot and has local junk in it
$_SESSION = $snapshot;
$_SESSION['stale_only'] = 'must-not-be-written';

$alice = new SessionBridge('alice', session_id(), static fn() => true);
$persisted = $alice->write(['tabAAAAAAAA' => ['id' => 'p1']]);
$statusAfter = session_status();

session_start();
$stored = $_SESSION;
session_write_close();

$_SESSION = $stored;
echo json_encode([
    'persisted' => $persisted,
    'closedAfterWrite' => $statusAfter !== PHP_SESSION_ACTIVE,
    'stored' => $stored,
    'aliceRead' => $alice->read(),
    'bobRead' => (new SessionBridge('bob', session_id(), static fn() => true))->read(),
    'guestRead' => (new SessionBridge('', session_id(), static fn() => true))->read(),
    'aliceOtherSessionRead' => (new SessionBridge('alice', 'othersid', static fn() => true))->read(),
]);
