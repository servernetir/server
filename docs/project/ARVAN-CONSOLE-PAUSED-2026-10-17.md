# Arvan customer console temporarily disabled — 2026-10-17

## 18 October follow-up: route repair approved, desktop control blocked before mutation

Owner approved exact-domain direct routing and the brief proxy/TUN interruption. Consistent SQLite backup (quick_check ok), GUI/core configuration copies and sanitized hashes were prepared in `C:/Users/admin/.codex/.tmp/vnc-routing-20261018-before`, with ACL restricted to current admin and SYSTEM. Current v2rayN7.25.2 active group is `localnetwork`, with one existing `10.10.10.0/24 -> proxy` rule. Supported repair is to add only `full:vnc.servernet.cloud -> direct` to this group, preserving all other state; existing TUN autoOutboundsInterface=auto binds outbounds to the physical interface. Applying through the GUI restarts the core/TUN; it is not hot reload.

Computer Use failed to activate the returned v2rayN window even after fresh window selection and the prescribed retry. No routing edit or reload was performed. Read-only comparison confirmed unchanged selected profile, system proxy, TUN, DNS, inbounds, outbounds, active/other routing groups and core config; target-rule count remains0. User assistance to open/apply the supported GUI rule is needed, not additional authorization. Do not silently replace the active profile or write only generated config.

The user-opened fresh cPanel Terminal works. Read-only production check confirmed service501/customer1 active, CloudInstance315 running, exact expected provider reference, public_enabled=false, test501_enabled=false and test_until=0. No window was opened, sales settings were not changed, no VM or DNS operation occurred. Real501 image/control remains untested. After route application, validate public browser loading before opening the short private501 acceptance window. Rollback of a future rule is removal of only that new domain rule and GUI apply; do not restore the entire database over intervening changes.

## 18 October 05:57 UTC: connection deadlines deployed; local TUN fault isolated

Published only `servernet-vnc`: deployment `34196f9f-3fda-4eed-9498-0177aa5c67a8`, version `743d9e77-1f39-438d-98e5-04078e5bba84`, 100%. The fixed-target helper checked for intervening changes, backed up source/settings, and verified normalized source equality and preservation of all three bindings. Public `/console.js` contains the new deadline. Local Worker/client tests: 23 passed. Upgrade requests now abort after 10 seconds; legacy/modern RFB connection handshakes fail visibly after 20 seconds, disconnect, and ignore late success. A successful handshake clears the deadline and does not abort an established socket. This bounds the connection phase; it is not a repair of a document that has not loaded.

Owner approved a new temporary Individual Workers Editor token for exactly `servernet-vnc`; no DNS, route, portal Worker or account-wide access. Validity 2026-10-18 05:57–07:00 UTC. The private local credential was removed after release verification; server-side expiration is scheduled, not a claim of revocation. Private rollback source/settings/receipt: `C:/Users/admin/AppData/Local/Temp/servernet-vnc-deadline-KB265b`. Source SHA256 `82e10b8963b5adda08098c50d1180ab89451365c7327bea16c432610a06aa1c2`. Rollback only this Worker to `b1732b2f-91bf-48ee-9f96-181a84e9d910`, retaining bindings/routes. No proxy/DNS/portal Worker/sales/VM change was made by this deployment.

Token-free phase probes isolate a local routing-dependent failure: same hostname/SNI `vnc.servernet.cloud` and destination `188.114.96.3`, default-source `172.18.0.1` (`xray_tun`) receives TLS and HTTP/2 SETTINGS but neither response headers nor PING ACK within 18 seconds under TLS1.2 and TLS1.3. Disposable requests bound to the existing Ethernet address `192.168.132.16` return HTTP200 in 319/275ms with valid TLS; TLS1.2 PING ACK is 69ms. HTTP/1.1 through the existing proxy works; HTTP/2 control requests to the main site and `www.cloudflare.com` through that proxy also work. Earlier "direct" probes meant no explicit HTTP proxy, NOT bypassing the system TUN. These observations narrow the failure to the local Xray/TUN path for these destinations, not Worker JS or broker. The precise tunnel/remote-path mechanism and real browser recovery remain unverified.

Existing proxy configuration has TLS/HTTP sniffing and a `direct` outbound. Proposed repair is a persistent exact-domain rule `full:vnc.servernet.cloud` to direct, without DNS or shared-Cloudflare-IP route changes. No rule has been applied: GUI-managed state must be preserved and applying may restart the proxy core, briefly interrupting unrelated apps. Owner approval for that interruption was requested. No VM creation/deletion/restart. Public console remains disabled (last live verified flag0), private501 window last verified0; no sale flags changed. Current cPanel tab is an inaccessible error page; a fresh user-opened Terminal is needed for live guard verification and private acceptance. No blocked browser observation was bypassed. Service501 image/input/reconnect acceptance is still NOT successful.

## 18 October: proxy transport comparison and reverted local trial

Two fresh public-root HTTP/2 probes returned200 directly (11205ms including DNS, then149ms), but both through127.0.0.1:10808 timed out at20s without response headers/body. Proxy HTTP/1.1 returned200 in124ms. This reproduces a transport-specific problem at this time, not proof of a universal proxy failure or the complete Chrome cause.

