<?php

namespace dokuwiki\plugin\aichat\Conversation;

use dokuwiki\plugin\aichat\Chunk;
use dokuwiki\plugin\aichat\Model\ChatInterface;

/**
 * Single-pipeline conversational RAG turn handler with grounded clarification.
 *
 * Flow per turn:
 *   redact secrets -> (resolve pending clarification | rephrase) -> retrieve (ACL-filtered, fresh)
 *   -> empty? NO_INFORMATION (no answer-model call)
 *   -> decision model call (ANSWER | CLARIFY | NO_INFORMATION), validated in code
 *   -> format (app-owned footer, validated sources) | store pending clarification
 *
 * All identifiers coming back from the model are validated against the permitted retrieved set.
 */
class ConversationService
{
    public const META_VERSION = 1;

    /** @var callable(string $query): Chunk[]  must return ONLY chunks the current user may read */
    protected $retriever;
    /** @var ChatInterface */
    protected $chat;
    /** @var callable(array $vars): string builds the decision prompt */
    protected $promptBuilder;
    /** @var callable|null (string $question, array $history): string */
    protected $rephraser;
    /** @var callable(string $page): string */
    protected $titleFn;
    protected PendingStore $pending;
    protected FollowupResolver $resolver;
    protected DecisionParser $parser;
    protected AnswerFormatter $formatter;
    protected SecretRedactor $redactor;
    /** @var array */
    protected $conf;
    /** @var array */
    protected $lang;
    /** @var \dokuwiki\plugin\aichat\Telemetry\TraceRecorder|null */
    protected $trace;
    /** @var callable|null (string $page): Chunk[]  chunks of one page, ONLY if the current user may read it */
    protected $pageFetcher;
    /** conversation id used for the current turn (may be freshly generated) */
    protected string $conversation = '';

    public function __construct(
        callable $retriever,
        ChatInterface $chat,
        callable $promptBuilder,
        PendingStore $pending,
        array $conf = [],
        array $lang = [],
        ?callable $rephraser = null,
        ?callable $titleFn = null,
        ?AnswerFormatter $formatter = null,
        $trace = null,
        ?callable $pageFetcher = null
    ) {
        $this->pageFetcher = $pageFetcher;
        $this->retriever = $retriever;
        $this->chat = $chat;
        $this->promptBuilder = $promptBuilder;
        $this->pending = $pending;
        $this->rephraser = $rephraser;
        $this->titleFn = $titleFn ?: static fn(string $page) => $page;
        $this->resolver = new FollowupResolver();
        $this->parser = new DecisionParser();
        $this->formatter = $formatter ?: new AnswerFormatter();
        $this->redactor = new SecretRedactor();
        $this->trace = $trace;
        $this->conf = array_merge([
            'maxClarifyRounds' => 2,
            'clarify' => true,
            'maxHistoryRows' => 6,
            'maxHistoryChars' => 2000,
        ], $conf);
        $this->lang = array_merge([
            'clarify_default' => 'Which of these do you mean?',
            'clarify_generic' => 'Which system or account is your question about? Please name it.',
            'none_followup' => 'Which system or account do you mean? Please name it, and I will search the wiki for it.',
            'cancelled' => 'Okay, I have cancelled that question. What else can I help you with?',
            'unknown_help' => 'I cannot tell which procedure applies without knowing the system or account. Please check with your helpdesk or a colleague who knows which system you use.',
            'max_rounds' => 'I still cannot tell which procedure applies. Please contact your helpdesk with the name of the system or account you are asking about.',
            'error' => 'Sorry, the AI chat service is currently unavailable. Please try again later. Reference: %s',
            'reject_followup' => 'Which one do you mean instead? You can also name the system or account.',
            'expired' => 'That earlier question has expired or belongs to another chat. Please ask your question again.',
            'redacted' => 'It looks like your message contained a password or other secret. I removed it and did not use it. Never share credentials in the chat.',
        ], $lang);
    }

