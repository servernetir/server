# Birthday at-cost preparation — 29 October 2026 (Tehran)

Owner chose SMS option 1 and requested implementation while the operator approves its pattern. PR: https://github.com/servernetir/server/pull/150; base: fa190192341bb50d1f2371c7a27c9823e2bd4486.

## Release state

This is source preparation for develop. No production installation, schema migration, activation, operator request, customer message or supplier purchase has been performed. Program and SMS defaults are OFF. The operator pattern ID is still missing. Final branded notification design remains owner input; the current card is a small accessible panel notice.

## Frozen SMS copy

Only the name is variable. The operator must approve the complete emoji/Unicode content and URL; if rejected, obtain a revised approval rather than silently removing characters or switching to free text.

~~~text
🎂 {{name}} عزیز، تولدت مبارک!
امروز روز توئه؛ هدیه‌اش با ما 🎁
یه سورپرایز مخصوص تولدت توی پنلت گذاشتیم؛ قیمت‌هایی که فقط ۲۴ ساعت برای تو فعالن!
بیا هدیه‌ات رو ببین 💙
https://console.servernet.cloud
~~~

Logical pattern event: birthday_surprise; payload: name only. This event is intentionally not installed in the live sender/relay registry until the actual operator approval is available.

## Customer behavior

Existing default-profile DOB remains the only birthday source; no offers menu or DOB form returns. Existing calendar matching is retained: Persian/Jalali anniversary for fa, Gregorian for en/tr, using Asia/Tehran day boundaries. The scheduler or first authenticated portal visit opens one 24-hour window from issuance, rather than extending it on each login. Annual uniqueness plus a 330-day cross-calendar guard also considers older birthday gifts and marketing runs.

A private, uncached portal card shows the deadline and shop link. Qualified invoice lines automatically show ordinary price, birthday price and savings without a code. Product-store previews use exact cycle/server cost records. Dynamic cloud catalog, public AI catalog and other shared public caches remain ordinary prices; personal final invoice/API quote determines the actual promotion. This is not yet a complete branded catalog treatment.

No wallet cash gift is created. Old invoices, wallet funding, partial payments, manually discounted orders and unknown cost items do not receive invented savings. Ordinary stored service and next-renewal prices stay unchanged.

## Cost coverage and boundaries

| Path | Verified basis | Boundaries |
|---|---|---|
| Domain purchase/renewal/transfer invoice | Fresh exact registrar operation/years wholesale in IRT, including existing landed supplier/FX policy | Exact domain line and ownership; no bypass below current supplier cost |
| Periodic cloud base service | Stored landed EUR cents × cycle, current provider pricing snapshot and valid stored FX | Unknown add-ons remain unquoted; no estimated IP fallback counted as wholesale |
| Fixed product base/setup | Admin-approved exact product + server + cycle + component cost; currency, validity and document reference | Enter actual landed cost first. Retail base_price_eur is not treated as supplier cost |
| Cloud hourly | Native landed hourly micro-EUR and valid FX; seconds intersected with the reward window, rounded up | Normal stored rate, 24-hour reserve, metering claims, holds and actual debit refunds remain unchanged; unknown add-ons excluded |
| AI | Valid normal quote, same supplier units/FX/fees, customer markup waived in a private quote | Ordinary quote validation stays intact; native contract kept; output tax retained; no change to public prices |
| Identity API | Valid supplier quote, purchase tax retained in cost, customer markup waived | Quote/version/max-charge and output-tax safeguards remain intact |
| Storage overages, traffic meters, special setup/IP/add-ons | No blanket assumed zero-profit formula | Require a supported documented component adapter/cost first; do not activate a promise that every component is covered |

Generic verified product costs can cover catalog hosting, licenses, backups or other fixed products when the order carries trusted product metadata and an exact active cost record. They do not by themselves make arbitrary usage meters eligible.

Currency units: IRT is whole toman; EUR/USD/TRY are cents in storage. Admin enters major currency units, converted exactly to integers. Cross-currency calculation uses current stored, verified exchange rates and one upward integer conversion. Cost evidence must include actual supplier tax, bank/FX fees and other direct acquisition charges. Known cost above retail never increases the agreed customer price or fabricates a birthday discount.

The feature retains output tax using the existing frozen line basis points. It does not claim tax exemption or implement a new tax rule.

## Invoice and payment integrity

