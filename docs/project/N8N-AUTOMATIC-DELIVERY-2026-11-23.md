# Dedicated n8n automatic delivery

Base: `3b0aebfb7940c594cd7f2729aee10bb4412187b2` on `develop`.

The owner requested independent Linux servers with automatic n8n installation,
dedicated subdomains, HTTPS and account access. The owner confirmed written
commercial hosting permission on 23 November 2026. Permission text and customer
credentials are not stored in this repository.

| Region | Allocation | Customer billing |
| --- | --- | --- |
| Iran | Proxmox, Linux template, purchased CPU/RAM/disk | Hourly, monthly, quarterly, six months, yearly |
| Germany | Hetzner Cloud, compatible Linux image | Hourly, monthly, quarterly, six months, yearly |
| Türkiye | VPS.TC monthly parent allocation | Monthly, quarterly, six months, yearly |

`ManagedN8nCatalog` reads current `CloudPlan` prices and adds 20%, rounded up in
IRT. Germany and Türkiye use their respective VPS catalogue rows. Iran uses the
least expensive Turkish tier meeting all purchased CPU, memory and disk sizes.
There is no general long-term discount on n8n. Existing Navasan snapshot, stock,
sales, identity, wallet and fraud controls remain in force. An unavailable or
unpriced reference is not sold. Normal VPS term restrictions remain intact.

The existing checkout and payment path creates the service and invoice. Unpaid
orders never enter provisioning. The managed application marker is persisted in
the original service transaction. Provisioning stays bound to the purchased
region/provider. No extra application VM is purchased on installation retry.

`cloud:n8n` runs every minute with a scheduler mutex and per-service locks. It
uses the allocated VM's SSH endpoint and encrypted credential, pins its host key,
and starts one bounded systemd installation unit. Docker, PostgreSQL, persistent
volumes and n8n 2.48.7 are installed on the dedicated VM. The instance owner is
initialized on loopback before public routing starts. Repeated installation
preserves encryption keys and verifies an already-created owner rather than
resetting it. Dependencies get bounded retries; a failed or timed-out install is
reported to the existing error tracker without exposing transport output.

Cloudflare creates a unique allocation subdomain. Public VMs use Caddy TLS; Iran
uses the existing NPM gateway and its domain boundary. A valid HTTPS certificate,
an allocation-specific marker and the actual n8n readiness response are required
before the customer receives a ready notice. Normal GET does not decrypt the
initial password; an owner-only CSRF-protected POST reveals it transiently. VM
reinstallation is blocked for this managed application. Stop, start, wallet
metering and lifecycle use the existing cloud paths. Gateway cleanup must succeed
before deleting the allocation.

VPS.TC procurement remains monthly. `VpsTcRenewal::retainManagedN8n` checks a paid
retail invoice and the paid-through date, then maintains the parent one month at
a time using the existing exact invoice, balance, currency and due-date checks.
Each native period has a distinct durable operation key. An unknown financial
response never authorizes another purchase. This does not extend the retail
period or charge the customer's wallet again. Normal VPS renewal is unchanged.

## Production status — 23 November 2026

Installed and accepted in production. `n8n_sales_enabled`,
`n8n_iran_sales_enabled` and `n8n_license_confirmed` are enabled. The ordinary
Proxmox VPS provider remains disabled; this product's scoped Iranian switch
does not reopen ordinary VPS sales.