    /**
     * Handle one chat turn.
     *
     * @param string $question raw user input
     * @param array $history untrusted client history [[q, a], ...] used for conversational context only
     * @param string $conversation per-tab conversation id (lookup key only)
     * @param string $pendingId pending clarification turn id echoed by the client (lookup key only)
     * @return array result, see result()
     */
    public function handle(string $question, array $history, string $conversation, string $pendingId = ''): array
    {
        $correlation = bin2hex(random_bytes(8));
        [$question, $redacted] = $this->redactor->redact($question);
        $history = $this->sanitizeHistory($history);

        // an invalid or missing conversation id gets a fresh server-generated one; clarification stays possible
        if (!PendingStore::isValidConversationId($conversation)) {
            $conversation = self::newConversationId();
            $pendingId = '';
        }
        $this->conversation = $conversation;

        try {
            $pending = $this->pending->get($conversation, $pendingId);
            if ($pending) {
                // single use, atomically: a concurrent request that already used this token wins
                if (!$this->pending->consume($conversation, $pendingId)) {
                    return $this->result(Outcome::NOTICE, $question, $this->lang['expired'], [], $correlation, $redacted);
                }
                return $this->handleFollowup($question, $history, $conversation, $pending, $correlation, $redacted);
            }
            // A stale bare choice is not a fresh question: preserve any newer pending token after get() rejects this id.
            if ($pendingId !== '' && FollowupResolver::isBareChoice($question)) {
                return $this->result(Outcome::NOTICE, $question, $this->lang['expired'], [], $correlation, $redacted);
            }

            // any stale pending state for this conversation is dropped on a fresh question
            $this->pending->clear($conversation);

            $search = $question;
            if ($this->rephraser && $history) {
                $span = $this->span('rephrase');
                $search = ($this->rephraser)($question, $history) ?: $question;
                $this->end($span);
            }
            return $this->answerOrClarify(
                $question, $search, $history, $conversation, $correlation, $redacted,
                $this->conf['clarify'] && !$this->isExplicitMulti($question), 0, null
            );
        } catch (\Throwable $e) {
            return $this->error($question, $e, $correlation, $redacted);
        }
    }

    public static function newConversationId(): string
    {
        return 'c' . bin2hex(random_bytes(12));
    }

    protected function handleFollowup(
        string $reply, array $history, string $conversation, array $pending, string $correlation, bool $redacted
    ): array {
        $span = $this->span('followup_resolution');
        $res = $this->resolver->resolve($reply, $pending['options']);
        $this->end($span, ['type' => $res['type']]);
        $need = $pending['need'];
        $rounds = (int)$pending['rounds'];

        switch ($res['type']) {
            case FollowupResolver::CANCEL:
                return $this->result(Outcome::NOTICE, $reply, $this->lang['cancelled'], [], $correlation, $redacted);

            case FollowupResolver::UNKNOWN:
                return $this->result(Outcome::NOTICE, $reply, $this->lang['unknown_help'], [], $correlation, $redacted);

            case FollowupResolver::NONE:
                if ($rounds >= $this->conf['maxClarifyRounds']) {
                    return $this->result(Outcome::NOTICE, $reply, $this->lang['max_rounds'], [], $correlation, $redacted);
                }
                return $this->clarifyResult($reply, $conversation, $need, [], $this->lang['none_followup'], $rounds + 1, $correlation, $redacted);

            case FollowupResolver::REJECT:
                // "not VPN": never select a rejected option
                $rejected = array_flip($res['rejected']);
                $remaining = [];
                foreach ($pending['options'] as $i => $opt) {
                    if (!isset($rejected[$i])) $remaining[] = $opt;
                }
                if ($res['remainder'] !== '' && $res['uncertain']) {
                    // uncertain corrective sentence with more content: plain free-text search of the full
                    // reply (the model sees the negation), never scoped to any offered option
                    return $this->freetext($need, $reply, $reply, $conversation, $correlation, $redacted, $rounds, []);
                }
                if ($res['remainder'] !== '') {
                    // "not VPN, my personal account": search again for what the user described,
                    // without evidence that only supports the rejected options
                    return $this->freetext(
                        $need, $res['remainder'], $reply, $conversation, $correlation, $redacted, $rounds,
                        $this->exclusivePages($pending['options'], $rejected)
                    );
                }
                if ($rounds >= $this->conf['maxClarifyRounds']) {
                    return $this->result(Outcome::NOTICE, $reply, $this->lang['max_rounds'], [], $correlation, $redacted);
                }
                // options from earlier state are re-authorized before they are shown again
                $remaining = $this->reauthorizeOptions($remaining);
                $q = $remaining ? $this->lang['reject_followup'] : $this->lang['none_followup'];
                return $this->clarifyResult($reply, $conversation, $need, $remaining, $q, $rounds + 1, $correlation, $redacted);

            case FollowupResolver::NEW_TOPIC:
                return $this->answerOrClarify(
                    $reply, $reply, $history, $conversation, $correlation, $redacted,
                    $this->conf['clarify'] && !$this->isExplicitMulti($reply), 0, null
                );

            case FollowupResolver::CHOICE:
                $opt = $pending['options'][$res['index']];
                $combined = $need . ' (' . $opt['label'] . ')';
                return $this->answerOrClarify(
                    $combined, $combined, [], $conversation, $correlation, $redacted, false, $rounds, $opt['pages']
                );

            default: // FREETEXT: description, correction or a system outside the offered options
                return $this->freetext($need, $reply, $reply, $conversation, $correlation, $redacted, $rounds, []);
        }
    }

