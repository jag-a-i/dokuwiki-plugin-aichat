<?php

namespace dokuwiki\plugin\aichat\Conversation;

/**
 * Best-effort detection and redaction of credentials volunteered by users.
 *
 * Limits: pattern based only. It catches "password is X", "pin: 1234", common token
 * formats (OpenAI/GitHub/AWS keys, JWTs, bearer tokens, PEM private keys). It will NOT
 * catch a bare password typed without context ("hunter2"), passphrases in prose,
 * or credentials in unusual formats.
 */
class SecretRedactor
{
    public const MASK = '[REDACTED]';

    protected const PATTERNS = [
        // keyword followed by is/was/=/: and a value
        '/\b(pass(?:word|wd|phrase|code)?|pwd|pin|secret|token|api[ _-]?key|security answer)\b(\s*(?:is|was|=|:)\s*)(["\']?)(\S{3,})\3/iu',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
        '/\bBearer\s+[A-Za-z0-9\-._~+\/]{16,}=*/',
        '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/',
        '/\bsk-[A-Za-z0-9_-]{16,}\b/',
        '/\bgh[pousr]_[A-Za-z0-9]{20,}\b/',
        '/\bAKIA[0-9A-Z]{16}\b/',
    ];

    /**
     * @param string $text
     * @return array [string $redactedText, bool $found]
     */
    public function redact(string $text): array
    {
        $found = false;
        foreach (self::PATTERNS as $i => $pattern) {
            $text = preg_replace_callback($pattern, static function ($m) use (&$found, $i) {
                $found = true;
                if ($i === 0) return $m[1] . $m[2] . self::MASK;
                return self::MASK;
            }, $text) ?? $text;
        }
        return [$text, $found];
    }
}
