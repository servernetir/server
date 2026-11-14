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

Source preparation and tests do not mean production installation. The pending design from PR312 and this coverage extension have not been installed: the connected cPanel browser is unavailable. Existing production renewal source was accepted at `22742b3334f8a46d11aadae782f40464a66d012a`.

`scripts/renewal-service-coverage-release-20261114.py` stages both pending layers relative to that known production source: nine app sources / ten destinations (CSS in app public and public_html). It uses the three release locks, preserves live overlays by three-way merge, refuses conflicts/symlinks/hash changes, creates private backups, lints PHP/Blade, and compiles only affected views. It needs an immutable release SHA for prepare, manifest review, then apply. No migration, global cache clear or route-cache mutation is needed. Verify authenticated previews/actions in all three locales and an actual supported server read-only after installation; do not create a financial operation merely as a live smoke test.