    /** search again for a free-text description; optionally without the given pages */
    protected function freetext(
        string $need, string $text, string $reply, string $conversation, string $correlation, bool $redacted,
        int $rounds, array $excludePages
    ): array {
        $combined = $need . ' (' . $text . ')';
        $allow = $rounds < $this->conf['maxClarifyRounds'];
        $result = $this->answerOrClarify(
            $combined, $combined, [], $conversation, $correlation, $redacted, $allow, $rounds, null, $excludePages
        );
        if (!$allow && $result['outcome'] === Outcome::CLARIFY) {
            $this->pending->clear($conversation);
            return $this->result(Outcome::NOTICE, $reply, $this->lang['max_rounds'], [], $correlation, $redacted);
        }
        return $result;
    }

    /**
     * Re-check stored options against CURRENT permissions and content: keep an option only if at
     * least one of its pages still exists and is readable, and keep only those pages. Without a
     * page fetcher nothing can be verified, so no stored option is shown again.
     */
    protected function reauthorizeOptions(array $options): array
    {
        if (!$options) return [];
        if (!$this->pageFetcher) return [];
        $span = $this->span('acl_recheck', ['options' => count($options)]);
        $readable = [];
        $out = [];
        foreach ($options as $opt) {
            $pages = [];
            foreach ($opt['pages'] as $page) {
                if (!array_key_exists($page, $readable)) {
                    $readable[$page] = false;
                    foreach (($this->pageFetcher)($page) as $c) {
                        if ($c->getPage() === $page) { $readable[$page] = true; break; }
                    }
                }
                if ($readable[$page]) $pages[] = $page;
            }
            if ($pages) $out[] = ['label' => $opt['label'], 'pages' => $pages];
        }
        $this->end($span, ['readable_options' => count($out)]);
        return $out;
    }

    /** pages that support ONLY rejected options (pages shared with a remaining option are kept) */
    protected function exclusivePages(array $options, array $rejected): array
    {
        $keep = [];
        $drop = [];
        foreach ($options as $i => $opt) {
            foreach ($opt['pages'] as $p) {
                if (isset($rejected[$i])) $drop[$p] = true; else $keep[$p] = true;
            }
        }
        return array_keys(array_diff_key($drop, $keep));
    }

    /** store a pending clarification (compare-and-swap) and build the CLARIFY result */
    protected function clarifyResult(
        string $question, string $conversation, string $need, array $options, string $text, int $rounds,
        string $correlation, bool $redacted
    ): array {
        $id = $this->pending->put($conversation, $need, $options, $rounds);
        if ($id === '') {
            // concurrent modification of this conversation: never show choices that are not stored
            return $this->result(Outcome::NOTICE, $question, $this->lang['expired'], [], $correlation, $redacted);
        }
        return $this->result(
            Outcome::CLARIFY, $question, $text, [], $correlation, $redacted, array_column($options, 'label'), $id
        );
    }

