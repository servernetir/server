# Report consultations and order resume — 26 October 2026

The owner approved growth priorities report → consultation and unfinished-order recovery. This release implements an inbound consultation form attached to a valid report and a customer-owned resume panel. It does not implement automatic email recovery, revenue attribution or new financial actions.

## Final behaviour

- Public report pages show a fa/en/tr consultation form with explicit permission to reply to that request, optional phone, CSRF, bounded validation, honeypot and IP rate limit. Reports older than 30 days offer a fresh check instead. No client score, URL, campaign, status or notification flag is trusted.
- Valid submissions use the existing isolated incoming CRM (`source=site-chat`) and existing staff Bale notification. The summary records persisted report id, host, score, source and consultation-reply-v1 evidence. These contacts do not enter cold marketing. A report/email pair has one lead; distinct validated messages append inbound follow-ups, while identical retries produce neither another message nor staff alert. Legacy chat ingestion without the optional message key keeps its existing identity.
- Reports send private/no-store, no-referrer and noindex headers. Their layout omits analytics and the chat widget so report access tokens and entered contacts are not transmitted to those integrations. Ordinary pages keep their existing integrations.
- The account dashboard offers at most three eligible existing unpaid initial service orders, with original service name, billing cycle, invoice amount/currency and an ordinary link to the existing invoice page. It removes duplicate todo links for those displayed invoices only.
- Expired, renewal, staff-created, activated, canceled, partially/fully paid, domain-only and top-up invoices are excluded. So are unresolved payment attempts (including expired pending), pending bank/crypto/SnappPay states, uncertain marketing reconciliations and invalid discount reservation state. Only the latest open invoice of a service can appear. Current payment and availability checks remain on the invoice page.

No order, payment, coupon, wallet credit, provider request, automated customer message or marketing subscription is created by the resume panel. Report requests are CRM work, not sales. No report-view or invoice-sales attribution is claimed.

## Development validation

PHP8.4.27/isolated SQLite and synthetic fixtures: **67 tests / 516 assertions pass** for ReportConsultationTest, CheckoutRecoveryTest, ChatLead matching regression, GiftCouponOnInvoiceTest and PaymentLanguageVisibilityTest. Includes actual dashboard GETs in fa/en/tr, ownership, duplicate CTA avoidance, payment uncertainty, follow-up and retry semantics, trusted-source facts, failed staff notification, privacy headers and analytics/chat exclusion. Blade compilation and diff checks pass. The entire unrelated suite, MariaDB concurrency and real payment/provider delivery were not run.

## Deployment design

The reviewed helper scripts/deploy-growth-conversion.py defaults to preflight; it makes zero application/DB writes in that mode (release lock files may be opened/created). It holds both existing release locks, rejects existing target/stage/route cache, preserves live shared-file differences through exact insertions, snapshots sources privately, lints staged files, checks before-write hashes and verifies final hashes. Failed installation restores only its own matching writes. It never migrates/clears data or creates credentials/public helper endpoints.

Production installation and acceptance are recorded separately after actual execution. A dedicated private cPanel Git checkout and its native one-time deployment task are used; no host cron or SSH key is required. A branch-specific .cpanel.yml invokes only the reviewed helper and is not added to develop as a general autopublish policy.

## Stop / rollback

Retain incoming CRM history and audits. To disable the form, remove its reviewed report-view inclusion/POST route under current hash checks; private-report headers may remain. To disable resume cards, remove its reviewed account-view inclusion/controller data insertion. Use private release before snapshots only when installed hashes still match; never overwrite later colleague edits or restore the database. No migration rollback or wallet compensation is necessary.
