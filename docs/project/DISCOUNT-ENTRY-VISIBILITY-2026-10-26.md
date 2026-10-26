# Invoice discount entry visibility — 26 October 2026

Status: **installed and accepted on production on 26 October 2026**, following the owner's explicit installation approval. Feature PR: [#134](https://github.com/servernetir/server/pull/134), merged into `develop` as `f383474e5b3ff3110cc70daadcd5b308e84d562d`. Production installation uses the narrow deployment branch at `c94fba275a4823d70d1dc50acc96ad16ad301f80`. The global display setting is explicitly `0`; every existing customer initially inherits it.

## Behavior

- Default visibility is **off**, including existing accounts. The global `invoice_discount_entry_enabled` setting defaults to `0` when absent.
- Settings → Accounts has an independent checkbox/form for the default. Saving bank details or another settings tab cannot reset this choice.
- Customer profile → Finance has radio options `inherit`, `enabled`, and `disabled`, plus the effective visibility. An explicit customer choice takes priority over the global default. Only administrators can save either control; support cannot change them.
- A new customer column `discount_entry_visibility` defaults to `inherit`. It is deliberately not mass assignable through the Customer model. Existing customers keep all other attributes unchanged. Before the additive migration, reads safely inherit the global default; saving an override returns 503.
- When enabled, a small, initially closed native disclosure, “Have a discount code?”, appears **after the invoice totals and before the payment section**. It opens without JavaScript and contains the labeled code input, a small Apply button, and My offers link. A code validation error reopens the disclosure. Gift coupon entry is in the same disclosure instead of a separate prominent box.
- Topups and paid invoices do not show code entry. Existing reserved/reconciliation discounts remain visible regardless of the preference. A zero-total reserved offer keeps its completion button even though the invoice has no cash due.
- This is a **display preference**, not a change to offer eligibility. Existing campaign/gift redemption endpoints, claim/settlement/idempotency rules, financial math, marketing consent/preferences and the offers page remain unchanged. No coupon is issued, applied, revoked or expired by changing this preference.
- Matching Persian, English and Turkish translations are included. Scoped styles support wrapping, keyboard focus and reduced motion. Native form/CSRF and invoice ownership protections remain intact.

## Verification

PHP 8.4.27, matching composer.lock dependencies, isolated SQLite with synthetic data:

```sh
php vendor/bin/phpunit tests/Feature/DiscountEntryVisibilityTest.php tests/Feature/AdminSettingsTabsTest.php tests/Feature/AdminSettingsFormNestingTest.php tests/Feature/MarketingCampaignEngineTest.php tests/Feature/GiftCouponOnInvoiceTest.php tests/Feature/InvoiceDiscountTest.php
```

Result: **101 tests passed / 2055 assertions**. This includes the global/customer matrix, invalid/unauthorized saves, existing-account migration defaults, three locales and disclosure position/default closed state, unchanged financial records, existing zero-total redemptions, non-nested settings forms, and actual campaign/gift settlement regressions. Pint checked all new PHP source/migration/tests. The local result above is separate from the production acceptance below.

An earlier isolated data-URL browser preview was blocked. After installation, the actual HTTPS production settings page was inspected successfully in the existing authenticated browser session; no substitute route around the blocked preview was used.

## Production installation and acceptance

The owner explicitly approved installation after the initial access review. cPanel API access succeeded. Direct SSH was unavailable from the execution environment; the generated local key was never imported and was removed. No SSH authorization or cron job was added.

cPanel's native Git deployment ran three one-time tasks from a dedicated private checkout. Both existing release locks were acquired. Preflight made private file backups outside the web root and prepared guarded overlays before any application write. It checked dependencies, PHP 8.4.27, MariaDB, the absence of the new column and the off default. All 13 staged files passed PHP syntax checks.

Installation changed exactly the 13 runtime files in the original manifest. Only the additive migration `2026_10_26_140000_add_discount_entry_visibility_to_customers.php` ran, by exact path; pending unrelated migrations were not executed. The global setting was explicitly saved as `0`, the route cache was cleared and the affected Blade views were compiled. The production receipt confirmed the new column, global `0`, and zero customer overrides.

Shared production files were patched around unique anchors instead of replaced wholesale. Existing invoice title behavior and concurrent ticket, infrastructure, wallet-refund and other route changes were retained. Consequently, some installed full-file hashes intentionally differ from the feature's repository source hashes. The [installed overlay manifest](discount-entry-visibility-20261026/installed-overlay-manifest.json) records the exact raw before/after hashes. The native verification checked all 13 installed raw hashes before running acceptance. Independent Fileman reads compared live content against the private staged copies; that transport can transcode file content, so its output is not substituted for the raw server-side manifest.

The live MariaDB acceptance helper passed **48 checks and 18 invoice renders**: global on/off in request memory, each customer mode and each of fa/en/tr. It checked hidden/visible behavior, closed disclosure and position before payment, the unchecked settings control, three customer radios and admin route middleware. The actual customer controller persisted all three modes on one synthetic customer inside a transaction, then rolled it back: **4 fixture writes rolled back, 0 test customers left, 0 financial SQL writes, 0 external HTTP calls**. In-memory global-on renders never enabled the setting for real customers. [Acceptance summary](discount-entry-visibility-20261026/production-acceptance.json).

The real authenticated settings page showed the new control unchecked. Saving it unchanged through the actual form succeeded with the success message, returned to the discount section and left it unchecked. This also exercised the deployed HTTP route/controller. The [cropped screenshot](discount-entry-visibility-20261026/live-default-off.png) contains only the new control. This is desktop settings UI acceptance; the customer invoice was rendered synthetically, not visually tested through a real customer's checkout. No real coupon redemption or payment was initiated.

Private production receipts/backups: `/home/servernetcloud/deploy-backups/discount-entry-20261026-f383474e`. Deployment checkout: `/home/servernetcloud/discount-entry-source-20261026`. The phase control is sealed as `complete` after acceptance, so an accidental later deployment task refuses to repeat installation. `.cpanel.yml` exists only on the deployment branch and is deliberately absent from this develop change.

The release helpers are archived as `scripts/deploy-discount-entry-20261026.py` and `scripts/verify-discount-entry-20261026.php`. They are specific to this dated release, validate source hashes and refuse blind repeated apply. They are not a general deployment command.

## Rollback

Restore only this release's guarded file backups, preserve newer live changes and leave the additive column in place. Set the global key to `0` if the UI must be disabled. Do not drop the column or erase per-customer choices during an application rollback. No financial state transition is part of this display preference. Provider operations, infrastructure and recurring billing are outside this release.
