# ServerNet marketing campaigns — 2026-10-25

## Delivery status

This is a development implementation, not a production deployment or authorization to send messages.
No production migration, customer wallet change, live campaign activation, or real SMS was performed.
Development is based on the current repository's Laravel application in website/, not the abandoned app/ tree.

Previously: GiftCoupon and birthday:gift issued fixed, customer-bound, short-lived gifts. Applying those gifts posted a gift payment immediately. Those historical semantics and records are preserved.

Added: campaign drafts/approvals, immutable issued snapshots, random private/public codes, percentage/fixed discounts, invoice-line eligibility, budget commitments, transactional checkout reservations and final consumption, customer preferences, birthday automation, persisted marketing SMS outbox, previews, reporting, CSV, staff permissions and audit history.

The complete original specification is NOT fully implemented. The unsupported capabilities below are intentionally not presented as operational.

## Pages and commands

- /admin/marketing/campaigns — campaigns, scheduler heartbeat, uncertain deliveries/payments, SMS emergency controls, staff capabilities.
- /admin/marketing/campaigns/create — six-step campaign form using the existing Persian date picker.
- /admin/marketing/campaigns/{id} — preview, approve/pause issuance, independently pause messaging, issue codes, revoke with a reason, history, reports.
- /admin/marketing/campaigns/{id}/export — permission-checked CSV.
- /account/offers, /en/account/offers, /tr/account/offers — owned offers, optional birthday/calendar, promotional SMS consent.
- Existing invoice pages include the new campaign code form. The historical coupon endpoint also recognizes new SN- codes.
- /m/{random-token} — login-preserving navigation; opening a link never redeems a discount.
- marketing:tick --dry-run — no grants, delivery records, financial commitments or wallet writes.
- marketing:tick — approved issuance, reservation/grant expiry handling and eligible outbox processing. Scheduled every minute with an overlap lock.

All new admin endpoints require web authentication plus their named capability: marketing.manage, marketing.publish, marketing.send or marketing.finance. Administrators have all four; support users require explicit, audited assignments. Authors and customers do not receive these permissions.
Financial reconciliation requires both publish and finance.

## Supported financial scope

Explicit metadata is attached only to the service line of newly issued product invoices and term-based cloud-server invoices, including supported service renewals. Setup/add-on rows and unmarked historical rows do not inherit eligibility.
Filters support product IDs, family, provider/control-panel type, plan, country, account IDs, locale, tags, existing service plans, active service state and purchase counts.

- Percentage values are integer basis points; currency amounts are integer minor units.
- IRT means toman, IRR means rial; EUR/USD/TRY use their existing minor-unit convention. No cross-currency coupon conversion.
- Discount allocation uses exact integer arithmetic, including large products that exceed native intermediate multiplication.
- Eligible line discounts are applied before line tax using each line's frozen tax_rate_bp, then Invoice::recalculateTotals supplies invoice totals.
- The new discount reduces invoice price. It does not increment paid or create spendable wallet credit.
- Only one campaign per invoice; stacking with existing line discounts needs explicit permission.
- First/later purchase selection can use the audience's maximum/minimum purchase count.
- No new tax rate or law was introduced.

Supplier cost is UNKNOWN unless trusted invoice context supplies a cost in the invoice currency. Automatic supplier-cost extraction for every product/cycle is not implemented. Without known cost and sufficient margin, applying a discount is blocked unless the administrator approves a bounded subsidy with a reason. Unknown costs produce unknown profit, never zero cost.

Personal guarantees reserve their maximum discount at issuance and occupy campaign AND customer capacity, including against public-code checkout. Unused expired commitments are released once. Public codes reserve actual discount at checkout. Spent/refunded benefits are not automatically reissued or turned into cash. Birthday annual scopes permit a later year's offer while preventing repeat gifts within the same cycle.

## Reservation and payment policy

Lock order for campaign operations is invoice -> campaign -> grant -> redemption; issuance serializes on the campaign. Natural/unique keys cover grant issuance, event runs, one redemption per invoice and each delivery.
Gateway start now snapshots/creates the payment attempt under the invoice lock, so a concurrent code cannot change its amount between checking and starting payment.

