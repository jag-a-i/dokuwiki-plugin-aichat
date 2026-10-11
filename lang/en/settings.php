<?php
/**
 * english language file for aichat plugin
 *
 * @author Andreas Gohr <gohr@cosmocode.de>
 */


$lang['chatmodel'] = 'The 🧠 model to use for chat completion. Configure required credentials below.';
$lang['rephrasemodel'] = 'The 🧠 model to use for rephrasing questions. Configure required credentials below.';
$lang['embedmodel'] = 'The 🧠 model to use for text embedding. Configure required credentials below.<br>🔄 You need to rebuild the vector storage when changing this setting.';
$lang['storage'] = 'Which 📥 vector storage to use. Configure required credentials below.<br>🔄 You need to rebuild the vector storage when changing this setting.';
$lang['customprompt'] = 'A custom prompt that is added to the prompt used by this plugin when querying the AI model. For consistency, it should be in English.';

$lang['openai_apikey'] = '🧠 <b>OpenAI</b> API key';
$lang['openai_org'] = '🧠 <b>OpenAI</b> Organization ID (if any)';
$lang['gemini_apikey'] = '🧠 Google <b>Gemini</b> API key';
$lang['anthropic_apikey'] = '🧠 <b>Anthropic</b> API key';
$lang['mistral_apikey'] = '🧠 <b>Mistral</b> API key';
$lang['voyageai_apikey'] = '🧠 <b>Voyage AI</b> API key';
$lang['reka_apikey'] = '🧠 <b>Reka</b> API key';
$lang['groq_apikey'] = '🧠 <b>Groq</b> API key';
$lang['ollama_apiurl'] = '🧠 <b>Ollama</b> base URL';
$lang['ollama_apikey'] = '🧠 <b>Ollama</b> API key (optional)';
$lang['generic_apikey'] = '🧠 <b>Generic</b> (OpenAI compatible) API key';
$lang['generic_apiurl'] = '🧠 <b>Generic</b> (OpenAI compatible) API URL';

$lang['pinecone_apikey'] = '📥 <b>Pinecone</b> API key';
$lang['pinecone_baseurl'] = '📥 <b>Pinecone</b> base URL';

$lang['chroma_baseurl'] = '📥 <b>Chroma</b> base URL';
$lang['chroma_apikey'] = '📥 <b>Chroma</b> API key. Empty if no authentication is required';
$lang['chroma_tenant'] = '📥 <b>Chroma</b> tenant name';
$lang['chroma_database'] = '📥 <b>Chroma</b> database name';
$lang['chroma_collection'] = '📥 <b>Chroma</b> collection. Will be created.';

$lang['qdrant_baseurl'] = '📥 <b>Qdrant</b> base URL';
$lang['qdrant_apikey'] = '📥 <b>Qdrant</b> API key. Empty if no authentication is required';
$lang['qdrant_collection'] = '📥 <b>Qdrant</b> collection. Will be created.';

$lang['chunkSize'] = 'Maximum number of tokens per chunk.<br>🔄 You need to rebuild the vector storage when changing this setting.';
$lang['similarityThreshold'] = 'Minimum similarity threshold when selecting sources for a question. 0-100.';
$lang['contextChunks'] = 'Maximum number of chunks to send to the AI model for context.';
$lang['fullpagecontext'] = 'Always send the full page content for each matching chunk as context for the AI model. This will not apply any token limits and may result in large, expensive requests. Use with large context models only! The number you configure here is the number of pages sent at most. It can not be larger than contextChunks. Leave at 0 to disable and use the chunks only.';
$lang['chatHistory'] = 'Number of previous chat messages to consider for context in the conversation.';
$lang['rephraseHistory'] = 'Number of previous chat messages to consider for context when rephrasing a question. Set to 0 to disable rephrasing.';

$lang['logging'] = 'Log all questions and answers. Use the <a href="?do=admin&page=logviewer&facility=aichat">Log Viewer</a> to access.';
$lang['restrict'] = 'Restrict access to these users and groups (comma separated). Leave empty to allow all users.';
$lang['skipRegex'] = 'Skip indexing pages matching this regular expression (no delimiters).<br>🔄 You need to rebuild the vector storage when changing this setting.';
$lang['matchRegex'] = 'Only index pages matching this regular expression (no delimiters).<br>🔄 You need to rebuild the vector storage when changing this setting.';
$lang['ignoreRegex'] = 'Ignore parts of the page content matching this regular expression (no delimiters).<br>🔄 You need to rebuild the vector storage when changing this setting.';
$lang['preferUIlanguage'] = 'How to work with multilingual wikis? (Requires the translation plugin)';

$lang['preferUIlanguage_o_0'] = 'Guess language, use all sources';
$lang['preferUIlanguage_o_1'] = 'Prefer UI language, use all sources';
$lang['preferUIlanguage_o_2'] = 'Prefer UI language, same language sources only';

$lang['feedback'] = 'Show Helpful / Not helpful buttons on answers. Votes are stored locally with the response metadata.';
$lang['diagnostics'] = 'Keep local metadata-only diagnostics per response (outcome, timings, counts, sanitized error category). Never stores questions, answers, page content, IP addresses or user names.';
$lang['diagnostics_retention'] = 'Days to keep local response records (diagnostics and feedback). Older records are deleted automatically.';
$lang['telemetry'] = 'Trace export backend. "off" sends nothing.';
$lang['telemetry_o_off'] = 'off';
$lang['telemetry_o_langfuse'] = 'Langfuse (OpenTelemetry endpoint)';
$lang['telemetry_o_otlp'] = 'Generic OTLP/HTTP JSON collector';
$lang['telemetry_endpoint'] = 'Langfuse base URL (e.g. https://langfuse.example.internal) or full OTLP traces URL (…/v1/traces).';
$lang['telemetry_langfuse_public'] = 'Langfuse public key (pk-lf-…)';
$lang['telemetry_langfuse_secret'] = 'Langfuse secret key (sk-lf-…)';
$lang['telemetry_otlp_authorization'] = 'Optional Authorization header value for the generic OTLP collector.';
$lang['telemetry_capture'] = 'PRIVACY: additionally export content (secret-redacted on a best-effort basis). Leave all unchecked for metadata only.';
$lang['telemetry_timeout'] = 'Export timeout per attempt in seconds.';
$lang['telemetry_retries'] = 'Export retries after a failed attempt.';
$lang['telemetry_spool_max'] = 'Maximum number of undelivered traces kept for a later retry (0 = drop).';
$lang['telemetry_spool_days'] = 'Days to keep undelivered traces before deleting them.';
