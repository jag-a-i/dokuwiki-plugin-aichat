<?php

namespace dokuwiki\plugin\aichat\test;

use dokuwiki\plugin\aichat\Conversation\ConversationService;
use dokuwiki\plugin\aichat\Conversation\FollowupResolver;
use dokuwiki\plugin\aichat\Conversation\Outcome;
use dokuwiki\plugin\aichat\Conversation\PendingStore;
use dokuwiki\plugin\aichat\Model\ModelException;
use dokuwiki\plugin\aichat\test\Fixtures\FakeChatModel;
use dokuwiki\plugin\aichat\test\Fixtures\SyntheticWiki;

require_once __DIR__ . '/Fixtures/FakeChatModel.php';
require_once __DIR__ . '/Fixtures/SyntheticWiki.php';

/**
 * Deterministic mocked tests for grounded clarification (Feature A/B).
 * These do NOT prove that a real model follows the decision policy.
 *
 * @group plugin_aichat
 * @group plugins
 */
class ConversationTest extends \DokuWikiTest
{
    protected SyntheticWiki $wiki;
    protected FakeChatModel $chat;
    protected array $session = [];
    protected int $now = 1000000;

    public function setUp(): void
    {
        parent::setUp();
        $this->wiki = new SyntheticWiki();
        $this->chat = new FakeChatModel();
        $this->session = [];
    }

    protected function service(?array &$session = null): ConversationService
    {
        if ($session === null) $session = &$this->session;
        $store = new PendingStore($session, null, fn() => $this->now);
        return new ConversationService(
            $this->wiki->retriever(),
            $this->chat,
            static fn(array $v) => "CLARIFY:{$v['clarify']}\nQ:{$v['question']}\n{$v['context']}",
            $store,
            [], [], null, null, null, null,
            $this->wiki->pageFetcher()
        );
    }

    protected function serviceWithQueryLog(array &$queries): ConversationService
    {
        $retriever = $this->wiki->retriever();
        return new ConversationService(
            static function (string $query) use ($retriever, &$queries): array {
                $queries[] = $query;
                return $retriever($query);
            },
            $this->chat,
            static fn(array $v) => "CLARIFY:{$v['clarify']}\nQ:{$v['question']}\n{$v['context']}",
            new PendingStore($this->session, null, fn() => $this->now),
            [], [], null, null, null, null,
            $this->wiki->pageFetcher()
        );
    }

    protected const CLARIFY_REPLY = "DECISION: CLARIFY\nQUESTION: Which account do you want to change the password for?\n" .
        "OPTION: E-Mail | S1, S4\nOPTION: VPN | S2\nOPTION: CRM | S3\nOPTION: E-mail | S4";

    protected function ask(string $q, string $conv = 'tabAAAAAAAA', string $pending = ''): array
    {
        return $this->service()->handle($q, [], $conv, $pending);
    }

    // 1. ambiguous -> one clarification, no procedure dump, waits
    public function testAmbiguousQuestionClarifies()
    {
        $this->chat->queue(self::CLARIFY_REPLY);
        $r = $this->ask('How do I change my password?');
        $this->assertSame(Outcome::CLARIFY, $r['outcome']);
        $this->assertSame(['E-Mail', 'VPN', 'CRM'], $r['options']); // duplicate E-mail merged
        $this->assertSame([], $r['sources']);
        $this->assertStringNotContainsString(Outcome::FOOTER_TEXT, $r['answer']);
        $this->assertStringNotContainsString('webmail settings', $r['answer']);
        $this->assertNotSame('', $r['pendingId']);
        $this->assertCount(1, $this->chat->calls);
        $this->assertStringContainsString('CLARIFY:allowed', $this->chat->lastPrompt());
    }

    // 2. specific question -> direct scoped answer
    public function testSpecificQuestionAnswersDirectly()
    {
        $this->chat->queue("DECISION: ANSWER\nUSED: S1\nANSWER:\nRun `vpnctl passwd` on the portal.");
        $r = $this->ask('How do I change my VPN password?');
        $this->assertSame(Outcome::ANSWER, $r['outcome']);
        $this->assertSame(['it:vpn:password'], array_map(fn($c) => $c->getPage(), $r['sources']));
        $this->assertSame(1, substr_count($r['answer'], Outcome::FOOTER_TEXT));
        $this->assertStringEndsWith("\n\n" . Outcome::FOOTER_TEXT, $r['answer']);
    }

    // 3. numeric and natural-language choices resolve against the pending options
    public function provideChoices(): array
    {
        return [['2'], ['#2'], ['option 2'], ['the second one'], ['VPN'], ['the vpn one please']];
    }

    /** @dataProvider provideChoices */
    public function testChoiceResolves(string $reply)
    {
        $this->chat->queue(self::CLARIFY_REPLY, "DECISION: ANSWER\nUSED: S1\nANSWER:\nUse vpnctl.");
        $first = $this->ask('How do I change my password?');
        $r = $this->ask($reply, 'tabAAAAAAAA', $first['pendingId']);
        $this->assertSame(Outcome::ANSWER, $r['outcome']);
        $this->assertStringContainsString('(VPN)', $this->chat->lastPrompt());
        $this->assertStringContainsString('CLARIFY:not allowed', $this->chat->lastPrompt());
        $this->assertStringNotContainsString('webmail settings', $this->chat->lastPrompt()); // only selected evidence
        $this->assertSame(['it:vpn:password'], array_map(fn($c) => $c->getPage(), $r['sources']));
        $this->assertSame([], $this->session, 'pending state cleared after resolution');
    }

