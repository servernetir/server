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
unverified services, inactive or ambiguous catalog matches and unknown prepaid pricing when FX is unavailable.
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
in memory, fake mail and HTTP stray-request protection: **46 tests / 333
assertions passed**, across ServiceRenewalCyclesTest, BillingPeriodAnchorTest
and HostingUpgradeTest. Test fixture errors (nonunique catalog slug and missing
bank reference) were corrected before this final run. No production data or
credentials were used in tests. The hosting CI matrix now includes the new
suite for SQLite and MariaDB; its result is recorded separately from local tests.

Nine runtime destinations were prepared through the release script; all PHP
sources and three staged Blade compilations passed native lint. Existing
production-only invoice and route overlays were merged and preserved. There is
no route cache at preflight. The nine destinations were installed and hash-verified, and only the three
changed views were compiled. Browser acceptance exposed an independent stale-FX
condition: the official feed returned an invalid TRY row, so all-or-none refresh
correctly refused the batch. Global FX validation was not weakened.

A second bounded layer permits an existing **monthly hosting contract only** to
renew using its recorded monthly recurring amount times the selected catalog
months and valid catalog term discount, with integer rounding. This never guesses
an exchange rate, affects new sales, or reconstructs unknown historical prepaid
discounts. The invoice and preview explicitly identify this contract basis.
When valid catalog FX is available, the ordinary catalog quote takes precedence.
No time or restoration is granted before payment. Five changed destinations are
prepared separately by `renewal-contract-fallback-release-20261114.py`.

At the initial feature head all 13 CI workflows succeeded, including the hosting
SQLite/MariaDB matrix. Final fallback source passed the isolated 46-test suite
with 333 assertions; its fresh CI and browser acceptance are recorded below.

Rollback: restore only matching installed hashes from the private release
backup, recompile the three affected views, and invalidate only related PHP
bytecode. Preserve later changes and all financial records; never restore the
whole database. Script: `scripts/renewal-cycles-release-20261114.py`.


## Final production acceptance

Runtime source `22742b3334f8a46d11aadae782f40464a66d012a` is installed.
The initial nine-file release and five-file follow-up both passed expected-before
guards, private backups, native lint, targeted view compilation and final hash
verification. There were no migrations, scheduler runs, provider operations,
blanket cache flushes or new customer payment/invoice records from acceptance.

The exact affected customer's existing unpaid invoice exposes the renewal link.
The authenticated renewal page displays all four periods with positive prices,
tax, contract-basis disclosure and a selection/issue form. Persian, English and
Turkish were read from the real production page. Normal customer checkout was
not submitted: the customer chooses their preferred term and pays themselves.
The current suspension and original due date were retained.

All **13 GitHub workflows succeeded** at runtime head `22742b3`, including the
hosting SQLite/MariaDB matrix with the new renewal regressions. Local final
validation remains 46 tests / 333 assertions. A later mobile viewport check timed
out and reset the browser connection; mobile rendering is **not verified**.
The browser connection also prevented returning from the ordinary impersonation
view; the owner can use the existing return-to-admin control. No credentials were
changed and no persistent access was granted.

The independent upstream TRY/FX failure remains outside this release; no stale
rate was relabeled fresh. Renewals of known monthly hosting contracts can proceed
using the explicitly disclosed contracted monthly amount, while unknown prepaid
pricing remains unavailable until a valid catalog quote exists.
