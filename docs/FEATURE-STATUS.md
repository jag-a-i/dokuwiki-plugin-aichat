# Feature status

Base `dd5f504`; branch `feature/clarify-grounded-answers`. "Tested" = deterministic tests with mocked
models / synthetic fixtures unless stated otherwise. No live model, vector store or Langfuse was used.

| Area | Status |
|---|---|
| Outcomes ANSWER / CLARIFY / NO_INFORMATION / NOTICE / ERROR | implemented, tested (mocked) |
| Grounded clarification, option grounding, same-page alternatives | implemented, tested (mocked + browser E2E) |
| Follow-up: number, name, description, none, unknown, cancel, topic change, negation | implemented, tested (mocked) |
| Chosen option answered only from its own re-authorized pages | implemented, tested (mocked) |
| Re-offered options re-authorized after rejection | implemented, tested (mocked) |
| Session-bound pending state, CAS single use, logout guard | implemented, tested (real PHP sessions + real AJAX) |
| Exact no-information text without model call | implemented, tested (mocked, E2E) |
| App-owned footer, source validation, fabricated link removal | implemented, tested (mocked, E2E) |
| Safe ERROR with correlation id, sanitized logs | implemented, tested |
| Credential redaction (pattern based) | implemented, tested; limits documented |
| Remote ask/similar compatibility + safety fixes | implemented, tested (mocked) |
| History replay compatibility (old rows) | implemented, tested (browser E2E) |
| Feedback Helpful/Not helpful + categories | implemented, tested (real AJAX + browser E2E) |
| Local metadata-only diagnostics, retention | implemented, tested |
| Langfuse OTLP exporter + generic OTLP + pluggable interface | implemented, tested (mocked transport + real HTTP to local stand-in) |
| Admin summary (admin only, CSV aggregates) | implemented, tested |
| Evaluation runner + synthetic fixtures | implemented, tested with scripted models; **live Gemma / candidate: NOT RUN** |
| Live staging with real wiki content, ACLs, Qdrant, llama-swap | **NOT RUN** (no access, not authorized) |
| Real Langfuse delivery | **NOT RUN** (no endpoint approved) |
| Topic analysis of unanswered questions | deferred (needs content; only feedback categories available) |
| Free-text feedback | not implemented (off by design) |
| Translations of new strings/prompt | English only |