    // 4. I don't know / none / cancel / topic change / correction
    public function testUnknownNoneCancelTopicChange()
    {
        $this->chat->queue(self::CLARIFY_REPLY);
        $p = $this->ask('How do I change my password?')['pendingId'];
        $r = $this->ask("I don't know", 'tabAAAAAAAA', $p);
        $this->assertSame(Outcome::NOTICE, $r['outcome']);
        $this->assertCount(1, $this->chat->calls, 'no guessing model call');

        $this->chat->queue(self::CLARIFY_REPLY);
        $p = $this->ask('How do I change my password?')['pendingId'];
        $r = $this->ask('none of these', 'tabAAAAAAAA', $p);
        $this->assertSame(Outcome::CLARIFY, $r['outcome']);
        $this->assertSame([], $r['options']);
        $this->assertNotSame('', $r['pendingId']);

        $this->chat->queue(self::CLARIFY_REPLY);
        $p = $this->ask('How do I change my password?')['pendingId'];
        $r = $this->ask('cancel', 'tabAAAAAAAA', $p);
        $this->assertSame(Outcome::NOTICE, $r['outcome']);
        $this->assertSame([], $this->session);

        $this->chat->queue(self::CLARIFY_REPLY, "DECISION: ANSWER\nUSED: S1\nANSWER:\nEvery Friday.");
        $p = $this->ask('How do I change my password?')['pendingId'];
        $r = $this->ask('When is the coffee machine descaled?', 'tabAAAAAAAA', $p);
        $this->assertSame(Outcome::ANSWER, $r['outcome']);
        $this->assertStringContainsString('Q:When is the coffee machine descaled?', $this->chat->lastPrompt());

        // correction / system outside the offered list -> searched as free text
        $this->chat->queue(self::CLARIFY_REPLY, "DECISION: NO_INFORMATION");
        $p = $this->ask('How do I change my password?')['pendingId'];
        $r = $this->ask('actually I meant my Zoom account', 'tabAAAAAAAA', $p);
        $this->assertStringContainsString('(actually I meant my Zoom account)', $this->chat->lastPrompt());
        $this->assertSame(Outcome::NO_INFORMATION_TEXT, $r['answer']);
    }

    // 5. explicit comparison is not narrowed
    public function testComparisonNotNarrowed()
    {
        $this->chat->queue("DECISION: ANSWER\nUSED: S1, S2\nANSWER:\nE-Mail uses webmail; VPN uses vpnctl.");
        $r = $this->ask('Compare the VPN and E-Mail password change procedures');
        $this->assertStringContainsString('CLARIFY:not allowed', $this->chat->lastPrompt());
        $this->assertCount(2, $r['sources']);
    }

    // 6. several articles about one system do not create fake alternatives
    public function testSameSystemNoFakeAlternatives()
    {
        $this->chat->queue(
            // same system named twice ("E-mail account" normalizes to "e-mail"); a different alias such as
            // "Webmail" is NOT detectable in code and relies on the prompt (documented limitation)
            "DECISION: CLARIFY\nQUESTION: Which?\nOPTION: E-Mail | S1, S4\nOPTION: E-mail account | S4",
            "DECISION: ANSWER\nUSED: S1\nANSWER:\nOpen webmail settings."
        );
        $r = $this->ask('How do I change my email password?');
        $this->assertSame(Outcome::ANSWER, $r['outcome']);
        $this->assertCount(2, $this->chat->calls);
    }

    // 7. ACL-denied alternatives never appear; revoked access respected on the next turn
    public function testAclDeniedAndRevoked()
    {
        $this->chat->queue(self::CLARIFY_REPLY . "\nOPTION: HR portal | S9");
        $r = $this->ask('How do I change my password?');
        $this->assertNotContains('HR portal', $r['options']);
        $this->assertStringNotContainsString('HIDDEN-HR-MARKER', $this->chat->lastPrompt());

        $this->wiki->deny['it:vpn:password'] = ['alice']; // revoked between turns
        $calls = count($this->chat->calls);
        $r2 = $this->ask('2', 'tabAAAAAAAA', $r['pendingId']);
        $this->assertCount($calls, $this->chat->calls, 'no model call: other procedures are never used instead');
        $this->assertSame(Outcome::NO_INFORMATION, $r2['outcome']);
        $this->assertSame(Outcome::NO_INFORMATION_TEXT, $r2['answer']);
        $this->assertSame([], $r2['sources']);
    }

    // review finding 1: chosen evidence revoked while other procedures remain -> never broaden
    public function testChosenSourceRevokedOtherProceduresRemain()
    {
        $this->chat->queue(self::CLARIFY_REPLY);
        $r = $this->ask('How do I change my password?');
        $this->wiki->deny['it:vpn:password'] = ['alice'];
        $calls = count($this->chat->calls);
        $r2 = $this->ask('VPN', 'tabAAAAAAAA', $r['pendingId']);
        $this->assertSame(Outcome::NO_INFORMATION_TEXT, $r2['answer']);
        $this->assertSame([], $r2['sources']);
        $this->assertCount($calls, $this->chat->calls);
        $this->assertStringNotContainsString('vpnctl', json_encode($r2));
    }

    public function testChosenPageDeletedOtherProceduresRemain()
    {
        $this->chat->queue(self::CLARIFY_REPLY);
        $r = $this->ask('How do I change my password?');
        unset($this->wiki->pages['it:vpn:password']);
        $calls = count($this->chat->calls);
        $r2 = $this->ask('2', 'tabAAAAAAAA', $r['pendingId']);
        $this->assertSame(Outcome::NO_INFORMATION_TEXT, $r2['answer']);
        $this->assertCount($calls, $this->chat->calls);
    }

    public function testChosenPageStillReadableButNotRankedIsFetchedDirectly()
    {
        $this->chat->queue(self::CLARIFY_REPLY, "DECISION: ANSWER\nUSED: S1\nANSWER:\nUse vpnctl.");
        $r = $this->ask('How do I change my password?');
        // fresh retrieval no longer ranks the VPN page (e.g. top-k cutoff), but it still exists and is readable
        $all = $this->wiki->retriever();
        $s = new ConversationService(
            static fn($q) => array_values(array_filter($all($q), fn($c) => $c->getPage() !== 'it:vpn:password')),
            $this->chat, static fn(array $v) => "CLARIFY:{$v['clarify']}\nQ:{$v['question']}\n{$v['context']}",
            new PendingStore($this->session, null, fn() => $this->now),
            [], [], null, null, null, null, $this->wiki->pageFetcher()
        );
        $r2 = $s->handle('2', [], 'tabAAAAAAAA', $r['pendingId']);
        $this->assertSame(Outcome::ANSWER, $r2['outcome']);
        $this->assertSame(['it:vpn:password'], array_map(fn($c) => $c->getPage(), $r2['sources']));
        $prompt = $this->chat->lastPrompt();
        $this->assertStringContainsString('vpnctl', $prompt);
        $this->assertStringNotContainsString('webmail settings', $prompt);
        $this->assertStringNotContainsString('avatar', $prompt);
    }

