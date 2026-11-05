# Evidence and rollout acceptance

No physical node has been purchased, activated or tested by this change. No
existing customer or provider is migrated/disabled. The one-working-day delivery
target is unconfirmed until an actual approved node passes the full matrix with
recorded start/finish timestamps.

Dedicated-engine products support monthly, quarterly, semiannual and yearly VPS billing (1/3/6/12 months). Existing
non-engine hourly products retain their own behavior. Dedicated hourly checkout
and API requests must fail closed; asynchronous billing holds and interrupted
delivery recovery require a separately tested integration before hourly sales.

## Software purchase gate: before buying a node

Copy `infra/cloud-engine/onboarding/readiness.example.json` to a private evidence
directory. For each completed item record `result: passed`, mode (`unit`, `mock`,
`review` or `real`), relative `evidence_path`, its SHA256, command/commit/date,
reviewer and limitations. Test items also require `exit_code: 0` and `executed`
greater than zero. Preserve failed/skipped results separately; do not mark a
zero-test runner successful. Evidence must belong to the final reviewed SHA.

```sh
python3 infra/cloud-engine/onboarding/gate.py /private/evidence/readiness.json \
  --phase software
```

Exit zero establishes that supplied evidence records meet the gate schema and
hash checks. It does not independently certify logs, grant purchase permission,
publish code or activate sales. A human reviewer must examine each artifact.
Pending items return exit 2 with explicit reasons. The committed example
deliberately fails; it must not be changed to pretend absent evidence exists.

The software gate requires architecture/application/schema completion; API,
existing-regression, provisioning and security test results; documented network
and subnet design; prepared pinned images; console/traffic simulation; deployment
and monitoring tools; backup runbook; measured/justified capacity assumptions and
dated commercial inputs. Missing signed image provenance or current supplier
quotations are concrete blockers. A synthetic economic example is not a quote.

## Acceptance matrix

| Scenario | Software evidence | Physical evidence required after approved purchase |
|---|---|---|
| Paid create/duplicate callback | Existing invoice/Wallet gate and idempotency tests | Internal test payment/order creates exactly one VM/IP |
| VMID/IP/resource reservation | Transaction/uniqueness, concurrent order and exhaustion tests | Reconcile actual inventory/capacity including templates |
| Clone/config/start failures | Failed UPID, timeout, malformed body and lost-response fixtures | Induce bounded task failure/disconnect and recover same VM |
| Image initialization | Checksum/manifest and disabled-candidate tests | Every accepted image version: login, cloud-init, DNS, disk growth, reboot |
| Power/reinstall/credentials | Scoped API and asynchronous operation tests | Supported guest actions and verified resulting guest state |
| Console | Ownership, expiry, replay, identity change and gateway authentication tests | Browser WSS/noVNC, invalid Origin, expiration, node TLS and cross-customer denial |
| Networking | Validated routed subnet templates/IPAM history | Independent ingress/egress, MTU, VPN UDP/TCP, spoofing/lateral management denial |
| Traffic | Exact-byte duplicates/resets/epochs/month coverage fixtures | Generated traffic measured at one interface observation point before/after VM/node restart |
| Renewal/expiry/grace/refund | Existing billing/lifecycle/compensation regressions | Approved internal account lifecycle without affecting current customers |
| Delete/release | Unknown outcome retains IP; authoritative absence required | Stop/delete VM, clear bound sessions/network state, verify absence before IP reuse |
| Backup/restore | Runbook, manifest/config and failure checks | Off-node backup verification and isolated restore with login/data integrity |
| Sales/drain/fallback | Disabled/default, stale health and provider selection tests | Drain node, stop new orders, preserve existing VM actions and Hetzner fallback |

All real-node records require `mode: real` and `node_identity`; mock records are
rejected for physical acceptance. Run `gate.py ... --phase hardware` after the
matrix is complete. GPU/foreign-provider tests and existing customer lifecycle
must remain separately identifiable, not swallowed by new suite totals.

## Rollout and reversibility

* **A, development:** local fixtures/mocks only; no purchase, live API mutation,
  production queue or paid infrastructure. Software gates stay explicit.
* **B, approved node:** node installation and physical acceptance with sales
  disabled. Track real testing costs and approved scope. No customer migration.
* **C, internal orders:** use existing confirmed-payment/refund path with approved
  internal accounts, record VM/IP/billing identity and teardown reconciliation.
* **D, pilot:** owner-approved low cap and known customers; watch task age, traffic
  coverage, backup, node resource headroom and support incidents.
* **E, gradual eligible new orders:** add selected product/node mappings and sales
  caps. Leave Hetzner Cloud selectable as a fallback.
* **F, accepted rollout:** commercial/security review, restore acceptance and
  operator on-call/runbook ownership. Existing services are never auto-migrated.

Any loss of identity, IP ownership, accounting coverage, restore capability or
node health freezes new sales and escalates review. It does not authorize
destroying uncertain VMs, releasing their addresses or charging customers again.
Rollback starts with sales-off/drain and preserves existing services, data,
allocation history and the old provider path. See [node rollback](NODE-RUNBOOK.md).
