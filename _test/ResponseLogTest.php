<?php

namespace dokuwiki\plugin\aichat\test;

use dokuwiki\plugin\aichat\Telemetry\ResponseLog;

/**
 * @group plugin_aichat
 * @group plugins
 */
class ResponseLogTest extends \DokuWikiTest
{
    protected string $dir;
    protected int $now = 2_000_000_000;

    public function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/aichat_rl_' . bin2hex(random_bytes(4));
    }

    public function tearDown(): void
    {
        foreach (array_merge(glob($this->dir . '/*') ?: [], glob($this->dir . '/.cleanup') ?: []) as $f) unlink($f);
        @rmdir($this->dir);
        parent::tearDown();
    }

    protected function log(bool $diag = true): ResponseLog
    {
        return new ResponseLog($this->dir, 30, $diag, fn() => $this->now);
    }

    public function testOnlyAllowListedMetadataIsStored()
    {
        $id = str_repeat('b', 24);
        $this->log()->record(['id' => $id, 'owner' => 'o1', 'outcome' => 'ANSWER', 'sources' => 2,
            'question' => 'my password is X', 'answer' => 'secret steps', 'user' => 'alice', 'ip' => '10.1.2.3', 'pages' => ['it:a']]);
        $raw = file_get_contents("$this->dir/$id.json");
        foreach (['password', 'secret steps', 'alice', '10.1.2.3', 'it:a'] as $leak) $this->assertStringNotContainsString($leak, $raw);
        $this->assertSame(2, json_decode($raw, true)['sources']);
    }

    public function testDiagnosticsDisabledKeepsOnlyFeedbackBinding()
    {
        $id = str_repeat('c', 24);
        $this->log(false)->record(['id' => $id, 'owner' => 'o1', 'outcome' => 'ANSWER', 'timings' => ['total' => 5], 'model' => 'm']);
        $this->assertSame(['id', 'owner', 'outcome', 'ts'], array_keys(json_decode(file_get_contents("$this->dir/$id.json"), true)));
    }

    public function testVoteChangeOverwritesAndOwnershipIsEnforced()
    {
        $id = str_repeat('d', 24);
        $log = $this->log();
        $log->record(['id' => $id, 'owner' => 'owner-a', 'outcome' => 'ANSWER']);
        $this->assertSame('ok', $log->setFeedback($id, 'owner-a', 'helpful'));
        $this->assertSame('ok', $log->setFeedback($id, 'owner-a', 'not_helpful', 'wrong_source'));
        $this->assertSame(['vote' => 'not_helpful', 'category' => 'wrong_source'], array_intersect_key($log->get($id)['feedback'], ['vote' => 1, 'category' => 1]));
        $this->assertCount(1, glob("$this->dir/*.json"), 'one record, no duplicates');
        $this->assertSame('not_found', $log->setFeedback($id, 'owner-b', 'helpful'), 'other owner cannot probe or modify');
        $this->assertSame('not_helpful', $log->get($id)['feedback']['vote']);
        $this->assertSame('not_found', $log->setFeedback(str_repeat('e', 24), 'owner-a', 'helpful'));
        $this->assertCount(1, glob("$this->dir/*.json"), 'unknown id does not create files');
        $this->assertSame('invalid', $log->setFeedback($id, 'owner-a', 'helpful', 'wrong_source'), 'category only with not_helpful');
        $this->assertSame('invalid', $log->setFeedback($id, 'owner-a', 'love it'));
    }

    public function testOnlyAnswersAreVotable()
    {
        $id = str_repeat('f', 24);
        $this->log()->record(['id' => $id, 'owner' => 'o', 'outcome' => 'CLARIFY']);
        $this->assertSame('not_allowed', $this->log()->setFeedback($id, 'o', 'helpful'));
    }

    public function testRetention()
    {
        $old = str_repeat('1', 24);
        $new = str_repeat('2', 24);
        $this->log()->record(['id' => $old, 'owner' => 'o', 'outcome' => 'ANSWER']);
        $this->now += 31 * 86400;
        $this->assertNull($this->log()->get($old), 'expired record not returned');
        $this->assertSame('not_found', $this->log()->setFeedback($old, 'o', 'helpful'));
        $this->log()->record(['id' => $new, 'owner' => 'o', 'outcome' => 'ANSWER']);
        $this->assertSame(0, $this->log()->cleanup(), 'cleanup already ran on write');
        $this->assertFileDoesNotExist("$this->dir/$old.json");
        $this->assertFileExists("$this->dir/$new.json");
    }

    public function testStorageFailureNeverThrows()
    {
        $file = sys_get_temp_dir() . '/aichat_not_a_dir_' . bin2hex(random_bytes(3));
        touch($file);
        $log = new ResponseLog($file . '/sub', 30);
        $this->assertFalse($log->record(['id' => str_repeat('3', 24), 'owner' => 'o', 'outcome' => 'ANSWER']));
        unlink($file);
    }
}
