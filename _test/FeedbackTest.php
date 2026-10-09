<?php

namespace dokuwiki\plugin\aichat\test;

use dokuwiki\Input\Input;
use dokuwiki\plugin\aichat\Conversation\Outcome;
use dokuwiki\plugin\aichat\Model\ModelException;
use dokuwiki\plugin\aichat\test\Fixtures\FakeChatModel;
use dokuwiki\plugin\aichat\test\Fixtures\SyntheticWiki;

require_once __DIR__ . '/Fixtures/FakeChatModel.php';
require_once __DIR__ . '/Fixtures/SyntheticWiki.php';

/**
 * Feedback endpoint, local response records and trace export through REAL AJAX requests.
 * The export transport is mocked; no network.
 *
 * @group plugin_aichat
 * @group plugins
 */
class FeedbackTest extends \DokuWikiTest
{
    protected $pluginsEnabled = ['aichat', 'sqlite'];
    protected FakeChatModel $chat;
    protected array $sent = [];
    protected $httpStatus = 200;

    public function setUp(): void
    {
        parent::setUp();
        if (session_status() !== PHP_SESSION_ACTIVE && session_id() === '') session_id('aichattestsession0001');
        $this->chat = new FakeChatModel();
        $this->sent = [];
        $this->httpStatus = 200;
        $helper = plugin_load('helper', 'aichat');
        $helper->updateConfig(['feedback' => 1, 'diagnostics' => 1, 'telemetry' => 'off', 'restrict' => '']);
        $helper->setConversationOverrides([
            'chat' => $this->chat,
            'retriever' => (new SyntheticWiki())->retriever(),
            'rephraser' => fn($q) => $q,
            'telemetryHttp' => function ($url, $headers, $body) {
                $this->sent[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
                if ($this->httpStatus instanceof \Throwable) throw $this->httpStatus;
                return $this->httpStatus;
            },
        ]);
    }

    protected function token(string $user): string
    {
        global $INPUT;
        [$i, $s] = [$INPUT, $_SERVER];
        $_SERVER['REMOTE_USER'] = $user;
        $INPUT = new Input();
        $t = getSecurityToken();
        [$INPUT, $_SERVER] = [$i, $s];
        return $t;
    }

    protected function post(string $call, array $post, string $user = 'alice', ?string $sectok = null): array
    {
        $req = new \TestRequest();
        if ($user !== '') $req->setServer('REMOTE_USER', $user);
        $resp = $req->post(array_merge(['call' => $call, 'sectok' => $sectok ?? $this->token($user)], $post), '/lib/exe/ajax.php');
        return [json_decode($resp->getContent(), true), $resp->getContent()];
    }

    protected function answer(string $user = 'alice'): array
    {
        $this->chat->queue("DECISION: ANSWER\nUSED: S1\nANSWER:\nRun `vpnctl passwd`.");
        return $this->post('aichat', ['question' => 'How do I change my VPN password?', 'history' => '[]'], $user)[0];
    }

    protected function record(string $id): array
    {
        global $conf;
        return json_decode((string)@file_get_contents($conf['metadir'] . "/aichat/responses/$id.json"), true) ?: [];
    }

    protected function vote(string $id, string $vote, string $category = '', string $user = 'alice', ?string $sectok = null): array
    {
        return $this->post('aichat_feedback', ['responseId' => $id, 'vote' => $vote, 'category' => $category], $user, $sectok)[0] ?? [];
    }

    public function testAnswerIsRecordedAsMetadataOnly()
    {
        $r = $this->answer();
        $this->assertTrue($r['meta']['feedback']);
        $rec = $this->record($r['meta']['responseId']);
        $this->assertSame('ANSWER', $rec['outcome']);
        $this->assertSame(1, $rec['sources']);
        $this->assertArrayHasKey('retrieval', $rec['timings']);
        $this->assertArrayHasKey('model_call', $rec['timings']);
        $raw = json_encode($rec);
        foreach (['VPN', 'vpnctl', 'alice', '127.0.0.1', 'it:vpn'] as $leak) $this->assertStringNotContainsString($leak, $raw);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $rec['owner'], 'keyed hash, not the user name');
    }

