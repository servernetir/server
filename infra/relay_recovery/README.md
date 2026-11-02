# Relay recovery controller

This isolated Python 3.12+ package provides a durable recovery planner, a WHM
controller, an authenticated pull agent on Proxmox, and narrowly scoped provider
adapters. It does not use the public customer provision/terminate queue.

**Deployment status:** the framework is being installed in observation mode.
Purchasing, promotion and retirement must remain disabled until the production
recipes and an end-to-end recovery drill have passed. A missing recipe, guest
probe, backup proof or fencing receipt is a blocker, never a successful check.
The first signed agent exchange and restricted WHM-to-relay SSH inspection have
been exercised. The Proxmox timer, Windows listener, clone guards and final
consumer/DNS cutover integration are not yet active. Merely setting `enabled`
to true does not complete this installation.

## Components

| File | Responsibility |
| --- | --- |
| `core.py` | Pure planner, SQLite journal, budget ledger and fresh effect permits |
| `worker.py` | Observation CLI and isolated scheduler test API; enabled legacy CLI is blocked |
| `production_driver.py` | Default-off restore/cutover sequencer; concrete production integration is still required |
| `gateway.py`, `agent-endpoint.php.tpl` | Signed data exchange and durable receipt inbox |
| `agent.py`, `protocol.py` | Root agent, replay protection, durable outbox and acknowledgement |
| `iran_adapter.py`, `iran_probes.py` | Fixed, hash-pinned local probes and recipes |
| `hetzner.py`, `provider_adapter.py` | Live quotes, explicit adoption and exact-resource cleanup |
| `controller_adapter.py` | Receipt validation, evidence collection and probe publication |
| `servernet_bridge.php` | Private CLI for the existing owner's protected logical service |
| `relay_side.py` | Restricted SSH inspection and private consistent SQLite snapshots |
| `backup_client.py` | Pinned SSH download, strict archive validation and disk admission |
| `cloudflare_adapter.py` | Scoped content-only DNS plans, receipts and reconciliation |
| `bootstrap.py`, `snapshot_adapter.py` | Quarantined trial bootstrap and durable native disk restoration |
| `generation_guard.py`, `lifecycle_side.py` | Physical-instance writer guard and signed lifecycle operations |
| `lifecycle_client.py`, `lifecycle_cli.py` | Pinned SSH lifecycle client and restricted dispatcher |
| `restore_workflow.py` | Preparation through `PRE_CUTOVER_READY`; global cutover is separate |
| `iran_stage.py`, `ct_stage.py` | Isolated CT113 trial tunnel and reviewed endpoint transactions |
| `windows_probe*.py`, `windows_probe.ps1` | Authenticated read-only guest egress proof without QGA |
| `maintenance.py` | Bounded pruning of acknowledged, obsolete control history |

Importing modules performs no provider or application operation. All credentials
and infrastructure inventories belong in private configuration outside this
repository and outside the web root. Never commit `.env`, private keys, exported
archives, credential output, or live configuration.

## Deployment boundaries

The WHM controller runs as the existing cPanel application owner. Its release,
configuration and SQLite journals must be local, privately owned and inaccessible
over HTTP. The public PHP endpoint contains only immutable paths. It verifies
the HMAC before spawning Python and limits admission; the gateway independently
checks replay and durably saves job-bearing reports before acknowledging them.

The Proxmox agent, adapter, probe scripts, configuration and bundle must be
root-owned. Commands received from the network are not shell commands. Only
reviewed fixed local executables with SHA-256 pins may execute. A genuine probe
inside VM109 is required; a host route lookup or CT113 connection cannot stand
in for a guest result. The supplied QGA probe reports failure when QGA is absent.

The relay SSH key must use a forced command and disable forwarding, agent
forwarding, PTY and arbitrary file access. Pin the host key against an already
authenticated administrative connection. Never use `StrictHostKeyChecking=no`.

The relay helper exports configured inventory and per-database online SQLite
backups. It explicitly does not prove global write consistency, successful
restoration, runtime application health or persistent fencing. Container image
and bind mount exports must also account for the writable container layer.
Snapshots containing credentials must stay private on the owner's servers.

The native snapshot path rejects attached volumes and private-network
dependencies. A clone must retain fail-closed writer guards through its first
boot and guarded machine-ID reset. Restored cloud-init data preserves source
SSH host keys, account credentials and SSH policy; initial trial pins are
replaced with the independently trusted source pin only after the verified cold
rebuild. Cloud-init clean hooks and all non-cloud-init startup writers still
need a reviewed inventory on the real source.

## Windows probe access policy

The default `windows_firewall_v1` enrollment requires effective scoped Windows
Firewall rules. An existing VM whose firewall is disabled may explicitly enroll
`source_ip_hmac_v1` without changing its global firewall profiles. This is an
application authorization policy: the listener binds the fixed private address,
admits only the fixed Proxmox socket peer, and verifies the distinct HMAC key,
purpose, timestamps and durable nonce before its fixed read-only egress probe.
It grants no command, arbitrary URL or file access and makes no network-firewall
or network-isolation claim. TCP/HTTP availability exposure remains on that port.

