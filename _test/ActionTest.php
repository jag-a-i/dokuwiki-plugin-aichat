<?php

namespace dokuwiki\plugin\aichat\test;

use dokuwiki\Input\Input;
use dokuwiki\plugin\aichat\Conversation\Outcome;
use dokuwiki\plugin\aichat\Conversation\SessionBridge;
use dokuwiki\plugin\aichat\Model\ModelException;
use dokuwiki\plugin\aichat\test\Fixtures\FakeChatModel;
use dokuwiki\plugin\aichat\test\Fixtures\SyntheticWiki;

require_once __DIR__ . '/Fixtures/FakeChatModel.php';
require_once __DIR__ . '/Fixtures/SyntheticWiki.php';

/**
 * Real AJAX requests through lib/exe/ajax.php -> action -> helper -> ConversationService.
 * Only the chat model and the vector retrieval are replaced by synthetic doubles.
 *
 * @group plugin_aichat
 * @group plugins
 */
class ActionTest extends \DokuWikiTest
{
    protected $pluginsEnabled = ['aichat', 'sqlite'];

    public static ?array $captured = null;
    protected SyntheticWiki $wiki;
    protected FakeChatModel $chat;
    protected array $rephraseInputs = [];

    protected const CLARIFY_REPLY = "DECISION: CLARIFY\nQUESTION: Which account?\n" .
        "OPTION: E-Mail | S1, S4\nOPTION: VPN | S2\nOPTION: CRM | S3";

    public function setUp(): void
    {
        parent::setUp();
        if (session_status() !== PHP_SESSION_ACTIVE && session_id() === '') {
            session_id('aichattestsession0001'); // so security tokens are real HMACs
        }
        $this->wiki = new SyntheticWiki();
        $this->chat = new FakeChatModel();
        /** @var \helper_plugin_aichat $helper */
        $helper = plugin_load('helper', 'aichat');
        $this->rephraseInputs = [];
        $helper->setConversationOverrides([
            'chat' => $this->chat,
            'retriever' => $this->wiki->retriever(),
            // stands in for the rephrase model; records what it would receive
            'rephraser' => function ($q, $h) { $this->rephraseInputs[] = $h; return $q; },
        ]);
        // the test base recreates the event handler for every test, so register the capture hook every time;
        // it runs at the end of the real AJAX request and captures the session as written by the plugin
        global $EVENT_HANDLER;
        $EVENT_HANDLER->register_hook('AJAX_CALL_UNKNOWN', 'AFTER', null, static function () {
            ActionTest::$captured = $_SESSION ?? [];
        });
        self::$captured = null;
    }

    protected function token(string $user): string
    {
        global $INPUT;
        $oldInput = $INPUT;
        $oldServer = $_SERVER;
        $_SERVER['REMOTE_USER'] = $user;
        $INPUT = new Input();
        $token = getSecurityToken();
        $INPUT = $oldInput;
        $_SERVER = $oldServer;
        return $token;
    }

    /** @return array [decoded response, session after the request, http body] */
    protected function post(array $post, string $user = 'alice', array $session = [], ?string $sectok = null): array
    {
        $req = new \TestRequest();
        if ($user !== '') $req->setServer('REMOTE_USER', $user);
        foreach ($session as $k => $v) $req->setSession($k, $v);
        $resp = $req->post(array_merge([
            'call' => 'aichat',
            'sectok' => $sectok ?? $this->token($user),
            'history' => '[]',
            'pagecontext' => '',
        ], $post), '/lib/exe/ajax.php');
        $body = $resp->getContent();
        return [json_decode($body, true), self::$captured ?? [], $body];
    }

