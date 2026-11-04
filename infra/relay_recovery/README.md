# Relay recovery controller

This isolated Python 3.12+ package provides a durable recovery planner, a WHM
controller, an authenticated pull agent on Proxmox, and narrowly scoped provider
adapters. It does not use the public customer provision/terminate queue.

**Deployment status:** installed versions and their acceptance evidence are
tracked in the private operational checkpoint. Publication does not install or
arm this package. Purchasing, promotion and retirement must remain disabled
until actual production recipes and an end-to-end recovery drill have passed.
A missing recipe, guest probe, backup proof or fencing receipt is a blocker,
never a successful check. Merely setting `enabled` to true does not complete
the integration.

## Components

| File | Responsibility |
| --- | --- |
| `core.py` | Pure planner, SQLite journal, budget ledger and fresh effect permits |
| `worker.py` | Observation CLI and isolated scheduler test API; enabled legacy CLI is blocked |
| `production_driver.py` | Default-off restore/cutover sequencer with separate durable external and CRM claims |
| `production_hooks.py` | Typed Cloudflare/origin/lifecycle components; the complete global coordinator is still required |
| `isolated_verifier_enrollment.py` | Explicit private guest verifier enrollment, pins and durable replay checks |
| `isolated_trial.py` | Private read-only CT/dependency/native-guest composite evidence producer; no scheduler or global readiness binding |
| `gateway.py`, `agent-endpoint.php.tpl` | Signed data exchange and durable receipt inbox |
| `agent.py`, `protocol.py` | Root agent, replay protection, durable outbox and acknowledgement |
| `iran_adapter.py`, `iran_probes.py` | Fixed, hash-pinned local probes and recipes |
| `iran_effects.py` | Durable, claim-bound Iran stage/apply publication and authenticated outcome reconciliation; private in-process API |
| `hetzner.py`, `provider_adapter.py` | Live quotes, explicit adoption and exact-resource cleanup |
| `controller_adapter.py` | Receipt validation, evidence collection, probe publication and transactional Iran effect inbox routing |
| `servernet_bridge.php` | Private CLI for the existing owner's protected logical service |
| `crm_bridge.py` | Fixed private PHP promotion hooks, fresh bundle dispatch and actual application readback |
| `relay_side.py` | Restricted SSH inspection and private consistent SQLite snapshots |
| `backup_client.py` | Pinned SSH download, strict archive validation and disk admission |
| `cloudflare_adapter.py` | Scoped content-only DNS plans, receipts and reconciliation |
| `bootstrap.py`, `snapshot_adapter.py` | Quarantined trial bootstrap and durable native disk restoration |
| `generation_guard.py`, `lifecycle_side.py` | Physical-instance writer guard and signed lifecycle operations |
| `lifecycle_client.py`, `lifecycle_cli.py` | Pinned SSH lifecycle client and restricted dispatcher |
| `restore_workflow.py` | Preparation through `PRE_CUTOVER_READY`; global cutover is separate |
| `iran_stage.py`, `ct_stage.py` | Isolated CT113 trial tunnel and reviewed endpoint transactions |
| `windows_probe*.py`, `windows_probe.ps1` | Authenticated read-only guest egress proof without QGA |
| `windows_candidate_probe_client.py` | Explicit, default-off native candidate guest envelope; separate fixed proxy enrollment and live transport required |
| `maintenance.py` | Bounded pruning of obsolete probe history while retaining Iran effects and their provenance |

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

The host control endpoint is enrolled privately; no production control address
belongs in source or test fixtures. Before upgrading the host probe, create the
root-owned `0600` enrollment described below and update its pinned local SPEC.
Missing or invalid enrollment must keep host control evidence false. The
installed release and its private configuration are a separate deployment.

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

## Private host-control enrollment

For the `host` probe, add `--host-control-config` and the absolute enrollment
path to the locally reviewed, hash-pinned probe SPEC. A typical private path is
`/etc/servernet/relay-recovery/host-control.json`. The file must be a root-owned
regular file with exactly `0600` permissions, no symlinks or hardlinks, and
trusted parent directories. Its maximum size is 1024 bytes.

The schema has exactly three fields. This illustration contains deliberately
invalid placeholders; create the actual file privately, never in Git:

```json
{"agent_id":"LOCAL_AGENT_ID","ipv4":"CONTROL_IPV4","schema":"servernet.relay.host-control.v1"}
```

Write canonical JSON bytes: sorted keys, compact separators and no trailing
newline. The agent ID must match the local agent. The address must be a
canonical global unicast IPv4 address of the independently managed control
endpoint, distinct from the relay being tested. Port 443 is fixed; the file
cannot supply a URL, command, alternate port or ownership bypass. Job data
cannot select this file or the control endpoint.

