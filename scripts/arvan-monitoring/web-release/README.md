# V3 guarded web-source release for Arvan Windows onboarding

This is an explicitly invoked six-file release helper for the web changes merged in `f54256d529b6f0cbe38f687bcf82511f44bb96aa` (PR 214), relative to base `d757b2e`. It is not a guest installer, scheduler, billing command or automatic deployment. A Git merge does not prove installation or production acceptance.

The fixed file order is broker, CloudInstance model, Persian/English/Turkish UI translations, then the account server view. `patchdata.json` contains 11 unique old/new context hunks. Applying them to a live snapshot preserves unrelated live edits and existing line endings; missing, duplicate or changed contexts require review. Already-applied hunks are recognized.

## Review and execution

Use the account's native PHP 8.4 CLI at `/opt/cpanel/ea-php84/root/usr/bin/php`, with working directory `/home/servernetcloud/servernet_app`. Copy only `deploy.php` and `patchdata.json` to an owner-only directory matching:

`storage/app/private/arvan-delivery-20261103-v3q4m7dz`

Use this new V3 directory. Preserve the frozen V2 package and its historical plan; prepare and review a fresh V3 plan rather than reusing the prior plan hash.

The release directory must be mode `0700`, owned by the executing account, outside webroot and free of symlink components. Uploaded files should be `0600`. No credentials, production data or private receipts belong in Git.

1. Verify the two artifact hashes below and run `deploy.php --inspect`. This reads the current six sources, prints hashes/semantic anchor counts and checks patch applicability. It still inspects live files if an old receipt exists.
2. Run `deploy.php --plan`. It creates private before/candidate snapshots, lints candidates with `proc_open`, rechecks live hashes and writes `plan.json`. Application source files are not changed. PHP lint of a Blade source is not a substitute for rendering/Blade compilation tests.
3. Review the private before/after diff, six before/after hashes, ownership/modes and all hunk statuses. Drift outside the patch must remain intact. Save the exact SHA-256 of the approved `plan.json`.
4. After the coordinator reviews that concrete plan within the already authorized installation scope, run `deploy.php --apply <plan-sha256>`. The helper verifies its fixed mutex inode, the full plan/package scope and all six before hashes, then prepares and flushes all six install/reversal files before atomic per-file renames in dependency order. A multi-file release is not globally atomic; coordinate other deployments.
5. Read `receipt.json`, verify all six live hashes independently, then activate and accept the change using the approved environment-specific path.

The helper performs no Laravel bootstrap, database access, migration, wallet change, provider call, queue execution or cache flush. It does not reset PHP-FPM. A successful receipt is deliberately named `source_installed_activation_pending`.

Determine the actual web PHP-FPM/OPcache behavior separately. If targeted invalidation is needed, invalidate the new broker before exposing model/view code that calls its method, then the model, language files and exact compiled view. Do not substitute CLI OPcache invalidation for web-pool invalidation or clear shared caches by default. Console connectivity and actual Windows sign-in remain separate acceptance checks.

## Retry and rollback behavior

A receipt prevents another mutation. A successful-receipt retry must match the receipt's plan hash and reverify all six after hashes, modes, owners and groups. Later live drift returns nonzero, preserving both the edit and historical receipt. `--inspect` remains read-only and reports current live state.

On an apply failure, rollback considers only this invocation's attempted renames, including a rename whose subsequent verification failed. Each path must still match this release's after hash and mode/uid/gid before restoration. Prepared reversals require no new source-sized write after a storage/receipt failure. Concurrent edits are preserved; rollback continues for other owned paths and records each unresolved error. A failed receipt requires operator review, not an automatic retry or restoring an entire old application tree.

Success receipt creation, flush, atomic no-overwrite publication, verification and final source checks are inside the same rollback scope. If a later step fails, only this invocation's exact receipt dev/inode/hash may be withdrawn before rollback. A foreign receipt is never replaced or removed; its presence/change returns `failed_receipt_reconciliation_requires_operator` and preserves the current source state for reviewed reconciliation. If failure recording also fails, stdout reports the failure and `receipt_recording_error`; no success is claimed. Never blindly retry after an ambiguous result.

The fixed Arvan release mutex is checked for regular-file identity, mode0600, ownership, link count and held dev/inode before/after acquisition and around mutations. If it is replaced, preserve the active receipt and current source state for operator reconciliation; do not withdraw receipts, roll back files or publish canonical failure state under another process's mutex. This is not a shared mutex for unrelated deployment tools: the coordinator must reserve these six paths against parallel changes.

Private snapshots/receipts remain for audit. Do not delete them automatically or use this historical patch against a newer changed method without review.

## Live-context revision 2

The first plan safely refused view hunk 3 because production independently added `&& ! $ownerAcceptanceConsole` to the console-unavailable note. This revision binds that exact observed context and preserves the clause while adding only the intended `hasPassword()` condition. The observed before hash is recorded in the patch package. Other hunks, release logic and the canonical PR 214 reference are unchanged. Original frozen artifacts are retained separately; this revision requires a new private release directory/plan or explicit removal of unused private staging after review. No previous application source write is inferred from a failed plan.

## Receipt-recovery revision 3

V3 changes release tooling only. The 11 source hunks and `patchdata.json` remain byte-identical to V2, including the live owner-acceptance console clause. V2 receipt publication was outside the source rollback catch; V3 handles receipt failures under the same guarded rollback and mirrors the independently reviewed meter-release pattern. The FPM companion uses the same V3 helper pin and new stage/primary identifiers. No source installation or live acceptance follows from this package.

## Artifact hashes

- `deploy.php`: `02c952a7ba3ac370e88081898c43be2b2b91ff200e894ce927c5e8bc7da8c93e`
- `patchdata.json`: `59d21b9b2b3d131d7cbcc51d1d20b16798ccfabb1c7459a807a3ee40ff5053ed`

## Isolated validation

Run `php scripts/arvan-monitoring/web-release/test-pure.php` and `php scripts/arvan-monitoring/web-release/test-receipt-flows.php`. Tests need no Composer/app dependencies and access only newly created temporary fixture trees. Native PHP/root/version changes and syscall faults are injected solely into test copies; the shipped helper remains PHP8.4-only at the fixed cPanel root.

Available PHP8.3.6 passed lint, 30 patch checks, six original complete flows and 17 additional receipt/lock fault flows. The latter prove six source renames precede receipt write/flush/hash/publication/verification failures; reversal uses preallocated files after further staged writes are denied; foreign receipt bytes/inodes, source bytes/modes and tampered reversal data are preserved; and replacing the held mutex stops further mutations. The focused CI runs these fixtures on PHP8.3/8.4 without an external network interface. These checks do not exercise actual cPanel FPM, provider handshakes or a Windows guest.
