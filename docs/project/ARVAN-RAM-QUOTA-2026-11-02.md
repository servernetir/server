# Arvan account RAM quota: repeated order prevention

Base: `6443ab136e52ae51d1af73347971dd07ead10cb1` on `develop`.
Review: [PR 210](https://github.com/servernetir/server/pull/210).

## Cause and resulting behavior

The provider returned `You have reached RAM size limit` for a small VPS order.
The account quota applies to aggregate allocated RAM, not the RAM requested by
this individual customer. Provider catalogue stock does not establish account
quota headroom. The production account quota was observed at 65536 MiB; an
existing active VM alone had 64 GiB allocated. No active VM was resized or deleted.

The structural-error matcher recognized `quota`, `limit reached`, and
`limit exceeded`, but not this actual response. The failed delivery consequently
did not quarantine the supplier, allowing another order to encounter the same
known account constraint.

`CloudProvisioner::quarantineProvider()` now recognizes that response, closes
only that supplier's current sellable rows, and also disables its supplier
sales switch. Newly discovered rows therefore cannot bypass the account block.
The existing checkout reselects from `sellable()` on POST, so a previously loaded
checkout cannot create another service, invoice, or credit debit after the block.
Manual plan notes, healthy suppliers, active services, and transient-error
handling are preserved. Menu/country caches are invalidated after quarantine.

This is containment of a definitive provider refusal, not an invented live
quota API or a guarantee of capacity before the provider's first refusal.
No new paid API request, automatic reorder, migration, or scheduler is added.

## Validation

The dedicated `cloud-account-quota.yml` workflow runs PHP 8.4 against disposable
SQLite and MariaDB, with external application egress blocked and no production
credentials. It covers the exact response through delivery, a second delivery
attempt with no second create, newly synced rows, stale checkout with zero
financial writes, peer-supplier isolation, preservation of manual notes,
transient failures, and deliberate reopening. Related delivery, failure,
admin-control, and sales-monitor suites run in the same job.

The first CI run exposed a stale Eloquent fixture when deliberately reopening
a plan; the fixture now refreshes before updating. On code/test commit
`6ee9a085f8ee2bfbd2e40eb330d57b70a6fd2e90`, the final
[quota CI run](https://github.com/servernetir/server/actions/runs/41437614415)
passed **38 tests / 157 assertions on each of SQLite and MariaDB**, with zero
errors, failures, or skips, on PHP 8.4.27. Do not treat the first failed run as
passing.
Local PHP 8.3 lint passed both changed PHP files; the locked Symfony packages
require PHP >=8.4.1, so runtime tests use the compatible CI environment.

## Production state and installation boundary

The owner's requested customer order was cancelled and its ticket sent through
the existing authenticated admin UI. Customer identity and private ticket text
are deliberately absent from this source record.

**Production code installation is pending.** Automatic browser approval denied
the cPanel terminal origin and requires explicit authorization for
`https://servernet.cloud:2083`. No alternative terminal, API, SSH, or public helper
was used to bypass that denial. The current supplier-toggle UI did not persist
the requested temporary stop, so a live sales block is not claimed.

The only production code destination is
`/home/servernetcloud/servernet_app/app/Services/Cloud/CloudProvisioner.php`.
Candidate SHA-256:
`76c9720ef7334f284cf92884c37eac0ddbd9a9dbd660b5fdd47721f8eb5812d1`.
The source-base file SHA-256 is
`54acdc59aca405426e0cf35920f2083e64043a86f1f14d59b03439de47953779`;
this is **not** an observed live-file hash.

Once access is authorized: inspect the actual live file and active services,
hold the normal release lock, preserve unrelated live changes, back up privately,
and install only the reviewed method change with before/after hash guards.
Activate only the relevant OPcache according to the current pool configuration.
Apply the known account block locally without invoking provisioning, a paid API,
or a cancelled order retry. Verify the sales switch, stale checkout rejection,
active-service preservation, and final source hash. No general cache flush is
required. Record the actual live receipt separately.

## Reopening and rollback

Increasing the requested quota is not confirmation of approval. Reopening needs
provider-confirmed headroom, review of automatically quarantined rows through
`cloud:reopen arvan` (or the equivalent admin controls), and explicit restoration
of the supplier sales switch. Reconcile any still-pending order before retrying;
the cancelled customer order must stay cancelled.

File rollback uses the same release's private backup, only if the installed hash
still matches this release. Rolling back code must not reopen sales, alter
wallet entries, undo the customer cancellation, or recreate a server.
