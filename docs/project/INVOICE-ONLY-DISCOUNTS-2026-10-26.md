# Invoice-only discount entry — 26 October 2026

Status: installed and accepted on production. This follow-up supersedes the customer offers page and presentation-only permission behavior described in the earlier discount visibility release. PR: [#136](https://github.com/servernetir/server/pull/136).

## Customer behavior

- The customer menu has no offers/discounts item in Persian, English or Turkish. The standalone offers dashboard and its birthday/preferences form are removed. The invoice disclosure no longer links to My offers.
- New code entry is available only on an eligible invoice when the existing administrator-controlled global/customer preference allows it. Both campaign and gift POST handlers enforce that choice server-side. The global default stays off; explicit customer choices keep their priority.
- Existing reserved discounts and zero-total completion are preserved. Campaign eligibility, financial arithmetic, tax bases, gift claim rules, wallets and payment settlement are unchanged. This is permission to submit a new code, not an automatic discount grant.
- Marketing birthdays use `customer_profiles.birth_date` through the existing default profile. Historical marketing birthday fields remain stored but are ignored. No birthday data is copied, cleared or requested in a second customer form. Persian anniversaries, Gregorian leap days and annual duplicate protection are tested.
- `/account/offers/preferences` returns 410 without writing anything. An explicit, unlisted SMS unsubscribe confirmation remains available at `/account/marketing/unsubscribe`; it contains no birthday or code fields and cannot grant consent. Old offers URLs redirect there so previously delivered SMS opt-out links remain usable. GET does not change consent. POST withdraws only the authenticated customer's consent, records an audit and blocks scheduled marketing deliveries; service/OTP messaging remains independent.

## Validation

PHP 8.4.27 with lockfile-matched dependencies, isolated SQLite and synthetic customers:

```sh
php vendor/bin/phpunit tests/Feature/InvoiceOnlyDiscountAccessTest.php tests/Feature/DiscountEntryVisibilityTest.php tests/Feature/AdminSettingsTabsTest.php tests/Feature/AdminSettingsFormNestingTest.php tests/Feature/MarketingCampaignEngineTest.php tests/Feature/GiftCouponOnInvoiceTest.php tests/Feature/BirthdayGiftTest.php tests/Feature/InvoiceDiscountTest.php
```

**122 tests / 2213 assertions passed**, including after merging concurrent develop work. Checks cover all three locales, retired routes and unchanged stored birthdays, forged customer IDs/consent payloads, direct forbidden code POSTs, authorized campaign/gift redemption, unchanged tax bases, profile birthdays, leap days, next-year issuance, reservation completion and SMS opt-out/OTP separation. Pint checked the edited modern PHP files; both release helpers passed syntax checks. The release acceptance helper also passed locally before publication.

## Production receipt

The deployment-only branch is pinned to `ef4c5b947a24eb9dda36724fcfddc63503d7c45b`; its source feature tree is `33b4b011f6566e9b6dfac60e2acad1b97db87212`. Its `.cpanel.yml` is deliberately absent from develop.

cPanel native Git tasks ran guarded preflight, apply and verification with both existing release locks. A private backup and exact before/after manifest were created outside the web root. Unique-context overlays preserved unrelated live source and line endings. The scope is **13 runtime paths: 11 patched files, one new unsubscribe view and one retired offers view**. No migration, global setting write, real customer preference change or financial operation was executed. The existing global setting (`0`) and customer override state were verified unchanged. Only the route cache and affected Blade compilation were refreshed.

The production acceptance checked raw installed hashes and passed **62 checks / 18 synthetic invoice renders**, with every SQL write blocked: **0 SQL writes, 0 external HTTP calls, 0 persisted test customers**. See [acceptance](invoice-only-discounts-20261026/production-acceptance.json) and [installed file manifest](invoice-only-discounts-20261026/installed-overlay-manifest.json).

The actual authenticated customer invoices page was reloaded in Chrome: the offers menu link count was zero, with Dashboard immediately followed by Buy service. The old offers URL redirected to the unlisted unsubscribe confirmation, where birthday and code input counts were both zero. No customer invoice, coupon or opt-out form was submitted during browser acceptance. The [menu screenshot](invoice-only-discounts-20261026/customer-menu.png) excludes customer identifiers. Invoice visibility across languages/modes was rendered synthetically; no real payment was initiated.

Private receipts and rollback copies: `/home/servernetcloud/deploy-backups/invoice-only-discounts-20261026`. Dedicated source checkout: `/home/servernetcloud/invoice-only-source-20261026`. The one-time phase file was sealed as `complete` after acceptance. No SSH key, cron job or public deployment helper was added.

## Rollback

Use the private manifest to restore only paths whose current hashes still match this release. Restore the retired view only with the corresponding controller/menu rollback. Retain all existing preference and birthday data. There is no schema or financial rollback. Never overwrite later live edits or run this dated apply step twice; inspect receipts and hashes first.