Missing, malformed or changed enrollment returns false control evidence without
falling back to a built-in address. The enrolled address and file contents are
not returned in probe receipts. Register this private file and repin the local
SPEC before installing a release containing this change. Publication alone
does not update existing probe installations or enroll an endpoint.

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

## Historical outcomes and isolated candidate acceptance

A terminal mutation reconciliation preserves `historical_outcome: true` and
reports `observed_at: null`, with a separate `outcome_reconciled_at` clock.
The gateway retains and acknowledges that outcome unchanged. Its receipt time
does not turn an old stage result into a fresh network or consumer measurement.

The explicit `isolated_candidate_v1` accept profile requires fresh CT stage
evidence, matching dependency registry digests before and after the probe, and
a raw guest HMAC response using `servernet.relay.vm-candidate-probe.v1`. Signed
scope binds the exact candidate identity, generation, stage profile, restore
epoch and proxy binding. Default-path guest responses and `staged: true` alone
cannot satisfy it; isolated evidence never proves default-path or global cutover.
The CLI can now inject the verifier through an explicitly pinned, private
`isolated_verifier` enrollment. Ordinary observation jobs do not load optional
isolated enrollment. This wiring creates no transport or readiness proof. The native
Windows runtime additionally supports an explicitly enrolled fixed candidate
SOCKS path, disabled by default. Its separate client returns only the signed
guest envelope; no proxy transport or production enrollment is supplied.
[The candidate contract and validation limits](windows_candidate_probe.md)
describe its boundary. Missing actual transport, native/live acceptance,
registry ownership, publisher coordination or production hooks keeps the
workflow on HOLD; no core profile gate is enabled by this contract.

`isolated_trial.IsolatedTrialProducer` now supplies a finite private in-process
producer for that evidence. It accepts separate, already authenticated isolated
guest and CT accept contexts, requiring the same target, full scope, resource,
profile and restore identity. Their different existing parameter grammars remain
unchanged. It reads an enrolled dependency baseline, performs a fresh real
`NodeStage`/CT accept, obtains the native Windows signed candidate envelope and
reads the dependencies again, then invokes the actual enrolled isolated verifier.
Its result contains only isolated proof; it does not implement `ProductionHooks`,
restore callbacks or global readiness. Ordinary publisher `publish_accept` is
still a separate operation.

Its canonical, privately hash-pinned config has exactly `schema`, `agent_id`,
`enabled` and `{path,sha256}` references named `node_stage`, `windows`,
`candidate_proxy`, `verifier`, `dependencies`. The schema is
`servernet.relay.isolated-trial.config.v1`. The dependency catalogue has schema
`servernet.relay.isolated-trial.dependencies.v1`, the exact agent ID and 2–32
resources: file entries `{id,kind:"file",location,path}` or unit entries
`{id,kind:"unit",location,unit}`; location is only `pve` or fixed `ct113`. Both
kinds are required. Every declared resource must be read. State hashes include
file bytes and loaded unit/process/start-time state; no raw config is returned.
Coverage refers to this finite catalogue and does not prove global completeness.

Use `inspect_dependencies()` to measure its finite baseline, then bind that
digest and the catalogue pin in an authenticated isolated request. `produce()`
does not accept a caller-provided observer, guest envelope or success flag.
Missing candidate enrollment fails before guest key/state/network access; an
unavailable native proxy path remains a HOLD. Separate guest transport and final
verifier replay stores are required. The composite shares a bounded deadline and
preserves original proof clocks. Opt-in `NodeStage.run(...,fresh_accept=True)`
rejects any existing context under its dispatch lock; cached ordinary accept
receipts cannot become fresh CT measurements. A repeated context requires a new
authenticated read-only job. No proxy listener or production enrollment is created.
The producer's separate offline validation passed 174 related tests, including
17 composite tests, using actual NodeStage/CT/client/verifier code with only OS and
guest wire transports replaced. This source delta has no live acceptance or
new CI result yet; the older suite/CI counts below cover earlier source.

The 3 November integration checkpoint passed 707 Python tests on Python 3.12.14
and 72 PHP helper assertions on PHP 8.3.6. These are offline results; they do not
establish native candidate-path execution or live readiness.

## Effect gates

