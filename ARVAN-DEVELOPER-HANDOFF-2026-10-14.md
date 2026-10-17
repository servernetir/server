# Arvan VPS developer handoff — 2026-10-14

## Update — 2026-10-17

The sections below are historical evidence from 14 October, not fresh production acceptance. See [current follow-up](docs/project/ARVAN-FOLLOWUP-2026-10-17.md) for completed test cleanup/backend reconciliation and unresolved delivery/font work. The cleanup monitor is paused and fixture broker disabled. The earlier implementation was pushed as `6e159909ad89cd75657d3901748ddbabdba86d5c`; this Git update is documentation only.

## Scope and production state

- Renewal pricing deployed at 11:58:27 UTC: shared read-only CloudRenewalPrice, fail-closed missing catalogue, forecast tax without saving a service, retained Hetzner/Rclone floors and existing monitored scheduler. See ARVAN-RENEWAL-FIX-2026-10-14.md.
- White-label console is an acceptance fixture only: service456 / instance293 / owner1, exact provider reference and expiry in ArvanConsoleBroker. HMAC, short-lived one-use grants, fixed upstream and strict ownership gates. Never generalize by removing these checks. No secret values belong in Git.
- Independent servernet-vnc Worker on vnc.servernet.cloud/*; console.servernet.cloud portal, remote.servernet.cloud and gpu-proxy wildcard must remain untouched. Existing production Worker version before font patch: 5f23f7e6.
- Font source now declares IRANSans regular/bold, fixed same-origin font endpoints backed by existing portal assets, and font-src self. No cookie forwarding, dynamic upstream, paid service or DNS change. **Font change is not yet deployed**: Cloudflare editor frame failed to load in both browsers. Verify actual font files, computed/rendered font and desktop header after publishing; revert only font routes/CSS/CSP if necessary.
- Linux monthly open; Windows closed. Manual password display, RDP desktop and white-label desktop were demonstrated, but automatic initial Windows password delivery, image licensing and console longevity remain unresolved. Support requested a reachable phone number at14:37; no number sent by agent.
- Budget100,000 toman, no fresh paid resources. Delete only the named test after48h (approximately16Oct05:38UTC; verify provider created_at), independently verify VM/disk/IP absence and reconcile456/293. Existing hourly automation tracks this. Do not merely mark deleted in database.

## Validation

- 12 Node Worker tests pass, including font allowlist/CSP.
- 52 console/broker/customer-page/store regression tests /342 assertions pass on current develop with the same targeted bootstrap.
- 24 targeted renewal tests /82 assertions pass on current develop with copied dependencies, SQLite and explicit vendor/autoload bootstrap. Full runtime bootstrap requires additional unavailable PHP extensions; this is not a claim that the full suite passes.
- Live post-renewal checks: HTTP200 home/Iran sales/portal login; owner456 quote0; missing plan rejected; foreign currency unchanged; no test invoice or debit.

Historical detailed reports are retained. This commit intentionally does not copy unrelated dirty workspace files or alter live settings, secrets, infrastructure, cron or customer services. The repository broker integration records the already-deployed narrow fixture; deployment to another environment defaults off and needs separately authorized credentials.
