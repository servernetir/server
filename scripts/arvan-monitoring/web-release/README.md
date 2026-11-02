# Guarded web-source release for Arvan Windows onboarding

This is an explicitly invoked six-file release helper for the web changes merged in `f54256d529b6f0cbe38f687bcf82511f44bb96aa` (PR 214), relative to base `d757b2e`. It is not a guest installer, scheduler, billing command or automatic deployment. A Git merge does not prove installation or production acceptance.

The fixed file order is broker, CloudInstance model, Persian/English/Turkish UI translations, then the account server view. `patchdata.json` contains 11 unique old/new context hunks. Applying them to a live snapshot preserves unrelated live edits and existing line endings; missing, duplicate or changed contexts require review. Already-applied hunks are recognized.

## Review and execution

Use the account's native PHP 8.4 CLI at `/opt/cpanel/ea-php84/root/usr/bin/php`, with working directory `/home/servernetcloud/servernet_app`. Copy only `deploy.php` and `patchdata.json` to an owner-only directory matching:

`storage/app/private/arvan-delivery-<at-least-six-safe-characters>`

The release directory must be mode `0700`, owned by the executing account, outside webroot and free of symlink components. Uploaded files should be `0600`. No credentials, production data or private receipts belong in Git.

1. Verify the two artifact hashes below and run `deploy.php --inspect`. This reads the current six sources, prints hashes/semantic anchor counts and checks patch applicability. It still inspects live files if an old receipt exists.
2. Run `deploy.php --plan`. It creates private before/candidate snapshots, lints candidates with `proc_open`, rechecks live hashes and writes `plan.json`. Application source files are not changed. PHP lint of a Blade source is not a substitute for rendering/Blade compilation tests.
3. Review the private before/after diff, six before/after hashes, ownership/modes and all hunk statuses. Drift outside the patch must remain intact. Save the exact SHA-256 of the approved `plan.json`.
4. Only after approval for that concrete plan, run `deploy.php --apply <plan-sha256>`. The helper locks, verifies the full plan/package scope and all six before hashes, then uses atomic per-file renames in dependency order. A multi-file release is not globally atomic; coordinate other deployments.
5. Read `receipt.json`, verify all six live hashes independently, then activate and accept the change using the approved environment-specific path.

The helper performs no Laravel bootstrap, database access, migration, wallet change, provider call, queue execution or cache flush. It does not reset PHP-FPM. A successful receipt is deliberately named `source_installed_activation_pending`.

Determine the actual web PHP-FPM/OPcache behavior separately. If targeted invalidation is needed, invalidate the new broker before exposing model/view code that calls its method, then the model, language files and exact compiled view. Do not substitute CLI OPcache invalidation for web-pool invalidation or clear shared caches by default. Console connectivity and actual Windows sign-in remain separate acceptance checks.

## Retry and rollback behavior

A receipt prevents another mutation. A successful-receipt retry must match the receipt's plan hash and reverify all six after hashes, modes, owners and groups. Later live drift returns nonzero, preserving both the edit and historical receipt. `--inspect` remains read-only and reports current live state.

On an apply failure, rollback considers only this invocation's attempted renames, including a rename whose subsequent verification failed. Each path must still match this release's after hash before restoration. Concurrent edits are preserved; rollback continues for other owned paths and records each unresolved error. A failed receipt requires operator review, not an automatic retry or restoring an entire old application tree.

Private snapshots/receipts remain for audit. Do not delete them automatically or use this historical patch against a newer changed method without review.

## Live-context revision 2

The first plan safely refused view hunk 3 because production independently added `&& ! $ownerAcceptanceConsole` to the console-unavailable note. This revision binds that exact observed context and preserves the clause while adding only the intended `hasPassword()` condition. The observed before hash is recorded in the patch package. Other hunks, release logic and the canonical PR 214 reference are unchanged. Original frozen artifacts are retained separately; this revision requires a new private release directory/plan or explicit removal of unused private staging after review. No previous application source write is inferred from a failed plan.

## Artifact hashes

- `deploy.php`: `0b9c796cda3f05e6e6092c445198ae49c25b805742f502786569f7b7ce92c75d`
- `patchdata.json`: `59d21b9b2b3d131d7cbcc51d1d20b16798ccfabb1c7459a807a3ee40ff5053ed`
- `test-pure.php`: `31b9f46f9fc6a738f9c66ec6afe3a88f58f582e542a5b93b74bf78e5b7d40df5`

## Isolated validation

Run `php scripts/arvan-monitoring/web-release/test-pure.php` from a checkout. The test needs no application dependencies or network. It creates only temporary synthetic application trees and adjusts runtime/root guards solely inside test copies; the delivered helper remains fixed to the production root and PHP 8.4 path.

Validated locally with available PHP 8.3.6: PHP syntax checks, 30 pure patch checks, and six isolated full flows: successful install/reverified retry; preflight drift; reverse rollback after an injected install failure; post-success drift with live inspection/refusal; untouched later-file drift while earlier writes are restored; and drift in one written file while other rollback paths continue. These are control-flow tests, not production deployment, real PHP-FPM activation or Windows guest acceptance.
