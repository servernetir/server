# Customer API: GPU/VPS management and product coverage

Base: `b12fb50fd333c95a99e5836939f9993c3c6f0397`, `develop`. Status: installed and verified in production on 10 November 2026. PR #307 merged into `develop` as `1b7fe1b88f6c0a4474ee0a8f7ea441d673e21291`; deployed source head `f9cfd5b362c076efac5cdd859b1775a8bff9d536`.

## Shipped contract

- Account navigation → API & developer center (`/account/api`), public `/developers/platform`, Persian/English/Turkish, OpenAPI 3.1 download.
- Existing CustomerApiToken remains the single authority. Explicit `cloud:read` and `cloud:power`; legacy `*` receives neither. Optional owned service-ID restriction, existing hash-only storage, expiry, revocation and CIDR checks. No provider or server password is returned by this API.
- GPU-Jupyter and VPS: inventory, stored details and prices, observed status, metrics, Start/Stop/Reboot when the resource supports it. `/api/v1/cloud/{service}/actions/{start,stop,reboot}` requires `Idempotency-Key`; receipts at `/api/v1/operations/{uuid}`. The service ID is the account's ID, never a provider reference.
- All product service records and invoices remain available. Added detailed owned service, invoice breakdown and consistent wallet snapshots; existing list response shapes are preserved.
- All `/api/v1` responses receive `no-store, private`. No session authentication or CSRF exemption is added to panel routes. API requests still require Bearer even if a browser is signed in.

## Financial and lifecycle invariants

The panel and API call the same CloudPower service and existing HourlyGpuStart/InterruptibleBillingClock/Wallet. Restart reserves one available IRT hour, including existing wallet reservations; settlement requires a later verified Running observation. A repeated Start on an observed-running GPU is a no-op and cannot reset the billing anchor. Accepted Stop does not mean Off: preserve its reservation until a later matching Off observation following an explicit stop request. Noninterruptible/periodic off-time charges continue.

A 300-second service lock serializes panel/API power; unique SQL `(customer_id,key_hash)` claims outlive cache eviction. Every retried key retains its original command/result. New keys cannot bypass a processing/uncertain dispatch. Unknown provider results do not automatically retry; a newer matching status reconciles, or a later confirmed safe Stop supersedes older unresolved actions. A crashed processing lease permits safe Stop after five minutes, without replaying the abandoned Start. API callers poll status separately from the dispatch receipt. Token revocation does not stop an existing rental; AI/domain daily caps do not cap continuous cloud rental.

Amounts use the recorded currency's integer representation, IRT=toman, EUR=cents. Detailed invoice and wallet output reports currency registry exponent; invoice tax/discount/paid amounts are read as recorded, not recomputed from current tariffs. Wallet locks customer first and computes available from one captured balance/reservation pair. Scope `read` is independently account-wide; cloud service restriction does not narrow `read`.

## Product coverage and completion plan

This is **not** a claim that every panel operation now has an API. No unsupported route is advertised as available.

| Product / operation | Current API coverage | Next implementation and acceptance gate |
| --- | --- | --- |
| Account/services/invoices/wallet | Read profile, all service records, invoices, credit and wallet holds | Paginated/filterable legacy lists; profile mutations with field policy and reauthentication |
| GPU hourly/Jupyter | Start, Stop, Status, metrics, supported actions, billing and durable receipts | Capacity/order/quote/cancel; same GpuCapacityOrders terms, initial 24h funding and immutable price confirmation |
| VPS/cloud/dedicated | Existing service records, power/status/metrics where supported | Quote/order/renew/upgrade, snapshots/rebuild/rescue/rDNS, verified instance capability checks; destructive operations need explicit consent and step-up policy |
| Windows/Linux/reseller hosting | Owned service and invoice records | Upgrade quotes and pay/apply through HostingUpgradeService, provisioning, renewal and temporary console/SSO grants; no credential export by default |
| Storage | Owned service/invoice records | Storage-specific provisioning, share/key operations and reconciliation; distinct provider tariffs and credential policy |
| Domains | Existing domain API: catalog/check/register/renew/nameservers/auto-renew/lock | Preserve reseller pricing, spend caps and supplier ledger; EPP/unlock/owner change require deliberate step-up design |
| AI Gateway | Existing project-bound AI API and model documentation | Keep its reservations, usage limits and native model contracts; do not merge charging engines |
| Identity API | Existing application-bound inquiry/catalog/request API | Keep application scopes, consent, price versions and personal-data controls |
| Exit/tunnel | Existing tunnel account and agent API | Maintain one-time secrets and pending-vs-delivered distinction |
| Managed DNS | Account UI; no new token API in this release | Zone/record ownership, versioned updates, DNSSEC and destructive-change policy |
| SMS / Cloud phone | No new token controls in this release | Shared service provisioning and tariff/recipient controls, explicit cost ceiling and idempotency |
| Tickets / refunds / account security | Panel workflows | Visible-message-only support API, refunds through existing workflow, step-up for sensitive account mutations |

