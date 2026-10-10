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

function begin(): array { session_start(); $s = $_SESSION; session_write_close(); return $s; } // request start
function stored(): array { session_start(); $s = $_SESSION; session_write_close(); return $s; }
/** a bridge for a request whose in-memory snapshot is $snap */
function req(array $snap, string $u = 'alice') { $_SESSION = $snap; return new SessionBridge($u, session_id(), static fn() => true); }
function item(string $id, int $t) { return ['id' => $id, 'created' => $t]; }
function ids(): array { $_SESSION = stored(); return array_map(fn($i) => $i['id'], req($_SESSION)->read()); }
$out = [];

session_start();
$_SESSION = ['other' => 'orig', DOKU_COOKIE => ['auth' => ['user' => 'alice']]];
session_write_close();

// 0. stale snapshot is not written back as a whole; foreign keys changed meanwhile survive
$snap = begin();
session_start(); $_SESSION['other'] = 'changed-by-b'; session_write_close();
$snap['stale_only'] = 'x';
$b = req($snap);
$out['applied0'] = $b->writeConversation('tab0000000', item('p0', 1), null);
$out['persisted0'] = $b->lastWritePersisted;
$out['closedAfterWrite'] = session_status() !== PHP_SESSION_ACTIVE;
$s = stored();
$out['other'] = $s['other']; $out['staleWritten'] = isset($s['stale_only']);
req($s)->writeConversation('tab0000000', null, 'p0');

// 1. reviewer case: two requests read EMPTY snapshots, then write A and B - both survive
$snapA = begin(); $snapB = begin();
$out['emptyBefore'] = req($snapA)->read();
$out['writeA'] = req($snapA)->writeConversation('tabAAAAAAAA', item('pA', 2), null);
$out['writeB'] = req($snapB)->writeConversation('tabBBBBBBBB', item('pB', 3), null);
$out['afterAB'] = ids();

// 2. reviewer case: a consumed token never reappears from a stale write
$s1 = begin(); $s2 = begin();                                      // both requests saw token pA
$out['consume1'] = req($s1)->writeConversation('tabAAAAAAAA', null, 'pA');               // first use wins
$out['consume2'] = req($s2)->writeConversation('tabAAAAAAAA', null, 'pA');               // double use refused
$out['stalePutOverConsumed'] = req($s2)->writeConversation('tabAAAAAAAA', item('pX', 4), 'pA'); // stale overwrite refused
$out['afterConsume'] = ids();
$s3 = begin();
$out['newToken'] = req($s3)->writeConversation('tabAAAAAAAA', item('pQ', 5), null);
$out['staleDeleteOfNewer'] = req($s2)->writeConversation('tabAAAAAAAA', null, 'pA');     // must not delete pQ
$out['afterStaleDelete'] = ids();

// 3. overlapping delete of one conversation keeps the others
$s4 = begin();
req($s4)->writeConversation('tabBBBBBBBB', null, 'pB');
$out['afterDeleteB'] = ids();

// 4. logout while a request of alice is still running: its late write is refused
$late = begin();
session_start(); unset($_SESSION[DOKU_COOKIE]['auth']); session_write_close(); // logout
$out['lateWriteAfterLogout'] = req($late)->writeConversation('tabDDDDDDDD', item('pD', 6), null);
$_SESSION = stored();
$out['aliceAfterLogout'] = array_keys(req($_SESSION)->read());
$out['guestRead'] = req($_SESSION, '')->read();
req($_SESSION, '')->writeConversation('tabGGGGGGGG', item('pG', 7), null);   // guest write drops other identities
$_SESSION = stored();
$out['aliceAfterGuestWrite'] = req($_SESSION)->read();
$out['identities'] = count($_SESSION[$K]['pending']);
$out['bobRead'] = req($_SESSION, 'bob')->read();
$_SESSION = stored();
$out['aliceOtherSessionRead'] = (new SessionBridge('alice', 'othersid', static fn() => true))->read();
echo json_encode($out);
