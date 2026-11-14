# Customer-selected hosting renewal periods — 14 November 2026

Base develop: `17180cc9ca5ccc745753b79a0b8e05e80a0fdbcc`.
Runtime commit: `1414a144f46a10639dcd14b04b4b596eef3e9ff5`.

Customers can select the same recurring periods sold for their existing catalog
hosting plan from the hosting card or unpaid invoice. `Product::orderCycles()`
and `priceForCycle()` remain the source of periods, location pricing and term
discounts. No setup fee is charged again. Applicable invoice marketing context
is retained; existing applied discounts are protected instead of silently lost.

Selection saves the future billing cycle, current catalog recurring price and
tax rate, and replaces wholly unpaid renewal invoices. It does not grant service
time or restore a suspended account. Normal verified settlement extends the
original due date using the selected cycle. Canceling an unpaid invoice grants
no time and keeps the selected future billing preference.

The feature covers catalog-matched WHM, Plesk and DirectAdmin hosting (including
catalog reseller hosting). It rejects hourly/cloud/internal/complimentary or
unverified services, inactive or ambiguous catalog matches and unavailable FX.
Cloud supplier prepaid renewal is outside this change; its supplier term must
be handled through its own reconciliation process.

Protection: customer ownership, normal CSRF/customer middleware, private/no-store
preview, 15-minute session quote and HMAC, fresh quote/state comparison under
service and invoice locks, idempotent resubmission, preserved historical invoices.
Partial payments, pending gateway/crypto/bank evidence, applied discounts and
open hosting upgrades block replacement. Old canceled invoices retain the normal
late-payment-to-wallet behavior. No schema, cron or provider configuration changes.

## Validation and release

Native PHP 8.4.27, isolated checkout with separate copied dependencies, SQLite
in memory, fake mail and HTTP stray-request protection: **46 tests / 328
assertions passed**, across ServiceRenewalCyclesTest, BillingPeriodAnchorTest
and HostingUpgradeTest. Test fixture errors (nonunique catalog slug and missing
bank reference) were corrected before this final run. No production data or
credentials were used in tests. The hosting CI matrix now includes the new
suite for SQLite and MariaDB; its result is recorded separately from local tests.

Nine runtime destinations were prepared through the release script; all PHP
sources and three staged Blade compilations passed native lint. Existing
production-only invoice and route overlays were merged and preserved. There is
no route cache at preflight. Installation and browser acceptance are pending.

Rollback: restore only matching installed hashes from the private release
backup, recompile the three affected views, and invalidate only related PHP
bytecode. Preserve later changes and all financial records; never restore the
whole database. Script: `scripts/renewal-cycles-release-20261114.py`.