Order: GPU/VPS lifecycle and financial read foundation → quote/order/renew/hosting upgrades → DNS/storage/support → SMS/phone → step-up sensitive controls. Each product requires exact request/response schema, explicit scopes, tenant tests, replay/timeout/financial tests on SQLite and MariaDB, and live non-costly acceptance before documentation says available. Do not tunnel all panel routes through a privileged API adapter.

## Validation and independent review

Independent source review identified repeated-Start billing-anchor resets, premature stop-reservation release, blocked safe shutdown and owner changes during status reads; these were addressed in shared code and behavioral tests. Tests use synthetic customers, mocked providers and no paid operations.

SQLite/PHP 8.4.27: 63 tests / 734 assertions passed, including the new API, migration, hourly runtime, powered-off billing and three-language account-workspace presentation suites. The source manifest dry-run preserved a production-only overlay, left live fixtures unchanged and rejected a concurrent write before installation. Existing GpuDeliveryE2e has five baseline failures/errors; existing ResellerDomainApi has twelve baseline failures, reproduced unchanged on the base SHA. They are not claimed green. Local MariaDB cannot create its required Unix socket under the environment policy; the dedicated CI matrix runs disposable MariaDB 11.4.

## Release and rollback

Install only the explicit changed web files with expected-before hashes, private backups and the shared deployment lock. Preserve production-only overlays through a reviewed three-way merge. Run only the new `2026_11_10_000100_create_customer_api_operations.php` migration, never all pending migrations. It adds a new operation ledger and nullable token resource restriction; no existing financial tables or token permissions are rewritten. Activate only affected routes/views/OPcache, then verify hashes, three locales, token denials and authenticated read-only requests. Do not Start/Stop a customer's GPU for deployment testing.

Rollback: restore only manifest files after checking no newer edits. Keep additive schema and operation receipts; do not run migration down on production, discard audit history, alter customer wallets or reset pending funded hours. Reconcile ambiguous operations before reopening any affected automation.

## Production acceptance — 10 November 2026

- Twenty-five explicit destinations installed, all post-install hashes verified; existing production-only `routes/web.php` overlays preserved. Three shared release locks, private backups and expected-before guards were used. Only the new additive operation-ledger/token-resource migration ran; four affected Blade sources were compiled. No broad cache flush or FPM restart.
- Six actual external authenticated read requests returned HTTP 200 and `ok=true`: inventory, owned cloud details/status, owned service, wallet and invoices. Observed status was Off; supported actions Start/Stop and paused off-time billing were confirmed for the inspected hourly GPU. A foreign service returned 404 and missing Bearer returned 401. Temporary read-only key was revoked. Wallet ledger count, balance, service state and restart reservation remained unchanged; no power request or paid provider operation was sent. Existing restart policy was enabled.
- Account navigation and developer workspace, security scope checkboxes, optional resource restriction, expiry and CSRF fields were inspected in the live customer view. Public fa/en/tr references rendered; desktop and 390px mobile checks found no page overflow. Staff impersonation ended after inspection. Customer support response was sent after acceptance; no token was included.
- Expanded local PHP 8.4.27 suite: **112 tests / 958 assertions passed**. Dedicated cloud API checks passed on **both SQLite and MariaDB 11.4** in CI; the broader triggered workflow checks, including hourly funding/birthday and Arvan finance, passed. A legacy metering fixture was corrected to model an actually stopped provider before Start and a later verified Running response, retaining its charging assertions.
- Remaining product-operation coverage is tracked in [issue #308](https://github.com/servernetir/server/issues/308). This release completes the requested existing-GPU Start/Stop/Status use case and establishes the shared API foundation; it does not declare unfinished purchase/renewal/DNS/SMS/phone controls available.
- Public entry point: https://servernet.cloud/developers/platform ; account workspace: https://console.servernet.cloud/account/api . Private installation/acceptance receipts and backups are retained outside webroot. Rollback remains limited to release files and targeted compiled templates, retaining the additive schema and audit receipts.