The native scheduler completed a newly created internal Iranian allocation
(service #994 / instance #622 / VM #111) without manual guest changes or manual
delivery advancement. Valid public HTTPS, the allocation marker, n8n readiness
and a real initial owner login were verified before sales activation. The
receipt is private; no credentials are included here.

All three internal acceptance services (#989, #993, #994) are cancelled, their
instances are deleted, and a fresh provider inventory contains no VM names for
this acceptance run. Their DNS and NPM routes were removed by the managed
cleanup path. Each has zero invoices; no customer wallet operations were made.
After cleanup, the naturally scheduled delivery heartbeat reported zero
pending services.

The live landing and purchase links render successfully. The live application
currently retains the existing per-customer Iranian purchase visibility gate:
guests do not see Iran, and eligible verified customers can select the owned
Iranian plans. The repository's newer locale-based public visibility change is
a separate, not-yet-installed change; this release does not deploy or bypass it.
The catalogue contains 2 Iranian, 7 Turkish and 20 German offers at acceptance.
Counts and prices are live values rather than fixed product definitions.

## Validation

The final isolated PHP run passed **173 tests / 877 assertions**, covering
managed n8n, Proxmox allocation/deletion, cloud provisioning/delivery,
VPS.TC lifecycle/renewal and service renewal. Eight guest-installer tests passed.
Three release/rollback tests passed; the unchanged release helper was also
exercised across the production phases below. Related GitHub checks passed on
each merged runtime PR, including MariaDB-backed delivery tests. One core
birthday job was rerun after a setup-php download timeout before tests started.

Legacy `CloudStoreTest`, `CloudHourlyTermSupportTest`, one hourly pricing test
and one cycle-conversion expectation fail on the untouched base. They are not
reported as passing. Local MariaDB could not create its Unix socket; MariaDB
evidence comes from the isolated CI services rather than SQLite.

Germany and Türkiye paid procurement and lifecycle paths were checked with
provider API fixtures. **No paid Hetzner or VPS.TC acceptance VM was purchased.**
The successful live end-to-end guest installation, HTTPS and initial-login
acceptance applies to Iran. Production migrations and the production test suite
were not run.

## Runtime follow-ups accepted in production

- MariaDB state persistence merges metadata under a row lock without
  dialect-specific JSON object casts; delivery and removal share service locks.
- A VM being ready is insufficient for a customer-ready notice. The actual n8n
  application and its own HTTPS endpoint must pass verification.
- Iranian vendor repositories returned HTTP 403. Installation uses signed
  Ubuntu `docker.io` / `docker-compose-v2` packages when needed and reuses a
  working Docker installation on retries. PostgreSQL and Caddy have verified
  Docker public ECR fallbacks; n8n has its official GHCR fallback. TLS validation
  remains enabled, and arbitrary mirrors are not used.
- The legacy QEMU-only allocator reused an address belonging to existing LXC
  #124. Only the temporary test VM was stopped and cleaned; LXC #124 was left
  unchanged and verified running. Allocation now inventories QEMU and every
  LXC network interface, reserves pending database addresses, fails closed if
  inventory is unreadable, and serializes clones by node. The new allocation
  pool starts at 10.10.10.140 after inventory and ARP checks; existing guests
  were not reconfigured.
- Fresh cloud-init can hold package locks. Installation waits for cloud-init
  before resource/package checks and runs in a bounded separate systemd unit.
- Proxmox refuses deletion of running VMs. Authorized deletion now stops the
  same guest, waits for its stop task, verifies stopped status, then deletes.
  Failed stops never authorize deletion. The real internal cleanup path passed.

## Installed releases

| Change | Pinned installed source | Merged PR |
| --- | --- | --- |
| Core, 34 exact destinations | `3e147d67d96b56b989ec16bc58a0116e98f83cca` | #389 |
| Scoped Iranian sales and signed Ubuntu fallback, 3 files | `865907cc56a797d0df12c230c876679b6624a481` | #391 |
| Official public ECR fallback, 1 file | `850b6bafecfa3485176bdbbc31ed350a1f8a7f1b` | #392 |
| IP allocation plus final runtime fallbacks, 2 files | `c0eb09a8fa97c80687f6eda432ae6a4d059f17f3` | #396, includes #394 runtime |
| Cloud-init ordering, 1 file | `06f97f14d7321e4e6c6e489f3795b0c81e71894b` | #398 |
| Stop before deletion, 1 file | `f9c5255351c79e48d4170cb7c3382b485c3a0729` | #399 |

Final runtime merge at this acceptance: `70d935872e0191cf0ac6061d4311c13c77795516`
on `develop`. Concurrent homepage and other operator changes were preserved.

Production has reviewed differences absent from the original base. Per-file
manifests and the reviewed overlay preserve them, including NPM 2.15, financial
floors and current private router dependencies. Each incremental manifest binds
only that update's destinations to exact installed preimages. Earlier immutable
manifests remain available at their pinned source commits; their superseded
file hashes must not be mistaken for the final state.

`scripts/n8n-release/release.py` fetches a fixed SHA, checks all before/after
hashes, stages privately, backs up exact destinations and uses the existing
same-account FPM activation transport for both observed pools. It compiles five
target views and invalidates only relevant scripts/page keys. Broad cache
flushes, whole-repository deploys, schema changes and paid purchases are not
part of these releases. Financial acceptance resources were external/once,
zero-price internal services.

Rollback restores only a manifest's prior bytes and refuses newer operator
work. Apply follow-up rollbacks in reverse order with current hashes verified.
File rollback does not remove customer servers or reverse financial activity.
Sales activation is a separate setting from file installation.
