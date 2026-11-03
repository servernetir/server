# Dedicated VPS.TC console — 3 November 2026

Initial base: `3aeac46c146becbb069e9a0b07c4cc1b1ca162ea`; reconciled with `develop` through `12e98f06b9d6280464b226085b3d053bd95a1ac2`.

## Scope and current state

The owner approved a custom API-based console, first on their existing VM, and a commercial follow-up. This change independently implements the Proxmox protocol, a default-off owner pilot, single-use broker, dedicated WebSocket relay and three-language shell. It does not install WHMCS, copy proprietary implementation code, buy a VM or change billing/schema.

**Installed for testing, then disabled; customer acceptance failed.** Six Laravel sources and a separate Cloudflare Worker/domain/route were installed with private backups and hash checks. Fresh backend login, single-VM inventory, console grant, WebSocket upgrade, RFB 3.8 authentication and ServerInit passed, both directly and through the relay. The rendered browser console did not reach a verified framebuffer or input test. It must not be described as a working customer console.

At 10:56:57 UTC, both Laravel `vpstc_console_owner_pilot_enabled` and `vpstc_console_enabled` were explicitly set to `0` and read back as false. Worker `ENABLED` is also `0`. The draft PR is not merged. The production broker remains the installed owner-only version; the broader, default-off customer policy exists in the PR but was not promoted to production.

| Acceptance | Actual result |
| --- | --- |
| Direct provider RFB | HTTP 101, RFB 3.8, VNC auth and ServerInit verified at 10:20:20 UTC |
| Same path through ServerNet relay | Launch 200 in 5.614 s; upgrade/RFB in 8.772 s; auth and ServerInit verified at 10:25:54 UTC |
| Browser | Failed; a later launch returned 200 but its socket disconnected with code 1006 |
| Cloudflare observation | `/socket` invocation recorded `outcome=loadShed`, CPU 2 ms, wall time 3,719 ms, and an internal-error reference |
| Screen, keyboard, mouse, mobile, fullscreen | Not accepted; no successful guest-screen claim |

The Cloudflare record is evidence of the failing invocation, not a proven root cause or evidence that a paid plan would fix it. A temporary owner-only direct socket handoff also failed to establish browser acceptance; it was reverted. The final source retains server-side five-minute/frame bounds. Do not enable customers on the strength of the isolated or ServerInit checks.

The sales follow-up requesting a written rate and response date was submitted in the existing ticket. No discount is approved. The owner's Turkey relay has auto-renew Off in the native provider widget. A separate ticket reply requests keeping its paid period intact without renewal or immediate deletion. Other services were not changed. Private service/IP identifiers and screenshots are excluded from this public report.

## Reference and protocol

Provider reference: https://github.com/whitelabelservices/whmcs-reseller at `7872bebfde141cea58e5d9d03c3e0fa874af7754`. Its README declares a proprietary license; only the protocol was inspected. The redirect that exposes a PVE ticket in a browser URL and disabled TLS validation are not retained.

Official API: https://pve.proxmox.com/pve-docs/api-viewer/index.html . Under `/api2/json`: POST `/access/ticket`, GET `/cluster/resources?type=vm`, POST `/nodes/{node}/qemu/{vmid}/vncproxy` with `websocket=1`, and GET `/nodes/{node}/qemu/{vmid}/vncwebsocket` using the returned port and VM-scoped VNC ticket.

The owner supplied the provider's answer in technical ticket 876841: Istanbul uses `https://console.whitelabelservices.us:443` (the same host serves API and browser console); New York uses `https://nyc1.whitelabelservices.us:8006`. Only Istanbul was tested and is configured. The New York host was not added to the console allowlist. The provider confirmed that the tested account exposed only its own VM and needed neither a permission change nor paid enablement. This does not settle commercial ticket 313805.

Live responses serialize the port as a decimal string and use modern Proxmox's eight-character password plus compatibility-prefixed scoped ticket. The client strictly normalizes only ports 5900–5999, keeps RFB `password` separate, and validates the compatibility prefix before using the scoped ticket. Legacy ticket-as-password fixtures remain covered. Protocol reference: https://github.com/proxmox/qemu-server/blob/master/src/PVE/API2/Qemu.pm .

## Security and limitations