Three additive tables record annual windows, immutable invoice original/cost snapshots, and verified product costs. One nullable birthday_reward_id foreign key in ai_usage preserves the source of zero-markup pricing during delayed/recovered settlement; ordinary historical snapshots keep their previous calculation. Birthday AI settlement uses exactly the rounded landed ledger cost, so per-million display rounding cannot create a hidden residual markup. Supplier evidence stays private. Each applied product-cost proof copies the original evidence reference, verifier, native units and validity into its invoice snapshot; editing the current cost registry cannot rewrite the document reference of a previous allocation. No existing migration is edited. Two new migrations are required: the three birthday tables, then the AI foreign key after the repository's existing November-dated AI migrations. Artisan runs pending migrations by filename order, including future-dated filenames.

A signed invoice-price token is required for birthday-related payment forms and checked before payment preparation. An expired page cannot silently charge the newly restored full price. If supplier cost changes, POST rejects the old quote and a GET presents the revised amount for review.

Untouched expired invoices restore original amounts. Pending gateway/bank/crypto attempts preserve the agreed amount and require review before a new charge. Verified paid invoices commit once. Paid or partially paid values are not reversed. Fingerprint mismatches enter reconciliation rather than overwriting an administrator's financial change. Manual coupon stacking is blocked.

Administrator disabling revokes open windows at the disable time. Historical hourly usage retains only the already-earned interval. Bounded invoice expiry cleanup continues with new issuance off; pending locks cannot starve later expirations.

## SMS gates and delivery states

All gates are required: program enabled, SMS enabled, explicit approved DB pattern reference matching installed config, enabled pattern-capable sender exposing birthday_surprise, verified IR mobile, active account, granted/non-suppressed marketing consent, allowed send hours and more than 30 minutes remaining.

No raw-text/OTP fallback. A customer/reward lock records the attempt before transport; accepted/queued are not handset delivery receipts. False, timeout, crash or interrupted network attempt becomes unknown and is never blindly retried. Opt-out is rechecked before transport. Default quiet hours are 09:00–21:00 Tehran.

## Installation and activation handoff

1. Deploy only the reviewed develop revision using the established live-overlay preservation workflow. Back up first and apply the two new additive migrations; never refresh the production database.
2. Keep birthday_offer_enabled and birthday_sms_enabled at 0. Verify the three tables, admin accounts settings, translated invoice rendering, dedicated CSS, ai_usage.birthday_reward_id and minute scheduler. Admin dates use existing localized rendering; the new cost form asks validity in days and adds no Gregorian date picker.
3. Audit live legacy birthday/campaign settings and outstanding gifts. The new program suppresses new legacy birthday issuance when enabled; the annual guard prevents duplicate gifts.
4. Enter/verify cost evidence for every advertised fixed product/server/cycle/component and audit native supplier/FX freshness. Uncovered metered/add-on paths above require further implementation before advertising universal at-cost coverage.
5. After the operator supplies the actual approved pattern ID, install its exact name variable mapping in the selected sender and relay allowlist. Set the dedicated config pattern_reference and matching birthday_pattern_reference plus birthday_pattern_approved. Do not expose relay secrets in docs, tests or logs.
6. Exercise synthetic messages in a non-production sender, then perform the separately authorized live acceptance. The admin SMS toggle stays disabled while any pattern gate is missing.
7. Enable only after the coverage review and live acceptance. Run birthday:rewards --dry-run for a read-only DOB-matching preview of one bounded cursor batch. It is not a whole-customer annual-eligibility count. Normal birthday:rewards and first authenticated visit create the actual unique windows.

Rollback: turn off SMS and program through the dedicated admin form, which revokes open windows. Preserve windows, invoice snapshots, completed payments and actual usage/debits; do not drop tables, refund fictional savings or roll back financial records.

## Verification

Execution uses synthetic records and fake provider/SMS responses only. Local Windows execution was unavailable because the tool launcher failed before shell startup; GitHub Actions provides isolated PHP 8.4 SQLite and MariaDB 11.4 execution. No production credentials enter CI.

The new birthday financial cases must all pass. Existing regression suites are also run against the immutable develop base and the change; the comparison rejects new failures and explicitly lists existing baseline failures. Existing manual-FX-dependent tests must not be described as passing because a baseline comparison is green. Final result counts and CI link are recorded below after completion.

No production or visual-browser acceptance is claimed.