    public function testClarifyThenNumericChoiceAcrossRealRequests()
    {
        $this->chat->queue(self::CLARIFY_REPLY, "DECISION: ANSWER\nUSED: S1\nANSWER:\nRun `vpnctl passwd`.");
        [$r1, $session] = $this->post(['question' => 'How do I change my password?']);

        $this->assertSame(1, $r1['meta']['v']);
        $this->assertSame(Outcome::CLARIFY, $r1['meta']['outcome']);
        $this->assertSame(['E-Mail', 'VPN', 'CRM'], $r1['meta']['options']);
        $this->assertSame([], $r1['sources']);
        $this->assertStringNotContainsString(Outcome::FOOTER_TEXT, $r1['answer']);
        $this->assertMatchesRegularExpression('/^c[0-9a-f]{24}$/', $r1['meta']['conversationId'], 'server-generated id');
        $this->assertNotSame('', $r1['meta']['pendingId']);
        // state was written to the session, bound to one identity, containing no raw text from the client except the need
        $this->assertCount(1, $session[SessionBridge::SESSION_KEY]['pending']);
        $stored = array_values($session[SessionBridge::SESSION_KEY]['pending'])[0];
        $this->assertSame('How do I change my password?', $stored[$r1['meta']['conversationId']]['need']);

        [$r2] = $this->post([
            'question' => '2',
            'conversation' => $r1['meta']['conversationId'],
            'pending' => $r1['meta']['pendingId'],
        ], 'alice', $session);
        $this->assertSame(Outcome::ANSWER, $r2['meta']['outcome']);
        $this->assertSame(['it:vpn:password'], array_column($r2['sources'], 'page'));
        $this->assertSame(1, substr_count($r2['answer'], Outcome::FOOTER_TEXT));
        $this->assertStringEndsWith('<p>' . Outcome::FOOTER_TEXT . '</p>', trim($r2['answer']));
        $this->assertStringContainsString('<code>vpnctl passwd</code>', $r2['answer']);
        $this->assertStringContainsString('(VPN)', $this->chat->lastPrompt());
    }

    public function testDoubleSubmitOfAPendingChoiceUsesItOnce()
    {
        $this->chat->queue(self::CLARIFY_REPLY, "DECISION: ANSWER\nUSED: S1\nANSWER:\nRun vpnctl.");
        [$r1, $session] = $this->post(['question' => 'How do I change my password?']);
        $this->assertNotEmpty($session[SessionBridge::SESSION_KEY]['pending'] ?? [], 'state really stored');
        $post = ['question' => '2', 'conversation' => $r1['meta']['conversationId'], 'pending' => $r1['meta']['pendingId']];
        // both requests start from the same (now stale) session snapshot
        [$a] = $this->post($post, 'alice', $session);
        $calls = count($this->chat->calls);
        [$b, $after] = $this->post(array_merge($post, ['question' => '3']), 'alice', $session);
        $this->assertSame(Outcome::ANSWER, $a['meta']['outcome']);
        $this->assertSame(Outcome::NOTICE, $b['meta']['outcome']);
        $this->assertCount($calls, $this->chat->calls);
        $stored = array_values($after[SessionBridge::SESSION_KEY]['pending'] ?? [[]])[0] ?? [];
        $this->assertArrayNotHasKey($r1['meta']['conversationId'], $stored, 'consumed token not resurrected');
    }

    public function testNegatedChoiceOverRealRequests()
    {
        $this->chat->queue(self::CLARIFY_REPLY);
        [$r1, $session] = $this->post(['question' => 'How do I change my password?']);
        [$r2] = $this->post([
            'question' => 'not VPN', 'conversation' => $r1['meta']['conversationId'], 'pending' => $r1['meta']['pendingId'],
        ], 'alice', $session);
        $this->assertSame(Outcome::CLARIFY, $r2['meta']['outcome']);
        $this->assertNotContains('VPN', $r2['meta']['options']);
        $this->assertCount(1, $this->chat->calls);
    }

    public function provideOtherIdentity(): array
    {
        return ['other user' => ['bob'], 'logged out' => ['']];
    }

    /** @dataProvider provideOtherIdentity */
    public function testOtherIdentityCannotUsePendingState(string $user)
    {
        $this->chat->queue(self::CLARIFY_REPLY);
        [$r1, $session] = $this->post(['question' => 'How do I change my password?']);
        $this->assertNotEmpty($session[SessionBridge::SESSION_KEY]['pending'] ?? [], 'state really stored (no false pass)');
        $calls = count($this->chat->calls);

        [$r2, $after] = $this->post([
            'question' => '2',
            'conversation' => $r1['meta']['conversationId'],
            'pending' => $r1['meta']['pendingId'],
        ], $user, $session);
        $this->assertSame(Outcome::NOTICE, $r2['meta']['outcome']);
        $this->assertSame([], $r2['sources']);
        $this->assertCount($calls, $this->chat->calls, 'nothing guessed');
        $this->assertStringNotContainsString('vpnctl', $r2['answer']);
    }

    public function testCsrfTokenRequiredForLoggedInUsers()
    {
        [$r, $session, $body] = $this->post(['question' => 'How do I change my password?'], 'alice', [], 'wrongtoken');
        $this->assertSame(Outcome::ERROR, $r['meta']['outcome']);
        $this->assertStringContainsString($r['meta']['correlationId'], $r['answer']);
        $this->assertCount(0, $this->chat->calls);
        $this->assertEmpty($session[SessionBridge::SESSION_KEY]['pending'] ?? []);
    }

