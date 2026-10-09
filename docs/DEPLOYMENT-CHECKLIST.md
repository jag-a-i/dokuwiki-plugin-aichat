# Deployment checklist (decisions and checks still open)

Unknown at handoff - must be confirmed by the operator:
- [ ] Installed plugin revision equals `dd5f504` or local changes are reviewed (INSTALL-STAGING.md step 2)
- [ ] Production PHP version (>= 8.1) and DokuWiki release; PHP-FPM or mod_php (affects when trace export runs)
- [ ] Non-default plugin settings: fullpagecontext, chatHistory, rephraseHistory, similarityThreshold,
      contextChunks, customprompt, preferUIlanguage, restrict, logging
- [ ] Guest access to the chat (`restrict`): guests get clarification and feedback bound to their session
- [ ] Session storage works for AJAX requests (pending clarifications are kept in the PHP session)
- [ ] Exact local model ids on llama-swap; behaviour of Gemma 4B with the new decision prompt (run aichat_eval)
- [ ] Whether the current model follows the DECISION format reliably (first_try_parse metric); malformed
      output becomes a safe ERROR, not an answer
- [ ] Languages used in the wiki: decide.prompt exists in English only; answers follow the language setting
- [ ] Langfuse: endpoint, keys, and whether any content capture is approved (default: metadata only)
- [ ] Retention: diagnostics_retention days; Langfuse-side retention is configured in Langfuse
- [ ] Existing `logging` setting: if on, it still records answers, IP and user (pre-existing; propose migration)
- [ ] Staging verification steps 1-6 passed with real content and real ACLs
- [ ] Backup taken and rollback rehearsed on staging