    // review finding 2: one page documenting several systems keeps distinct choices
    public function testDistinctSystemsOnOnePageArePreserved()
    {
        $this->wiki->pages = ['it:passwords:overview' =>
            'Password changes. E-Mail: open webmail settings. VPN: run vpnctl passwd. CRM: avatar, Profile.'];
        $this->chat->queue(
            "DECISION: CLARIFY\nQUESTION: Which account?\nOPTION: E-Mail | S1\nOPTION: VPN | S1\nOPTION: CRM | S1",
            "DECISION: ANSWER\nUSED: S1\nANSWER:\nRun vpnctl passwd."
        );
        $r = $this->ask('How do I change my password?');
        $this->assertSame(Outcome::CLARIFY, $r['outcome']);
        $this->assertSame(['E-Mail', 'VPN', 'CRM'], $r['options']);
        $r2 = $this->ask('2', 'tabAAAAAAAA', $r['pendingId']);
        $this->assertSame(Outcome::ANSWER, $r2['outcome']);
        $this->assertStringContainsString('Q:How do I change my password? (VPN)', $this->chat->lastPrompt());
        $this->assertStringContainsString('CLARIFY:not allowed', $this->chat->lastPrompt());
    }

    public function testSimilarButDistinctNamesAreNotMerged()
    {
        $this->chat->queue("DECISION: CLARIFY\nQUESTION: Which?\nOPTION: VPN | S2\nOPTION: VPN token | S2\nOPTION: VPN account | S2");
        $r = $this->ask('How do I change my password?');
        $this->assertSame(['VPN', 'VPN token'], $r['options'], '"VPN account" merges into "VPN", "VPN token" stays');
    }

    // review finding 4: negation / rejection must never select the rejected option
    public function provideRejections(): array
    {
        return [['not VPN'], ['No, not the VPN one'], ['not 2'], ['no 2'], ['it is not VPN']];
    }

    /** @dataProvider provideRejections */
    public function testRejectedOptionIsNeverSelected(string $reply)
    {
        $this->chat->queue(self::CLARIFY_REPLY);
        $r = $this->ask('How do I change my password?');
        $calls = count($this->chat->calls);
        $r2 = $this->ask($reply, 'tabAAAAAAAA', $r['pendingId']);
        $this->assertSame(Outcome::CLARIFY, $r2['outcome'], 'targeted re-clarification');
        $this->assertSame(['E-Mail', 'CRM'], $r2['options'], 'rejected option removed');
        $this->assertSame([], $r2['sources']);
        $this->assertCount($calls, $this->chat->calls, 'no model call, nothing answered');
        $this->assertStringNotContainsString('vpnctl', json_encode($r2));
        // the remaining options are resolved against the NEW pending list
        $this->chat->queue("DECISION: ANSWER\nUSED: S1\nANSWER:\nAvatar, Profile.");
        $r3 = $this->ask('2', 'tabAAAAAAAA', $r2['pendingId']);
        $this->assertStringContainsString('(CRM)', $this->chat->lastPrompt());
        $this->assertSame(['it:crm:password'], array_map(fn($c) => $c->getPage(), $r3['sources']));
    }

    // recheck finding: options re-offered after a rejection are re-authorized
    public function testRejectionDropsOptionsRevokedSinceClarification()
    {
        $this->chat->queue(self::CLARIFY_REPLY);
        $r = $this->ask('How do I change my password?');
        $this->assertSame(['E-Mail', 'VPN', 'CRM'], $r['options']);
        $callsBeforeRevoke = count($this->chat->calls);
        $this->wiki->deny['it:crm:password'] = ['alice'];          // CRM revoked after the clarification
        unset($this->wiki->pages['it:email:password']);            // one of two E-Mail pages deleted
        $r2 = $this->ask('not VPN', 'tabAAAAAAAA', $r['pendingId']);
        $this->assertSame(Outcome::CLARIFY, $r2['outcome']);
        $this->assertSame(['E-Mail'], $r2['options'], 'revoked CRM never shown');
        $this->assertStringNotContainsString('CRM', json_encode($r2));
        $this->assertCount(1, $this->chat->calls, 'no model call');
        // the stored follow-up state only references readable pages
        $stored = $this->session['tabAAAAAAAA']['options'];
        $this->assertSame([['label' => 'E-Mail', 'pages' => ['it:email:password-webmail']]], $stored);
        // and choosing it answers only from readable evidence
        $this->chat->queue("DECISION: ANSWER\nUSED: S1\nANSWER:\nSecurity tab.");
        $r3 = $this->ask('1', 'tabAAAAAAAA', $r2['pendingId']);
        $this->assertSame(['it:email:password-webmail'], array_map(fn($c) => $c->getPage(), $r3['sources']));
        $after = array_slice($this->chat->calls, $callsBeforeRevoke);
        $this->assertNotEmpty($after);
        $this->assertStringNotContainsString('avatar', json_encode($after), 'revoked CRM evidence never reaches the model');
        $this->assertStringNotContainsString('it:crm', json_encode($after));
    }

    public function testRejectionWithAllRemainingRevokedAsksGenerically()
    {
        $this->chat->queue(self::CLARIFY_REPLY);
        $r = $this->ask('How do I change my password?');
        foreach (['it:crm:password', 'it:email:password', 'it:email:password-webmail'] as $p) $this->wiki->deny[$p] = ['alice'];
        $r2 = $this->ask('not VPN', 'tabAAAAAAAA', $r['pendingId']);
        $this->assertSame(Outcome::CLARIFY, $r2['outcome']);
        $this->assertSame([], $r2['options'], 'generic question, no stale labels');
        foreach (['CRM', 'E-Mail'] as $label) $this->assertStringNotContainsString($label, json_encode($r2));
    }

