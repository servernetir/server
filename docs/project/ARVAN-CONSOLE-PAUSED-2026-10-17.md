# Arvan customer console temporarily disabled — 2026-10-17

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
