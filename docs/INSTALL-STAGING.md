# Staged installation, backup and rollback

Status: **review / staging candidate. Not production-ready.** Validated only with synthetic
fixtures, mocked models and a local synthetic DokuWiki (see FEATURE-STATUS.md). Your installed
revision, PHP/DokuWiki versions and settings were not available and are NOT verified.

## 0. Prerequisites
- PHP >= 8.1 (tested 8.3.33), DokuWiki with plugin support (tested 2026-07-14c "Mort").
- Base of this change: cosmocode/dokuwiki-plugin-aichat `dd5f504` (2026-08-27). If your installed
  copy differs, compare first (step 2) - do not overwrite local modifications.
- Use a STAGING wiki with a copy of production config, not production.

## 1. Backup (staging and later production)
```bash
WIKI=/path/to/dokuwiki ; TS=$(date +%Y%m%d-%H%M%S)
tar czf aichat-plugin-backup-$TS.tgz -C "$WIKI/lib/plugins" aichat
cp "$WIKI/conf/local.php" "local.php.$TS"; cp "$WIKI/conf/plugins.local.php" "plugins.local.php.$TS" 2>/dev/null
# plugin data: SQLite storage (if used), run data, logs
tar czf aichat-data-backup-$TS.tgz -C "$WIKI/data/meta" $(cd "$WIKI/data/meta" && ls -d aichat* 2>/dev/null)
```
Qdrant collections, embeddings and model endpoints are not touched by this change (no reindex needed).

## 2. Compare with the installed copy
```bash
diff -ru --exclude=vendor "$WIKI/lib/plugins/aichat" /path/to/dd5f504-checkout > installed-vs-dd5f504.diff
```
Empty diff (apart from vendor) = safe to proceed. Otherwise review/merge your local changes first.

## 3. Install on staging
Either the Extension Manager ("Manual install" -> upload `aichat-install-*.zip`) or:
```bash
rm -rf "$WIKI/lib/plugins/aichat" && unzip aichat-install-*.zip -d "$WIKI/lib/plugins/"   # creates lib/plugins/aichat/
touch "$WIKI/conf/local.php"     # invalidates DokuWiki caches incl. the combined JS bundle
```
Existing settings in `conf/local.php` are kept; new settings get defaults (see below).

## 4. Configure (Admin -> Configuration Settings -> aichat)
New settings and defaults:
- `feedback` = on, `diagnostics` = on, `diagnostics_retention` = 30 days (local, metadata only)
- `telemetry` = off. To test Langfuse later: `telemetry=langfuse`, `telemetry_endpoint` = base URL of your
  self-hosted instance, public/secret key. Only after approving the endpoint and the captured data.
  Then run `php bin/plugin.php aichat_telemetry --yes` (one synthetic metadata-only trace) and check the
  trace in Langfuse. Compatibility with your Langfuse version is unverified until this succeeds.
- `telemetry_capture` = none (metadata only). Enabling question/answer/context export is a separate privacy decision.
Unchanged: models, endpoint, storage, Qdrant collection, thresholds, prompts other than the new `decide.prompt`.

## 5. Verify on staging
1. Ask an ambiguous question that matches several systems -> one question with choices, no sources.
2. Click a choice -> scoped answer, footer line once, only that system's source.
3. Ask a specific question -> direct answer. Ask about something absent -> exactly
   "The Wiki does not contain information on that topic."
4. Vote Helpful / Not helpful; open Admin -> "AI Chat: usage summary".
5. Optional: `php bin/plugin.php aichat_eval --dry-run oracle` (scoring self-check, no model).
6. Optional live evaluation (sends only synthetic fixture texts to your configured endpoint):
   `php bin/plugin.php aichat_eval --config eval.config.json --yes --out eval-results`.

## 6. Rollback
```bash
rm -rf "$WIKI/lib/plugins/aichat"
tar xzf aichat-plugin-backup-$TS.tgz -C "$WIKI/lib/plugins"
cp "local.php.$TS" "$WIKI/conf/local.php"     # only if settings were changed
touch "$WIKI/conf/local.php"
```
Data created by this version and safe to delete after rollback: `data/meta/aichat/responses/`
(local response records with votes) and `data/meta/aichat/spool/` (undelivered traces, one hashed
sub-directory per endpoint/credential/capture configuration; never sent to a different configuration).
Pending clarifications live only in PHP sessions and expire after 15 minutes.
Unknown new settings left in `conf/local.php` are ignored by the old version.
