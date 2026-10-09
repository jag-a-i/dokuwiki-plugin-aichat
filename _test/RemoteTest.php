<?php

namespace dokuwiki\plugin\aichat\test;

use dokuwiki\plugin\aichat\Conversation\Outcome;
use dokuwiki\plugin\aichat\RemoteResponse\LlmReply;
use dokuwiki\plugin\aichat\test\Fixtures\FakeChatModel;
use dokuwiki\plugin\aichat\test\Fixtures\SyntheticWiki;
use dokuwiki\Remote\RemoteException;

require_once __DIR__ . '/Fixtures/FakeChatModel.php';
require_once __DIR__ . '/Fixtures/SyntheticWiki.php';

/**
 * Remote API compatibility: signatures and return shapes unchanged, safety fixes applied.
 *
 * @group plugin_aichat
 * @group plugins
 */
class RemoteTest extends \DokuWikiTest
{
    protected $pluginsEnabled = ['aichat', 'sqlite'];
    protected FakeChatModel $chat;
    protected SyntheticWiki $wiki;

    public function setUp(): void
    {
        parent::setUp();
        $this->chat = new FakeChatModel();
        $this->wiki = new SyntheticWiki();
        plugin_load('helper', 'aichat')->setConversationOverrides([
            'chat' => $this->chat, 'retriever' => $this->wiki->retriever(),
        ]);
    }

    public function testSignaturesUnchanged()
    {
        $sig = static function (string $method) {
            $out = [];
            foreach ((new \ReflectionMethod(\remote_plugin_aichat::class, $method))->getParameters() as $p) {
                $out[] = $p->getName() . '=' . var_export($p->isOptional() ? $p->getDefaultValue() : null, true);
            }
            return $out;
        };
        $this->assertSame(["query=NULL", "model=''", "lang=''"], $sig('ask'));
        $this->assertSame(["query=NULL", "max=-1", "threshold=-1", "lang=''"], $sig('similar'));
    }

    public function testAskAnswerShapeUnchanged()
    {
        $this->chat->queue('Run vpnctl passwd.');
        $reply = plugin_load('remote', 'aichat')->ask('How do I change my VPN password?');
        $this->assertInstanceOf(LlmReply::class, $reply);
        $this->assertSame('Run vpnctl passwd.', $reply->answer, 'legacy remote answers are passed through');
        $this->assertNotEmpty($reply->sources);
        $this->assertCount(1, $this->chat->calls);
    }

    public function testAskNoInformationIsDeterministic()
    {
        $reply = plugin_load('remote', 'aichat')->ask('xyzzy plugh');
        $this->assertSame(Outcome::NO_INFORMATION_TEXT, $reply->answer);
        $this->assertSame([], $reply->sources);
        $this->assertCount(0, $this->chat->calls, 'no model call, no permission hints');
    }

    public function testAskErrorDoesNotLeak()
    {
        plugin_load('helper', 'aichat')->setConversationOverrides([
            'chat' => $this->chat,
            'retriever' => static function () { throw new \RuntimeException('qdrant https://q.internal:6333 key=ABC'); },
        ]);
        try {
            plugin_load('remote', 'aichat')->ask('anything');
            $this->fail('expected RemoteException');
        } catch (RemoteException $e) {
            $this->assertSame(112, $e->getCode());
            $this->assertMatchesRegularExpression('/^AI chat service error\. Reference: [0-9a-f]{16}$/', $e->getMessage());
        }
    }

    public function testAskRedactsVolunteeredSecret()
    {
        $this->chat->queue('ok');
        plugin_load('remote', 'aichat')->ask('my vpn password is Hunter2!x how to change');
        $this->assertStringNotContainsString('Hunter2!x', json_encode($this->chat->calls));
    }
}