    public function testGuestFollowsDokuWikiCsrfConvention()
    {
        // DokuWiki does not issue tokens to anonymous users; access is still governed by the "restrict" setting
        $this->chat->queue("DECISION: ANSWER\nUSED: S1\nANSWER:\nOpen webmail settings.");
        [$r] = $this->post(['question' => 'How do I change my email password?'], '', [], '');
        $this->assertSame(Outcome::ANSWER, $r['meta']['outcome']);
    }

    public function testRestrictedUserGetsNoAnswer()
    {
        global $conf;
        $conf['plugin']['aichat']['restrict'] = '@nonexistinggroup';
        $helper = plugin_load('helper', 'aichat');
        $helper->updateConfig(['restrict' => '@nonexistinggroup']);
        try {
            [$r] = $this->post(['question' => 'How do I change my password?']);
            $this->assertSame(Outcome::NOTICE, $r['meta']['outcome']);
            $this->assertCount(0, $this->chat->calls);
        } finally {
            $helper->updateConfig(['restrict' => '']);
        }
    }

    public function testNoInformationIsExactAndSkipsModel()
    {
        [$r] = $this->post(['question' => 'xyzzy plugh']);
        $this->assertSame('<p>The Wiki does not contain information on that topic.</p>', trim($r['answer']));
        $this->assertSame([], $r['sources']);
        $this->assertSame(Outcome::NO_INFORMATION, $r['meta']['outcome']);
        $this->assertCount(0, $this->chat->calls);
    }

    public function testServiceErrorIsSanitizedInResponseAndLog()
    {
        global $conf;
        $this->chat->queue(new ModelException('POST http://10.0.0.5:8080/v1 failed: token=SECRETTOKEN body=wiki text'));
        [$r, , $body] = $this->post(['question' => 'How do I change my VPN password?']);
        $this->assertSame(Outcome::ERROR, $r['meta']['outcome']);
        $ref = $r['meta']['correlationId'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $ref);
        $this->assertStringContainsString($ref, $r['answer']);
        foreach (['10.0.0.5', 'SECRETTOKEN', 'wiki text', Outcome::NO_INFORMATION_TEXT] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
        $log = @file_get_contents($conf['logdir'] . '/error/' . date('Y-m-d') . '.log') ?: '';
        $this->assertStringContainsString("category=model_error ref=$ref class=ModelException", $log);
        $this->assertStringNotContainsString('SECRETTOKEN', $log);
        $this->assertStringNotContainsString('10.0.0.5', $log);
    }

    public function testLegacyClientAndMalformedHistory()
    {
        // an old client sends neither conversation nor pending and may send junk history
        $this->chat->queue("DECISION: ANSWER\nUSED: S1\nANSWER:\nOpen webmail settings.");
        [$r] = $this->post(['question' => 'How do I change my email password?', 'history' => '{not json']);
        foreach (['question', 'answer', 'sources'] as $key) $this->assertArrayHasKey($key, $r);
        $this->assertSame(Outcome::ANSWER, $r['meta']['outcome']);
        $this->assertSame(['page', 'url', 'title', 'score'], array_keys($r['sources'][0]));
    }

    public function testHistoryIsUntrustedAndFooterFree()
    {
        $history = json_encode([
            ['old q', '<p>old answer</p><p>' . Outcome::FOOTER_TEXT . '</p><script>x</script>', [['page' => 'it:hr:password']]],
        ]);
        $this->chat->queue("DECISION: ANSWER\nUSED: S1\nANSWER:\nOpen webmail settings.");
        [$r] = $this->post(['question' => 'How do I change my email password?', 'history' => $history]);
        $this->assertCount(1, $this->rephraseInputs, 'history is used for rephrasing');
        $sent = json_encode([$this->chat->calls, $this->rephraseInputs]);
        $this->assertStringNotContainsString(Outcome::FOOTER_TEXT, $sent);
        $this->assertStringNotContainsString('<script>', $sent);
        $this->assertNotContains('it:hr:password', array_column($r['sources'], 'page'));
    }

    public function testVolunteeredSecretIsNotSentAndWarned()
    {
        $this->chat->queue("DECISION: ANSWER\nUSED: S1\nANSWER:\nOpen webmail settings.");
        [$r, , $body] = $this->post(['question' => 'my email password is Hunter2!x, how do I change it']);
        $this->assertStringNotContainsString('Hunter2!x', json_encode($this->chat->calls));
        $this->assertStringNotContainsString('Hunter2!x', $body);
        $this->assertNotSame('', $r['meta']['warning']);
    }
}
