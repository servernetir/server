# Dedicated VPS.TC console — 3 November 2026

Base: `3aeac46c146becbb069e9a0b07c4cc1b1ca162ea` (`develop`).

## Scope and current state

The owner approved a custom API-based console, first on their existing VM, and a commercial follow-up. This change independently implements the Proxmox protocol, a default-off owner pilot, single-use broker, dedicated WebSocket relay and three-language shell. It does not install WHMCS, copy proprietary implementation code, buy a VM or change billing/schema.

**Not deployed or enabled.** The gateway answered the public version API and its authenticated resource inventory exposed only the owner VM in the existing browser session. Backend credential login, console grant and the full framebuffer/input path have not passed live acceptance. The provider's own portal launch was observed remaining at `Connecting…`; this is an observation, not a diagnosis. No working custom console is claimed.

The sales follow-up requesting a written rate and response date was submitted in the existing ticket. No discount is approved. The owner's Turkey relay has auto-renew Off in the native provider widget. A separate ticket reply requests keeping its paid period intact without renewal or immediate deletion. Other services were not changed. Private service/IP identifiers and screenshots are excluded from this public report.

## Reference and protocol

Provider reference: https://github.com/whitelabelservices/whmcs-reseller at `7872bebfde141cea58e5d9d03c3e0fa874af7754`. Its README declares a proprietary license; only the protocol was inspected. The redirect that exposes a PVE ticket in a browser URL and disabled TLS validation are not retained.

Official API: https://pve.proxmox.com/pve-docs/api-viewer/index.html . Under `/api2/json`: POST `/access/ticket`, GET `/cluster/resources?type=vm`, POST `/nodes/{node}/qemu/{vmid}/vncproxy` with `websocket=1`, and GET `/nodes/{node}/qemu/{vmid}/vncwebsocket` using the returned port and VM-scoped VNC ticket.

## Security and limitations

- Existing customer ownership, CSRF, writable state and rate limits remain. Only the configured persisted owner/service is eligible. Global VPS.TC console capability stays false.
- Local unique supplier reference and encrypted adoption/purchase receipt bind owner, instance, reference and API account. Each grant also binds the approved upstream origin. Ownership/state/account are rechecked at issue, redemption and socket claim.
- Backend fetches parent-service credentials, never guest root/SSH credentials. Login username must match, inventory must expose exactly the expected single QEMU VM, and cross-VM visibility is rejected. All upstream requests verify TLS, disable redirects and never retry.
- Random fragment grants last 90 seconds and are consumed under a shared lock. Backend session data is encrypted for 30 seconds. Browser receives only VM-console-scoped VNC challenge material; parent/service password, PVE login cookie, CSRF, node, VM ID and upstream host stay private.
- Worker uses a host-only Secure/HttpOnly/SameSite=Strict cookie plus per-tab binding. Duplicate cookies, reused claims and a tab whose cookie was replaced are rejected. Fresh fixed upstream headers exclude browser/portal credentials. Relay closes both sockets after five minutes and bounds client/upstream frame sizes. No raw errors, payloads, keystrokes or credentials are logged.
- Active streams are bounded to five minutes; ownership revocation is enforced at launch/claim, not continuously during an already established stream. Reconnect requires a new launch.
- The exact observed console gateway hostname is allowlisted; public version and browser-authenticated inventory reads were verified, but fresh backend login and VNC grant/upgrade remain unverified. A different API hostname requires provider documentation and a reviewed explicit allowlist change. Cloudflare egress/upgrade support and provider RFB authentication require live acceptance. Serial/LXC console is outside this pilot.

## Installation and acceptance

Use the project deployment procedure: release lock, exact-byte snapshots, private backups and hash-guarded installation. Install new classes before the small controller/route additions; do not replace live route/controller files wholesale. No migration, financial cron or shared cache flush is needed. Preserve the separate Arvan console infrastructure.

Prepare a separate Worker with `ENABLED=0`. Its dedicated hostname is `vps-vnc.servernet.cloud`. Provision a new shared secret as Worker `BROKER_KEY` and encrypted Laravel `vpstc_console_worker_key`; never reuse the Arvan key. Public settings: `vpstc_console_pve_origin`, `vpstc_console_owner_service_id`, `vpstc_console_owner_pilot_enabled` (absent/off by default). Persisted owner ID/code/status are also checked.

First confirm valid provider HTTPS API access/certificates. `php artisan cloud:vpstc-console-probe LOCAL_OWNER_SERVICE_ID` creates an unused expiring console grant, prints no credentials, and does not enable the pilot. Investigate TLS/auth/scope failures without bypassing protections. If the gateway is not the API endpoint, request the actual approved hostname.

After a successful probe, activate only the owner pilot. Check framebuffer, harmless input, fullscreen, mobile, FA/EN/TR, expiry/reconnect, cross-tab isolation and second-customer denial. Confirm no guest/power/billing changes and no secrets in browser HTML/location/redirects beyond the short single-console VNC challenge. Test Ctrl+Alt+Del only when suitable for the guest. Expanding to customers needs a separately reviewed capability policy and live acceptance.

## Validation and rollback

Local PHP 8.3 CLI lint passes for changed PHP files; it is not the locked PHP 8.4 test runtime. Sixteen Node relay tests pass (HMAC, redirects, strict target, privacy, cookie/tab isolation, socket forwarding, frame/session bounds and language shells). At code commit `ef6bf9f4cd0ad688b32934b1ca52f402dabe0f36`, GitHub Actions run https://github.com/servernetir/server/actions/runs/41605244466 passed 279 VPS.TC PHP tests / 2,369 assertions on PHP 8.4.27, 16 Node tests, and the separate 43-test / 206-assertion MariaDB attachment/complimentary regression job. These are mocked protocol and isolated database tests, not live console acceptance. Review: https://github.com/servernetir/server/pull/251 .

Rollback disables Worker `ENABLED` and the dedicated pilot flag first. Remove only this Worker's route and restore only manifest-listed controller/route hunks/classes with installed-hash guards. Invalidate affected opcodes; let grants expire. Do not flush shared cache, reset APP_KEY, roll back customer records or change VM state.
