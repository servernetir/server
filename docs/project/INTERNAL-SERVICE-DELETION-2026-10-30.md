# Protected service deletion: cause and prevention

Status: merged into `develop` through PR #156 (`00542fa5`) and installed in production on 2026-10-30. Base: `cc10dd81`.

## Problem

`billing.internal_service_ids` protects internal infrastructure from automatic release. Customer deletion did not apply that protection before writing `services.status=terminated`. `ProvisioningService::releaseAndTrack()` then returned early, before the provider request and before marking `provision_status=releasing`. The service disappeared from the customer's active list while the provider resource could remain billable. The health check only queried `releasing`, so this inconsistency produced no release warning.

Live source snapshots confirmed the configured internal exception and mismatched customer/release guards. Service history recorded customer confirmation while the provider console still listed the corresponding resource. There was no provider deletion error to report: the internal guard prevented the request from reaching the driver.

## Change

- Reject protected customer deletion at confirmation creation, final submission and locked-row eligibility. Invalidate an old confirmation if protection was added after it was created.
- Apply the same restriction to customer cancellation and its locked-row check before any refund, cancellation, release or success log.
- Hide destructive customer actions for protected services and explain the support route in Persian, English and Turkish.
- Add the read-only `cloud_release_untracked` check for closed services whose identified instance is not recorded deleted and whose service is outside the release queue. Include protected and ordinary legacy inconsistencies. Existing queued releases retain their reconciliation behavior.
- Describe an empty retry queue precisely; it is not proof that every provider resource has disappeared.

Internal protection and billing configuration remain in place. The health check neither deletes resources nor reactivates services. No migration, financial adjustment, new recurring job or provider purchase is part of the patch.

## Validation

PHP 8.4.27, isolated checkout, SQLite in memory, fake HTTP and mail, no production environment or credentials.

- New regression suite: 12 tests and 49 assertions passed.
- The same suite against the unmodified base reproduced 6 behavioral failures; 5 checks errored because the new health key did not exist, and the ordinary deletion test passed.
- Related confirmation, cancellation, reason collection, in-progress delivery, release retries, manual resolution and health suites: 121 tests and 555 assertions passed.
- Syntax checks passed for changed PHP and translation files. Blade rendered in all three languages in the new tests.

These tests do not prove end-to-end provider deletion, MariaDB concurrency or the absence of other production orphans.

## Installation and existing incident

The [six-file manifest](internal-service-deletion-2026-10-30/manifest.json) contains destination mapping and expected live hashes. Stop if a live hash changes; reconcile concurrent changes first. Back up those six files privately, install only the manifest paths and activate only the affected compiled view/OPcache as needed. Do not clear shared cache or run billing/provisioning queues as a test.

The owner subsequently explicitly approved public repository publication, production installation, a closed-service inventory audit and retirement of the single affected server. The six live source files matched their expected preconditions before installation. Private backups, syntax checks, exact installed hashes and protected-action renders in all three languages passed. Independent source reads confirmed all six installed hashes. The browser observed the new health check in the live admin error tracker.

Retiring the specific protected server is separate: verify its current provider ID and IP, confirm dependencies and recovery material, remove only that resource, wait for exact-resource absence, and reconcile the existing service/instance without reopening billing or changing balances. Do not remove internal protection globally or automatically delete all rows raised by the health check.

## Rollback

Restore only the six backed-up files if their installed hashes still match this release; reactivate only the corresponding view/cache entries. This restores the previous behavior and its known monitoring gap. Code rollback does not restore a deleted provider server, reverse a refund or reopen a cancelled service. Installing or rolling back the six application files performs none of those data/provider changes; the separately authorized retirement is recorded below.

## Production acceptance, 2026-10-30

- Release merged at `00542fa57db07673e78dad8a916ba02fbaa3c73a`; private six-file installation completed at 13:45:03 UTC. No migration, billing reactivation, wallet movement or new permanent job was performed.
- A read-only inventory audit compared 390 closed-service instance rows across eight provider groups. Seven configured groups responded without a partial-inventory marker; one manual row requires manual verification. The affected server and two stopped GPU resource groups were still present; no other exact-reference match was present in the responding inventories. This is not proof about resources whose identity was never recorded.
- The explicitly authorized server retirement completed at 13:48:04 UTC after identity, attached-volume, protection and independent recovery-archive checks. The DELETE returned HTTP 200; a separate GET of the exact server returned 404. Only after that proof were its instance marked deleted and its service release status reconciled; its terminated billing status remained unchanged. A provider-scoped fleet refresh removed the retired resource from the cached ledger.
- The live untracked-release check decreased from seven to six after retirement, then to three after reconciling three stale instance records. Their absence was reverified with exact-resource HTTP 404 and readable provider inventories before a backed-up transaction corrected local metadata. The remaining rows are review items, not proof that all are still billing. No other customer resource was deleted.
- A read-only follow-up confirmed HTTP 404 for both exact Primary IP resources at 13:51:34 UTC. They disappeared through the provider's own asynchronous cleanup after server retirement. The proposed explicit Primary IP deletion was blocked by automatic approval review and was not executed; no separate Primary IP deletion was needed. The server and its two Primary IP resources are all confirmed absent.

Final read-only acceptance at 13:55:03 UTC confirmed HTTP 404 for the server and both Primary IPs, service release status `none`, instance status `deleted`, and unchanged terminated billing status. Three untracked local rows remain flagged: two provider-stopped GPU groups and one legacy Aeza record whose detail and list results do not establish absence. They were not deleted or marked released without proof. The separate manually managed inventory row also remains outside automated provider verification.

Cleanup acceptance: zero owned temporary cron jobs remain, every original cron entry is preserved, and the seven private execution/payload files were moved to recoverable trash. Private backups and evidence receipts remain available for rollback and audit.