    // recheck detail: revoked E-Mail absent from the response AND the newly stored pending state
    public function testRejectionDropsRevokedEmailFromResponseAndStoredState()
    {
        $this->chat->queue(self::CLARIFY_REPLY);
        $r = $this->ask('How do I change my password?');
        $this->wiki->deny['it:email:password'] = ['alice'];
        $this->wiki->deny['it:email:password-webmail'] = ['alice'];
        $fetchedBefore = count($this->wiki->fetched);
        $r2 = $this->ask('not VPN', 'tabAAAAAAAA', $r['pendingId']);
        $this->assertGreaterThan($fetchedBefore, count($this->wiki->fetched), 'pages were re-fetched with current ACL');
        $this->assertSame(['CRM'], $r2['options']);
        $this->assertStringNotContainsString('E-Mail', json_encode($r2));
        $stored = $this->session['tabAAAAAAAA'];
        $this->assertSame($r2['pendingId'], $stored['id']);
        $this->assertSame([['label' => 'CRM', 'pages' => ['it:crm:password']]], $stored['options']);
        $this->assertStringNotContainsString('E-Mail', json_encode($stored['options']));
        $this->assertStringNotContainsString('it:email', json_encode($stored));
    }

    // P2: uncertain negative/corrective sentences never select or scope to the mentioned option
    public function provideUncertainNegatives(): array
    {
        return [["I don't mean VPN"], ["I don\u{2019}t mean VPN"], ['VPN is not it'], ["VPN isn\u{2019}t the one"], ['VPN was wrong']];
    }

    /** @dataProvider provideUncertainNegatives */
    public function testUncertainNegativeNeverSelectsOption(string $reply)
    {
        $this->chat->queue(self::CLARIFY_REPLY);
        $r = $this->ask('How do I change my password?');
        $calls = count($this->chat->calls);
        $r2 = $this->ask($reply, 'tabAAAAAAAA', $r['pendingId']);
        $this->assertNotSame(Outcome::ANSWER, $r2['outcome']);
        $this->assertNotContains('VPN', $r2['options']);
        $this->assertCount($calls, $this->chat->calls, 'no VPN-scoped model request');
        $this->assertStringNotContainsString('vpnctl', json_encode($r2));
    }

    public function testUncertainNegativeWithMoreTextIsPlainFreeText()
    {
        $this->chat->queue(self::CLARIFY_REPLY, "DECISION: NO_INFORMATION");
        $r = $this->ask('How do I change my password?');
        $this->ask("I don\u{2019}t mean VPN but my laptop login", 'tabAAAAAAAA', $r['pendingId']);
        $prompt = $this->chat->lastPrompt();
        $this->assertStringContainsString("(I don\u{2019}t mean VPN but my laptop login)", $prompt, 'full reply, negation visible to the model');
        $this->assertStringContainsString('CLARIFY:allowed', $prompt, 'not a forced scoped answer');
        $this->assertStringNotContainsString('(VPN)', $prompt, 'never a VPN choice request');
    }

    public function testRejectionWithoutPageFetcherShowsNoStoredLabels()
    {
        $this->chat->queue(self::CLARIFY_REPLY);
        $svc = new ConversationService($this->wiki->retriever(), $this->chat,
            static fn(array $v) => "CLARIFY:{$v['clarify']}\nQ:{$v['question']}\n{$v['context']}",
            new PendingStore($this->session, null, fn() => $this->now));
        $r = $svc->handle('How do I change my password?', [], 'tabAAAAAAAA');
        $r2 = $svc->handle('not VPN', [], 'tabAAAAAAAA', $r['pendingId']);
        $this->assertSame([], $r2['options'], 'cannot verify -> no menu');
    }

    public function testRejectionWithCorrectionSearchesWithoutRejectedEvidence()
    {
        $this->chat->queue(self::CLARIFY_REPLY, "DECISION: NO_INFORMATION");
        $r = $this->ask('How do I change my password?');
        $r2 = $this->ask('not VPN, my personal account', 'tabAAAAAAAA', $r['pendingId']);
        $prompt = $this->chat->lastPrompt();
        $this->assertStringContainsString('Q:How do I change my password? (my personal account)', $prompt);
        $this->assertStringNotContainsString('vpnctl', $prompt, 'evidence only supporting VPN excluded');
        $this->assertStringNotContainsString('(VPN)', $prompt);
        $this->assertSame(Outcome::NO_INFORMATION_TEXT, $r2['answer']);
    }

    public function testAllOptionsRejectedAsksGenerically()
    {
        $this->chat->queue(self::CLARIFY_REPLY);
        $r = $this->ask('How do I change my password?');
        $r2 = $this->ask('not e-mail, vpn or crm', 'tabAAAAAAAA', $r['pendingId']);
        $this->assertSame(Outcome::CLARIFY, $r2['outcome']);
        $this->assertSame([], $r2['options']);
        $this->assertCount(1, $this->chat->calls);
    }

    public function testPositiveChoiceNextToRejectionIsSelected()
    {
        $this->chat->queue(self::CLARIFY_REPLY, "DECISION: ANSWER\nUSED: S1\nANSWER:\nAvatar.");
        $r = $this->ask('How do I change my password?');
        $this->ask('the CRM one, not VPN', 'tabAAAAAAAA', $r['pendingId']);
        $this->assertStringContainsString('(CRM)', $this->chat->lastPrompt());
    }

