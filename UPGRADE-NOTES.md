# Grounded clarification upgrade - compatibility notes (work in progress)

Base: cosmocode/dokuwiki-plugin-aichat dd5f504 (fork jag-a-i). Not yet production-verified;
the installed production revision and settings are unknown. Tested only in a sandbox
(PHP 8.3, DokuWiki 2026-07-14c, sqlite plugin 2cd0915) with mocked models and retrieval.

## Web chat (lib/exe/ajax.php?call=aichat)
- Response keeps `question`, `answer` (HTML), `sources`; adds `meta` with `v: 1`:
  `outcome` (ANSWER | CLARIFY | NO_INFORMATION | NOTICE | ERROR), `options`, `pendingId`,
  `conversationId`, `responseId`, `correlationId`, `warning`.
- NOTICE is new: cancel, "I don't know", too many clarification rounds, or a bare choice
  ("2") whose pending question is expired / from another tab or user. It has no sources and
  no footer. Clients that ignore `meta` show it as a normal message with an empty source list.
- `sources` is only filled for ANSWER. The footer line is added by the server, once, for ANSWER only.
- New POST fields: `conversation`, `pending`, `sectok`. Logged-in users must send a valid
  security token (taken from `JSINFO.plugin_aichat.sectok`, set per request, not page-cached).
  Anonymous users follow DokuWiki's convention (no token); access stays governed by `restrict`.
- Pending clarification state lives in the PHP session under `plugin_aichat`, bound to
  user + session id, 15 min TTL, max 10 conversations, max 5 options. The session is reopened
  only to write and closed immediately; it is never held across model/vector calls.
- Client history is untrusted: only the last 6 [question, answer] pairs are used, stripped of
  HTML, footer and detectable secrets, size-capped, and only as conversational context.
- Old sessionStorage rows (no meta) replay unchanged; no footer is appended to them.

## Remote API (aichat.ask / aichat.similar)
- Signatures and return types unchanged. No interactive clarification (documented limitation).
- ask: empty authorized context now returns exactly "The Wiki does not contain information on
  that topic." without a model call (previously a model-generated message hinting at missing
  permissions). Volunteered secrets in the query are redacted before use.
- Service failures raise RemoteException code 112 "AI chat service error. Reference: <id>"
  instead of the raw exception text.

## Errors and logging
- New error logging writes only category, correlation id and exception class to DokuWiki's
  error log; exception messages are never logged by the new code.
- The pre-existing opt-in `logging` option is unchanged in format (it still stores answers,
  IP and username when enabled - a known privacy issue, see the separate migration proposal).
  It now logs the redacted question and nothing for ERROR outcomes.

## Clarification choices and option grounding
- A chosen option is answered only from that option's own pages, fetched again with the current
  read ACL (`Embeddings::getPageChunks`), independent of search ranking. If none of them is still
  readable or present, the answer is the exact no-information text without a model call; other
  procedures are never used as a fallback.
- Options are merged only when their normalized names match (case, punctuation and generic words
  such as "account", "password", "login" ignored). Options citing the same page stay separate,
  because one page may document several systems. Different aliases for the same system
  (e.g. "Webmail" vs "E-Mail") are not detected in code and rely on the prompt instructions.

## Session state concurrency
- Every pending-state write is a per-conversation compare-and-swap on the token id, applied to the
  fresh session data loaded under the session lock: consume (single use), put (only if the slot
  still holds what this request saw) and clear. Overlapping requests for other conversations are
  kept; a token presented by two requests is used once (the second gets NOTICE without a model
  call); a stale request can neither resurrect a consumed token nor delete a newer one.
- If the session login changed while a request was running (logout, re-login, user switch), the
  late write is refused. Setups that do not keep the login in the session (SSO, HTTP auth) are
  unaffected because fresh and start-of-request values are compared.
- The session is reopened only for the write itself and closed immediately; it is never held
  across model or vector store calls.

