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

## Known limits
- Secret detection is pattern based ("password is X", common token formats). A bare password
  typed without context is not detected.
- `lang/en/noanswer.prompt` is no longer used. `decide.prompt` exists in English only; other
  languages fall back to English instructions (answers still follow the language setting).
- Mocked tests do not prove that a given local model follows the decision format.
