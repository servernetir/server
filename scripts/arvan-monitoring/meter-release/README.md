# One-file Arvan meter release candidate

Candidate commit: `b0ff56359c3ab8786ec80220a25279c69740efe9` (merged PR225).
Only production target: `app/Services/Finance/ArvanTrafficMeter.php`.

| Artifact | SHA-256 |
| --- | --- |
| `deploy.php` | `0550824d42f54eb31c739cfd32a13bfcc04127cd0e29a8ffe073b79e283cd60e` |
| `candidate.php` | `56c3972ba0896e6bc14c4d443246c1eadaef6bccb63245e0cd3b274e292d2ca8` |
| Exact required live before source | `464b2db7f0bda854d6473b06e46da630cdc325c82bb22894088af8bcfaba4f3f` |

No production action has been performed by this package. It never bootstraps
Laravel, reads or writes the database, runs a finance command, contacts a provider,
charges a wallet, modifies a scheduler, or resets a cache.

Upload only `deploy.php` and `candidate.php` into a new cPanel-owned **0700**
directory matching:

`/home/servernetcloud/servernet_app/storage/app/private/arvan-meter-release-<6-to-80-safe-characters>`

Both uploaded files must be **0600**. Verify the artifact hashes independently.
Run only from `/home/servernetcloud/servernet_app`, with the native
`/opt/cpanel/ea-php84/root/usr/bin/php`:

```text
<native PHP> <private release directory>/deploy.php --inspect
<native PHP> <private release directory>/deploy.php --plan
<native PHP> <private release directory>/deploy.php --apply <reviewed-plan-sha256>
```

Every mode obtains the fixed **0600** `storage/framework/arvan-meter-source-release.lock`
mutex (creating only that release guard if absent), then a nonblocking exclusive
flock on the **existing**
`storage/app/private/arvan-traffic-meter/run.lock`. An absent/busy/replaced,
symlinked, unsafe or differently-owned lock is refused. The helper never creates,
replaces or removes that meter lock. Both lock inodes are checked again before replacement. A busy, replaced or unsafe
release guard is also refused.

Planning requires the exact before hash and creates a private `before.php` backup
plus a hashed `plan.json`; it performs native PHP lint without executing either
source. Applying requires that reviewed plan hash, rechecks ownership, modes,
backup/candidate/live hashes and the lock, then prepares both install and rollback rename files before mutation, then replaces
exactly one file by same-filesystem atomic rename while retaining original source
mode and group. Source reversal needs no new source-sized allocation.

Success receipts are flushed to a private temporary file and atomically published
without overwriting any existing receipt, using a hard link and removal of the
temporary name. Publication and final receipt/source verification are within the
rollback scope. If publication/verification fails, only this invocation's exact
receipt can be withdrawn before conditional source rollback. Unexpected receipt
changes are preserved with the installed source and require operator review. If
receipt IO remains unavailable after rollback, output records the failure; fresh
inspection must confirm the restored before-source before any guarded retry.

A successful receipt retry revalidates plan scope/hash and the current installed
hash/metadata. A failed historical receipt requires operator review. Inspection
always reads the live source, including after a receipt exists. Failure after
replacement conditionally restores only this invocation's change; a later
independent write is preserved and reported for operator review. An unrecorded
failure or interrupted process requires fresh inspection; do not blindly rerun.

`source_installed_activation_pending` proves source replacement only. Observe a
fresh natural meter process that starts after installation for runtime acceptance;
do not trigger a financial run to test this helper. An already-loaded process can
retain earlier PHP code, even though holding the run lock prevents replacing the
source while its protected meter execution is active.

Validation: production helper lint passed on available PHP8.3.6. **20 isolated
deployment flow tests passed**, including success/idempotence, post-success drift,
natural meter contention, missing/replaced lock, candidate/backup tampering,
concurrent source/mode drift, symlink refusal, conditional rollback and preserving
later writes, release mutex contention/inode replacement, receipt write/publication/
verification failures, atomic no-overwrite publication, foreign receipt preservation,
post-publication source drift and guarded recovery after total receipt IO failure.
An independent reviewer reran all 20 flows and lint successfully. Tests adapt only temporary helper copies to local paths/PHP8.3; the
production helper still requires the exact native cPanel PHP8.4 runtime.

No production installation, native production lint or runtime financial acceptance
is claimed by these local test results.
