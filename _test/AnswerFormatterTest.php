<?php

namespace dokuwiki\plugin\aichat\test;

use dokuwiki\plugin\aichat\Conversation\AnswerFormatter;
use dokuwiki\plugin\aichat\Conversation\Outcome;

/**
 * @group plugin_aichat
 * @group plugins
 */
class AnswerFormatterTest extends \DokuWikiTest
{
    protected function formatter(): AnswerFormatter
    {
        return new AnswerFormatter(static function (string $url) {
            return preg_match('/doku\.php\?id=([^&#]+)/', $url, $m) ? $m[1] : null;
        });
    }

    // 12. footer exactly once; duplicate model footers and source lists removed
    public function testFooterOnceAndDuplicatesRemoved()
    {
        $in = "Step 1: do this [S1].\n\nPlease see the following articles for more information:\n\n" .
            "Sources:\n- [VPN](/doku.php?id=it:vpn:password)\n- it:vpn:password\n\nPlease see the following articles for more information:";
        $out = $this->formatter()->format($in, ['it:vpn:password']);
        $this->assertSame("Step 1: do this.\n\n" . Outcome::FOOTER_TEXT, $out);
    }

    // 13. legitimate commands, URLs and code are preserved; fabricated wiki links unlinked
    public function testProceduralContentPreserved()
    {
        $in = "1. Run `vpnctl passwd --user [S1]`\n2. Open https://vpn.example.invalid/self-service\n" .
            "3. See [the portal](https://vpn.example.invalid/help) and [fake](/doku.php?id=secret:page)\n\n" .
            "```\ncurl https://api.example.invalid/reset\n# Sources:\n```";
        $out = $this->formatter()->format($in, ['it:vpn:password']);
        $this->assertStringContainsString('`vpnctl passwd --user [S1]`', $out);
        $this->assertStringContainsString('https://vpn.example.invalid/self-service', $out);
        $this->assertStringContainsString('[the portal](https://vpn.example.invalid/help)', $out);
        $this->assertStringContainsString(' and fake', $out);
        $this->assertStringNotContainsString('secret:page', $out);
        $this->assertStringContainsString("```\ncurl https://api.example.invalid/reset\n# Sources:\n```", $out);
        $this->assertSame(1, substr_count($out, Outcome::FOOTER_TEXT));
    }

    public function testLinkListWithoutHeaderIsKept()
    {
        $in = "Use one of these portals:\n- https://a.example.invalid\n- https://b.example.invalid";
        $out = $this->formatter()->format($in, []);
        $this->assertStringContainsString('- https://b.example.invalid', $out);
    }
}
