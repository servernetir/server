# Invoice discount entry visibility — 26 October 2026

Status: implemented and tested in the release branch; **not installed or verified on production**. Base: `f202bc5b` from `develop`. No production setting, customer, invoice, payment, wallet, grant, reservation, or campaign has been changed by this release.

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

Result: **101 tests passed / 2055 assertions**. This includes the global/customer matrix, invalid/unauthorized saves, existing-account migration defaults, three locales and disclosure position/default closed state, unchanged financial records, existing zero-total redemptions, non-nested settings forms, and actual campaign/gift settlement regressions. Pint checked all new PHP source/migration/tests. Production MariaDB acceptance and visual browser acceptance are not claimed.

Browser preview navigation was rejected because the browser allows only HTTP/HTTPS and the isolated preview used a data URL; no alternate browser route was attempted. The PHP/DOM tests passed, but this is not a screenshot or visual acceptance result.

## Production blocker and concrete installation scope

Automatic approval review rejected a request to use the previously supplied cPanel credential for production SSH-key inspection: it judged that privileged live access was not specifically authorized/necessary for this coding request. That action was not retried through another transport or key-management path. Installation now requires owner approval for this specific release. No temporary SSH key was installed and no live state was inspected or changed by this release attempt.

After approval: inspect current live files/dependencies, take private backups, preserve independent live differences and use a per-file hash guard. The deployment manifest lists only this release's 13 runtime files. The **only** database operation is the additive migration `2026_10_26_140000_add_discount_entry_visibility_to_customers.php`, executed by exact path; do not run all pending migrations. Install the helper, controller, migration and translations before dependent views/routes, and activate only the affected route/view/OPcache state if needed. Validate the default remains off, the admin controls persist, a selected synthetic account can see the disclosure, and a default account cannot. Do not issue/redeem a real coupon or start a real payment for acceptance.

Rollback: restore only this release's guarded file backups, preserve newer live changes and leave the additive column in place. Set the global key to `0` if the UI must be disabled. Do not drop the column or erase per-customer choices during an application rollback. There is no financial rollback because no money/state transition is part of this preference. Provider operations, infrastructure, routing and recurring jobs are outside this release.
