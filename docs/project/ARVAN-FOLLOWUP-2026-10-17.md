# Arvan VPS follow-up — 2026-10-17

## Scope and evidence

Documentation-only handoff based on develop `b880e2dc` and this task's recorded observations. Implementation commit: `6e159909ad89cd75657d3901748ddbabdba86d5c`. No production deployment, financial operation, new resource, credential, DNS change or sales-setting change is part of this update. Private operational inventory and console authentication URLs are deliberately omitted.

## Completed versus pending

- Renewal pricing changes were deployed and narrowly checked on 14 October; see the historical [renewal report](../../ARVAN-RENEWAL-FIX-2026-10-14.md). This is not a fresh full-suite or production-health claim.
- The isolated console fixture demonstrated an actual Windows desktop. Manual password display and user-confirmed RDP login are not automatic password provisioning acceptance.
- IranSans source and tests are already in Git under `scripts/arvan-console-worker/`. Publishing remains unconfirmed: the Cloudflare editor failed with `NLS MISSING: 1833`. The last recorded deployed Worker version is `5f23f7e6`, without the font patch.
- Official deployment tooling was investigated, but no new deployment credential was created. A proposed temporary, single-Worker credential still needs explicit authorization; do not broaden access or alter the portal/RustDesk Workers or routes.
- Automatic initial Windows password delivery, image licensing and console session longevity remain unresolved. The last support response observed requested telephone clarification, not a technical resolution.
- Required sales posture remains Linux monthly available, Windows gated until acceptance. No fresh live sales-setting verification is claimed by this documentation update.

## Cleanup completed — 17 October update

After fresh explicit owner confirmation, the exact test VM was permanently deleted. Independent provider views showed no remaining regional VM, volume, or public IP for this fixture. The live backend was then reconciled in a transaction with row locks and exact owner/service/provider identity and zero-price guards: service 456 is `terminated`, instance 293 is `deleted`, and a staff `terminate` activity event was recorded. A separate post-read verified these states. The fixture broker setting is now disabled and the cleanup monitor is paused. No invoice, wallet, sales setting, other service, network route, firewall, or DNS was changed. Deletion is not reversible by restoring database statuses; do not reactivate the fixture or buy a replacement under the exhausted budget. Actual final provider charges remain unverified.

The Cloudflare editor was checked again in an authenticated Chrome session: code pane remained blank and Deploy disabled; active version remained `5f23f7e6`. No font publication or new credential is claimed. A written technical follow-up was submitted to the existing provider ticket requesting the supported Windows password initialization contract and console expiry/renewal contract, explicitly forbidding new resources or charges.

### Historical overdue observation (superseded by completion above)

The sole owner acceptance fixture (internal service 456 / instance 293) was last observed running at 2026-10-17 05:35 UTC. Provider UI creation time was 14 October 09:08 Tehran, to minute precision; the 48-hour cleanup deadline was 16 October 05:38 UTC. Cleanup has **not** been verified.

The cPanel execution session expired. No new human confirmation for permanent UI deletion was received in this task; scheduled heartbeat messages are not that confirmation. An authenticated, authorized execution path or owner action is needed. The approved total cap remains 100,000 toman. The time-based estimate at the last observation was approximately 124,800 toman assuming the original hourly rate and 10% tax; this is not an actual provider invoice or itemized usage reading. The owner has been warned about the overdue cleanup and budget overrun risk.

Before acting, verify the exact fixture identity and owner against the private operational record and live backend. Delete only its VM and any dedicated billable disk/IP not used elsewhere. Independently verify absence at the provider before closing service 456 and marking instance 293 deleted with an audit event. Do not simply set database status to hide a surviving resource. Preserve other services and existing invoices. Disable only the fixture-specific console access if appropriate after reconciliation. Stop the existing cleanup monitor only after actual cleanup is confirmed.

## Next acceptance and validation

1. Cleanup is complete; do not create a replacement paid resource under the exhausted budget.
2. Obtain the provider's supported Windows initialization contract and resolve licensing; do not treat manual initialization as automatic delivery.
3. Publish the reviewed font change only through an authorized path, preserving existing Worker variables/secrets and routes. Verify font assets and rendered header on a real authorized console session.
4. Keep Windows sales gated until end-to-end password delivery, actual RDP and customer-console acceptance are evidenced.

This Git update changes documentation only. `git diff --check` passed. `node --test scripts/arvan-console-worker/worker.test.mjs` was rerun against base `8f9e6333`: 12 passed, zero failures/skips, with no paid provider calls. No PHP suite or new live Windows acceptance test was run. Historical PHP test counts remain in the original handoff. Reverting this documentation commit has no infrastructure rollback effect and cannot undo deletion or accrued provider charges.
