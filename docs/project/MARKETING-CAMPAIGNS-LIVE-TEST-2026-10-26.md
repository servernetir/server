# Live private campaign acceptance — 2026-10-26

The owner explicitly requested a live test campaign and sample percentage/fixed discount codes. Two bounded campaigns were created on the deployed production engine; this is a synthetic-account test, not a public promotion or sale.

## Current trial

| Field | Percentage | Fixed |
|---|---|---|
| Admin page | /admin/marketing/campaigns/1 | /admin/marketing/campaigns/2 |
| State / trigger | Active / scheduled | Active / manual |
| Rule | 10%, cap 50,000 IRT | 25,000 IRT |
| Code | SN-8B1C0E37E6696D33AEAC | SN-4E211DD17B0A9389CEB1 |
| Uses / per customer | 1 / 1 | 1 / 1 |
| Minimum eligible purchase | 100,000 IRT | 100,000 IRT |
| Budget / held guarantee | 50,000 / 50,000 IRT | 25,000 / 25,000 IRT |
| Spent / paid orders | 0 / 0 | 0 / 0 |

The sole allowed audience is synthetic customer 592, public code SN-978032, profile label «حساب آزمایش کمپین». It has no phone, an example.invalid email and a random unknown password. Both grants are private to this account; other customers cannot apply them. This test account remains for review/retesting; the temporary negative-test account was rolled back.

Both campaigns/grants expire **2026-10-27 09:32:37 Asia/Tehran** (06:02:37 UTC). Existing scheduler expiry handling releases unused guarantees. There is no recurring grant or permanent credit. No public code was issued.

The live CampaignController save/activation paths ran at 09:32:37 Tehran. For the scheduled campaign, the cursor began immediately before the sole test account. The existing natural scheduler issued the percentage code at **09:33:13 Tehran**, without a manual tick. A later heartbeat at 09:36:13 still showed one grant/run; direct issueDue replay returned false. The fixed code was issued through CampaignManager to the same account.

message.enabled=false, messages_paused=true and campaign message limits/budgets=0. No outbox/SMS was created. Global sender policy, n8n-relay and OTP infrastructure were unchanged; the unsupported promotional free-text route was not bypassed.

## Live invoice results

The deployed OfferController apply action and DiscountEngine ran against MariaDB in synthetic authenticated-customer context. This is direct controller/service integration; a browser login/CSRF flow or paid gateway checkout is not claimed. Outgoing HTTP was blocked and mail/notification/queue transports faked in the test process. A SQL guard rejected payment, credit, business/accounting and other unrelated writes.

Fixtures each contained 200,000 IRT eligible VPS service, 50,000 IRT ineligible setup and frozen 10% fixture VAT: total 275,000 IRT. Site tax configuration was not changed.

| While reserved | Percentage | Fixed |
|---|---:|---:|
| Service discount | 20,000 | 25,000 |
| Setup discount | 0 | 0 |
| VAT | 23,000 | 22,500 |
| Invoice total | 253,000 | 247,500 |
| Actual paid / wallet credit | 0 / 0 | 0 / 0 |
| Evidence invoice | 618 / INV-261026-8123 | 620 / INV-261026-4240 |

All **45 live assertions passed**: forged client amounts ignored; exact discount/tax; duplicate application idempotency; one live reservation per private grant; stacking blocked; 50,000 IRT percentage cap; purchase minimum; currency mismatch; unmarked lines; private ownership; expired-code backend rejection; capacity; scheduler replay; reporting; zero payment/wallet/service/SMS side effects.

Both evidence invoices were canceled after application. Their totals returned to 275,000 IRT with zero discount, and redemptions are released. They are not collectible debts or paid sales. Extra cap/currency/ownership/expiry/reuse fixtures used nested transactions and were rolled back. Codes remain valid within the private test scope until expiry. Each report correctly shows one grant, one released checkout and zero sales/spend. Held 75,000 IRT is a bounded discount promise, not a wallet debit.

Two admin detail views and fa/en/tr offers rendered inside a MariaDB read-only transaction, with both codes present. Site returned 200; unauthenticated campaigns/offers returned 302 to login. Browser visual inspection and real SMS delivery are not claimed.

## Persistence and rollback

Affected app: core.servernet.cloud (65.109.176.14), /home/servernetcloud/servernet_app and production MariaDB. Only test customer/profile, campaign/grant/run/audit/event and canceled invoice/redemption data persisted. No source deployment, schema change, global policy, host cron, VM, route/NAT/firewall/DNS or agent source/state changed. Existing scheduling advances the bounded campaign.

Private scripts/receipts: /home/servernetcloud/marketing-live-test-20261026-1792994209428, outside webroot, directory 0700/files 0600. [Acceptance JSON](marketing-campaigns-live-test-20261026/acceptance.json) contains checks and observed state. Creation/acceptance helpers refuse existing receipts; inspect state before any rerun.

To stop early, pause campaigns 1/2, confirm reservations remain released without payments, then revoke grants 2/1 with an audit reason through admin UI. Revocation releases the 50,000/25,000 IRT promises. Preserve canceled invoices/audits. No wallet compensation, full SQL restore, migration rollback or unrelated service action is needed. Recheck live state because administrators may change the trial later.

## Access cleanup

The publication's expired temporary key was independently found still authorized. Its exact line and matching public file were removed on 26 October, preserving all other lines and the main key; local private/public files were deleted. This closes the pending cleanup in the 25 October record. The current run's key cleanup is recorded in acceptance.json after final verification.

Current trial cleanup completed at 2026-10-26 09:42:20 Tehran: exact temporary authorization/public file removed, other/main keys preserved, attempted reuse rejected with SSH exit 255, independent cPanel read confirmed absence, and local private/public files deleted. No temporary public HTTP helper was created by this trial.
