# Relay recovery controller

This isolated Python 3.12+ package provides a durable recovery planner, a WHM
controller, an authenticated pull agent on Proxmox, and narrowly scoped provider
adapters. It does not use the public customer provision/terminate queue.

**Deployment status:** the framework is being installed in observation mode.
Purchasing, promotion and retirement must remain disabled until the production
recipes and an end-to-end recovery drill have passed. A missing recipe, guest
probe, backup proof or fencing receipt is a blocker, never a successful check.

## Components

| File | Responsibility |
| --- | --- |
| `core.py` | Pure planner, SQLite journal, budget ledger and fresh effect permits |
| `worker.py` | Independent bounded scheduler; commits intent before effects |
| `gateway.py`, `agent-endpoint.php.tpl` | Signed data exchange and durable receipt inbox |
| `agent.py`, `protocol.py` | Root agent, replay protection, durable outbox and acknowledgement |
| `iran_adapter.py`, `iran_probes.py` | Fixed, hash-pinned local probes and recipes |
| `hetzner.py`, `provider_adapter.py` | Live quotes, explicit adoption and exact-resource cleanup |
| `controller_adapter.py` | Receipt validation, evidence collection and probe publication |
| `servernet_bridge.php` | Private CLI for the existing owner's protected logical service |
| `relay_side.py` | Restricted SSH inspection and private consistent SQLite snapshots |

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

## Effect gates

Default policy requires a sustained three-hour outage with continuous fresh
observations and a healthy independent control path. Probe gaps and an unhealthy
WHM/Internet path do not count as evidence to buy a replacement. Country priority
is Germany, Finland, then the United States; compatible x86 models are compared
using current project prices, IPv4, taxes and expected outgoing traffic.

At most two managed generations may coexist. The ledger bounds attempts in a
rolling 24-hour window, promotions and trial spending. The currency is immutable
once the ledger has entries. Exhaustion holds safely; a new incident cannot erase
old costs or ambiguous operations.

Before promotion, all critical consumers, a verified restore checkpoint, complete
registry and persistent old-writer fence must be fresh. Before retirement, the
new generation must pass the observation window, known DNS TTL expiry, zero
remaining old references and a durable backup covering the promotion checkpoint.

An unreachable candidate is cleaned up only after exact ownership, never-promoted
status and absence of actual writes are proven. Deleting the server is not a
billing-completion receipt: captured dedicated IP resources must also be absent.
Unknown create/delete outcomes are reconciled with reads only. Another mutation
requires a new intent and fresh gates. Existing customer servers and unrecorded
provider resources are never implicit cleanup targets.

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
