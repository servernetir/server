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

## Validation and release

Source checks: 165 relevant PHP tests / 845 assertions passed; a further
cleanup mutex check brings the n8n suite to 19 tests / 106 assertions.
MariaDB CI caught an unsupported JSON object cast; installation state now
merges under a row lock without dialect-specific JSON updates. The corrected
MariaDB delivery job passed on `0639157c08e6fc0ab143fd492f448a935fc16ba5`. Three guest-installer tests and three release/rollback tests passed.
The final CI SHA and database results must be recorded after publication.

Legacy `CloudStoreTest`, `CloudHourlyTermSupportTest`, one hourly pricing test,
and one cycle-conversion expectation also fail on the untouched base. These
baseline failures are not reported as passing. A locally attempted MariaDB
server could not create a Unix socket in this executor; MariaDB validation is
assigned to the isolated CI service, not inferred from SQLite.

Production has reviewed source differences absent from `develop`. The release
manifest and `production-overlay.patch` preserve them, including NPM 2.15,
financial floors and current private router dependencies. The overlay is not a
request to roll these changes back. Candidate PHP tests cannot bootstrap all
production-only dependencies in the local checkout; complete production
bootstrap and page acceptance are separate steps.

`scripts/n8n-release/release.py` fetches a fixed SHA, checks every before/after
hash, applies the reviewed overlay privately, backs up exact destinations and
uses the existing account-owned FPM activation transport. Dependencies install
before entry points. Broad cache flushes, database migrations, external-server
purchases and customer charges are not part of this file release. Runtime sales
activation is separate and remains off until acceptance.

Rollback restores only this manifest's prior bytes and refuses newer operator
work. It does not remove real customer servers or reverse financial activity.
Database schema is unchanged. External paid procurement is covered by fixtures;
an actual paid provider purchase requires a separately bounded acceptance run.

Status at preparation: implementation and isolated source tests complete;
production installation and live n8n acceptance are pending.

The CLI-only acceptance helper allocates one 2 vCPU / 2 GiB / 40 GB Proxmox
VM for the configured internal owner, with external/once zero-price billing,
no invoice and no wallet operations. A private durable receipt binds creation,
HTTPS/owner-login verification and cleanup to that one allocation. Unknown
allocation responses cannot authorize a second purchase. It is not a paid
external-provider acceptance run.

## Live acceptance follow-up

Core release `3e147d67d96b56b989ec16bc58a0116e98f83cca` installed 34 exact
files and activated both account-owned FPM pools. PR #389 merged as
`49d4928d808c190bb8ca1581e419cdcd19a58fae`. All 41 checks passed; the birthday
MariaDB job needed a retry because GitHub timed out downloading setup-php
before any tests ran. The delivery matrix passed 167 tests / 855 assertions.

Internal Proxmox acceptance service #989 / VM #108 was newly allocated with
2 cores, 2048 MiB fixed memory and a verified 40 GiB disk. No customer invoice
or wallet debit was created. Runtime sales remain closed while acceptance runs.
The guest revealed an HTTP 403 from download.docker.com in Iran. Ubuntu's
signed docker.io and docker-compose-v2 packages provide the bounded fallback;
TLS errors and other unexpected repository failures remain errors.

Ordinary Proxmox VPS sales were already globally disabled. The explicit
`n8n_iran_sales_enabled` switch permits the owned n8n product independently,
while retaining stock, exact-plan quarantine, current prices and Shahkar checks.
It does not reopen ordinary VPS products or disabled foreign providers.

The follow-up manifest contains only its three changed production files, with
preimages bound to the installed core release. The original full manifest and
its rollback recipe remain available at the immutable core release SHA.


Iranian acceptance also exposed a legacy allocator defect: QEMU-only discovery
reused 10.10.10.60, which belongs to existing LXC #124. The temporary new VM
was stopped and cleaned; the existing container was not modified. Allocation
now inventories QEMU and every LXC network interface, reserves pending database
addresses, fails closed on an unreadable inventory and serializes clones under
a per-node lock. A separate address-pool start is used above legacy addresses.
Three regression tests cover LXC reservations, unreadable inventory and clone
contention. Ordinary Proxmox VPS sales remain disabled.