Default policy requires a sustained three-hour outage with continuous fresh
observations and a healthy independent control path. Probe gaps and an unhealthy
WHM/Internet path do not count as evidence to buy a replacement. Country priority
is Germany, Finland, then the United States; compatible x86 models are compared
using current project prices, IPv4, taxes and expected outgoing traffic.
An unavailable external egress measurement produces unknown path evidence;
it does not turn a verified SSH tunnel into an outage. Verified tunnel/target
failure remains distinct from an unavailable Windows guest or diagnostic read.
The CT process inspection has a three-second local timeout; the adapter's
eight-second outer read budget remains unchanged. A valid slower inspection can
now finish, but an exhausted outer budget still produces unknown evidence.

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
live installation. `production_hooks` now contains real typed Cloudflare readers,
content-only migrations, direct-and-edge authenticated origin proof components
and durable lifecycle enrollment. Its `build_integrations()` deliberately refuses
enabled global execution until the actual Iran consumer/publisher, authoritative
DNS, restore callbacks, cutover and retirement coordinators are connected.
The finite `IranEffectPublisher` and `PromotionBridge` components below do not
register those integrations or change the builder's refusal. Live enrollment,
complete registry coverage and coordination remain separate work.
The origin responder is an API, not an installed listener. The private complete
dependency registry, real endpoint enrollment and actual application transaction
still require installation and acceptance. No CLI flag substitutes for them.

Before activation, resolve the real Windows-to-candidate acceptance path and
the existing upstream pull agent's authority during the staged transition. An
unchanged guest route through the powered-off old source cannot prove restored
candidate readiness. Source lifecycle configuration and the trusted host pin
must also be durably enrolled for each newly promoted active generation; the
original startup seed is never a fallback for a later provider ID.

The driver and PHP bridge now share a durable `CRM_PROMOTION` claim, bound to the
original checkpoint, gate hash and expiry; expired parent claims never authorize
new CRM writes. Completed or unknown effects are reconciled with reads only.

An interrupted `COMMITTED` child step can resume only when its durable row has
no result and no immutable dispatch claim. Expired trial permission can then be
renewed for the same intent and context; external and CRM permission remain
bounded by the original fresh `PROMOTE` claim. Renewal and dispatch are one CAS
transaction with immutable audit history. After dispatch, a missing receipt or
caller-supplied `no_write` flag can only lead to read-only reconciliation. Legacy
dispatched rows without the new audit table record also cannot repeat a write.

An expired original cutover claim, missing real CRM integration, or loss of
source control before durable fencing still causes HOLD. Provider power-off
alone cannot persist the retired-generation tombstone. Source SSH loss after a
verified durable fence can continue through the existing provider snapshot path;
loss before that fence requires independently authenticated rescue/control or a
complete restore-tested checkpoint with explicit data freshness policy. These
are actual acceptance prerequisites, not successful unattended failover.

## Original mutation deadlines

NodeStage requires an authenticated absolute `params.expires_at` for stage,
apply and rollback. The CT envelope uses `min(original expiry, now+25)`;
delivery never starts a fresh lease. Push, dispatch and CT write guards retain
that same deadline. Legacy mutation jobs without it fail closed, while their
historical read-only reconciliation remains available. The durable publisher
may not renew it. `IranEffectPublisher` binds this value to the exact
durable driver claim and, for apply, the original PROMOTE expiry. Installation
still requires compatible pinned recipes and private enrollment.

The adapter accepts a finite CT public-data grammar: a canonical Ed25519 host
public key with its matching SHA-256 fingerprint, exact resource identity and
configuration hash, and the original absolute deadline. Restored profiles bind
the restore epoch/hash; apply and rollback additionally require distinct old/new
identities and addresses plus the expected registry hash. Private key data,
extra fields and malformed identities are rejected. This parser adds no CT
runtime dependency and grants no write authority by itself.

## Durable Iran publication and outcomes

`iran_effects.IranEffectPublisher` is an explicit private in-process component,
not a network-selected command or enabled production factory. Stage/apply
publication requires the exact immutable dispatched driver claim, current core
scope and live authorization. Provider reads resolve and recheck addresses;
authorization is checked again after those reads. The exposure lock then checks
the current claim and deadline before the one-time manifest exposure.

The manifest, original permit/context and publication mapping are durable before
exposure. A pending effect protects the agent's single manifest slot from ordinary
probe publication. A repeated call with an existing mapping returns that original
record; it does not renew the lease, issue another revision or retry an ambiguous
write after interruption.

