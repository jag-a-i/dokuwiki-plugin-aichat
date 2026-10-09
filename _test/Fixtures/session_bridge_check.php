<?php
// Separate PHP process with real file based sessions (no DokuWiki), see SessionBridgeTest.
// Simulates lib/exe/ajax.php: each "request" loads the session, closes it early and keeps a
// stale in-memory snapshot while other requests modify the stored session.
define('DOKU_COOKIE', 'DWtest');
require __DIR__ . '/../../Conversation/PendingStore.php';
require __DIR__ . '/../../Conversation/SessionBridge.php';
use dokuwiki\plugin\aichat\Conversation\SessionBridge;

ini_set('session.save_path', $argv[1]);
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.cache_limiter', '');
session_id('aichattestsid1234567890');
$K = SessionBridge::SESSION_KEY;
$open = static fn() => true;

function begin(): array { session_start(); $s = $_SESSION; session_write_close(); return $s; } // request start
function stored(): array { session_start(); $s = $_SESSION; session_write_close(); return $s; }
function bridge(string $u) { return new SessionBridge($u, session_id(), static fn() => true); }
$out = [];

session_start();
$_SESSION = ['other' => 'orig', DOKU_COOKIE => ['auth' => ['user' => 'alice']]];
session_write_close();

// 1. stale snapshot is not written back as a whole; foreign keys changed meanwhile survive
$snapA = begin();
session_start(); $_SESSION['other'] = 'changed-by-b'; session_write_close();
$_SESSION = $snapA; $_SESSION['stale_only'] = 'x';
$out['persisted'] = bridge('alice')->writeConversation('tabAAAAAAAA', ['id' => 'pA', 'created' => 1]);
$out['closedAfterWrite'] = session_status() !== PHP_SESSION_ACTIVE;
$s = stored();
$out['other'] = $s['other']; $out['staleWritten'] = isset($s['stale_only']);

// 2. two overlapping requests (two tabs, same user) writing different conversations
$snap1 = begin(); $snap2 = begin();               // both start before either writes
$_SESSION = $snap1; bridge('alice')->writeConversation('tabBBBBBBBB', ['id' => 'pB', 'created' => 2]);
$_SESSION = $snap2; bridge('alice')->writeConversation('tabCCCCCCCC', ['id' => 'pC', 'created' => 3]);
$_SESSION = stored();
$out['afterOverlap'] = array_keys(bridge('alice')->read());

// 3. overlapping delete of one conversation keeps the others
$snap3 = begin();
$_SESSION = $snap3; bridge('alice')->writeConversation('tabBBBBBBBB', null);
$_SESSION = stored();
$out['afterDelete'] = array_keys(bridge('alice')->read());

// 4. logout happens while a request of alice is still running: its late write is refused
$snapLate = begin();
session_start(); unset($_SESSION[DOKU_COOKIE]['auth']); session_write_close(); // logout
$_SESSION = $snapLate;
$out['lateWriteAfterLogout'] = bridge('alice')->writeConversation('tabDDDDDDDD', ['id' => 'pD', 'created' => 4]);
$_SESSION = stored();
$out['aliceAfterLogout'] = array_keys(bridge('alice')->read());   // old entries still stored but...
$out['guestRead'] = bridge('')->read();                          // ...unreachable for the guest identity
// a guest write drops all other identities' entries
bridge('')->writeConversation('tabGGGGGGGG', ['id' => 'pG', 'created' => 5]);
$_SESSION = stored();
$out['aliceAfterGuestWrite'] = bridge('alice')->read();
$out['identities'] = count($_SESSION[$K]['pending']);

// 5. other user on the same session id, other session for the same user
$out['bobRead'] = bridge('bob')->read();
$out['aliceOtherSessionRead'] = (new SessionBridge('alice', 'othersid', $open))->read();
echo json_encode($out);
