# Supported service renewal periods — 2026-11-14

Base: develop `b4b0fb17cb9d57294bd1cfbffe3dfdb776ce4ccd`.

Customers can select future prepaid renewal terms on existing delivered services. Windows Plesk, Linux WHM/cPanel and DirectAdmin use the matching active hosting product's purchase cycles, pricing and discounts. The existing safe monthly-contract hosting fallback is unchanged. No customer-specific flag or migration is required.

## Cloud coverage

| Allocation | Offered periods | Settlement |
| --- | --- | --- |
| Hetzner Cloud, Arvan, Proxmox, LightNode, Salad with verified cycle billing | Current recurring checkout cycles | Ordinary paid-invoice service renewal; underlying usage allocation continues |
| VPS.TC | Monthly only | Existing native supplier renewal queue/readback; no local due-date advance before supplier confirmation |
| Aeza, OVH/manual dedicated, unknown drivers | Not offered by this selector | Supplier term reconciliation must be implemented/verified first |
| Hourly services | Not offered by this selector | Existing wallet/hourly conversion workflow remains authoritative |

Cloud quotes use the actual allocated `cloud_plan_id`, provider and location binding, the saved IP addons, and `CloudStoreController::priceForCycle`. This preserves checkout-specific period limits, discounts and procurement cost floors, including no base-plan term discount for LightNode/Salad/VPS.TC. Current cloud pricing snapshot and plan quarantine are checked; no hosting-contract fallback is used for clouds. New-sales stock/manual switches alone do not block renewal of an existing allocation.

Missing/deleted/mismatched allocations, lifecycle review, unverified billing, unsupported addon keys/counts, zero/unknown prices, stale FX snapshots, quarantined plans and pending native renewals cannot create a renewal quote. Preview hashes bind allocation identity, plan reference, addons and supplier due boundary. Service, outstanding invoices, instance and plan are locked before requoting and issuing. Ownership, session expiry, partial-payment/payment-evidence protection and idempotent invoice/payment handling remain in place. Issuing a renewal does not grant unpaid time.

The shared renewal page uses generic service copy in Persian, English and Turkish and returns to the correct hosting/server list. The server management page has a dedicated renewal section outside deletion controls; server list cards and invoice actions reuse the existing selector link. Supplier identities stay private.

## Validation

- 87 PHP tests / 656 assertions passed: service coverage, hosting cycles (WHM/Plesk/DirectAdmin), native VPS.TC reconciliation, billing anchors and hosting upgrades.
- Additional account/panel/payment/coupon/hourly regression: 73 of 74 tests passed, 331 assertions. One pre-existing hourly procurement-floor assertion expects 11,880,000 but receives 9,600,000; reproduced unchanged against the entire base tracked source at the SHA above. This release does not modify the converter or its pricing paths.
- Actual Laravel HTML in Chromium: 72 locale/theme/viewport/payment states, 2,556 checks passed.
- No production invoices, payments, supplier API operations or schema changes performed during these tests.

## Production status and narrow release

Production accepted at 2026-11-14 08:43 UTC. Immutable release `3f176c8f443382f1932f1035511f4adf423ee10b` installed both the pending PR312 design and PR313 service coverage. Nine sources / ten destinations passed final SHA-256 verification with no drift. The three-way merge preserved the live cloud management page overlay; private backups and receipts were retained. No schema or financial mutation occurred.

`scripts/renewal-service-coverage-release-20261114.py` stages both pending layers relative to that known production source: nine app sources / ten destinations (CSS in app public and public_html). It uses the three release locks, preserves live overlays by three-way merge, refuses conflicts/symlinks/hash changes, creates private backups, lints PHP/Blade, and compiles only affected views. It needs an immutable release SHA for prepare, manifest review, then apply. No migration, global cache clear or route-cache mutation is needed. Verify authenticated previews/actions in all three locales and an actual supported server read-only after installation; do not create a financial operation merely as a live smoke test.

### Live acceptance

- Authenticated customer hosting preview rendered all four periods in Persian, English and Turkish with resolved translations; the new design and current catalog pricing were visible.
- A real delivered Hetzner Cloud service exposed the selector from the server list; its web preview rendered four periods, four POST forms, the server-list return link and cloud pricing explanation. Read-only production quote checks also returned all four cycles for an existing Arvan allocation.
- A VPS.TC sample had no valid current quote and therefore exposed no purchasable period, as intended; code support remains monthly-only when pricing and native renewal prerequisites are valid.
- The original customer's service remained monthly with the original due boundary; its prior invoice remained unpaid with zero paid amount. No selection/issue/payment/provision action was submitted during acceptance. The browser was returned from customer impersonation to administration.
- Actual web execution of the new controller and ServiceRenewals class was confirmed by successful previews calling the newly added `unavailableReason` method. No manual OPcache reset, generic cache clear or route-cache mutation was performed. The attempted same-account FastCGI observation could not open its temporary primary and did not invalidate anything; the HTTP-denied temporary directory was moved back outside public_html. Do not report that attempt as successful FPM activation.
- Screenshots were used internally for visual inspection; customer identity/payment screenshots were not committed or saved as deliverables.