Use `install_windows_probe.ps1 -AgentId ir-agent -AccessPolicy source_ip_hmac_v1`
only for that explicit enrollment, and select the same `access_policy` in the
private PVE client config. The authenticated response binds the server policy;
a missing or different policy fails closed. Existing agent/policy binding and
key bytes are preserved, so an upgrade cannot silently change authorization.
Native PowerShell, SYSTEM startup and a signed PVE round trip remain required.

## Effect gates

Default policy requires a sustained three-hour outage with continuous fresh
observations and a healthy independent control path. Probe gaps and an unhealthy
WHM/Internet path do not count as evidence to buy a replacement. Country priority
is Germany, Finland, then the United States; compatible x86 models are compared
using current project prices, IPv4, taxes and expected outgoing traffic.
An unavailable external egress measurement produces unknown path evidence;
it does not turn a verified SSH tunnel into an outage. Verified tunnel/target
failure remains distinct from an unavailable Windows guest or diagnostic read.

At most two managed generations may coexist. The ledger bounds attempts in a
rolling 24-hour window, promotions and trial spending. The currency is immutable
once the ledger has entries. Exhaustion holds safely; a new incident cannot erase
old costs or ambiguous operations.

Before promotion, all critical consumers, a verified restore checkpoint, complete
registry and persistent old-writer fence must be fresh. Before retirement, the
new generation must pass the observation window, known DNS TTL expiry, zero
remaining old references and a durable backup covering the promotion checkpoint.
The core also requires the exact committed restore epoch, snapshot and workflow
to emit fresh `PRE_CUTOVER_READY` acceptance after at least twenty minutes of
observed restored health. Network-only trials cannot satisfy that gate, even
when ordinary health, backup and critical-consumer checks appear successful.

An unreachable candidate is cleaned up only after exact ownership, never-promoted
status and absence of actual writes are proven. Deleting the server is not a
billing-completion receipt: captured dedicated IP resources must also be absent.
Unknown create/delete outcomes are reconciled with reads only. Another mutation
requires a new intent and fresh gates. Existing customer servers and unrecorded
provider resources are never implicit cleanup targets.

An installation restore drill uses an explicit one-shot local authorization and
the same cost and concurrency ledger. Its private operator configuration may
enable that bounded workflow while the regular scheduler remains disabled; it
must never turn a consumed drill into another ordinary CREATE. Before source
fencing or rebuilding, `begin_restore` persists a hold that blocks promotion and
candidate deletion. Only matching snapshot, boot and quarantine proof completes
the epoch. Fresh post-restore jobs must carry that epoch and hash; old successful
observations cannot be relabelled. The final driver must also enforce the
workflow's 20-minute acceptance window and real consumer/DNS/CRM transaction.
The `worker` module CLI refuses an enabled policy with
`PRODUCTION_DRIVER_REQUIRED`; its status reader remains available. The final
effect-capable scheduler must use the production orchestration entry point.
An obsolete legacy invocation writes its refusal to `legacy-worker-status.json`
without replacing the production driver's authoritative `status.json`.

## Production integration still required

The default-off driver sequences the reviewed components; it is not a completed
live installation. The concrete `production_hooks` module and its private
dependency registry are intentionally absent. No CLI configuration can substitute
an all-success hook. Actual readers, exact compare-and-set endpoint changes,
Cloudflare origin verification and the protected application transaction still
need to be wired and exercised on the owner's infrastructure.

Before activation, resolve the real Windows-to-candidate acceptance path and
the existing upstream pull agent's authority during the staged transition. An
unchanged guest route through the powered-off old source cannot prove restored
candidate readiness. Source lifecycle configuration and the trusted host pin
must also be durably enrolled for each newly promoted active generation; the
original startup seed is never a fallback for a later provider ID.

The current safe checkpoint holds on an expired cutover claim without a proven
no-write renewal, missing concrete CRM continuation support, or unavailable
source SSH. A separately reviewed exact provider-fencing/restore alternative is
required to cover complete source-control loss. These holds prevent unsafe
writes; they are unresolved unattended-recovery cases, not successful failover.

## Application bridge

The bridge is CLI-only and must never be installed under `public_html`. It binds
one existing CloudInstance, logical service and exempt infrastructure owner. The
`protect` operation preserves existing metadata and saves its prior value before
adding the internal-infrastructure guard.

Credentials remain bound to that stable instance/service/owner after promotion.
`promote` requires a current committed core intent and a private fresh verified
proof bundle. It uses row locks and exact old-generation comparison to update
the existing CloudInstance and explicitly selected ExitUpstream records in one
database transaction. It does not create a second ambiguous instance row or
disable the generic infrastructure termination guard.

## Validation

Run offline Python checks from the repository root:

```sh
python3 -m unittest discover -s infra/relay_recovery/tests -q
git diff --check
```

Lint both PHP files with the deployed PHP version. Run
`tests/test_bridge_helpers.php` in an isolated temporary directory; it does not
bootstrap the live application. Production model transactions, real signed
agent exchange, staged candidate routing, backup restoration, DNS rollback and
exact provider resource absence still require separate acceptance. Offline
tests are not evidence that failover is armed or that a live migration succeeded.