    /**
     * @param string[]|null $preferPages pages of a selected option; current permissions are re-checked
     *                                   because retrieval runs again with the current user's ACL
     */
    protected function answerOrClarify(
        string $question, string $search, array $history, string $conversation, string $correlation,
        bool $redacted, bool $allowClarify, int $rounds, ?array $preferPages, array $excludePages = []
    ): array {
        $span = $this->span('retrieval', ['acl_filtered' => true]);
        $chunks = array_values(($this->retriever)($search));
        if ($excludePages) {
            $ex = array_flip($excludePages);
            $chunks = array_values(array_filter($chunks, static fn(Chunk $c) => !isset($ex[$c->getPage()])));
        }
        $this->end($span, ['chunks' => count($chunks)]);

        if ($preferPages !== null) {
            // A clarification choice is answered ONLY from the chosen option's pages, re-checked now.
            // Never broaden to other procedures if that evidence disappeared or became unreadable.
            $prefer = array_flip($preferPages);
            $selected = [];
            foreach ($chunks as $c) {
                if (isset($prefer[$c->getPage()])) $selected[] = $c;
            }
            if ($this->pageFetcher) {
                $aclSpan = $this->span('acl_recheck', ['pages' => count($preferPages)]);
                $seen = [];
                foreach ($selected as $c) $seen[$c->getPage() . '#' . $c->getId()] = true;
                foreach ($preferPages as $page) {
                    foreach (($this->pageFetcher)($page) as $c) { // fetcher applies the current ACL
                        if ($c->getPage() !== $page) continue;
                        $k = $c->getPage() . '#' . $c->getId();
                        if (!isset($seen[$k])) { $selected[] = $c; $seen[$k] = true; }
                    }
                }
                $this->end($aclSpan, ['readable_chunks' => count($selected)]);
            }
            $chunks = $selected;
        }

        if (!$chunks) {
            return $this->result(Outcome::NO_INFORMATION, $question, Outcome::NO_INFORMATION_TEXT, [], $correlation, $redacted);
        }

        // label chunks S1..Sn - these labels are the only identifiers the model sees
        $refs = [];
        $context = [];
        foreach ($chunks as $i => $chunk) {
            $ref = 'S' . ($i + 1);
            $refs[$ref] = $chunk;
            $context[] = "[$ref] " . ($this->titleFn)($chunk->getPage()) . "\n```\n" . $chunk->getText() . "\n```";
        }

        if ($this->trace && $this->trace->captures('context')) {
            $this->trace->content('context', $this->redactor->redact(implode("\n\n", $context))[0]);
        }
        $prompt = ($this->promptBuilder)([
            'context' => implode("\n\n", $context),
            'question' => $question,
            'clarify' => $allowClarify ? 'allowed' : 'not allowed',
        ]);
        $messages = $this->historyMessages($history);
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $decision = $this->decide($messages);
        if ($decision === null) {
            throw new MalformedModelOutputException('model output did not match the decision format');
        }

        if ($decision['decision'] === Outcome::NO_INFORMATION) {
            return $this->result(Outcome::NO_INFORMATION, $question, Outcome::NO_INFORMATION_TEXT, [], $correlation, $redacted);
        }

        if ($decision['decision'] === Outcome::CLARIFY) {
            $span = $this->span('clarify_decision');
            if ($allowClarify) {
                $options = $this->groundOptions($decision['options'], $refs);
                if (count($options) >= 2) {
                    $q = $this->cleanQuestion($decision['question']) ?: $this->lang['clarify_default'];
                    $this->end($span, ['result' => 'clarify', 'options' => count($options)]);
                    return $this->clarifyResult($question, $conversation, $question, $options, $q, $rounds + 1, $correlation, $redacted);
                }
                if (!$decision['options'] && $this->cleanQuestion($decision['question'])) {
                    // no menu possible: generic targeted question, no invented choices
                    $this->end($span, ['result' => 'clarify_generic']);
                    return $this->clarifyResult(
                        $question, $conversation, $question, [], $this->cleanQuestion($decision['question']),
                        $rounds + 1, $correlation, $redacted
                    );
                }
            }
            // clarification not allowed or not grounded (e.g. one system only): force a direct answer
            $this->end($span, ['result' => 'forced_answer']);
            $prompt = ($this->promptBuilder)([
                'context' => implode("\n\n", $context),
                'question' => $question,
                'clarify' => 'not allowed',
            ]);
            $messages[count($messages) - 1]['content'] = $prompt;
            $decision = $this->decide($messages);
            if ($decision === null || $decision['decision'] === Outcome::CLARIFY) {
                if ($allowClarify || $rounds > 0) {
                    return $this->result(Outcome::NOTICE, $question, $this->lang['max_rounds'], [], $correlation, $redacted);
                }
                throw new MalformedModelOutputException('model insisted on clarification where not allowed');
            }
            if ($decision['decision'] === Outcome::NO_INFORMATION) {
                return $this->result(Outcome::NO_INFORMATION, $question, Outcome::NO_INFORMATION_TEXT, [], $correlation, $redacted);
            }
        }

        // ANSWER
        $span = $this->span('render');
        $used = array_values(array_filter(array_map(static fn($r) => $refs[$r] ?? null, $decision['used'])));
        if (!$used) $used = array_values($refs); // model did not name valid sources: all permitted context
        $pages = [];
        foreach ($used as $chunk) $pages[$chunk->getPage()] = $chunk;
        $answer = $this->formatter->format($decision['answer'], array_keys($pages));
        $this->end($span, ['sources' => count($pages)]);
        return $this->result(Outcome::ANSWER, $question, $answer, array_values($pages), $correlation, $redacted);
    }

