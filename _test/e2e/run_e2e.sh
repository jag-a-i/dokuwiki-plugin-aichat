#!/usr/bin/env bash
# Browser end-to-end test of the chat UI against a throw-away synthetic DokuWiki site.
# Real plugin code, real Generic model classes and SQLite storage; only the OpenAI-compatible
# endpoint is a local synthetic mock (mock_llm.py). No real model, no external network.
#
# Usage: _test/e2e/run_e2e.sh /path/to/dokuwiki-source /path/to/sqlite-plugin [workdir]
# Needs: php (8.1+, pdo_sqlite), python3, `pip install playwright && python3 -m playwright install chromium`
set -euo pipefail
DW=$(realpath "$1"); SQLITE=$(realpath "$2"); WORK=${3:-$(mktemp -d)}
PLUGIN=$(realpath "$(dirname "$0")/../..")
HERE=$(realpath "$(dirname "$0")")
SITE="$WORK/site"; rm -rf "$SITE"; mkdir -p "$SITE"
(cd "$DW" && tar --exclude=./_test --exclude=./.git --exclude=./lib/plugins/aichat --exclude=./lib/plugins/sqlite -cf - .) | (cd "$SITE" && tar xf -)
ln -s "$PLUGIN" "$SITE/lib/plugins/aichat"; ln -s "$SQLITE" "$SITE/lib/plugins/sqlite"
HASH=$(php -r 'echo password_hash("synthetic-alice-pw", PASSWORD_BCRYPT);')
echo "alice:$HASH:Alice Test:alice@example.invalid:user" > "$SITE/conf/users.auth.php"
printf '*\t@ALL\t0\n*\t@user\t8\nit:hr:*\t@user\t0\n' > "$SITE/conf/acl.auth.php"
cat > "$SITE/conf/local.php" <<'PHP'
<?php
$conf['useacl'] = 1; $conf['authtype'] = 'authplain'; $conf['superuser'] = '@admin'; $conf['userewrite'] = 0;
$conf['plugin']['aichat']['chatmodel'] = 'Generic synthetic-chat';
$conf['plugin']['aichat']['rephrasemodel'] = 'Generic synthetic-chat';
$conf['plugin']['aichat']['embedmodel'] = 'Generic synthetic-embed';
$conf['plugin']['aichat']['generic_apiurl'] = 'http://127.0.0.1:8099/v1';
$conf['plugin']['aichat']['generic_apikey'] = 'placeholder-not-a-secret';
$conf['plugin']['aichat']['storage'] = 'SQLite';
$conf['plugin']['aichat']['similarityThreshold'] = 20;
// trace export to the LOCAL mock only (stand-in for a self-hosted Langfuse); placeholder keys
$conf['plugin']['aichat']['telemetry'] = 'langfuse';
$conf['plugin']['aichat']['telemetry_endpoint'] = 'http://127.0.0.1:8099';
$conf['plugin']['aichat']['telemetry_langfuse_public'] = 'pk-lf-placeholder';
$conf['plugin']['aichat']['telemetry_langfuse_secret'] = 'sk-lf-placeholder';
PHP
NOTE=$'\nThis synthetic page exists only for automated testing. It contains no real procedures, accounts or personal data of any kind.'
mk(){ mkdir -p "$SITE/data/pages/$(dirname "$1")"; printf '%s\n%s\n' "$2" "$NOTE" > "$SITE/data/pages/$1.txt"; }
mk it/email/password $'====== E-Mail password ======\nTo change your E-Mail password open the webmail settings, choose Security and enter the old and the new password.'
mk it/vpn/password $'====== VPN password ======\nTo change your VPN password run `vpnctl passwd` on the VPN portal https://vpn.example.invalid/self-service and confirm.'
mk it/crm/password $'====== CRM password ======\nTo change your CRM password click your avatar in the CRM, open Profile and choose Change password.'
mk it/hr/password $'====== HR portal password ======\nHR password change procedure. HIDDEN-HR-MARKER.'
mk kitchen/coffee $'====== Coffee machine ======\nThe coffee machine is descaled every Friday.'
printf '====== Synthetic Test Wiki ======\n<aichat>Hello from the synthetic test wiki</aichat>\n' > "$SITE/data/pages/start.txt"

# refuse to run against stale servers from an earlier run (they would silently receive the traffic)
for port in 8088 8099; do
  if php -r "exit(@fsockopen('127.0.0.1', $port) ? 0 : 1);"; then echo "port $port already in use - stop the old server first" >&2; exit 3; fi
done
: > "$WORK/requests.jsonl"   # fresh request log per run
python3 "$HERE/mock_llm.py" 8099 "$WORK/requests.jsonl" & MOCK=$!
php -S 127.0.0.1:8088 -t "$SITE" > "$WORK/php.log" 2>&1 & PHPSRV=$!
trap 'kill $MOCK $PHPSRV 2>/dev/null' EXIT
sleep 1
(cd "$SITE" && php bin/indexer.php -q && php bin/plugin.php aichat embed --clear > "$WORK/embed.log" 2>&1)
find "$SITE/data/cache" -name '*.js' -delete   # never test a stale combined JS bundle
python3 "$HERE/test_ui_e2e.py" http://127.0.0.1:8088 "$WORK"
python3 "$HERE/check_otel.py" "$WORK/requests.jsonl"