    public function providePolaritySafeResolverCases(): array
    {
        $options = static fn(array $labels): array => array_map(
            static fn(string $label): array => ['label' => $label, 'pages' => []],
            $labels
        );
        $calendar = $options(['Calendar', 'Payroll', 'Drive']);
        $desk = $options(['Desk', 'Desk Token', 'Portal']);
        $accounts = $options(['E-Mail', 'VPN', 'CRM']);

        return [
            'negative no-good cue survives another rejection' => [
                $calendar, 'not Payroll, but Drive is no good', FollowupResolver::FREETEXT, null, [], false,
            ],
            'direct no-good mention is not a choice' => [
                $calendar, 'Drive is no good', FollowupResolver::REJECT, null, [2], true,
            ],
            'think hedge survives another rejection' => [
                $calendar, 'not Payroll, but I think Drive', FollowupResolver::FREETEXT, null, [], false,
            ],
            'direct think hedge is not a choice' => [
                $calendar, 'I think Drive', FollowupResolver::FREETEXT, null, [], false,
            ],
            'guess hedge survives another rejection' => [
                $calendar, 'not Payroll, but I guess Drive', FollowupResolver::FREETEXT, null, [], false,
            ],
            'suppose hedge survives another rejection' => [
                $calendar, 'not Payroll, but I suppose Drive', FollowupResolver::FREETEXT, null, [], false,
            ],
            'overlapping label keeps no-good cue in reordered clause' => [
                $desk, 'Desk Token is no good, not Portal', FollowupResolver::FREETEXT, null, [], false,
            ],
            'overlapping label keeps no-good cue after rejection' => [
                $desk, 'not Portal, but Desk Token is no good', FollowupResolver::FREETEXT, null, [], false,
            ],
            'overlapping label keeps multiword hedge after rejection' => [
                $desk, 'not Portal, but I think Desk Token', FollowupResolver::FREETEXT, null, [], false,
            ],
            'drive correction remains positive' => [
                $calendar, 'the Drive one, not Payroll', FollowupResolver::CHOICE, 2, [], false,
            ],
            'direct Drive button remains positive' => [
                $calendar, 'Drive', FollowupResolver::CHOICE, 2, [], false,
            ],
            'prefix rejection and trailing negation' => [
                $calendar, 'not Payroll, but Drive is not it', FollowupResolver::FREETEXT, null, [], false,
            ],
            'mixed case punctuation and curly apostrophe' => [
                $calendar, 'NOT PAYROLL — but DRIVE isn’t it either.', FollowupResolver::FREETEXT, null, [], false,
            ],
            'conjunction rejects both named options' => [
                $calendar, 'not Payroll and Drive', FollowupResolver::REJECT, null, [1, 2], false,
            ],
            'reordered labels with a trailing correction' => [
                $calendar, 'Drive is not it, not Payroll either', FollowupResolver::FREETEXT, null, [], false,
            ],
            'uncertain leftover after a rejection' => [
                $desk, 'not Portal, but maybe Desk Token', FollowupResolver::FREETEXT, null, [], false,
            ],
            'uncertain direct mention' => [
                $desk, 'Maybe Desk Token', FollowupResolver::FREETEXT, null, [], false,
            ],
            'overlapping positive label keeps longest identity' => [
                $desk, 'the Desk Token one, not Portal', FollowupResolver::CHOICE, 1, [], false,
            ],
            'overlapping positive after contrast remains valid' => [
                $desk, 'not Portal, but Desk Token', FollowupResolver::CHOICE, 1, [], false,
            ],
            'positive option after rejected option remains valid' => [
                $calendar, 'not Payroll, but Drive', FollowupResolver::CHOICE, 2, [], false,
            ],
            'positive CRM after rejected VPN remains valid' => [
                $accounts, 'not VPN, but CRM', FollowupResolver::CHOICE, 2, [], false,
            ],
            'CRM before rejected VPN remains valid' => [
                $accounts, 'the CRM one, not VPN', FollowupResolver::CHOICE, 2, [], false,
            ],
            'the corrected failing sentence stays unselected' => [
                $accounts, "not VPN, but CRM isn't it either", FollowupResolver::FREETEXT, null, [], false,
            ],
            'number 2 controls remain valid' => [
                $calendar, 'No. 2', FollowupResolver::CHOICE, 1, [], false,
            ],
            'ordinal control remains valid' => [
                $calendar, 'the second one', FollowupResolver::CHOICE, 1, [], false,
            ],
            'bare no 2 remains a rejection' => [
                $calendar, 'no 2', FollowupResolver::REJECT, null, [1], false,
            ],
            'multiple unresolved mentions remain free text' => [
                $calendar, 'Payroll or Drive', FollowupResolver::FREETEXT, null, [], false,
            ],
        ];
    }

    /** @dataProvider providePolaritySafeResolverCases */
    public function testResolveMixedPolarityChoicesSafely(
        array $options,
        string $reply,
        string $expectedType,
        ?int $expectedIndex,
        array $expectedRejected,
        bool $expectedUncertain
    ) {
        $result = (new FollowupResolver())->resolve($reply, $options);
        $this->assertSame($expectedType, $result['type'], $reply);
        $this->assertSame($expectedIndex, $result['index'], $reply);
        $this->assertSame($expectedRejected, $result['rejected'], $reply);
        $this->assertSame($expectedUncertain, $result['uncertain'], $reply);
    }

    public function testMixedNegativeCueUsesFreeTextInsteadOfSelectingCRM()
    {
        $reply = "not VPN, but CRM isn't it either";
        $this->chat->queue(self::CLARIFY_REPLY, 'DECISION: NO_INFORMATION');
        $service = $this->service();
        $first = $service->handle('How do I change my password?', [], 'tabAAAAAAAA');
        $this->assertSame(Outcome::CLARIFY, $first['outcome']);
        $this->assertCount(1, $this->chat->calls);

        $resolution = (new FollowupResolver())->resolve($reply, $this->session['tabAAAAAAAA']['options']);
        $this->assertSame(FollowupResolver::FREETEXT, $resolution['type']);
        $this->assertNull($resolution['index']);

        $second = $service->handle($reply, [], 'tabAAAAAAAA', $first['pendingId']);
        $this->assertSame(Outcome::NO_INFORMATION, $second['outcome']);
        $this->assertCount(2, $this->chat->calls, 'free-text follow-up makes one normal model call');
        $prompt = $this->chat->lastPrompt();
        $this->assertStringContainsString('Q:How do I change my password? (' . $reply . ')', $prompt);
        $this->assertStringContainsString('CLARIFY:allowed', $prompt, 'reply follows the free-text clarification path');
        $this->assertStringNotContainsString('Q:How do I change my password? (CRM)', $prompt);
        $this->assertStringNotContainsString('CLARIFY:not allowed', $prompt, 'CRM was not sent as a forced choice');
    }

