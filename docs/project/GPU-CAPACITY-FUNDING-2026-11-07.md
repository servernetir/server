# GPU capacity queue and direct funding — 2026-11-07

Base: `origin/develop`, `b932380d`. Production changes are installed and the affected GPU application reached confirmed readiness. The customer UI showed access and running controls, an application password was present, and the first hourly charge settled after readiness. Customer data and credentials are excluded from this note.

## Cause and fix

The capacity command repeatedly selected twenty old cancelled services with a legacy `charge_pending` flag. Each returned `skip` without changing its queue position, so new requests never entered the bounded batch despite a healthy heartbeat.

`GpuCapacityOrders::candidates()` now excludes dead services unless `stop_pending=true`. Outstanding provider stops remain eligible. A regression test creates a cancelled backlog larger than the batch and verifies that a live request and a pending stop are selected.

## Direct funding

The waiting page now offers a POST button whenever the authoritative funding window reports insufficient credit, including before a worker records that state. It opens the ordinary top-up invoice for the current shortfall toward the initial 24-hour requirement; the existing 1,000 IRT minimum still applies.

Only the service ID is submitted. The server locks the customer and owned service, recomputes credit and required amount, rejects expired/unapproved-price requests, and creates no further invoice after funding or allocation. Repeated clicks reuse a recent matching unpaid top-up invoice. Integer IRT amounts remain exact for fa/en/tr. Normal top-up conversion, payment settlement and provisioning gates are retained.

## Scope

Production: `app/Services/Cloud/GpuCapacityOrders.php`, `app/Http/Controllers/Account/PaymentController.php`, `resources/views/account/partials/gpu-capacity-wait.blade.php`, `lang/{fa,en,tr}/ui.php`. Repository paths use the `website/` prefix.

Tests: `website/tests/Feature/GpuCapacityQueueEligibilityTest.php` and `website/tests/Feature/GpuRequestFundingTest.php`.

No schema, migration, scheduler, global cache, provider rate or wallet-ledger policy changes.

## Validation and limits

- PHP 8.4 syntax checks passed for both classes and all three translation files.
- 8 tests / 21 assertions passed for the changed application and again in the isolated checkout. Coverage includes backlog starvation, retained pending stops, exact shortfall, tampered amount, invoice reuse, ownership, already-funded requests, expiry, unapproved prices, non-Persian amount handling and waiting-view rendering.
- The isolated checkout reused dependencies matching its composer lock, with an in-memory autoloader remap to its own application/test paths. SQLite in memory, fake mail/cache/session and blocked unexpected HTTP were used.
- The initial CLI run exceeded its memory limit; successful runs used 512M. The initial shared dependency autoloader pointed at the deployment tree; the isolated adapter corrected the paths before the successful checkout run.
- Natural scheduled runs advanced live candidates and settled readiness after the fix, instead of repeatedly skipping the same cancelled backlog.
- Readiness and customer access UI were observed. No real payment was made to test the funding button.
- SQLite does not prove MariaDB concurrency behavior. The new waiting form is covered by application rendering tests; a separate live unfunded checkout and the complete mobile/browser matrix were not performed.

## Deployment and rollback

The six production files were narrowly patched with private pre-change copies outside the public root. A private manifest records before/installed SHA-256 values. Only the scoped patch was transferred to fresh develop; unrelated deployment/repository differences were preserved. Full deployment/HEAD equality is not claimed.

To roll back, verify current files still match the recorded installed hashes, then restore only these six files. Revert the source commit separately. Do not cancel the delivered resource, alter credit, or restore database snapshots as part of code rollback.
