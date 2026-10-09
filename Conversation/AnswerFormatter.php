<?php

namespace dokuwiki\plugin\aichat\Conversation;

/**
 * Cleans a model answer and appends the application-owned footer.
 *
 * What is removed (outside fenced/inline code only):
 *  - inline citation markers like [S1] or [S1, S2]
 *  - model-written copies of the footer sentence and "Sources:/References:" sections
 *    consisting only of links / page ids at the END of the answer
 *  - markdown links pointing to wiki pages that are not in the permitted source set
 *    (the link text is kept, the link target is dropped)
 * What is preserved:
 *  - fenced code blocks and inline code verbatim (commands, paths, URLs inside)
 *  - external links and bare URLs that are part of the procedure text
 *  - links to permitted wiki pages
 */
class AnswerFormatter
{
    /** @var callable(string $url): ?string returns wiki page id if the URL is a wiki page link */
    protected $wikiPageFromUrl;

    public function __construct(?callable $wikiPageFromUrl = null)
    {
        $this->wikiPageFromUrl = $wikiPageFromUrl ?: static fn(string $url) => null;
    }

    /**
     * @param string $answer raw markdown answer from the model
     * @param string[] $allowedPages page ids the user may read and which back this answer
     * @return string markdown with exactly one footer line at the end
     */
    public function format(string $answer, array $allowedPages): string
    {
        $answer = str_replace(["\r\n", "\r"], "\n", $answer);

        // protect code: fenced blocks and inline code
        $protected = [];
        $answer = preg_replace_callback('/```.*?(```|$)|`[^`\n]+`/s', static function ($m) use (&$protected) {
            $key = "@@AICHATCODE" . count($protected) . "@@";
            $protected[$key] = $m[0];
            return $key;
        }, $answer);

        // citation markers
        $answer = preg_replace('/\s?\[\s*S\d{1,3}(?:\s*,\s*S\d{1,3})*\s*\]/i', '', $answer);

        // markdown links to non-permitted wiki pages -> keep text only
        $allowed = array_flip($allowedPages);
        $answer = preg_replace_callback('/\[([^\]\n]+)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', function ($m) use ($allowed) {
            $page = ($this->wikiPageFromUrl)($m[2]);
            if ($page !== null && !isset($allowed[$page])) return $m[1];
            return $m[0];
        }, $answer);

        // trailing footer copies / source sections
        $lines = explode("\n", rtrim($answer));
        while ($lines) {
            $last = trim(end($lines));
            if (
                $last === '' ||
                $this->isFooterLike($last) ||
                ($this->isLinkOnlyLine($last) && $this->hasSourceHeaderAbove($lines))
            ) {
                array_pop($lines);
                continue;
            }
            break;
        }
        $answer = implode("\n", $lines);
        // footer sentence anywhere else in the text (model repeated it mid-answer)
        $answer = preg_replace('/^[\s>*_#-]*please see the following articles for more information:?[\s*_]*$/mi', '', $answer);
        $answer = preg_replace("/\n{3,}/", "\n\n", $answer);

        $answer = strtr($answer, $protected);
        $answer = trim($answer);

        return ($answer === '' ? '' : $answer . "\n\n") . Outcome::FOOTER_TEXT;
    }

    protected function isFooterLike(string $line): bool
    {
        $l = strtolower(trim($line, " \t*_#>:-"));
        return (bool)preg_match(
            '/^(please see the following articles for more information|sources?|references?|related articles|for more information(, see)?|see also)$/',
            $l
        );
    }

    protected function isLinkOnlyLine(string $line): bool
    {
        $l = preg_replace('/^\s*(?:[-*+]|\d+[.)])\s*/', '', $line);
        return (bool)preg_match(
            '/^(\[[^\]]+\]\([^)]+\)|<?https?:\/\/\S+>?|\[\[[^\]]+\]\]|[a-z0-9_.-]+(:[a-z0-9_.-]+)+)\s*$/i',
            $l
        );
    }

    /** true if, walking up over link-only lines, we reach a footer/source header */
    protected function hasSourceHeaderAbove(array $lines): bool
    {
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $l = trim($lines[$i]);
            if ($l === '' || $this->isLinkOnlyLine($l)) continue;
            return $this->isFooterLike($l);
        }
        return false;
    }
}