- Existing customer ownership, CSRF, writable state and rate limits remain. The installed pilot permits only the configured persisted owner/service. The PR also prepares `vpstc_console_enabled`, default off, for active customers with eligible receipt-bound services. Global provider capability and sales flags are unchanged.
- Local unique supplier reference and encrypted adoption/purchase receipt bind owner, instance, reference and API account. Each grant also binds the approved upstream origin. Ownership/state/account are rechecked at issue, redemption and socket claim.
- Backend fetches parent-service credentials, never guest root/SSH credentials. Login username must match, inventory must expose exactly the expected single QEMU VM, and cross-VM visibility is rejected. All upstream requests verify TLS, disable redirects and never retry.
- Random fragment grants last 90 seconds and are consumed under a shared lock. Backend session data is encrypted for 30 seconds. Browser receives only VM-console-scoped VNC challenge material; parent/service password, PVE login cookie, CSRF, node, VM ID and upstream host stay private.
- Worker uses a host-only Secure/HttpOnly/SameSite=Strict cookie plus per-tab binding. Duplicate cookies, reused claims and a tab whose cookie was replaced are rejected. Fresh fixed upstream headers exclude browser/portal credentials. Relay closes both sockets after five minutes and bounds client/upstream frame sizes. No raw errors, payloads, keystrokes or credentials are logged.
- Active streams are bounded to five minutes; ownership revocation is enforced at launch/claim, not continuously during an already established stream. Reconnect requires a new launch.
- Only the exact Istanbul gateway origins are allowlisted. Fresh backend login, grant, upgrade and RFB auth now pass; the complete browser path does not. Serial/LXC and New York acceptance are outside the verified scope.
- Browser CSP allows the new relay only as a form-redirect destination on the eligible authenticated customer's own service detail page, in fa/en/tr. It does not add the host across the portal or payment pages.
- Current Cloudflare dates deliver binary messages as Blob by default. Both sockets explicitly select ArrayBuffer before acceptance; see https://developers.cloudflare.com/changelog/post/2026-04-21-websocket-standard-binary-type/ .
- The browser client is bundled from the repository's vendored noVNC/Pako sources, with license/source notices, and served by the Worker as one content-addressed module. Both build-time parsing and a separate Node syntax check cover the actual served string. Its verified public SHA-256 is `cbd3e54b19092a8e3f4bc2aeec7269b4ad611bd7a92188edea9362b0e2a4c620`. Static module delivery is not console acceptance.

## Installation and acceptance

Use the project deployment procedure: release lock, exact-byte snapshots, private backups and hash-guarded installation. Install new classes before the small controller/route additions; do not replace live route/controller files wholesale. No migration, financial cron or shared cache flush is needed. Preserve the separate Arvan console infrastructure.

The separate Worker, dedicated hostname `vps-vnc.servernet.cloud`, exact route and separate shared secret were provisioned. The application's existing DNS token returned HTTP 403 for the Workers API; deployment used the existing authenticated Cloudflare dashboard without expanding that token's permissions. See `scripts/vpstc-console-worker/README.md` for the locked build and upload artifact. Worker `BROKER_KEY` and encrypted Laravel `vpstc_console_worker_key` do not reuse the Arvan key. Public settings are `vpstc_console_pve_origin`, `vpstc_console_owner_service_id`, `vpstc_console_owner_pilot_enabled`, and the separately gated `vpstc_console_enabled`.

Production's route comment, rate limit and formatting differed from Git. Surgical insertions preserved them, as well as all unrelated controller and middleware bytes. Prepare checks stopped on drift before source installation; the validated packages then installed three new classes and narrow controller, route and SecurityHeaders additions. Private manifests record exact before/after hashes. No shared cache flush, migration, cron, guest power, guest credentials or billing state was changed.

First confirm valid provider HTTPS API access/certificates. `php artisan cloud:vpstc-console-probe LOCAL_OWNER_SERVICE_ID` creates an unused expiring console grant, prints no credentials, and does not enable the pilot. Investigate TLS/auth/scope failures without bypassing protections. If the gateway is not the API endpoint, request the actual approved hostname.

After a successful probe, activate only the owner pilot. Check framebuffer, harmless input, fullscreen, mobile, FA/EN/TR, expiry/reconnect, cross-tab isolation and second-customer denial. Confirm no guest/power/billing changes and no secrets in browser HTML/location/redirects beyond the short single-console VNC challenge. Test Ctrl+Alt+Del only when suitable for the guest. Expanding to customers needs a separately reviewed capability policy and live acceptance.

## Validation and rollback

PHP 8.3 CLI lint passes locally; it is not the locked PHP 8.4 test runtime. At `5dba1dbc6c6c956926e4fa9051b0f0390d8ec460`, GitHub Actions run https://github.com/servernetir/server/actions/runs/41621152553 passed the PHP 8.4 and separate MariaDB jobs, plus the locked browser-bundle build and Node tests. The latest independently inspected PHP result was 285 tests / 2,402 assertions; MariaDB was 43 / 206. Local final Node checks pass 19 tests, including parsing the actual browser response, HMAC, redirects, strict targets, privacy, cookie/tab isolation, binary delivery, frame/session bounds and language shells. These remain isolated checks, not successful browser acceptance. Review: https://github.com/servernetir/server/pull/251 .

The runtime flags are disabled. Keep the private backups and receipts. A source rollback must touch only manifest-listed files/hunks with installed-hash guards; a route rollback must touch only this dedicated Worker. Let short grants expire. Do not flush shared cache, reset APP_KEY, roll back customer records, change VM state or modify the existing Arvan infrastructure.
