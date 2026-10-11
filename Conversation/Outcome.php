<?php

namespace dokuwiki\plugin\aichat\Conversation;

/**
 * Internal response outcome categories (versioned via ConversationResult::META_VERSION)
 */
final class Outcome
{
    public const ANSWER = 'ANSWER';
    public const CLARIFY = 'CLARIFY';
    public const NO_INFORMATION = 'NO_INFORMATION';
    public const ERROR = 'ERROR';
    /** user cancelled a pending clarification or gave up ("I don't know") - no sources, no footer */
    public const NOTICE = 'NOTICE';

    /** The exact, application-owned no-information text */
    public const NO_INFORMATION_TEXT = 'The Wiki does not contain information on that topic.';
    /** The exact, application-owned answer footer */
    public const FOOTER_TEXT = 'Please see the following articles for more information:';
}
