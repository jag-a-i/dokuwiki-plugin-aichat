<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Logger;
use dokuwiki\plugin\aichat\Chunk;
use dokuwiki\plugin\aichat\Conversation\ConversationService;
use dokuwiki\plugin\aichat\Conversation\ErrorReporter;
use dokuwiki\plugin\aichat\Conversation\Outcome;

/**
 * DokuWiki Plugin aichat (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <gohr@cosmocode.de>
 */
class action_plugin_aichat extends ActionPlugin
{
    public const MAX_QUESTION_LEN = 2000;
    public const MAX_HISTORY_BYTES = 65536;

    /** @inheritDoc */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleQuestion');
        $controller->register_hook('DOKUWIKI_STARTED', 'AFTER', $this, 'addSecurityToken');
    }

    /**
     * Expose the per-request security token to the chat UI (JSINFO is not part of the page cache)
     *
     * @param Event $event
     * @param mixed $param
     * @return void
     */
    public function addSecurityToken(Event $event, mixed $param)
    {
        global $JSINFO;
        $JSINFO['plugin_aichat'] = ['sectok' => getSecurityToken()];
    }

    /**
     * Event handler for AJAX_CALL_UNKNOWN event
     *
     * Response: legacy fields question, answer (HTML), sources plus a versioned "meta" object.
     *
     * @see https://www.dokuwiki.org/devel:events:ajax_call_unknown
     * @param Event $event Event object
     * @param mixed $param optional parameter passed when event was registered
     * @return void
     */
    public function handleQuestion(Event $event, mixed $param)
    {
        if ($event->data !== 'aichat') return;
        $event->preventDefault();
        $event->stopPropagation();
        global $INPUT;

        /** @var helper_plugin_aichat $helper */
        $helper = plugin_load('helper', 'aichat');

        $question = mb_substr(trim($INPUT->post->str('question')), 0, self::MAX_QUESTION_LEN);
        $pagecontext = $INPUT->post->str('pagecontext');
        $conversation = $INPUT->post->str('conversation');
        $pendingId = $INPUT->post->str('pending');
        $rawHistory = $INPUT->post->str('history');
        $history = strlen($rawHistory) <= self::MAX_HISTORY_BYTES ? json_decode($rawHistory, true) : null;
        if (!is_array($history)) $history = [];
        header('Content-Type: application/json');

        if (!$helper->userMayAccess()) {
            echo json_encode([
                'question' => $question,
                'answer' => $this->getLang('restricted'),
                'sources' => [],
                'meta' => $this->meta(['outcome' => Outcome::NOTICE]),
            ], JSON_THROW_ON_ERROR);
            return;
        }

        // the chat writes per-session state, so the request must carry the user's security token
        if (!checkSecurityToken($INPUT->post->str('sectok'))) {
            http_status(403);
            $ref = ErrorReporter::newCorrelationId();
            echo json_encode([
                'question' => $question,
                'answer' => sprintf($this->getLang('sectok'), $ref),
                'sources' => [],
                'meta' => $this->meta(['outcome' => Outcome::ERROR, 'correlationId' => $ref]),
            ], JSON_THROW_ON_ERROR);
            return;
        }

        try {
            $result = $helper->askConversational($question, $history, $conversation, $pendingId, $pagecontext);
        } catch (\Throwable $e) {
            // should not happen (the service catches), but never leak details
            $ref = ErrorReporter::newCorrelationId();
            $result = [
                'outcome' => Outcome::ERROR, 'question' => $question, 'answer' => sprintf($this->getLang('error'), $ref),
                'sources' => [], 'options' => [], 'pendingId' => '', 'conversationId' => '', 'responseId' => '',
                'correlationId' => $ref, 'warning' => '', 'errorCategory' => ErrorReporter::category($e), 'exception' => $e,
            ];
        }

        if ($result['outcome'] === Outcome::ERROR) {
            ErrorReporter::log('chat', $result['errorCategory'] ?? 'backend_error', $result['correlationId'], $result['exception'] ?? null);
            $result['answer'] = sprintf($this->getLang('error'), $result['correlationId']);
        }

        $sources = [];
        if ($result['outcome'] === Outcome::ANSWER) {
            foreach ($result['sources'] as $source) {
                /** @var Chunk $source */
                if (isset($sources[$source->getPage()])) continue; // only show the first occurrence per page
                $sources[$source->getPage()] = [
                    'page' => $source->getPage(),
                    'url' => wl($source->getPage()),
                    'title' => p_get_first_heading($source->getPage()) ?: $source->getPage(),
                    'score' => sprintf("%.2f%%", $source->getScore() * 100),
                ];
            }
        }

        $parseDown = new Parsedown();
        $parseDown->setSafeMode(true);

        echo json_encode([
            'question' => $result['question'],
            'answer' => $parseDown->text($result['answer']),
            'sources' => array_values($sources),
            'meta' => $this->meta([
                'outcome' => $result['outcome'],
                'options' => array_values($result['options']),
                'pendingId' => $result['pendingId'],
                'conversationId' => $result['conversationId'],
                'responseId' => $result['responseId'],
                'correlationId' => $result['correlationId'],
                'warning' => $result['warning'],
            ]),
        ], JSON_THROW_ON_ERROR);

        // pre-existing opt-in raw logging, unchanged in format (privacy issue documented);
        // logs the redacted question instead of the raw input, and nothing for errors
        if ($this->getConf('logging') && $result['outcome'] !== Outcome::ERROR) {
            try {
                Logger::getInstance('aichat')->log(
                    $result['question'],
                    [
                        'interpretation' => $result['question'],
                        'answer' => $result['answer'],
                        'sources' => $sources,
                        'ip' => $INPUT->server->str('REMOTE_ADDR'),
                        'user' => $INPUT->server->str('REMOTE_USER'),
                        'stats' => $helper->getChatModel()->getUsageStats()
                    ]
                );
            } catch (\Throwable $ignored) {
                // logging must never break the chat
            }
        }
    }

    protected function meta(array $data): array
    {
        return array_merge([
            'v' => ConversationService::META_VERSION,
            'outcome' => Outcome::ANSWER,
            'options' => [],
            'pendingId' => '',
            'conversationId' => '',
            'responseId' => '',
            'correlationId' => '',
            'warning' => '',
        ], $data);
    }
}
