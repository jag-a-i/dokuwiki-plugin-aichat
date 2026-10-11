<?php

namespace dokuwiki\plugin\aichat\test;

use dokuwiki\plugin\aichat\Conversation\SessionBridge;

/**
 * Real PHP session persistence (separate process, file handler): merge into fresh locked
 * session data, no stale snapshot write-back, session closed again, identity binding.
 *
 * @group plugin_aichat
 * @group plugins
 */
class SessionBridgeTest extends \DokuWikiTest
{
    public function testRealSessionMergeAndIdentityBinding()
    {
        $dir = sys_get_temp_dir() . '/aichat_sess_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/Fixtures/session_bridge_check.php') .
            ' ' . escapeshellarg($dir) . ' 2>&1';
        $out = shell_exec($cmd);
        $r = json_decode((string)$out, true);
        $this->assertIsArray($r, (string)$out);

        $this->assertTrue($r['applied0']);
        $this->assertTrue($r['persisted0'], 'written to real session storage');
        $this->assertTrue($r['closedAfterWrite'], 'session lock released right after the write');
        $this->assertSame('changed-by-b', $r['other'], 'fresh data kept');
        $this->assertFalse($r['staleWritten'], 'stale snapshot not written back');

        // two requests read empty snapshots, then write A and B: both survive
        $this->assertSame([], $r['emptyBefore']);
        $this->assertTrue($r['writeA']);
        $this->assertTrue($r['writeB']);
        $this->assertSame(['tabAAAAAAAA' => 'pA', 'tabBBBBBBBB' => 'pB'], $r['afterAB']);

        // a consumed token never reappears; double use and stale writes are refused
        $this->assertTrue($r['consume1']);
        $this->assertFalse($r['consume2'], 'token is single use across concurrent requests');
        $this->assertFalse($r['stalePutOverConsumed']);
        $this->assertSame(['tabBBBBBBBB' => 'pB'], $r['afterConsume']);
        $this->assertTrue($r['newToken']);
        $this->assertFalse($r['staleDeleteOfNewer'], 'stale request cannot delete a newer token');
        $this->assertSame(['tabBBBBBBBB' => 'pB', 'tabAAAAAAAA' => 'pQ'], $r['afterStaleDelete']);
        $this->assertSame(['tabAAAAAAAA' => 'pQ'], $r['afterDeleteB']);

        $this->assertFalse($r['lateWriteAfterLogout'], 'late write after logout refused');
        $this->assertNotContains('tabDDDDDDDD', $r['aliceAfterLogout'], 'no resurrection');
        $this->assertSame([], $r['guestRead'], 'logged-out identity sees nothing');
        $this->assertSame([], $r['aliceAfterGuestWrite'], 'other identities dropped on write');
        $this->assertSame(1, $r['identities']);
        $this->assertSame([], $r['bobRead'], 'other user');
        $this->assertSame([], $r['aliceOtherSessionRead'], 'other session');
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }

    public function testNoSessionMeansMemoryOnly()
    {
        $_SESSION = [];
        $b = new SessionBridge('alice', '', static fn() => false);
        $this->assertTrue($b->writeConversation('x', ['id' => '1', 'created' => 1], null));
        $this->assertFalse($b->lastWritePersisted, 'memory only without a session');
        $this->assertSame(['x' => ['id' => '1', 'created' => 1]], $b->read());
        $this->assertFalse($b->writeConversation('x', null, 'other'), 'same CAS rules in memory');
        $this->assertTrue($b->writeConversation('x', null, '1'));
        $this->assertSame([], $_SESSION[SessionBridge::SESSION_KEY]['pending']);
    }
}
