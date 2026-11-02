# Limited domain UI release helper

This is a fixed nine-file presentation release. It is not a deployment transport API and cannot accept paths, commands or file contents from HTTP input. The private engine and payload remain outside the document root. The temporary launcher uses the existing application admin session and normal CSRF middleware; remove it immediately after integrity and browser acceptance.

Only the two home/domain Blade sources, three `domain-discovery.php` translation files and `domain-home-discovery.js/.css` at both public destinations are allowed. No controller, model, route, configuration, scheduler, dependency, migration, financial or supplier mutation is supported.

## Private payload interface

Save UTF-8 `payload.json` under `/home/servernetcloud/domain-ui-release-20261102`, with that directory mode `0700`, owned by the actual account UID. Use `0600` for private files where supported; they must never be group/world writable. The release account has UID 1020 in the inspected environment; reverify it before use.

```json
{
  "release": "domain-ui-20261102",
  "source_sha": "<exact 40-character source commit>",
  "account_uid": 1020,
  "entries": [
    {
      "destination": "servernet_app/resources/views/pages/home.blade.php",
      "before_sha256": "<raw server SHA-256, or absent for new files>",
      "after_sha256": "<SHA-256 of exact UTF-8 content bytes>",
      "before_mode": 420,
      "mode": 420,
      "content": "<exact source bytes represented as a JSON string>"
    }
  ],
  "protected": [
    {"destination": "servernet_app/app/Services/Domain/DomainSearch.php", "sha256": "<unchanged raw SHA-256>"}
  ]
}
```

The entries must contain all nine fixed destinations, once each. Existing views preserve the observed mode (`420` is `0644`; `384` is `0600`). New dependencies use mode `420`, `before_sha256: "absent"` and `before_mode: null`. There is no deleted source file in this release. Pin unchanged backend, route, layout and old-asset hashes using only the engine's protected allowlist.

Inject the SHA-256 of `engine.php` and the exact raw `payload.json` into the two placeholders in `launcher.php.template`. Upload the private engine/payload first and the launcher last. The launcher must itself have an exact raw upload hash verified. No token or credential belongs in any of these files.

Authenticated GET displays only release state and a fixed-scope raw file hash/size/mode/UID inventory. It does not preview, acquire filesystem locks or modify files. This inventory can detect character-set conversion in Fileman content reads; a converted API response is not a raw before-hash.

## Fixed phases

1. `preview`: acquire all three existing release locks; validate identities, paths, modes and before-hashes; stage exact candidates and private backups; compile both candidates using the real Blade compiler with their live source paths; lint the two compiled candidates and three language files with PHP 8.4. Nothing is installed in application or public destinations.
2. `apply`: recheck all conditions, candidate hashes and protected files. Install dependencies before views and atomically replace each listed source and compiled view. Compiled mtimes are newer than their source to avoid immediately recompiling identical source bytes. Invalidate only the three translation scripts and two compiled views. Forget only home/domain guest page keys for fa/en/tr on `servernet.cloud`, for base URLs `''` and `/index.php`.
3. `verify`: compare all nine installed source hashes/modes, the two compiled view hashes and every protected hash. This proves file integrity; public browser behavior is a separate acceptance step.
4. `rollback`: restore old source and compiled views and invalidate them before removing new dependencies. Every replacement/deletion immediately rechecks the observed hash. Refuse rollback if any current source or compiled file has a hash outside this release's known before/after pair, or if a protected file changed.

An apply failure attempts rollback only after the complete known-hash check. A crash in `applying` is not permission to reapply; inspect the private state and use the same guarded rollback. The helper never flushes shared cache, calls `opcache_reset`, runs Artisan jobs or opens a network connection.

## Validation commands

`python3 docs/project/domain-ui-release-20261102/test-release-scope.py` performs four local scope/authentication/order policy checks. It does not substitute for PHP execution.

In an unprivileged CI checkout with locked Laravel dependencies installed:

```sh
php -l docs/project/domain-ui-release-20261102/engine.php
php -l docs/project/domain-ui-release-20261102/launcher.php.template
php -l docs/project/domain-ui-release-20261102/test-engine-native.php
php docs/project/domain-ui-release-20261102/test-engine-native.php
```

The native harness copies the engine into unique temporary fixtures with a test-only HOME/PHP constant substitution. It uses the installed real Blade compiler, a fake cache manager and synthetic files. It checks the full preview/apply/verify/rollback sequence, duplicate apply, preserved `0600` mode, old compiled views becoming active before dependency deletion, refusal of unknown before/after hashes, immediate rollback CAS and symlink rejection. It never boots the real application or uses the production HOME path. The production engine exposes no override for its fixed paths.