    public function testPreservedNegativeCueUsesFreeTextInsteadOfSelectingCRM()
    {
        $reply = 'not VPN, but CRM is no good';
        $this->chat->queue(self::CLARIFY_REPLY, 'DECISION: NO_INFORMATION');
        $service = $this->service();
        $first = $service->handle('How do I change my password?', [], 'tabAAAAAAAA');
        $this->assertSame(Outcome::CLARIFY, $first['outcome']);
        $this->assertCount(1, $this->chat->calls);

        $resolution = (new FollowupResolver())->resolve($reply, $this->session['tabAAAAAAAA']['options']);
        $this->assertSame(FollowupResolver::FREETEXT, $resolution['type']);
        $this->assertNull($resolution['index']);

        $second = $service->handle($reply, [], 'tabAAAAAAAA', $first['pendingId']);
        $this->assertSame(Outcome::NO_INFORMATION, $second['outcome']);
        $this->assertCount(2, $this->chat->calls, 'free-text follow-up makes one normal model call');
        $prompt = $this->chat->lastPrompt();
        $this->assertStringContainsString('Q:How do I change my password? (' . $reply . ')', $prompt);
        $this->assertStringContainsString('CLARIFY:allowed', $prompt, 'reply follows the free-text clarification path');
        $this->assertStringNotContainsString('Q:How do I change my password? (CRM)', $prompt);
        $this->assertStringNotContainsString('CLARIFY:not allowed', $prompt, 'CRM was not sent as a forced choice');
    }

    // review finding 5 (service level): a token presented by two overlapping requests is used once
    public function testConcurrentRequestsCannotDoubleUseAToken()
    {
        $fresh = []; // stands in for the fresh, locked session data
        $persist = function (string $conv, ?array $item, ?string $expected) use (&$fresh): bool {
            $cur = $fresh[$conv]['id'] ?? null;
            if ($cur !== $expected) return false;
            unset($fresh[$conv]);
            if ($item !== null) $fresh[$conv] = $item;
            return true;
        };
        $svc = function (array $snapshot) use ($persist) {
            $snap = $snapshot;
            return new ConversationService(
                $this->wiki->retriever(), $this->chat,
                static fn(array $v) => "CLARIFY:{$v['clarify']}\nQ:{$v['question']}\n{$v['context']}",
                new PendingStore($snap, $persist, fn() => $this->now),
                [], [], null, null, null, null, $this->wiki->pageFetcher()
            );
        };
        $this->chat->queue(self::CLARIFY_REPLY, "DECISION: ANSWER\nUSED: S1\nANSWER:\nUse vpnctl.");
        $r = $svc($fresh)->handle('How do I change my password?', [], 'tabAAAAAAAA');
        $snap1 = $fresh; $snap2 = $fresh;            // two requests start with the same snapshot
        $a = $svc($snap1)->handle('2', [], 'tabAAAAAAAA', $r['pendingId']);
        $calls = count($this->chat->calls);
        $b = $svc($snap2)->handle('3', [], 'tabAAAAAAAA', $r['pendingId']);
        $this->assertSame(Outcome::ANSWER, $a['outcome']);
        $this->assertSame(Outcome::NOTICE, $b['outcome'], 'second use refused');
        $this->assertCount($calls, $this->chat->calls, 'no model call for the refused request');
        $this->assertSame([], $fresh, 'consumed token not resurrected');
    }

    public function testSequentialStaleBareChoicePreservesNewerPending()
    {
        $queries = [];
        $service = $this->serviceWithQueryLog($queries);
        $this->chat->queue(
            self::CLARIFY_REPLY,
            "DECISION: ANSWER\nUSED: S1\nANSWER:\nOpen CRM settings to change its password."
        );

        $first = $service->handle('How do I change my password?', [], 'tabAAAAAAAA');
        $this->assertSame(Outcome::CLARIFY, $first['outcome']);
        $p1 = $first['pendingId'];
        $this->assertNotSame('', $p1);
        $this->assertCount(1, $this->chat->calls);
        $this->assertCount(1, $queries);

        $second = $service->handle('not VPN', [], 'tabAAAAAAAA', $p1);
        $this->assertSame(Outcome::CLARIFY, $second['outcome']);
        $p2 = $second['pendingId'];
        $this->assertNotSame('', $p2);
        $this->assertNotSame($p1, $p2);
        $p2Record = $this->session['tabAAAAAAAA'];
        $this->assertSame('How do I change my password?', $p2Record['need']);
        $this->assertSame(['E-Mail', 'CRM'], array_column($p2Record['options'], 'label'));
        $this->assertSame(2, $p2Record['rounds']);
        $this->assertSame($this->now, $p2Record['created']);
        $this->assertCount(1, $this->chat->calls, 'rejecting VPN does not add a model call');

        // A second conversation's pending record must remain isolated from stale requests.
        $otherStore = new PendingStore($this->session, null, fn() => $this->now);
        $otherId = $otherStore->put('tabBBBBBBBB', 'Other account question', [
            ['label' => 'CRM', 'pages' => ['it:crm:password']],
        ], 1);
        $this->assertNotSame('', $otherId);
        $otherRecord = $this->session['tabBBBBBBBB'];

        $assertStale = function (string $reply, string $token) use ($service, $p2Record, $otherRecord, &$queries) {
            $callsBefore = count($this->chat->calls);
            $queriesBefore = $queries;
            $this->wiki->fetched = [];

            $result = $service->handle($reply, [], 'tabAAAAAAAA', $token);

            $this->assertSame(Outcome::NOTICE, $result['outcome'], $reply);
            $this->assertSame(
                'That earlier question has expired or belongs to another chat. Please ask your question again.',
                $result['answer']
            );
            $this->assertSame($p2Record, $this->session['tabAAAAAAAA'] ?? null, 'newer pending record is unchanged');
            $this->assertSame($otherRecord, $this->session['tabBBBBBBBB'] ?? null, 'other conversation is unchanged');
            $this->assertCount($callsBefore, $this->chat->calls, 'stale bare choice makes no model call');
            $this->assertSame($queriesBefore, $queries, 'stale bare choice makes no retrieval call');
            $this->assertSame([], $this->wiki->fetched, 'stale bare choice does not fetch grounded pages');
        };

        $assertStale('2', $p1);
        $assertStale('the second one', $p1);
        do {
            $unknownId = bin2hex(random_bytes(12));
        } while ($unknownId === $p1 || $unknownId === $p2);
        $assertStale('2', $unknownId);
        $assertStale('2', $p1); // repeated stale replay remains harmless

        $this->wiki->fetched = [];
        $callsBeforeChoice = count($this->chat->calls);
        $queriesBeforeChoice = count($queries);
        $answer = $service->handle('2', [], 'tabAAAAAAAA', $p2);
        $this->assertSame(Outcome::ANSWER, $answer['outcome']);
        $this->assertCount($callsBeforeChoice + 1, $this->chat->calls, 'current token makes exactly one model call');
        $this->assertCount($queriesBeforeChoice + 1, $queries, 'current token makes one answer retrieval');
        $this->assertSame('How do I change my password? (CRM)', $queries[count($queries) - 1]);
        $this->assertSame(['it:crm:password'], array_map(fn($c) => $c->getPage(), $answer['sources']));
        $this->assertSame(['it:crm:password'], $this->wiki->fetched, 'only the selected CRM page is fetched');
        $this->assertStringContainsString('(CRM)', $this->chat->lastPrompt());
        $this->assertStringNotContainsString('vpnctl', $this->chat->lastPrompt());
        $this->assertStringNotContainsString('webmail settings', $this->chat->lastPrompt());
        $this->assertArrayNotHasKey('tabAAAAAAAA', $this->session, 'current token was consumed');
        $this->assertSame($otherRecord, $this->session['tabBBBBBBBB']);

        $callsBeforeReplay = count($this->chat->calls);
        $queriesBeforeReplay = $queries;
        $this->wiki->fetched = [];
        $replay = $service->handle('2', [], 'tabAAAAAAAA', $p2);
        $this->assertSame(Outcome::NOTICE, $replay['outcome'], 'consumed current token cannot answer twice');
        $this->assertCount($callsBeforeReplay, $this->chat->calls);
        $this->assertSame($queriesBeforeReplay, $queries);
        $this->assertSame([], $this->wiki->fetched);
        $this->assertArrayNotHasKey('tabAAAAAAAA', $this->session);
        $this->assertSame($otherRecord, $this->session['tabBBBBBBBB']);
    }

