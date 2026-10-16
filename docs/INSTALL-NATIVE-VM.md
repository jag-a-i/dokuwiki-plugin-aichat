# AIChat nonstreaming upgrade on native DokuWiki

This package is a conventional DokuWiki plugin upgrade. It includes the existing grounded conversation, safe browser error handling, clarification fixes and an optional Generic-model thinking switch. It does not implement streaming. It adds no service, worker, container, cron job or PHP extension requirement.

## Compatibility before installation

- PHP **8.1 or newer**, with `mbstring` and `ctype`. `plugin.info.txt` and the bundled Composer platform check both require PHP 8.1; the root composer.json's looser PHP declaration does not lower that requirement.
- A compatible DokuWiki installation and permission to update extensions. The workplace DokuWiki version, PHP handler and webserver configuration must be checked locally; home Docker testing does not establish their compatibility.
- Preserve the existing AIChat model and storage configuration. A first installation still needs access to its configured model and storage endpoints. The SQLite backend needs the DokuWiki SQLite plugin and PDO SQLite; another configured backend has its own existing requirements.
- Keep the bundled `vendor/` directory. No Composer, Git, Node, Python or Docker command is needed on the target VM.
- DokuWiki's configured data and metadata directories must retain their existing webserver write permissions. Use the VM's existing account and permissions; do not copy Unraid's UID/GID values.

The normal HTTP path uses DokuWiki's HTTP client. This release does not add a cURL requirement or change PHP/nginx/Apache timeouts. It cannot guarantee an unlimited response duration: model/client, PHP, webserver or proxy limits still apply. An interrupted request is reported safely; it is not automatically resubmitted by the browser.

## Package and configuration

The ZIP has exactly one top-level directory, `aichat/`. Its contents belong in `DOKUWIKI_ROOT/lib/plugins/aichat/`, without an extra nested `aichat` directory. It includes runtime code, language/configuration defaults, bundled dependencies and licenses, and this guide. Development tests, Git metadata, streaming drafts, private traces and local.php are excluded.

The accompanying release report identifies the exact published source commit, ZIP SHA256 and per-file manifest. Verify those before uploading. Existing wiki configuration, accounts, pages, media, embeddings and indexes are not part of the package and must remain in place.

`generic_disable_thinking` defaults to off. Enable it only for an endpoint that supports `chat_template_kwargs.enable_thinking`; it affects chat/rephrasing, not embedding payloads. Keep existing settings during an upgrade.

Telemetry remains optional and off by default. Configured installations retain their own endpoint, credentials and capture settings. Do not copy the home TEST wiki's local.php, endpoints or credentials into a workplace installation. Completed question/answer capture depends on the existing explicit capture policy and content limit; streaming/partial-generation capture is not added by this release.

## Installation

1. Record the current plugin version, DokuWiki/PHP versions, configured storage backend, and the absolute installed plugin path. Confirm that this is the intended wiki. If extension updates are restricted, give the package and this guide to the administrator; the plugin cannot bypass those permissions.
2. Create a backup **outside the wiki webroot** of the current `lib/plugins/aichat/` directory and wiki configuration using your normal backup process. Retain the existing wiki-data/storage backup. Record the backup's absolute path and verify it can be read or extracted; compare its files/checksums to the originals. Record current ownership/permissions. Keep backups private because wiki configuration may contain credentials.
3. Save the old package/version and the new ZIP SHA256 with that backup. Identify the rollback source before replacing any file. There is no required reindex, embedding rebuild, collection deletion or data migration step for these changes.
4. Use the site's existing extension upgrade process to upload this ZIP. If the administrator installs manually, extract to a new staging directory, verify its `aichat/plugin.info.txt` and `aichat/vendor/autoload.php`, compare the old plugin for local customizations, and replace only the installed plugin directory. Preserve customizations deliberately; do not overwrite them silently. Keep the complete verified backup. Do not merge two plugin versions by retaining obsolete code files.
5. Preserve the VM's existing ownership/permissions. Do not edit global PHP/webserver configuration or copy the home TEST configuration. A service restart is not a routine installation step. If stale bytecode/assets persist, follow the site's existing cache-refresh procedure with its administrator.
6. Verify the wiki loads, then test a grounded question, an ambiguous question followed by a choice, and a question absent from the wiki. Check source links, safe error display and PHP/application logs. Verify existing model/storage access. If telemetry is configured, verify an actual completed trace and its configured question/answer capture; an HTTP acceptance response alone does not prove content ingestion.
7. Record the installed commit/package hash and results. Do not describe the workplace installation as verified until these checks pass there. Home TEST is supporting evidence only.

If a clarification choice fails after the server consumed its single-use token, ask the original question again. Retrying only the old choice number can produce an expired-question notice. This release preserves that existing safe state behavior.

## Rollback

If the upgrade breaks the wiki or its chat workflow:

1. Record the failure and stop submitting further test turns.
2. Have the same authorized operator restore the verified old plugin directory from the recorded private backup to the exact original `lib/plugins/aichat/` path. Keep the failed new plugin copy separately for diagnosis; avoid mixing old and new files.
3. Restore recorded ownership/permissions. This procedure changes only the plugin. Restore wiki configuration only if it was changed during installation, using the verified matching configuration backup. Do not restore or clear pages, media, indexes or external collections as a routine plugin rollback.
4. Follow the site's existing cache-refresh procedure if needed. Recheck wiki loading and the previously working chat workflow, then record the restored version/hash and results.

No workplace deployment or administrator configuration change is performed by preparing this package. Streaming remains paused and excluded.
