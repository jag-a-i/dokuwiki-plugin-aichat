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

Findings of this pass:
- FIXED: remote `similar` sent the query unredacted to the embedding endpoint (`ask` already redacted).
- FIXED (packaging): upstream `.gitattributes` export-ignores `_test/`; earlier checkpoint zips made with
  `git archive` therefore contained no tests. The review package now includes a full source archive.
- OPEN/DOCUMENTED: no rate limiting for chat or feedback requests (as upstream). Consider web-server limits.
- OPEN/DOCUMENTED: with `telemetry_capture` enabled, undelivered traces containing that content are kept in
  `data/meta/aichat/spool/` (bounded, max age); default capture is off.
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
