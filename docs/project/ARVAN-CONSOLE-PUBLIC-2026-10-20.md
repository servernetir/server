# Arvan console public reopening — 20 October 2026

## Current production state

The owner explicitly requested reopening consoles for all eligible Arvan customers
after the completed [real owner test](ARVAN-CONSOLE-OWNER-TEST-2026-10-20.md).
At **08:03:27 UTC**, only two persisted application settings changed:

| Setting | Before | After |
|---|---|---|
| `arvan_console_worker_enabled` | `0` | `1` |
| `arvan_console_owner_pilot_enabled` | `1` | `0` |
| `arvan_hourly_sales_enabled` | `1` | `1` (unchanged) |

This is authenticated customer access to each customer's own eligible machine,
not an anonymous console or access to other customers' machines. Existing and
future eligible Arvan services use the same policy; no per-service allowlist was
added. The owner-only pilot is now off because the public capability is on.

The change used a guarded database transaction, locked setting rows, verified live
source hashes, broker-key format and unchanged sales/Windows allowlist, and
created activity event `arvan_console_public_enabled`, ID **7027**. Only the
settings cache/request memo was invalidated by `Setting::put`; no shared cache
flush, PHP deployment, migration or new credential was needed.

Private non-credential before/after receipts are under
`/home/servernetcloud/servernet_app/storage/app/private/arvan-console-public-20261020/`
(directory0700/files0600). Do not blindly rerun a write or overwrite the receipts.

## Verification

- Preflight08:01:53UTC: public0/pilot1/hourly1; four eligible active Arvan services,
  none allowed by the owner-only pilot; deleted service535 ineligible.
- Independent fresh-process readback **08:03:52UTC**: public1/pilot0/hourly1;
  **four eligible / four policy-allowed**, service535 still ineligible, valid
  existing broker key. No key material was printed or changed.
- Windows image allowlist SHA256 remained
  `cc9e1285e69a6a37cde1b114b19c860293fae75e3f311bce3ac146744206b845`.
- Live controller SHA256 remained
  `b462f032754ba0827a33ae77f7eff9d4bdb00277fe8f3190498f41d0116595be`;
  live broker SHA256 remained
  `7cfe1cf7842b51760cb767e61c6f4d4d059cb2f11056caab022459683acbad73`.
- Fresh isolated `ArvanConsoleBrokerTest`: **29 tests / 90 assertions**, passed
  with in-memory SQLite and network execution blocked; public issuance, replay,
  stale ownership/ref and owner-pilot protections remain covered.
- Real authenticated owner browser: service535 still renders the closed-order
  page without console controls; requesting another customer's service493
  renders404. No console request or guest interaction on that customer's VM.
- The real Windows image, mouse/keyboard, concurrent tabs and reconnect were
  accepted earlier on service535 before its verified deletion. That evidence is
  not a new guest-control test on all regions/customers. No VM was created for
  this settings-only reopening.

## Unchanged scope and limitations

Worker `servernet-vnc` remains version
`05aa6a4e-0c43-469d-921f-64918c9ddd24`; no Worker publication, token creation,
DNS or portal Worker change occurred in this reopening. Sales remain open.
Authentication, ownership, CSRF, rate limits, signed broker requests, encrypted
grants, single-use90-second tickets and per-tab binding remain in place.

No VM/disk/IP, billing, provider account, route/NAT/firewall, proxy, cron or
periodically reconciled network-agent state changed. No Arvan support contact.
The sole paid test was already deleted; do not recreate it or restart its helper.
Automatic Windows password delivery, desktop/RDP acceptance, actual test charges
and all-region network reliability are **not** newly resolved by this reopening.

## Persistence and rollback

The two values persist in the production settings table. Git documents the
runtime change; it does not make the feature default-on for other installations.
If rollback is necessary, after checking current ownership/approval/state, set
public0 and owner-pilot1 via the existing `Setting::put` path and record an audit
event. Keep hourly/Windows sales and broker secrets unchanged. Do not alter DNS,
Worker routes or customer VMs as a rollback shortcut.

Local proof images (not committed):
`output/arvan-console-public-20261020/live-public-readback.jpg` and
`output/arvan-console-public-20261020/foreign-service-denied.jpg`.