    public function testVoteChangeAndCategory()
    {
        global $conf;
        $id = $this->answer()['meta']['responseId'];
        $files = count(glob($conf['metadir'] . '/aichat/responses/*.json'));
        $this->assertSame(['ok' => true, 'vote' => 'helpful', 'category' => ''], $this->vote($id, 'helpful'));
        $this->assertSame(['ok' => true, 'vote' => 'not_helpful', 'category' => 'unclear'], $this->vote($id, 'not_helpful', 'unclear'));
        $this->assertSame('not_helpful', $this->record($id)['feedback']['vote']);
        $this->assertSame('unclear', $this->record($id)['feedback']['category']);
        $this->vote($id, 'not_helpful', 'unclear'); // duplicate submission
        $this->assertCount($files, glob($conf['metadir'] . '/aichat/responses/*.json'), 'votes never add records');
        $this->assertSame('not_helpful', $this->record($id)['feedback']['vote']);
    }

    public function testInvalidInputIsRejected()
    {
        $id = $this->answer()['meta']['responseId'];
        foreach ([
            [$id, 'love it', ''], [$id, 'helpful', 'unclear'], [$id, 'not_helpful', 'free text here'],
            ['../../conf/local', 'helpful', ''], [str_repeat('z', 24), 'helpful', ''], [str_repeat('a', 500), 'helpful', ''],
        ] as [$rid, $vote, $cat]) {
            $this->assertSame(['ok' => false, 'error' => 'invalid'], $this->vote($rid, $vote, $cat), "$rid/$vote/$cat");
        }
        $this->assertArrayNotHasKey('feedback', $this->record($id));
    }

    public function testOtherUserCannotVoteOnAnswer()
    {
        $id = $this->answer('alice')['meta']['responseId'];
        $this->assertSame(['ok' => false, 'error' => 'not_found'], $this->vote($id, 'helpful', '', 'bob'));
        $this->assertSame(['ok' => false, 'error' => 'not_found'], $this->vote(str_repeat('0', 24), 'helpful'));
        $this->assertArrayNotHasKey('feedback', $this->record($id));
    }

    public function testCsrfRequired()
    {
        $id = $this->answer()['meta']['responseId'];
        $this->assertSame(['ok' => false, 'error' => 'sectok'], $this->vote($id, 'helpful', '', 'alice', 'forged'));
        $this->assertArrayNotHasKey('feedback', $this->record($id));
    }

    public function testClarificationIsNotVotable()
    {
        $this->chat->queue("DECISION: CLARIFY\nQUESTION: Which?\nOPTION: E-Mail | S1\nOPTION: VPN | S2");
        $r = $this->post('aichat', ['question' => 'How do I change my password?', 'history' => '[]'])[0];
        $this->assertSame(Outcome::CLARIFY, $r['meta']['outcome']);
        $this->assertFalse($r['meta']['feedback']);
        $this->assertSame(['ok' => false, 'error' => 'not_allowed'], $this->vote($r['meta']['responseId'], 'helpful'));
    }

    public function testGuestPolicy()
    {
        // guests with chat access (restrict empty) and a session may vote on their own answers
        $id = $this->answer('')['meta']['responseId'];
        $this->assertSame(['ok' => true, 'vote' => 'helpful', 'category' => ''], $this->vote($id, 'helpful', '', '', ''));
        // a logged-in user cannot vote on a guest's answer
        $this->assertSame(['ok' => false, 'error' => 'not_found'], $this->vote($id, 'not_helpful', '', 'alice'));
        // guests restricted from chat cannot vote at all
        plugin_load('helper', 'aichat')->updateConfig(['restrict' => '@user']);
        $this->assertSame(['ok' => false, 'error' => 'forbidden'], $this->vote($id, 'not_helpful', '', '', ''));
    }

    public function testFeedbackDisabled()
    {
        $id = $this->answer()['meta']['responseId'];
        plugin_load('helper', 'aichat')->updateConfig(['feedback' => 0, 'diagnostics' => 0]);
        $r = $this->answer();
        $this->assertFalse($r['meta']['feedback']);
        $this->assertSame([], $this->record($r['meta']['responseId']), 'nothing stored when both are off');
        $this->assertSame(['ok' => false, 'error' => 'forbidden'], $this->vote($id, 'helpful'));
    }