    /** one model call plus at most one bounded repair attempt */
    protected function decide(array $messages): ?array
    {
        $raw = $this->modelCall($messages, false);
        $parsed = $this->parser->parse($raw);
        if ($parsed !== null) return $parsed;

        $messages[] = ['role' => 'assistant', 'content' => mb_substr($raw, 0, 4000)];
        $messages[] = ['role' => 'user', 'content' =>
            'Your reply did not follow the required format. Reply again, starting with a line ' .
            '"DECISION: ANSWER", "DECISION: CLARIFY" or "DECISION: NO_INFORMATION", exactly as instructed.'];
        $raw = $this->modelCall($messages, true);
        return $this->parser->parse($raw);
    }

    /** one traced model call; usage is the delta of the model's cumulative counters */
    protected function modelCall(array $messages, bool $repair): string
    {
        $before = $this->usage();
        $span = $this->span('model_call', ['model' => (string)$this->chat->getModelName(), 'repair' => $repair]);
        try {
            $raw = $this->chat->getAnswer($messages);
        } finally {
            $after = $this->usage();
            $tokens = $after['tokens'] - $before['tokens'];
            $this->end($span, $tokens > 0
                ? ['usage.total_tokens' => $tokens, 'usage.available' => true]
                : ['usage.available' => false]);
        }
        return $raw;
    }

    protected function usage(): array
    {
        try {
            $u = $this->chat->getUsageStats();
            return ['tokens' => (int)($u['tokens'] ?? 0)];
        } catch (\Throwable $e) {
            return ['tokens' => 0];
        }
    }

    /**
     * Map model-proposed options to permitted evidence; merge duplicates; drop ungrounded ones.
     * @param Chunk[] $refs
     */
    protected function groundOptions(array $proposed, array $refs): array
    {
        $byLabel = [];
        foreach ($proposed as $opt) {
            $label = $this->cleanLabel($opt['label']);
            if ($label === '') continue;
            $pages = [];
            foreach ($opt['refs'] as $r) {
                if (isset($refs[$r])) $pages[$refs[$r]->getPage()] = true;
            }
            if (!$pages) continue; // not backed by permitted evidence
            $key = $this->optionKey($label);
            if (isset($byLabel[$key])) {
                $byLabel[$key]['pages'] += $pages;
            } else {
                $byLabel[$key] = ['label' => $label, 'pages' => $pages];
            }
        }
        // NOTE: options are NOT merged because they share source pages - one page may document
        // several distinct systems. Only options with the same normalized name are merged.
        $out = [];
        foreach ($byLabel as $opt) {
            $out[] = ['label' => $opt['label'], 'pages' => array_keys($opt['pages'])];
        }
        return array_slice($out, 0, PendingStore::MAX_OPTIONS);
    }