    // trace spans cover the whole clarify -> choice flow, correlated, metadata only
    public function testTraceSpansAcrossClarificationAndFollowup()
    {
        $mk = fn($trace) => new ConversationService(
            $this->wiki->retriever(), $this->chat,
            static fn(array $v) => "CLARIFY:{$v['clarify']}\nQ:{$v['question']}\n{$v['context']}",
            new PendingStore($this->session, null, fn() => $this->now),
            [], [], static fn($q) => $q . ' (rephrased)', null, null, $trace, $this->wiki->pageFetcher()
        );
        $this->chat->queue(self::CLARIFY_REPLY, "DECISION: ANSWER\nUSED: S1\nANSWER:\nUse vpnctl.");
        $t1 = new \dokuwiki\plugin\aichat\Telemetry\TraceRecorder();
        $r = $mk($t1)->handle('How do I change my password?', [['earlier', 'answer']], 'tabAAAAAAAA');
        $this->assertSame(['rephrase', 'retrieval', 'model_call', 'clarify_decision'],
            array_column($t1->toArray()['spans'], 'name'));
        $t2 = new \dokuwiki\plugin\aichat\Telemetry\TraceRecorder();
        $mk($t2)->handle('2', [], 'tabAAAAAAAA', $r['pendingId']);
        $spans = $t2->toArray()['spans'];
        $this->assertSame(['followup_resolution', 'retrieval', 'acl_recheck', 'model_call', 'render'], array_column($spans, 'name'));
        $this->assertSame('choice', $spans[0]['attrs']['type']);
        $this->assertSame(1, $spans[2]['attrs']['readable_chunks']);
        $this->assertSame('fake', $spans[3]['attrs']['model']);
        $this->assertFalse($spans[3]['attrs']['usage.available'], 'unknown usage is marked, not invented');
        $this->assertStringNotContainsString('vpnctl', json_encode($t2->toArray()));
        $this->assertStringNotContainsString('password', json_encode($t2->toArray()));
    }

    public function testContextCaptureOnlyWhenEnabled()
    {
        $this->chat->queue("DECISION: ANSWER\nUSED: S1\nANSWER:\nUse vpnctl.", "DECISION: ANSWER\nUSED: S1\nANSWER:\nUse vpnctl.");
        foreach ([[], ['context']] as $capture) {
            $t = new \dokuwiki\plugin\aichat\Telemetry\TraceRecorder($capture);
            (new ConversationService($this->wiki->retriever(), $this->chat,
                static fn(array $v) => $v['context'], new PendingStore($this->session),
                [], [], null, null, null, $t))->handle('my vpn password is Hunter2!x how to change', [], 'tabAAAAAAAA');
            $content = $t->toArray()['content'];
            if (!$capture) {
                $this->assertSame([], $content);
            } else {
                $this->assertStringContainsString('vpnctl', $content['context']);
                $this->assertStringNotContainsString('Hunter2!x', json_encode($t->toArray()));
            }
        }
    }

    // 8. tabs, users, forged ids, expiry, invalid conversation ids cannot bypass or hijack clarification
    public function provideForeignState(): array
    {
        return [
            'other tab' => ['tab'],
            'other user/session' => ['user'],
            'forged pending id' => ['forged'],
            'expired' => ['expired'],
        ];
    }

    /** @dataProvider provideForeignState */
    public function testNumericChoiceWithForeignStateIsNeverResolved(string $case)
    {
        $this->chat->queue(self::CLARIFY_REPLY);
        $p = $this->ask('How do I change my password?')['pendingId'];
        $callsBefore = count($this->chat->calls);

        foreach (['2', 'the second one'] as $reply) {
            if ($case === 'tab') {
                $r = $this->ask($reply, 'tabBBBBBBBB', $p);
            } elseif ($case === 'user') {
                $other = [];
                $r = $this->service($other)->handle($reply, [], 'tabAAAAAAAA', $p);
                $this->assertSame([], $other, 'nothing stored for the other user');
            } elseif ($case === 'forged') {
                $r = $this->ask($reply, 'tabAAAAAAAA', 'forged' . $p);
            } else {
                $this->now += PendingStore::TTL + 1;
                $r = $this->ask($reply, 'tabAAAAAAAA', $p);
            }
            $this->assertSame(Outcome::NOTICE, $r['outcome'], "$case / $reply");
            $this->assertSame([], $r['sources']);
            $this->assertStringNotContainsString('vpnctl', $r['answer']);
        }
        $this->assertCount($callsBefore, $this->chat->calls, 'no model call, nothing guessed');
        if ($case === 'tab' || $case === 'user') {
            $this->assertArrayHasKey('tabAAAAAAAA', $this->session, 'original state untouched');
        }
        if ($case === 'expired') {
            $this->assertSame([], $this->session, 'genuinely expired pending state is still cleaned up');
        }
    }

