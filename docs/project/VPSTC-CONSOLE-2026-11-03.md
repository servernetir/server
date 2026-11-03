# Dedicated VPS.TC console — 3 November 2026

Initial base: `3aeac46c146becbb069e9a0b07c4cc1b1ca162ea`; reconciled with `develop` through `12e98f06b9d6280464b226085b3d053bd95a1ac2`.

## Scope and current state

The owner approved a custom API-based console, first on their existing VM, and a commercial follow-up. This change independently implements the Proxmox protocol, a default-off owner pilot, single-use broker, dedicated WebSocket relay and three-language shell. It does not install WHMCS, copy proprietary implementation code, buy a VM or change billing/schema.

**Installed, accepted in the browser, and enabled for customers.** This supersedes the earlier failed, disabled pilot. The owner asked to continue fixing the complete console and enable customers. The strict relay subsequently passed repeated browser connections in FA/EN/TR, a visible owner framebuffer, harmless key input and deletion at the login prompt, full-screen expansion and its exit control. Native fullscreen was denied by this browser; the accepted CSS expansion fallback now keeps that control useful. No guest login, command, power or billing operation was performed.

At 11:36:57 UTC the installed owner-only broker was promoted, using a separate one-file manifest, to the tested customer policy. At 11:42:53 UTC persisted `vpstc_console_enabled` became `1` and `vpstc_console_owner_pilot_enabled` became `0`: the owner now uses the same customer path. Worker `ENABLED` is `1`. All three existing active receipt-bound services were eligible. At 11:50:28 UTC a separate one-file release restricted the protocol client to `tr-istanbul`; New York and unknown regions cannot send console credentials to the Istanbul gateway or retain pending grants.

| Acceptance | Actual result |
| --- | --- |
| Earlier direct/provider and relay checks | HTTP 101, RFB 3.8, VNC authentication and ServerInit passed |
| Earlier failed browser attempt | Socket close 1006; Cloudflare recorded `loadShed`, CPU 2 ms and wall time 3,719 ms |
| Current independent browser transport check | Binary WebSocket echo passed; temporary bounded diagnostic was removed from the final Worker |
| Current browser | Owner screen visible at 720×400; harmless keyboard input and clearing verified; repeated FA/EN/TR launches connected |
| Full screen | Native API denied; expansion fallback and exit button accepted |
| Session expiry | Five-minute relay close produced the translated session-ended message; fresh launch reconnects |
| All three current services through the customer relay | Launch 200, upgrade 101, RFB 3.8 authentication and ServerInit passed; handshake times 8.568–12.044 seconds |
| Other-customer access | Installed controller's ownership gate returned 404 under a request-scoped foreign customer guard, before grant/provider work; no customer session was written |
| Mobile | Viewport override did not change this connected browser's actual 1920 px width; physical mobile rendering/input are not claimed |

The earlier Cloudflare failure did not recur in current acceptance. Its root cause was not established, and no particular code change or paid upgrade is represented as its cure. The strict five-minute/frame-bounded relay remains in production. The final Worker also distinguishes an ended session from a failed initial connection. Other customers' guest screens and input were not requested; their checks stopped at ServerInit.

The sales follow-up requesting a written rate and response date was submitted in the existing ticket. No discount is approved. The owner's Turkey relay has auto-renew Off in the native provider widget. A separate ticket reply requests keeping its paid period intact without renewal or immediate deletion. Other services were not changed. Private service/IP identifiers and screenshots are excluded from this public report.

## Reference and protocol

Provider reference: https://github.com/whitelabelservices/whmcs-reseller at `7872bebfde141cea58e5d9d03c3e0fa874af7754`. Its README declares a proprietary license; only the protocol was inspected. The redirect that exposes a PVE ticket in a browser URL and disabled TLS validation are not retained.

Official API: https://pve.proxmox.com/pve-docs/api-viewer/index.html . Under `/api2/json`: POST `/access/ticket`, GET `/cluster/resources?type=vm`, POST `/nodes/{node}/qemu/{vmid}/vncproxy` with `websocket=1`, and GET `/nodes/{node}/qemu/{vmid}/vncwebsocket` using the returned port and VM-scoped VNC ticket.

The owner supplied the provider's answer in technical ticket 876841: Istanbul uses `https://console.whitelabelservices.us:443` (the same host serves API and browser console); New York uses `https://nyc1.whitelabelservices.us:8006`. Only Istanbul was tested and is configured. The New York host was not added to the console allowlist. The provider confirmed that the tested account exposed only its own VM and needed neither a permission change nor paid enablement. This does not settle commercial ticket 313805.

Live responses serialize the port as a decimal string and use modern Proxmox's eight-character password plus compatibility-prefixed scoped ticket. The client strictly normalizes only ports 5900–5999, keeps RFB `password` separate, and validates the compatibility prefix before using the scoped ticket. Legacy ticket-as-password fixtures remain covered. Protocol reference: https://github.com/proxmox/qemu-server/blob/master/src/PVE/API2/Qemu.pm .

## Security and limitations

