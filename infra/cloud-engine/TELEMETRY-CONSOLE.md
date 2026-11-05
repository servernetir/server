# Traffic observations and console gateway

This component is source prepared for development, not a deployed or accepted
Proxmox service. Tests use synthetic counters and RFB fixtures; the local socket
frame test transfers real bytes only between local sockets. None establishes
per-VM network accuracy, real Proxmox VNC handshake, browser keyboard/display,
or production readiness. Complete those items in the hardware acceptance gate.

## Traffic contract

`traffic_collector.py` reads exactly one host tap (`tap<vmid>i0`) for each
operator-approved VM UUID/VMID assignment. Host tap TX means guest inbound; host
tap RX means guest outbound. It never adds bridge, physical-NIC or guest counters.
Only one NIC per VM is currently supported; additional NICs require an explicit
accounting design and acceptance before sale. Initial baseline is not historical
usage. Decimal GB=1,000,000,000 bytes; GiB=1,073,741,824 bytes.

A persistent mode0600 outbox is fsynced before submission. Lost acknowledgments
retry the identical epoch/sequence/payload; the server accepts exact duplicates
without adding bytes twice and rejects conflicting duplicates. Host boot changes, tap ifindex changes,
decreasing counters or a missing interface produce a new random epoch and
sequence1. Collector restart on the same host retains its state. Never delete its
state during normal upgrades. A stale rejected outbox starts a new baseline and
the server records the epoch gap; missed bytes are never invented.

Laravel serializes ingestion under the VM row lock, validates the node/VM/tap,
strict integer counters and UTC sample timestamps, verifies the exact PVE name/ownership
marker on every baseline/new epoch, and records gaps for missing
sequences, reset epochs, decreases, excessive sampling intervals and crossing a
calendar-month boundary. Cross-boundary deltas are not guessed into either month.
Reports expose known inbound/outbound bytes, gap reasons, exact units and
`billable=false`. They do not claim complete monthly coverage. Configure an
integer `EngineVm.metadata.traffic_quota_bytes`; configurable80/90/100% threshold
records are deduplicated and visible in reports. Notification delivery and
financial overage charging are deliberately separate; no automatic bill or VM
shutdown is performed.

The node agent has its own encrypted `EngineAgentCredential` secret and disabled
by default binding. Do not reuse the PVE token. Provision a random secret securely
into that model and the collector's mode0600 secret file. Ingestion is
`POST /api/v1/cloud-engine/internal/nodes/{node}/traffic`, HMAC-SHA256 over
`unix_seconds + "\n" + exact_json_body`, headers `X-Engine-Time` and
`X-Engine-Signature`, maximum clock skew30s. TLS verification is mandatory.

Install on an authorized new node only, after reviewing the node runbook:

1. Create `servernet-engine` service user and `/var/lib/servernet-engine` owned by
   it with mode0700. Install the collector under `/opt/servernet-engine`.
2. Copy `traffic-collector.example.json` to `/etc/servernet-engine/traffic.json`,
   replace only explicit node assignments/endpoint and secure the secret file.
   Generate assignments from reviewed EngineVm reservations, never by adopting
   all guests discovered on a host.
3. Run one collection with `--once`, verify accepted baseline and direction using
   generated guest traffic, then compare persisted observations across process,
   VM and node restarts. Exercise lost receipts and the UTC month boundary.
4. Review and install `servernet-engine-traffic.service`. No service is started
   automatically by this repository change.

## Console contract

Laravel grants are90s maximum, one use, hashed at rest and bound to the exact
service/customer/EngineVm UUID/fingerprint/node/VMID and current CloudInstance.
Issue, redemption, connection and periodic validation recheck owner, active
service, VM state and fresh node health. Concurrent claims lock the database row.
Deletion revokes grants and retains the IP until any claimed session's maximum
lease has expired; a disconnected gateway is never assumed to have closed early.

The browser receives an opaque grant and gateway URL only. The gateway HMAC
backchannel redeems then atomically connects the grant; only the gateway receives
PVE credentials. It requests the supported PVE `vncproxy` operation and connects
only to the bound `vncwebsocket`, validating TLS and an exact host:port allowlist.
Upstream RFB3.8/VNC password authentication is terminated inside the gateway, so
the browser never receives the PVE ticket or API token. Browser RFB security type
None is carried only inside the TLS, authorized, expiring WebSocket. Unsupported
RFB versions/security methods fail closed. Real PVE compatibility remains an
acceptance prerequisite.

The gateway enforces an exact Origin allowlist, masked bounded browser frames,
connection caps, handshake timeouts and session deadline. It revalidates ownership
at least every10s while connected. It does not log URLs, tokens, headers, VNC
payloads or raw exceptions. Both PVE and application TLS certificates must verify;
for an internal CA, mount its file under the configured CA directory and use
matching paths in trusted node configuration. No verification bypass exists.

Deployment preparation (do not execute on existing infrastructure implicitly):

1. Create a private venv and install the pinned `requirements.txt`; copy only the
   existing `website/public/assets/js/novnc/` asset directory beside the gateway.
2. Prepare a dedicated TLS hostname/certificate, configure exact browser Origin,
   PVE host:port, certificate trust and Laravel backchannel URL. Securely set the
   same random32+byte gateway secret in Laravel and the mode0600 gateway envfile.
   Set Laravel `ENGINE_CONSOLE_GATEWAY_URL=https://<gateway-host>`.
3. Review `servernet-engine-console.service` and `console.env.example`; keep the
   gateway loopback-bound behind a TLS reverse proxy that preserves Origin,
   permits Upgrade and does not log query strings. The gateway listener itself
   also requires TLS. Mount the TLS key read-only, readable only by its user.
4. Test cross-customer denial, grant replay/expiry, concurrent connection claims,
   ownership transfer, suspension, node failure, display/keyboard, and active
   session deletion before accepting console functionality.
5. Rollback by disabling gateway issuance and stopping the two new service units.
   Preserve traffic state and database evidence; do not roll back by releasing
   addresses, deleting guests, or rewriting customer billing.

Development checks:

```bash
source /workspace/.servernet-env/activate.sh
cd /workspace/server/website
php artisan test --filter=EngineTelemetryTest
cd /workspace/server
python3 -m venv /tmp/engine-gateway-tests
/tmp/engine-gateway-tests/bin/pip install -r infra/cloud-engine/requirements.txt
/tmp/engine-gateway-tests/bin/python -m unittest discover -s infra/cloud-engine -p test_telemetry.py -v
```

The official PVE API viewer/source domains were unavailable under the initial
cloud network policy. Endpoint compatibility requires verification against the
current official PVE schema and real-node acceptance; simulated receipts are not
substitutes for those checks.