    public function testNamedChoiceWithForeignStateIsAFreshQuestionAndCanClarify()
    {
        $this->chat->queue(self::CLARIFY_REPLY, self::CLARIFY_REPLY);
        $p = $this->ask('How do I change my password?')['pendingId'];
        $other = [];
        $r = $this->service($other)->handle('password', [], 'tabAAAAAAAA', $p);
        $this->assertStringContainsString("CLARIFY:allowed\nQ:password", $this->chat->lastPrompt());
        $this->assertSame(Outcome::CLARIFY, $r['outcome']);
        $this->assertStringNotContainsString('(VPN)', json_encode($this->chat->calls));
    }

    public function testInvalidConversationIdGetsFreshStateAndStillClarifies()
    {
        foreach (['bad id!', '', str_repeat('x', 65)] as $conv) {
            $this->session = [];
            $this->chat->queue(self::CLARIFY_REPLY, "DECISION: ANSWER\nUSED: S1\nANSWER:\nUse vpnctl.");
            $r = $this->ask('How do I change my password?', $conv);
            $this->assertSame(Outcome::CLARIFY, $r['outcome'], var_export($conv, true));
            $this->assertStringContainsString('CLARIFY:allowed', $this->chat->lastPrompt());
            $this->assertNotSame($conv, $r['conversationId']);
            $this->assertTrue(PendingStore::isValidConversationId($r['conversationId']));
            $this->assertArrayHasKey($r['conversationId'], $this->session);
            // the client adopts the fresh id and the follow-up works
            $r2 = $this->ask('2', $r['conversationId'], $r['pendingId']);
            $this->assertSame(Outcome::ANSWER, $r2['outcome']);
            $this->assertStringContainsString('(VPN)', $this->chat->lastPrompt());
        }
    }

    public function testPendingIdIsSingleUse()
    {
        $this->chat->queue(self::CLARIFY_REPLY, "DECISION: ANSWER\nUSED: S1\nANSWER:\nUse vpnctl.");
        $p = $this->ask('How do I change my password?')['pendingId'];
        $this->assertSame(Outcome::ANSWER, $this->ask('2', 'tabAAAAAAAA', $p)['outcome']);
        $calls = count($this->chat->calls);
        $this->assertSame(Outcome::NOTICE, $this->ask('3', 'tabAAAAAAAA', $p)['outcome'], 'replay of a used id');
        $this->assertCount($calls, $this->chat->calls);
    }

    // 9. empty / all-unauthorized retrieval -> exact text, no answer-model call
    public function testNoInformationWithoutModelCall()
    {
        $r = $this->ask('xyzzy plugh');
        $this->assertSame(Outcome::NO_INFORMATION, $r['outcome']);
        $this->assertSame('The Wiki does not contain information on that topic.', $r['answer']);
        $this->assertSame([], $r['sources']);
        $this->assertCount(0, $this->chat->calls);

        $r = $this->ask('confidential HIDDEN-HR-MARKER procedure'); // only matches the denied page... and none else
        $this->assertCount(0, $this->chat->calls);
        $this->assertSame(Outcome::NO_INFORMATION_TEXT, $r['answer']);
    }

    // 10. nonempty irrelevant context abstains
    public function testIrrelevantContextAbstains()
    {
        $this->chat->queue("DECISION: NO_INFORMATION");
        $r = $this->ask('Which coffee beans does the Friday team prefer?');
        $this->assertSame(Outcome::NO_INFORMATION_TEXT, $r['answer']);
        $this->assertSame([], $r['sources']);
    }

    // 11. outages and malformed output are errors, not "no information"
    public function testErrors()
    {
        $this->chat->queue(new ModelException('connect to http://10.0.0.5:8080 failed, token abc'));
        $r = $this->ask('How do I change my password?');
        $this->assertSame(Outcome::ERROR, $r['outcome']);
        $this->assertSame('model_error', $r['errorCategory']);
        $this->assertStringContainsString($r['correlationId'], $r['answer']);
        $this->assertStringNotContainsString('10.0.0.5', $r['answer']);
        $this->assertStringNotContainsString(Outcome::NO_INFORMATION_TEXT, $r['answer']);

        $this->chat->queue('Sure! Here is everything...', 'still no format');
        $r = $this->ask('How do I change my password?');
        $this->assertSame('model_malformed', $r['errorCategory']);

        $this->chat->queue('oops', "DECISION: ANSWER\nUSED: S2\nANSWER:\nRecovered.");
        $r = $this->ask('How do I change my VPN password?');
        $this->assertSame(Outcome::ANSWER, $r['outcome']);

        $broken = new ConversationService(
            static function () { throw new \RuntimeException('qdrant https://q.internal:6333 down'); },
            $this->chat, static fn($v) => '', new PendingStore($this->session)
        );
        $r = $broken->handle('anything', [], 'tabAAAAAAAA');
        $this->assertSame('backend_error', $r['errorCategory']);
        $this->assertStringNotContainsString('q.internal', $r['answer']);
    }

    // credential volunteered by the user: not sent to the model, warning shown
    public function testSecretRedaction()
    {
        $this->chat->queue("DECISION: ANSWER\nUSED: S1\nANSWER:\nOpen webmail settings.");
        $r = $this->ask('my email password is Hunter2!x how do I change it');
        $this->assertStringNotContainsString('Hunter2!x', json_encode($this->chat->calls));
        $this->assertStringNotContainsString('Hunter2!x', $r['question']);
        $this->assertTrue($r['redacted']);
        $this->assertNotSame('', $r['warning']);
    }

    // 17. retrieved prompt injection cannot add unauthorized sources
    public function testInjectedModelOutputCannotAddSources()
    {
        $this->chat->queue("DECISION: ANSWER\nUSED: S1, S99\nANSWER:\nSee [HR](/doku.php?id=it:hr:password) [S99].");
        $r = $this->ask('How do I change my VPN password?');
        $pages = array_map(fn($c) => $c->getPage(), $r['sources']);
        $this->assertNotContains('it:hr:password', $pages);
        $this->assertStringNotContainsString('S99', $r['answer']);
    }
}