The collector routes signed gateway inbox reports into the effect outcome ledger
inside a serialized SQLite write transaction before acknowledging consumption.
It binds the saved manifest, job, agent and revision and preserves original
receipt clocks. Opposing terminal outcomes produce `CONFLICT`; duplicate delivery
cannot refresh health or replace the first terminal receipt. Historical outcomes
and read-only reconciliation establish an old effect's fate without becoming
fresh acceptance. A separate explicit `publish_accept` after a successful effect
requests a new read-only measurement; publication alone proves no readiness.

Maintenance recognizes the exact optional effect-table pair and retains all
effect mappings/outcomes and referenced manifests, receipts and authenticated
non-probe reports, including consumed reports. It rejects partial or unexpected
schemas and broken provenance rather than pruning through them. Ordinary probe
history remains subject to the existing bounded retention rules.

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

`promotion_bundle.PromotionBundleWriter` creates the private
`promotion-<PROMOTE id>.json` consumed by the bridge. The private
`crm_bridge.PromotionBridge.apply` hook invokes it immediately before the fixed
PHP transaction. Neither component registers that hook or enables the incomplete
global builder. The writer reads
the existing controller, driver and restore journals, requires exact fresh
external resource and consumer readbacks, independently checks provider source
power-off, and publishes atomically with mode0600. Missing proofs cause HOLD.

Its pinned canonical private enrollment is selected by
`production_driver.promotion_bundle:{path,sha256}`. The enrollment contains
`schema=servernet.relay.promotion-bundle.enrollment.v1`, owner/service/project,
`registry_hash`, and an explicit `locations` mapping from provider references
to application location codes. Provider references are not application codes.
The bundle deadline is bounded by every transient proof, including the
15-second proxied-origin proof, and by the original PROMOTE and driver permit.
An assigned IPv6 prefix requires an independently verified exact host address;
the writer never invents a host suffix. No enrollment has been installed by
publishing this component.

Every PHP promotion write requires the exact committed CRM continuation and
the registry's pinned instance/upstream selection. The bridge enforces the
bundle's shorter proof deadline before writes and after commit. A late or
unknown acknowledgement is reconciled through fresh application readback,
including actual ExitUpstream host and parsed link authority. Route-only
snapshots omit raw credentials. Existing receipts without this complete
readback shape cannot silently count as verified promotions.

`PromotionBridge` binds the exact durable CRM continuation, parent context,
registry selection and historical bundle identity/address scope. Dispatch uses
the fixed, hash-pinned private PHP pipe; the remaining proof/permit lifetime caps
its process timeout. An acknowledgement leaves the step dispatched. Only fresh
read-only actual row/upstream readback can produce the CRM completion observation.
Unknown or late acknowledgements are reconciled without another mutation, even
after expiry or disablement. Keep the original private bundle for reconciliation
of new immutable claims; its historical binding never renews proof freshness.
Real application/MariaDB transaction acceptance is still required.

## Validation

Run offline Python checks from the repository root:

```sh
python3 -m unittest discover -s infra/relay_recovery/tests -q
git diff --check
```

The November 3 sanitized publication candidate passed 724 Python tests in
174.349 seconds on Python 3.12 and 72 standalone PHP bridge assertions on PHP 8.3. Both PHP
entry points passed lint. The host-control enrollment adds 17 isolated checks;
no live endpoint or provider credentials are used by these tests.

The November 4 continuation passed 97 focused Python tests, 112 PHP bridge
assertions, both PHP entry-point lints and independent review. The local full
suite encountered this runtime's non-root-owned SSH binary and its mode0755
temporary parent; production trust checks remain intact. The standard CI full
suite is tracked separately in the pull request. Real model transactions and
deployment acceptance remain required.

The final November 4 source-component review independently passed 118 focused
Python tests covering the CRM bridge, Iran adapter/probes and effect publisher.
That run includes regressions for concurrent contradictory inbox outcomes and
live authorization revoked during provider reads. The final frozen source also
passed 528 related Python tests across 22 modules, 112 PHP helper assertions and
both PHP entry-point lints. The previous standard-environment CI passed 780
Python tests; CI for this final source delta is still pending. Offline checks do
not establish installation, actual MariaDB transactions or live acceptance.

The pull-request workflow also runs the standalone Linux/PHP suite without
network access and the two native Windows PowerShell 5.1 fixture scripts.
Workflow results are separate from the local checks above. Neither native
script starts the installed probe or reads production keys or enrollment.

Lint both PHP files with the deployed PHP version. Run
`tests/test_bridge_helpers.php` in an isolated temporary directory; it does not
bootstrap the live application. Production model transactions, real signed
agent exchange, staged candidate routing, backup restoration, DNS rollback and
exact provider resource absence still require separate acceptance. Offline
tests are not evidence that failover is armed or that a live migration succeeded.
