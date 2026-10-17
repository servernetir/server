# Arvan sales: current production handoff — 17 October 2026

This record supersedes earlier instructions to keep Arvan Windows/hourly sales closed. The owner explicitly approved public Windows sales after being informed that automatic password delivery failed, and subsequently explicitly requested hourly sales to open. **Commercial enablement is not technical acceptance.**

## Current production settings

| Area | Applied state | Evidence and limits |
| --- | --- | --- |
| Linux monthly | Remained available | Not disabled or modified by this task |
| Public Windows | Enabled for 20 existing usable Arvan image references through `arvan_windows_ready_image_refs` | Real customer order form showed 2016/2019/2022/2025; 2025 was selected without purchasing |
| Arvan hourly | `arvan_hourly_sales_enabled=1`, applied 08:48:22 UTC / 12:18:22 Tehran | Customer form accepted hourly selection; Urmia CV-1-3 displayed 1,700 toman/hour and hourly order summary |
| Test console broker | Previous fixture broker remains disabled | Do not enable a deleted-fixture broker for public customers |

Both settings were persisted using `Setting::put`, invalidating the settings cache. Previous values were null and were recorded in private production backups. No rates, other providers, existing contracts, wallet entries, invoices, network routes, DNS, firewall rules, or general scheduler jobs were changed by these sales toggles. No new purchase was submitted during form validation.

The hourly gate and positive upstream hourly-cost requirement are already committed in `e462f448`; renewal/console implementation is in `6e159909`, and actual IranSans publication is recorded in `cec6f3c4`. These database changes are deliberately **not** migrations or seed defaults: checking out develop must not silently open sales in another environment. Do not replace production with the old dirty workspace snapshot.

The post-toggle hourly-capable query counted 397 Arvan rows without applying the sellable filter. This is not a claim of 397 purchasable plans; the earlier readiness audit found 374 sellable Iran rows. Existing capacity/price/image guards still apply.

## Windows password and console: unresolved

- The second test's create request timed out, but the VM existed. Only one create request was sent; exact name/time/reference reconciliation attached it to the owner. Never retry a timed-out create blindly.
- A subsequent provider detail response had no usable password. Because the original POST response was lost, do not claim that its response definitively contained no password.
- The actual first-boot console required changing the Administrator password before sign-in. Automatic initial-password delivery and real RDP for this second test did not pass.
- Earlier user-confirmed RDP/desktop access used manual setup. It is not proof of automatic credential delivery. Manual console initialization and RDP configuration may still require operator assistance.
- The existing method name `providerAccessIsAutomated` and ready-image setting now serve an owner-approved sales exception; a true gate result must not be used as evidence that an image passed automation acceptance.
- End-to-end console access for arbitrary new customer orders, session expiry/renewal, automatic password initialization, and licensing still need a supported provider contract and fresh acceptance. Never expose provider-account credentials or reuse test-only broker scope.

## Test cleanup and actual-cost uncertainty

The second fixture (internal service 491 / instance 309) was created at 07:26:50 UTC on 17 October and deleted early at the owner's request. The driver verified detail absence and absence from regional inventory; independent provider UI views also showed no VM, standalone disk, or Internet IP left in its region. Only its guarded owner/zero-price records were reconciled: service terminated, next due date cleared, instance deleted, and a termination audit event recorded. The single fixture cleanup cron line was removed, preserving other jobs; its monitor was paused. The older fixture 456/293 had already been cleaned up. Do not reactivate these records or recreate test resources.

The latest recorded billing inspection, from the readiness audit around 08:27 UTC, shows **875 rial = 87.5 toman**, solely for 30 GB economic SSD storage, for the second fixture. The provider labels current-month amounts provisional. CPU, RAM, and IPv4 were not itemized in that observation. **This is not the complete final test cost, proof of zero minimum commitment, or proof that 48 hours were charged.** Earlier observations with no row are superseded by this partial amount.

Support previously described a 48-hour deletion restriction; early API deletion nevertheless succeeded. Those facts do not establish final billing policy. The newer cost response referred the question to sales. The owner told the other readiness workflow not to send its prepared follow-up; this handoff sends no support message or scheduled inquiry.

Next: obtain the completed itemization or explicit provider confirmation for CPU/RAM/disk/IP and any minimum commitment; reconcile it against the approved 120,000-toman test budget. Do not attribute the older test's separate charge to this fixture. No further paid tests are authorized by this documentation request.

## Validation, preservation, and rollback

Live checks above verified settings and order-form selection, not payment, new provisioning, or final provider billing. Prior readiness tests (60 tests / 418 assertions) are historical evidence, not a new run in this documentation commit. IranSans header publication was already verified; no Worker was republished here.

If the owner requests rollback, restore only the relevant previous setting through `Setting::put` (hourly to null; Windows refs to their private saved prior value). Do not cancel existing customer services, change prices, restore whole crontabs, or run deployment helpers. Database status restoration cannot recover permanently deleted VM data. Reverting this documentation commit does not revert production settings or charges.

No passwords, API tokens, session URLs, private keys, customer identifiers, or provider authentication URLs are included in this handoff.