    /**
     * Normalized identity of an option name: case, punctuation and generic words
     * ("account", "password", "login", ...) are ignored, so "E-Mail account" == "e-mail".
     * Different names for the same system ("Webmail" vs "E-Mail") are NOT detected.
     */
    protected function optionKey(string $label): string
    {
        $l = mb_strtolower($label);
        $l = preg_replace('/\b(the|my|your|an?|accounts?|passwords?|passwort|logins?|systems?|credentials?)\b/u', ' ', $l);
        $key = preg_replace('/[^\p{L}\p{N}]+/u', '', $l);
        return $key !== '' ? $key : mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $label));
    }

    protected function cleanLabel(string $label): string
    {
        $label = strip_tags($label);
        $label = preg_replace('/[\[\]()*_`#>|]+/', ' ', $label);
        $label = preg_replace('/https?:\/\/\S+/', '', $label);
        $label = trim(preg_replace('/\s+/', ' ', $label), " .:-");
        return mb_substr($label, 0, PendingStore::MAX_LABEL_LEN);
    }

    protected function cleanQuestion(string $q): string
    {
        $q = trim(preg_replace('/\s+/', ' ', strip_tags($q)));
        if ($q === '' || mb_strlen($q) > 300) return '';
        return $q;
    }

    /** explicit comparisons / multiple procedures should not be narrowed */
    protected function isExplicitMulti(string $q): bool
    {
        return (bool)preg_match(
            '/\b(compare|comparison|difference|differences|differ|versus|vs\.?|both|all (the )?(systems|procedures|options|ways)|each (system|account))\b/i',
            $q
        );
    }

    protected function sanitizeHistory(array $history): array
    {
        $clean = [];
        foreach (array_slice($history, -max(0, (int)$this->conf['maxHistoryRows'])) as $row) {
            if (!is_array($row) || !isset($row[0], $row[1])) continue;
            $q = $this->redactor->redact(mb_substr(trim(strip_tags((string)$row[0])), 0, $this->conf['maxHistoryChars']))[0];
            $a = $this->redactor->redact(mb_substr(trim(html_entity_decode(strip_tags((string)$row[1]))), 0, $this->conf['maxHistoryChars']))[0];
            $a = str_replace(Outcome::FOOTER_TEXT, '', $a);
            $clean[] = [$q, $a];
        }
        return $clean;
    }

    protected function historyMessages(array $history): array
    {
        $messages = [];
        $rows = (int)($this->conf['chatHistoryRows'] ?? $this->conf['maxHistoryRows']);
        foreach ($rows > 0 ? array_slice($history, -$rows) : [] as $row) {
            $messages[] = ['role' => 'user', 'content' => $row[0]];
            $messages[] = ['role' => 'assistant', 'content' => $row[1]];
        }
        return $messages;
    }

    protected function error(string $question, \Throwable $e, string $correlation, bool $redacted): array
    {
        $category = $e instanceof MalformedModelOutputException ? 'model_malformed'
            : ($e instanceof \dokuwiki\plugin\aichat\Model\ModelException ? 'model_error' : 'backend_error');
        if ($this->trace) $this->trace->error($category);
        $r = $this->result(Outcome::ERROR, $question, sprintf($this->lang['error'], $correlation), [], $correlation, $redacted);
        $r['errorCategory'] = $category;
        $r['exception'] = $e; // for server-side logging only, never sent to the client
        return $r;
    }

    protected function result(
        string $outcome, string $question, string $text, array $sources, string $correlation, bool $redacted,
        array $options = [], string $pendingId = ''
    ): array {
        return [
            'outcome' => $outcome,
            'question' => $question,
            'answer' => $text,
            'sources' => $sources,
            'options' => $options,
            'pendingId' => $pendingId,
            'conversationId' => $this->conversation,
            'responseId' => bin2hex(random_bytes(12)),
            'correlationId' => $correlation,
            'redacted' => $redacted,
            // rendered separately by the UI so outcome texts (e.g. NO_INFORMATION) stay exact
            'warning' => $redacted ? $this->lang['redacted'] : '',
        ];
    }

    protected function span(string $name, array $attrs = [])
    {
        return $this->trace ? $this->trace->start($name, $attrs) : null;
    }

    protected function end($span, array $attrs = []): void
    {
        if ($this->trace && $span !== null && $span >= 0) $this->trace->end($span, $attrs);
    }
}