- Existing customer ownership, CSRF, writable state and rate limits remain. The installed customer policy uses `vpstc_console_enabled`, which defaults off in source and is enabled in this deployment for active customers with eligible Istanbul receipt-bound services. The separate owner pilot is off. Global provider capability and sales flags are unchanged.
- Local unique supplier reference and encrypted adoption/purchase receipt bind owner, instance, reference and API account. Each grant also binds the approved upstream origin. Ownership/state/account are rechecked at issue, redemption and socket claim.
- Backend fetches parent-service credentials, never guest root/SSH credentials. Login username must match, inventory must expose exactly the expected single QEMU VM, and cross-VM visibility is rejected. All upstream requests verify TLS, disable redirects and never retry.
- Random fragment grants last 90 seconds and are consumed under a shared lock. Backend session data is encrypted for 30 seconds. Browser receives only VM-console-scoped VNC challenge material; parent/service password, PVE login cookie, CSRF, node, VM ID and upstream host stay private.
- Worker uses a host-only Secure/HttpOnly/SameSite=Strict cookie plus per-tab binding. Duplicate cookies, reused claims and a tab whose cookie was replaced are rejected. Fresh fixed upstream headers exclude browser/portal credentials. Relay closes both sockets after five minutes and bounds client/upstream frame sizes. No raw errors, payloads, keystrokes or credentials are logged.
- Active streams are bounded to five minutes; ownership revocation is enforced at launch/claim, not continuously during an already established stream. Reconnect requires a new launch.
- Only the exact Istanbul gateway origins are allowlisted. Fresh backend login, grant, upgrade and RFB auth pass for all three current services. The owner's complete browser framebuffer/input path also passes. Serial/LXC, other customers' framebuffer/input and New York acceptance are outside the verified scope; unaccepted regions are gated off.
- Browser CSP allows the new relay only as a form-redirect destination on the eligible authenticated customer's own service detail page, in fa/en/tr. It does not add the host across the portal or payment pages.
- Current Cloudflare dates deliver binary messages as Blob by default. Both sockets explicitly select ArrayBuffer before acceptance; see https://developers.cloudflare.com/changelog/post/2026-04-21-websocket-standard-binary-type/ .
- The browser client is bundled from the repository's vendored noVNC/Pako sources, with license/source notices, and served by the Worker as one content-addressed module. Both build-time parsing and a separate Node syntax check cover the actual served string. Its verified public SHA-256 is `cbd3e54b19092a8e3f4bc2aeec7269b4ad611bd7a92188edea9362b0e2a4c620`. Static module delivery is not console acceptance.

## Installation and acceptance

Use the project deployment procedure: release lock, exact-byte snapshots, private backups and hash-guarded installation. Install new classes before the small controller/route additions; do not replace live route/controller files wholesale. No migration, financial cron or shared cache flush is needed. Preserve the separate Arvan console infrastructure.

The separate Worker, dedicated hostname `vps-vnc.servernet.cloud`, exact route and separate shared secret were provisioned. The application's existing DNS token returned HTTP 403 for the Workers API; deployment used the existing authenticated Cloudflare dashboard without expanding that token's permissions. See `scripts/vpstc-console-worker/README.md` for the locked build and upload artifact. Worker `BROKER_KEY` and encrypted Laravel `vpstc_console_worker_key` do not reuse the Arvan key. Public settings are `vpstc_console_pve_origin`, `vpstc_console_owner_service_id`, `vpstc_console_owner_pilot_enabled`, and the separately gated `vpstc_console_enabled`.

Production's route comment, rate limit and formatting differed from Git. Surgical insertions preserved them, as well as all unrelated controller and middleware bytes. Prepare checks stopped on drift before source installation; the validated packages then installed three new classes and narrow controller, route and SecurityHeaders additions. Private manifests record exact before/after hashes. No shared cache flush, migration, cron, guest power, guest credentials or billing state was changed.

First confirm valid provider HTTPS API access/certificates. `php artisan cloud:vpstc-console-probe LOCAL_OWNER_SERVICE_ID` creates an unused expiring console grant, prints no credentials, and does not enable the pilot. Investigate TLS/auth/scope failures without bypassing protections. If the gateway is not the API endpoint, request the actual approved hostname.

The owner pilot and subsequent customer promotion were accepted in the stated scope above. Keep future region expansion gated until its own approved upstream and VM acceptance exist. Do not exercise Ctrl+Alt+Del on a guest simply to test its button. All browser screenshots and service identifiers remain private, outside this public repository.

## Validation and rollback

At source commit `8411dc67e5a282d56dedcd60fa4c3b7a1c6bb368`, GitHub Actions run https://github.com/servernetir/server/actions/runs/41630530502 passed PHP 8.4 with **286 tests / 2,410 assertions**, separate MariaDB with **43 / 206**, and the locked bundle build with **19 Node tests**, zero failures. Local Node build/checks also passed 19 tests. The new region test verifies that New York, unknown and null locations cannot use Istanbul, and that a pending grant is revoked without an upstream request. Production's release helper lint and exact before/after hash guards passed. Local PHP CLI was unavailable in the resumed environment; PHP acceptance is the inspected CI and production lint, not an invented local run. Review: https://github.com/servernetir/server/pull/251 .

The customer promotion's broker hash is `5901dcda9cee83fa9684cd1d55de855e5028f4a5a79b9215e9b9e3a2a0e01ca0`; the region-bound protocol hash is `3d4b852fc2e775831ca0fd662eecd87ada1fff0eb7a6c77d275cfed0b56b4b71`. Private backups/manifests and activation/handshake receipts are retained under `storage/app/private/vpstc-console-customer-20261103` and `vpstc-console-istanbul-scope-20261103`. The install helper's `enabled:false` receipt field means it did not activate a flag; the separate persisted activation receipt is authoritative for runtime flags.

Rollback starts by disabling the customer and owner-pilot flags, then this dedicated Worker. Restore only the installed manifest-listed files/hunks, with hash guards and in reverse dependency order: region scope, customer broker, then any initial pilot files if necessary. A route rollback touches only this dedicated Worker. Do not flush shared caches, reset APP_KEY, roll back customer records, change VM state or modify the existing Arvan infrastructure.