## Follow-up replies with negation
- "not VPN", "not 2", "no 2", "neither E-Mail nor CRM", "not E-Mail, VPN or CRM" reject options;
  a rejected option is never selected. Without further text the remaining options are offered
  again (or a generic question if none remain). With a correction ("not VPN, my personal
  account") the described text is searched again, excluding pages that only support rejected
  options. "the CRM one, not VPN" selects CRM. "No. 2" (with a dot) means number 2.
- Conservative fallback: any negative/corrective word (not, don't, isn't, wrong, never, nicht, ...)
  together with an option name is never read as choosing that option ("I don't mean VPN",
  "VPN is not it", "VPN was wrong"). With nothing else in the reply the remaining (re-authorized)
  options are offered again; with more text the full reply is searched as free text, never scoped
  to an offered option. A rare wrong rejection ("VPN, I never use the others") costs one more
  question instead of answering a wrong procedure. Curly apostrophes are normalized.
- Detection is English/German keyword based (not, no, isn't, without, except, instead of,
  rather than, neither/nor, nicht, kein). Remaining options are re-authorized before they are shown
  again: each option's pages are fetched with the current read ACL; options without a readable page
  are dropped and stored pages are reduced to readable ones. If none remains, a generic question is
  asked without a menu. Other phrasings of rejection may be read as free text,
  which is searched again rather than guessed.

## Browser end-to-end test
- `_test/e2e/run_e2e.sh <dokuwiki-src> <sqlite-plugin>` builds a throw-away synthetic site and drives
  the real UI in headless Chromium (Playwright for Python) against a local synthetic
  OpenAI-compatible mock. It purges DokuWiki's JS cache first: the combined bundle is cached and
  `script.js` pulls in `script/AIChatChat.js` via an include, so a stale bundle can hide JS changes.

## Pre-existing upstream issues observed (not changed here)
- `SQLiteStorage::createLanguageClusters()` returns inside an open transaction when there are no
  embeddings; the following VACUUM then fails ("cannot VACUUM from within a transaction").
- Pages smaller than 150 bytes are never embedded (`Embeddings::createNewIndex`). Short wiki pages
  are therefore invisible to the chat.

## Feedback (Helpful / Not helpful)
- Shown on ANSWER responses only (`meta.feedback` = true). AJAX call `aichat_feedback`, POST
  `responseId`, `vote` (helpful | not_helpful), optional `category` (wrong_answer,
  missing_information, wrong_source, unclear; only with not_helpful), `sectok`.
- The response id is server-issued and bound to a keyed hash of the user (or guest session);
  another user's id answers 404 (same as unknown ids, so ids cannot be probed). One vote per
  response; changing it overwrites. Free-text feedback is not implemented (off by design).
- Guests: allowed only if guests may use the chat (`restrict`) and have a session; bound to that
  session. Disabled by `feedback = 0`.

## Local diagnostics (metadata only)
- One JSON file per response in `data/meta/aichat/responses/` (id, time, outcome, owner hash,
  chat model name, config revision hash, source/option counts, per-stage timings, sanitized error
  category, trace id). Never questions, answers, retrieved text, page ids, IPs or user names.
- Finite retention: `diagnostics_retention` days (default 30), cleanup at most daily on write.
  `diagnostics = 0` keeps only what feedback needs (id, time, owner hash, outcome).
- Storage failures are swallowed; they never affect the chat. Historical `logging` files are untouched.

## Trace export (Langfuse first-class, pluggable)
- `telemetry` = off (default) | langfuse | otlp. Nothing is sent unless configured.
- Langfuse: OTLP/HTTP JSON to `{telemetry_endpoint}/api/public/otel/v1/traces`, Basic auth from
  `telemetry_langfuse_public`/`telemetry_langfuse_secret`, header `x-langfuse-ingestion-version: 4`
  as described in the current official docs (langfuse.com/docs/opentelemetry, checked Oct 2026).
  The deprecated `/api/public/ingestion` API is not used.
- Langfuse version compatibility: requires a CURRENT Langfuse that accepts OTLP over HTTP with a JSON
  body and maps `langfuse.*` attributes. The minimum self-hosted version is NOT verified - the first
  OTel endpoint (v3.22.0) only decoded protobuf, so "has an OTel endpoint" is not sufficient. This
  exporter was never tested against a real Langfuse instance; the E2E stand-in only proves the request
  shape (path, headers, JSON body). Before relying on export run the preflight:
  `bin/plugin.php aichat_telemetry --yes` - it sends ONE synthetic metadata-only trace and explains
  the response (2xx accepted -> confirm in the Langfuse UI that the trace and attributes appear;
  400/415 -> endpoint does not accept OTLP/JSON, upgrade; 404 -> no OTLP endpoint or wrong URL;
  401/403 -> keys; 3xx -> redirect refused; 0 -> unreachable/TLS). Protobuf is not implemented.
- One trace per chat turn, root span `aichat.turn` with child spans: rephrase, followup_resolution,
  retrieval (ACL-filtered inside Embeddings), acl_recheck (chosen pages), clarify_decision,
  model_call (Langfuse generation, model name, total-token delta or `usage.available=false`),
  render, error (category only). `langfuse.session.id` = per-tab conversation id; trace metadata
  carries outcome, correlation id and response id, propagated to every span.
- Metadata only by default. `telemetry_capture` (question, answer, context) is an explicit, separate
  privacy decision; captured text is secret-redacted best-effort. User names, IPs and credentials
  are never exported.
- Bounded: `telemetry_timeout` seconds per attempt (1-10), `telemetry_retries` (0-2; 4xx except 429
  is not retried); failed traces go to a spool (`data/meta/aichat/spool/<namespace>/`, max
  `telemetry_spool_max` files, `telemetry_spool_days` days) and up to 2 are re-sent after the next
  successful export.
- OTLP partial success (opentelemetry.io/docs/specs/otlp/#partial-success-1): a 2xx response body with
  `partialSuccess.rejectedSpans > 0` (lowerCamelCase or snake_case, int64 as string accepted) is
  recorded as `result=partial` with the rejected count; the batch is not retried or spooled (that would
  duplicate the accepted spans). The server's `errorMessage` is not stored. Empty or non-JSON 2xx
  bodies count as accepted but are flagged (`response=empty|malformed`); the preflight reports partial
  acceptance as a failure. Responses are read up to 64 KB. Drops are reported: the local response
  record gets a sanitized `export` status such as `partial:rejected=2` (current trace) or
  `sent;flushed_partial=1:rejected=4` (partially accepted spooled batches re-sent in that request);
  partially accepted batches are never retried.
- Spool provenance: the namespace is a one-way hash of backend, endpoint URL, credential identity and
  capture policy (no URL or key in clear text on disk). The URL used is the EXACT effective request
  URL of the selected backend (the same function builds the exporter's URL): Langfuse base URLs share a
  namespace only if they yield the identical ingest URL; generic OTLP URLs are used as-is, so
  `/v1/traces` and `/v1/traces/` are different destinations. Only scheme and host are case-normalized;
  port, path, trailing slash and query are compared exactly (they can be case-sensitive, e.g. tenant or
  project selectors); an empty path equals "/".
- Endpoint URLs with embedded credentials (`https://user:pass@host`) are rejected (export stays off for
  that configuration; the preflight explains why). Put secrets only into the key/Authorization settings. Queued traces are ONLY re-sent to exactly the
  same combination. After changing the endpoint, project keys, backend or `telemetry_capture`, older
  backlogs are never sent anywhere (no automatic migration); they are deleted once older than
  `telemetry_spool_days`, at most 5 obsolete namespaces are kept, and spool files from earlier
  plugin versions (no provenance) are never sent and expire the same way. Retention cleanup also runs
  while export is off. Operator option: delete `data/meta/aichat/spool/` to discard all backlogs
  immediately; restoring the previous configuration within the retention period resumes delivery of
  that configuration's own backlog. Export runs after the answer was sent (`fastcgi_finish_request` under FPM);
  with mod_php the request still finishes the export before the worker is freed.
- Redirects are never followed (security): DokuWiki's HTTP client would otherwise re-send the
  Authorization header and, for 307/308, the trace body to whatever host a redirect names
  (including http:// downgrades). Any 3xx is a non-retryable failure; only http(s) endpoint URLs are
  accepted. Configure the final endpoint URL directly (no redirecting proxies or http->https redirects).
- Debug output is always off for telemetry requests: with `allowdebug` on, DokuWiki's HTTP client
  would dump requests (incl. the Authorization header and trace body) when `?httpdebug` or a
  Referer containing `httpdebug` is present. The exporter uses the base HTTPClient with
  `debug = false` and DokuWiki's proxy settings; it does not trigger HTTPCLIENT_REQUEST_SEND, so
  other plugins never see the credential headers.
- Other backends: `ExporterFactory::register('name', fn($conf, $http, $spool) => new MyExporter())`
  with an `ExporterInterface` implementation; no chat code changes needed. `otlp` targets any
  OpenTelemetry collector.
- Verifying delivery: point `telemetry_endpoint` at the instance, send one chat question, open the
  trace in Langfuse (filter by session id = `meta.conversationId`, or search the correlation id).
  `_test/e2e/check_otel.py` shows the checks done against a local stand-in (transport shape only,
  not Langfuse behaviour).
- Retention of exported data is the responsibility of the receiving system.

## Known limits
- Secret detection is pattern based ("password is X", common token formats). A bare password
  typed without context is not detected.
- `lang/en/noanswer.prompt` is no longer used. `decide.prompt` exists in English only; other
  languages fall back to English instructions (answers still follow the language setting).
- Mocked tests do not prove that a given local model follows the decision format.