- Default reservation: 30 minutes, capped by the grant expiry.
- Repeated checkout on the same live reservation returns the existing record.
- Successful payment commits once and cancels queued reminders.
- Failed payments permit restoration after reservation expiry; a private grant remains reusable while valid.
- Unknown/in-flight or partial payments retain commitment and enter reconciliation instead of guessing.
- Existing gateway verification does not provide a trustworthy provider-paid timestamp. A verified payment arriving after the offer reservation window is credited as ACTUAL money to the existing wallet, with a marketing_payment_reconciliations record; the discounted order is not fulfilled automatically.
- Such money is a wallet liability, not service revenue. Gateway fees retain the existing treatment.
- Manual release requires no unresolved payments, no invoice-paid amount, an unchanged price fingerprint and an audit reason. It restores original invoice amounts without adding more wallet money.
- Free orders need a separate authenticated POST confirmation through the existing settlement/provisioning path.
- Refund eligibility and amount continue to use actual invoice.paid; discount value itself is not refundable cash.

Concurrency has been exercised using two real PHP workers sharing SQLite for private-code reuse, last campaign capacity and last budget. MariaDB concurrency has not been validated.

## Birthday and marketing messages

Birthday fields are optional; year is optional. Calendar is retained. Existing verified/profile data is used as a fallback without requesting identity data for marketing.
Persian Esfand 30 falls on Esfand 29 in a non-leap year. Gregorian February 29 falls on February 28 in a non-leap year.
Day/schedule decisions use the campaign timezone, default Asia/Tehran. Birthday catch-up stays within the current local day. Recent gifts, calendar changes and multiple campaigns cannot create another annual gift. The legacy birthday program must be disabled before approving a new birthday campaign.

Available automatic triggers: birthday, signup within 24 hours, first paid purchase within 24 hours, inactivity, scheduled audience run. Manual private/public issuance is available.
Scheduler scans are bounded and resume using each campaign's stored cursor. A restart cannot recreate an already recorded event/grant/delivery.

SMS controls include explicit consent/suppression, verified Iranian phone, permitted hours, per-account frequency and spacing, campaign/global count and spending ceilings, known tariff, independent campaign message pause and global emergency stop.
Consent is checked at queueing and again before network sending.
Actual GSM-7/extension or UTF-16 length determines segment estimates.
Delivery body is encrypted at rest. No names, phones or emails are put into tracking URLs or analytics.
Outbox attempts are persisted before network I/O; interrupted/ambiguous sends become unknown and are never blindly retried.
Provider acceptance is not phone delivery.

Read-only live discovery: /system/sms-status returned HTTP 200 and active driver n8n-relay (requested n8n_relay). No credentials or destinations were extracted.
That existing SignedRelaySender accepts known transactional patterns and explicitly returns false for arbitrary text. Consequently live promotional sending through the CURRENT relay is not ready and is blocked rather than using an OTP/service pattern.
The new engine reuses the existing SmsSender abstraction for supported direct free-text providers, with sending disabled by default. The existing relay, OTP code, production provider configuration and n8n workflow were not changed.
Provider tariff/country requirements and a supported promotional transport still need review before real activation.

## Reports

Reports count issued/expired/revoked codes, redemption states, paid invoices, actual paid sales, discounts, tax reduction, estimated SMS expenditure, budget commitments and remaining capacity.
One invoice can belong to only one campaign. Authenticated destination opens are deduplicated per grant/account/UTC day. Clicks are not purchases and attributed revenue is not proven incremental revenue.
Unknown supplier costs or incompatible reporting currencies prevent a profit figure.
CSV contains aggregate metrics, not raw customer contact details.

## Current limitations and follow-up scope

- No service-usage promotional credit ledger; no hourly/metred discounts or permanent consumption discount.
- Domain, AI Gateway, identity API, usage-based GPU, upgrade and package-specific checkouts are not integrated. They must not be advertised as enabled campaign destinations.
- No arbitrary automation graph, purchased-service event, renewal-near event, usage threshold or abandoned-cart integration.
- No automated promotional email, customer-tag action or separate push-notification action.
- Tags can be used for filtering existing preference data; a full segment/tag administration console is not supplied.
- No A/B/control-group engine, incremental-lift reporting, or unsubscribe-rate funnel.
- No provider delivery-receipt/query integration or real administrator test-SMS endpoint. Unknown results require manual provider reconciliation.
- Global time/frequency limits are configuration defaults; this UI exposes the emergency switch, review flag, tariff, daily budget/count and campaign-specific budget/count.
- No automatic extension of issued offers. To change issued rule promises, create another audited campaign/code rather than edit the original snapshot.
- Live SMS and production deployment remain unvalidated.

