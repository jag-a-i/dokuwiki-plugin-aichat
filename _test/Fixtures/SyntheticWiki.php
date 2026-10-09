<?php

namespace dokuwiki\plugin\aichat\test\Fixtures;

use dokuwiki\plugin\aichat\Chunk;

/**
 * Synthetic wiki: pages with text, per-user read ACL and a naive keyword retriever.
 * All content is invented for tests.
 */
class SyntheticWiki
{
    public array $pages = [
        'it:email:password' => 'Change your E-Mail password: open webmail settings, choose Security, enter old and new password.',
        'it:vpn:password' => 'Change your VPN password: run `vpnctl passwd` on the VPN portal https://vpn.example.invalid/self-service and confirm.',
        'it:crm:password' => 'Change your CRM password: in the CRM click your avatar, Profile, Change password.',
        'it:hr:password' => 'HR portal password change: confidential HR procedure. HIDDEN-HR-MARKER.',
        'it:email:password-webmail' => 'Webmail password change details for the E-Mail system: Security tab, new password twice.',
        'kitchen:coffee' => 'The coffee machine is descaled every Friday.',
    ];
    /** page => list of users who may NOT read it */
    public array $deny = ['it:hr:password' => ['alice', 'bob']];
    public string $user = 'alice';

    public function retriever(): callable
    {
        return function (string $query): array {
            $terms = array_filter(preg_split('/\W+/', strtolower($query)), fn($t) => strlen($t) >= 3);
            $out = [];
            foreach ($this->pages as $page => $text) {
                if (in_array($this->user, $this->deny[$page] ?? [], true)) continue; // ACL as in Embeddings
                $hits = 0;
                foreach ($terms as $t) if (str_contains(strtolower($text . ' ' . $page), $t)) $hits++;
                if ($hits) $out[] = new Chunk($page, crc32($page), $text, [], 'en', 1, $hits);
            }
            usort($out, fn($a, $b) => $b->getScore() <=> $a->getScore());
            return $out;
        };
    }
}
