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

        $this->assertTrue($r['persisted']);
        $this->assertTrue($r['closedAfterWrite'], 'session must not stay open (e.g. across model calls)');
        $this->assertSame('changed-by-request-b', $r['stored']['other'], 'fresh data kept, stale snapshot not written back');
        $this->assertSame('b', $r['stored']['login']);
        $this->assertArrayNotHasKey('stale_only', $r['stored']);
        $this->assertSame(['tabAAAAAAAA' => ['id' => 'p1']], $r['aliceRead']);
        $this->assertSame([], $r['bobRead'], 'other user');
        $this->assertSame([], $r['guestRead'], 'after logout');
        $this->assertSame([], $r['aliceOtherSessionRead'], 'other session');
        $this->assertCount(1, $r['stored'][SessionBridge::SESSION_KEY]['pending']);
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }

    public function testNoSessionMeansMemoryOnly()
    {
        $_SESSION = [];
        $b = new SessionBridge('alice', '', static fn() => false);
        $this->assertFalse($b->write(['x' => ['id' => '1']]));
        $this->assertSame(['x' => ['id' => '1']], $b->read());
        $b->write([]);
        $this->assertSame([], $_SESSION[SessionBridge::SESSION_KEY]['pending']);
    }
}
