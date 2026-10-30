# Protected service deletion: cause and prevention

Status: prepared and tested locally; not installed in production. Base: `cc10dd81` on `develop`.

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

A proposed diagnostic upload/job was rejected by automatic approval review because the investigation did not explicitly authorize production file and cron changes. Read-only verification confirmed that no audit file or job was created. The investigation continued through existing admin pages and source reads. A live cross-provider inventory page could not be loaded; a complete provider inventory audit remains unverified.

Retiring the specific protected server is separate: verify its current provider ID and IP, confirm dependencies and recovery material, remove only that resource, wait for exact-resource absence, and reconcile the existing service/instance without reopening billing or changing balances. Do not remove internal protection globally or automatically delete all rows raised by the health check.

## Rollback

Restore only the six backed-up files if their installed hashes still match this release; reactivate only the corresponding view/cache entries. This restores the previous behavior and its known monitoring gap. Code rollback does not restore a deleted provider server, reverse a refund or reopen a cancelled service. None of those data/provider changes has been performed in this patch.