## Schema and main files

Migration: website/database/migrations/2026_10_25_180000_create_marketing_campaign_engine.php

New tables: marketing_campaigns, marketing_preferences, marketing_grants, marketing_redemptions, marketing_runs, marketing_deliveries, marketing_events, marketing_audits, marketing_permissions, marketing_dispatch_locks and marketing_payment_reconciliations.
Existing invoice_items receives nullable marketing_context. No historical coupon or order data is rewritten.

Main implementation:
- website/app/Services/Marketing/{CampaignManager,CampaignAudience,CampaignRunner,CampaignMessages,DiscountCalculator,DiscountEngine,InvoiceContext,CampaignReport}.php
- website/app/Http/Controllers/Admin/CampaignController.php
- website/app/Http/Controllers/Account/OfferController.php
- website/app/Models/Marketing*.php
- website/config/marketing.php
- website/app/Console/Commands/MarketingTick.php
- website/resources/views/admin/marketing/campaign*.blade.php
- website/resources/views/account/offers.blade.php
- website/resources/views/account/partials/campaign-discount.blade.php
- website/public/assets/{css/campaigns.css,js/campaign-wizard.js}
- website/lang/{fa,en,tr}/marketing.php

Existing integrations: product/cloud/renewal invoice issuers; Invoice/InvoiceItem; PaymentController/PaymentService; BusinessLedger/AccountingReport; web/console routes and existing navigation/invoice views.
No payment-language visibility rule was removed or bypassed.

## Validation

Recorded before the final synchronization with develop:
- 94 tests, 440 assertions PASS. Filter: MarketingCampaignEngineTest|MarketingDiscountMathTest|MarketingCampaignConcurrencyTest|GiftCouponOnInvoiceTest|BirthdayGiftTest|InvoiceDiscountTest|PaymentCallbackTest|PaymentLanguageVisibilityTest.
- 43 of those are new engine/math/concurrency tests, including the full birthday scenario with simulated time and mock SMS.
- JavaScript wizard test PASS: navigation, required validation and reopening an invalid hidden step.
- Blade view:cache PASS. New UI uses static assets; no new frontend package/build dependency.
- PHP syntax and git diff --check passed during development.

CreditInvoicePaymentTest has three legacy failures concerning the domain-renewal fixture's supplier-price availability. The same three failures were reproduced with unchanged HEAD versions of PaymentController, PaymentService, Invoice, BusinessLedger and AccountingReport. They are not counted as passing and were not weakened. The new discounted-invoice wallet path has its own passing integration test.
The full repository test suite and live provider tests were not run.

## Safe first activation after a separately authorized deployment

1. Back up the application database; deploy matching code and migrate before exposing these pages.
2. Keep the global promotional sender OFF, campaign messages paused and the old birthday program OFF while reviewing a new birthday campaign.
3. Run marketing:tick --dry-run and review recipients, service eligibility, dates, maximum discount commitments and unknown supplier-cost handling.
4. Configure/verify a lawful, supported promotional route using the existing account infrastructure; record actual tariff and country review. Do not change OTP delivery to work around a promotional limitation.
5. Approve a real campaign with explicit budget/rules; separately authorize its messaging policy. Individual approved recipients do not need another approval.
6. Observe the scheduler heartbeat, unknown outbox results, reconciliation rows and financial totals.

These example test percentages/budgets are not a request to activate a real campaign.

## Rollback

Before any live deployment, pause new issuance and promotional messages independently. Preserve valid issued promises and reconcile in-flight payments.
The migration has a down() method for an empty/test installation. It removes the new tables and invoice context, so it is not a safe production rollback after real offers or financial commitments exist. Export/retain the records and restore a reviewed database backup if a schema rollback is necessary.
Revert application code only with matching schema and retained audit/commitment data. No network/route/firewall configuration was changed in this task.
