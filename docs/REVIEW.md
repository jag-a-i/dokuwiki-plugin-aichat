# Skeptical self-review (author's own, not independent)

Scope: everything changed since `dd5f504`. Independent review rounds found 7 defects
(getPageContext removed, choice fallback broadening, same-page option merge, negation ignored,
session overwrite/token reuse, unauthorized labels after rejection, uncertain negatives selecting
an option); all fixed with regression tests that fail on the earlier code.

Independent review of 1d08dc4 (release-blocking, FIXED): the telemetry transport left DokuWiki's
auto-redirect on, so a 301/302/303/307/308 from the endpoint would re-send the Langfuse Basic secret /
OTLP Authorization header (and for 307/308 the trace body) to any Location host, including http://.
Now `max_redirect = 0`, non-http(s) URLs refused, 3xx fails closed without retry. Regression: real local
two-server test (redirect receiver gets no request) for all five codes on both backends plus an
https->http downgrade; 12 of 13 cases fail on the previous code.

Independent review (conditional P1, FIXED): with `allowdebug` enabled, `?httpdebug` or a Referer
containing `httpdebug` switched DokuHTTPClient into debug mode, which echoes the full request
(Authorization header, Basic secret, trace body) into the AJAX output. Verified on the previous code:
2881 bytes of output containing the header, the base64 secret and the body. Now the base HTTPClient is
used with `debug = false` (also no HTTPCLIENT_REQUEST_SEND exposure to other plugins). Regression for
both triggers on both backends; fails on the previous code. While writing it, a test-client bug
(overwriting DokuWiki's global $conf) initially made the regression pass on vulnerable code; caught by
checking against the old code, fixed, and the test now asserts allowdebug is active at export time.

Official-source review (FIXED, documentation/preflight): the earlier claim "self-hosted Langfuse >= 3.22"
was not valid for this JSON exporter (v3.22.0's OTel route only protobuf-decoded requests and lacked current
langfuse.* mappings). The claim is removed; the minimum version is now documented as unverified, a current
version accepting OTLP/HTTP JSON is required, the local stand-in is described as request-shape proof only,
and an operator preflight (`aichat_telemetry --yes`, Telemetry\Preflight) with contract tests was added.
Protobuf encoding was deliberately not implemented.

Independent review (P2 privacy, FIXED): the spool was shared and had no provenance, so after a failed export
with content capture, changing the endpoint/project/backend or narrowing capture let the next metadata-only run
flush the old captured payloads to the NEW destination with the current credentials. Now each destination +
credential + capture-policy combination has its own hashed namespace; backlogs are only flushed to exactly the
same configuration, never migrated, and expire by age (bounded number of namespaces; legacy flat files never
sent). Regression: queued PRIVATE marker under A with capture; B (other endpoint, other keys on the same
endpoint, narrowed/changed capture, other backend, other path) never receives it; positive control flushes to A.

Independent review (OTLP compliance, FIXED): every 2xx was treated as full success, so an OTLP
`partialSuccess` with rejected spans silently lost traces. The transport now returns the (bounded)
body; partial success is recorded as a sanitized count and never retried. While wiring this, the real-
transport tests caught a TypeError (closure return type `int`) that the mocked tests could not see.

Findings of this pass:
- FIXED: remote `similar` sent the query unredacted to the embedding endpoint (`ask` already redacted).
- FIXED (packaging): upstream `.gitattributes` export-ignores `_test/`; earlier checkpoint zips made with
  `git archive` therefore contained no tests. The review package now includes a full source archive.
- OPEN/DOCUMENTED: no rate limiting for chat or feedback requests (as upstream). Consider web-server limits.
- OPEN/DOCUMENTED: with `telemetry_capture` enabled, undelivered traces containing that content are kept in
  `data/meta/aichat/spool/<namespace>/` (bounded, max age, only re-sent to the same configuration); default
  capture is off.
- OPEN/DOCUMENTED: under mod_php the request waits for the trace export (timeout x (retries+1)); under FPM the
  answer is sent first.
- OPEN/DOCUMENTED: pre-existing opt-in `logging` still writes answers, IP and user name; not changed to avoid
  silently altering existing logs; a separate migration is proposed.
- OPEN/DOCUMENTED: negation/choice understanding is keyword based (English/German); uncertain replies fall back
  to re-asking or free-text search, never to selecting an option.
- OPEN/DOCUMENTED: option aliases ("Webmail" vs "E-Mail") are only merged if the model does it.
- OPEN/DOCUMENTED: mocked tests do not show that Gemma 4B (or any model) follows the decision format;
  use `aichat_eval` against the real endpoint before rollout.
- OPEN/DOCUMENTED: guests follow DokuWiki's CSRF convention (no token for anonymous users).
- Checked, no issue found: no exception messages in new logs; response/feedback files are only addressed by
  validated hex ids; owner hashes, trace ids and response ids never appear in the admin page or CSV.
