# Fixed hosting-domain release helper

These are reviewable source templates, not evidence of an installation. `engine.php` must remain outside webroot in `/home/servernetcloud/hosting-domain-release-20261102`; `launcher.php.template` is a transient authenticated admin entrypoint. Fill the two exact SHA-256 placeholders from the final private engine and payload. Remove the public launcher after acceptance.

The payload requires `release=hosting-domain-20261102`, the real unprivileged account uid, a full source commit SHA, exactly fourteen unique `entries`, and optional selected `protected` entries. Each entry carries destination, UTF-8 content, before/after SHA-256 (`absent` only for before), before mode/null and exact preserved mode. Obtain inventory from current production; do not copy historical before hashes. New files must be 0644; existing private Blade modes are preserved. The two component source paths are compiled with the application's real Blade compiler and linted with its PHP8.4 binary during preview. New partial is ordered before the existing card; backend/routes/assets are ordered before both views.

All preview/apply/verify/rollback operations require three existing cross-release locks, account ownership, non-symlink confined paths, pinned payload bytes and exact source/compiled mode/hash preconditions. Backup, candidates, state, lint diagnostics and fresh-route diagnostics stay private outside webroot. Only changed PHP/compiled files receive OPcache invalidation. No public PageCache keys, configuration, route cache or global cache is cleared. The engine rejects existing configuration/routes caches rather than silently changing deployment policy.

After installation and during verify, a fresh read-only CLI bootstrap verifies the new controller-bound named GET route. This is necessary because the current admin request booted the previous route file. Failure triggers the existing guarded file rollback. This route check does not call the endpoint, resolve customer DNS, run schedules or perform provider operations.

The admin-only `?phase=visual&locale=fa|en|tr` renders the actual installed component with a synthetic model, no customer data and no password. Its result comes from the equally protected `visual-dns` fixture endpoint, clearly marked synthetic. It is useful for layout/copy/retry checks, **not** a substitute for a real customer-scoped endpoint acceptance and actual production DNS egress verification.

Native rehearsal:

```sh
php docs/project/hosting-domain-release-20261102/test-engine-native.php
```

The native test uses a synthetic compiler/router, unique disposable paths and no Laravel/production data. It tests fourteen-file installation, cache rejection, hash drift, symlinks, modes, duplicate apply, fresh-route failure rollback and explicit rollback. On a root-only test sandbox it permits uid0 **only in the throwaway generated engine copy**, because setuid is unavailable; the production engine retains its uid>0 guard. Actual Blade/UI acceptance still requires the application tests and browser review.