    public function testLangfuseExportOverRealRequestWithPlaceholderConfig()
    {
        plugin_load('helper', 'aichat')->updateConfig([
            'telemetry' => 'langfuse', 'telemetry_endpoint' => 'https://langfuse.example.invalid',
            'telemetry_langfuse_public' => 'pk-lf-placeholder', 'telemetry_langfuse_secret' => 'sk-lf-placeholder',
        ]);
        $r = $this->answer();
        $this->assertCount(1, $this->sent);
        $this->assertSame('https://langfuse.example.invalid/api/public/otel/v1/traces', $this->sent[0]['url']);
        $body = $this->sent[0]['body'];
        $otlp = json_decode($body, true);
        $spans = $otlp['resourceSpans'][0]['scopeSpans'][0]['spans'];
        $names = array_column($spans, 'name');
        foreach (['aichat.turn', 'aichat.retrieval', 'aichat.model_call', 'aichat.render'] as $n) $this->assertContains($n, $names);
        $this->assertStringContainsString($r['meta']['correlationId'], $body, 'trace correlated with the response');
        $this->assertStringContainsString($r['meta']['conversationId'], $body, 'session id = conversation');
        foreach (['vpnctl', 'VPN password', 'alice', '127.0.0.1', 'sk-lf-placeholder', 'aichattestsession'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
        $rec = $this->record($r['meta']['responseId']);
        $this->assertSame($spans[0]['traceId'], $rec['trace_id'], 'local record links to the trace');
    }

    public function testClarifyAndFollowupSpansExported()
    {
        plugin_load('helper', 'aichat')->updateConfig([
            'telemetry' => 'otlp', 'telemetry_endpoint' => 'http://collector.example.invalid/v1/traces',
        ]);
        $this->chat->queue("DECISION: CLARIFY\nQUESTION: Which?\nOPTION: E-Mail | S1\nOPTION: VPN | S2",
            "DECISION: ANSWER\nUSED: S1\nANSWER:\nUse vpnctl.");
        $req = new \TestRequest();
        $req->setServer('REMOTE_USER', 'alice');
        $req->post(['call' => 'aichat', 'sectok' => $this->token('alice'), 'question' => 'How do I change my password?', 'history' => '[]'], '/lib/exe/ajax.php');
        $names1 = array_column(json_decode($this->sent[0]['body'], true)['resourceSpans'][0]['scopeSpans'][0]['spans'], 'name');
        $this->assertContains('aichat.clarify_decision', $names1);
        // session written by the first request is not captured here; follow-up spans are covered via the service:
        $this->assertNotContains('aichat.followup_resolution', $names1);
    }

    public function testExportFailureNeverBreaksChat()
    {
        plugin_load('helper', 'aichat')->updateConfig([
            'telemetry' => 'langfuse', 'telemetry_endpoint' => 'https://langfuse.example.invalid',
            'telemetry_langfuse_public' => 'pk', 'telemetry_langfuse_secret' => 'sk', 'telemetry_spool_max' => 0,
        ]);
        $this->httpStatus = new \RuntimeException('connect to https://langfuse.example.invalid failed sk');
        $r = $this->answer();
        $this->assertSame(Outcome::ANSWER, $r['meta']['outcome']);
        $this->assertCount(2, $this->sent, 'one attempt plus one retry, bounded');
    }

    public function testErrorsAreTracedAsCategoriesOnly()
    {
        plugin_load('helper', 'aichat')->updateConfig([
            'telemetry' => 'otlp', 'telemetry_endpoint' => 'http://collector.example.invalid/v1/traces',
        ]);
        $this->chat->queue(new ModelException('http://10.0.0.5 token=SECRET'));
        $r = $this->post('aichat', ['question' => 'How do I change my VPN password?', 'history' => '[]'])[0];
        $this->assertSame(Outcome::ERROR, $r['meta']['outcome']);
        $body = $this->sent[0]['body'];
        $this->assertStringContainsString('model_error', $body);
        $this->assertStringNotContainsString('10.0.0.5', $body);
        $this->assertStringNotContainsString('SECRET', $body);
        $this->assertStringContainsString('"code":2', $body, 'error status');
    }
}
