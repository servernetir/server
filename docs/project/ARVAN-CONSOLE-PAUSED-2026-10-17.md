# Arvan customer console temporarily disabled — 2026-10-17

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