Under existing owner authorization for local network repair, temporarily appended only `vnc.servernet.cloud` to HKCU Internet Settings ProxyOverride and notified WinINet with settings-changed/refresh. ProxyEnable1 and server127.0.0.1:10808 were preserved. Fresh Chrome public-root navigation still timed out; binding the earlier test tab also timed out on Emulation.setFocusEmulationEnabled. The exception was removed and WinINet refreshed again; rollback verified VncBypass=false. No persistent route, NAT/firewall, DNS, Worker, sale, VM or public-console authorization changes. No authenticated guest ticket replay or real501 connection/control acceptance. A user-managed Chrome restart is the next isolation step; do not terminate other active tabs/applications automatically.

## 18 October: broker reachability established, browser acceptance still fails

Public root returned200 both direct and via existing local proxy. Synthetic all-zero ticket POST to Worker /launch returned410. Previous Apache log counts stayed0 even for a direct known broker request, so those counts are NOT evidence of non-arrival. Production default logging level is error, explaining absent info events.

Installed dedicated daily status-only broker logging, permission0640, retention3days; no body, token, signature, URL, customer or guest input logged. Global logging settings unchanged. Backup of broker before instrumentation: `/home/servernetcloud/arvan-broker-log-0oc3rqgm/before.php`. PHP lint passed;17 local broker tests/40 assertions passed. Live formatting is compact but semantically equivalent to Git's multiline logger configuration.

Dedicated log recorded403 at04:59:53UTC with public/private gates off and410 at05:01:14UTC during owner-approved private501 window for the all-zero ticket. The latter passes HMAC validation and fails the nonexistent-grant check: Worker→broker transport and shared signing contract work. No guest authorization was used in synthetic probes.

The real customer501 console button was clicked during a15-minute private window. A new VNC tab was created, but initial navigation observation timed out and no corresponding valid ticket redemption was logged. No real image/control success claimed. Private expiry then set back to0; global flag remains0. No DNS, Worker, proxy, sales or VM mutation. Remaining blocker is browser document/asset loading before launch; exact transport cause remains unproven.

Rollback instrumentation by removing only the added logger block after checking current changes, or guarded restore from backup if unchanged. Do not re-enable public console as part of rollback.

## Owner-approved private acceptance attempt

Added service-aware guard in broker, customer controller and CSP middleware. Public capability stays disabled. Only service 501/customer 1/exact existing provider reference may use a configured future `arvan_console_501_test_until` within one hour. Existing ownership/status/rate limits, signatures, single-use grants and reassignment checks remain enforced. Redeem/claim also recheck service scope and expiry.

Local broker tests: 17 passed/40 assertions; Worker tests:16 passed. Production three-file PHP lint passed. Deployment used before-hash guards, a private backup at `/home/servernetcloud/arvan501-scope-8aezr2gf` (0 broker,1 controller,2 middleware; `.before.php` and `.after.php`), and per-file atomic replacement. Existing live middleware differs from Git; only the exact guard expression was replaced, preserving unrelated edits. First malformed terminal input was abandoned by reconnecting; only the subsequent base64-encoded deployment returned successful lint/hashes.

Live 30-minute acceptance configuration returned public=false,test501=true,other_enabled=0. Reloaded customer501 displayed console button; actual click created a fresh VNC tab but observing initial navigation timed out. Broker access count remained0 in the inspected origin log. Real desktop/control NOT accepted. Test expiry was then set to0; public switch remains0. No Worker/DNS/proxy/sales/VM changes.

Rollback: keep both settings0. Restore only the three backed-up files after matching current after-hashes and checking for intervening edits; do not overwrite newer work. This patch enables isolated future testing, not a repair of the browser transport failure.

Owner explicitly requested disabling/hiding Arvan consoles for all customers until repaired and tested.

## Production change and validation

- Existing application kill switch `arvan_console_worker_enabled`: 1 → 0 through `App\Models\Setting::put`. Database persistence and settings cache invalidation use the existing model.
- Terminal confirmed `before=1`, `after=0`, `broker_enabled=0`.
- Live ArvanClient console capability uses broker enabled(); controller guards also check broker enabled(). This is provider-wide, not only service 501.
- Reloaded authenticated service 501 customer page: console button disappeared; power controls and Remote Desktop information remain. Existing fallback text says the infrastructure does not provide a web console; this is generic text, not a claim Arvan itself lacks a console.
- No VM created/deleted/restarted. No sales settings, DNS, Worker deployments, portal Worker, proxy, routes, NAT or firewall changed.

## Limitations and rollback

Real console image/control acceptance remains unverified. This action contains the broken entry point; it does not fix the underlying connection failure. Existing established WebSocket sessions are not claimed to be forcibly terminated.

Technical rollback is Setting::put of the same key to `1`, only after repair, real acceptance testing and owner approval. Do not blindly re-enable during deployment.

## Latest diagnostic evidence before pause

Fresh token-free Node HTTP/2 requests to the public VNC root succeeded directly (200, 1699 bytes, 303ms) and through existing local proxy (200, 1699 bytes, 255ms). Chrome previously showed ERR_HTTP2_PING_FAILED. This does not establish a general proxy/HTTP2 outage or prove the console works. Chrome socket-pool flush did not establish recovery. Investigation paused at owner's request.
