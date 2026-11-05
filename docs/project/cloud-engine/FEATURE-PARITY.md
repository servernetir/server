# VPS feature parity and billing expansion

Requested 2026-11-05. Existing feature base: `c8dc7108`; no live provider or
customer mutations are authorized by this implementation task.

The target is the Hetzner Cloud **VPS experience**, including its attached
network/storage, not unrelated managed products such as load balancers or DNS
hosting. The existing Hetzner adapter exposes server lifecycle, console, metrics,
resize, SSH keys, extra IPs and volume operations. The common customer operations
also define snapshots, rescue and reverse DNS; some legacy adapters advertise
capabilities without implementing those methods, which is not parity evidence.

| Area | Existing Engine state | Required acceptance |
|---|---|---|
| Create/delete/power/reboot/shutdown | Durable implemented phases | Same ownership and ambiguous-outcome safeguards for added actions |
| OS reinstall/password/hostname | Implemented, image and guest-agent gated | Per-image real initialization and action evidence |
| Browser console | Credential-hiding gateway implemented | Real Proxmox/noVNC interoperability and replay/expiry acceptance |
| CPU/RAM/disk/network monitoring, traffic | Implemented; guest filesystem explicitly unknown | Measured guest filesystem capability and real TAP traffic |
| SSH authentication | Implemented; owned public-key references and encrypted provisioning credentials | Per-image real login; Windows SSH requires separate explicit image acceptance |
| Resize | Implemented; offline CPU/RAM/disk phases, capacity holds and verified contract update | Real storage/task behavior; no disk shrinking; IRT contracts only |
| Snapshots/backups and restore | Unavailable | Scoped inventory, quota, durable tasks, confirmed destructive restore and resource safety |
| Rescue/recovery | Unavailable | Accepted rescue image, retained boot configuration, reversible verified boot selection |
| Extra IPv4, IPv6, rDNS | Primary IPv4 only | Durable address ownership, routed networking, spoof filters and supported provider DNS |
| Firewall/private network | Fixed isolation template only | Customer rules and tenant network ownership without management access |
| Volumes | Unavailable | Storage reservation, immutable ownership, attach/detach/expand/delete recovery |
| Labels/delete/rebuild protection/action history | Implemented; scoped API and fa/en/tr customer controls | Customer protection is independent of expiry cleanup; no raw infrastructure journal exposure |

This is **partial parity**, not a claim that every Hetzner API capability is
implemented. Existing Hetzner Cloud services continue using their current adapter.

## Complete VPS feature inventory to finish

The rows below are requirements, not implemented endpoint promises. Verify the
current official Cloud, Robot and Proxmox schemas and allowed node permissions
before expanding the adapter. Do not infer coverage from a capability boolean.

| Feature group | Customer operations required | Remaining infrastructure contract |
|---|---|---|
| Server creation | Location/type/image selection, hostname, SSH key, cloud-init/user data, public networking choices | Creation currently uses accepted catalog images, primary IPv4 and controlled cloud-init only; arbitrary user data and disabling public networking need validation and image acceptance |
| Server type | CPU/RAM change, optional permanent disk expansion, architecture constraints | Local resize implemented; cross-node/type migration and replacement require explicit planned migration |
| Images | List accepted images, create/list/rename/delete snapshots, snapshot protection, backups converted to snapshots | Tenant-owned inventory, exact source VM ownership, storage quotas, retention and confirmed restore; no shared customer disk images |
| Backups | Enable/disable automatic backups, schedule/window, retention, list/restore/delete | Backup storage and schedule admission, authenticated durable tasks, verified off-node restoration and independent storage pricing |
| Rescue/ISO | Enable/disable rescue, credentials/SSH, attach/detach an accepted ISO | Signed/accepted recovery media, preserved boot/disk configuration, no arbitrary node path, verified reversible boot and console access |
| Primary IPs | IPv4/IPv6 visibility, safe assignment/unassignment, rDNS and protection | Primary IPv4 exists; IPv6 prefixes, routed ownership, anti-spoof rules, dependency-aware release and official Robot reverse DNS remain |
| Additional/floating IPs | Purchase existing approved inventory, assign/unassign, move where routing permits, rDNS | No purchase from customer requests; safe IPAM reservation, guest routing, failover support and network reconciliation before release |
| Firewalls | Create/list/update/delete rule sets, attach/detach servers | Direction, protocol, port/CIDR validation and tenant ownership; customer rules must never change node management rules or weaken mandatory anti-spoof isolation |
| Private networks | Create/delete networks/subnets/routes, attach/detach, private address aliases | Tenant-owned network and NIC inventory; verify SDN/VLAN support and node routes; prohibit overlap with management and other tenants |
| Volumes | Create/list/resize/delete, attach/detach, protection, labels | Storage reservations independent of the boot disk; immutable ownership, exact disk slots/receipts, no shrink or automatic destructive formatting |
| Placement | Placement-group selection and location/datacenter/type discovery | Multiple nodes already supported; anti-affinity needs admission across nodes and explicit rejection when impossible |
| Metadata and safety | Names/hostnames, labels, deletion/rebuild protection, operation history | Hostname, labels, protection and paginated history implemented; public labels remain separate from internal metadata |
| Monitoring/console | CPU/RAM/disk I/O/network charts, traffic, power state, boot time, browser console | Software implemented; real noVNC, image boots, filesystem measurement and generated TAP traffic acceptance remain |

Separate managed products such as load balancers, hosted DNS zones, certificates
for those products and object-storage buckets are outside VPS parity. Reverse DNS
for the VPS address remains in scope.

## Billing contract

The existing `billing.cycles` configuration remains authoritative: monthly (1),
quarterly (3), semiannual (6), yearly (12). Prices are integer IRT/Toman, with
existing configured cycle discounts, independent rounding of base/add-ons,
settled invoices and existing Wallet/PaymentService/renewal logic. Provider
delivery must not shorten a 3/6/12-month paid entitlement to one month.
Hourly Engine billing remains unavailable until its separate asynchronous hold
and resume contract is implemented; the new request does not require hourly.

Changes must cover quotations, initial payment/replay, no-overflow month-end due
dates, renewal invoicing and restoration for every requested cycle. No parallel
billing system or unverified production pricing is introduced.

The new term tests exercise real initial and renewal `PaymentService` settlement
and duplicate callbacks for all four cycles. Existing configured discounts are
0%, 5%, 10%, 20%; they remain configurable, not new commercial promises. A monthly
1,000,000 IRT plan currently quotes 1,000,000 / 2,850,000 / 5,400,000 / 9,600,000 IRT.
Prices are serialized as decimal strings, with overflow rejected before ordering.

Resize preserves the existing `CloudOperations` financial policy: no difference
invoice or immediate wallet debit; the new contract price applies at the next
renewal. It never rewrites an already-paid invoice or shortens its entitlement.
The customer form and API options explicitly disclose this rule. Prorating an
upgrade would be a separate change to the shared billing policy.

## Resize and metadata safety

Resize admission locks the existing service/VM/node, reserves only positive
resource deltas, and excludes held resources from both new sales and subsequent
resizes. Current locking capacity/VMID reads avoid stale InnoDB snapshots inside
longer API/payment transactions. The worker verifies ownership, live stopped state,
CPU/RAM settings, exact disk size and task results before committing the new VM
resources, existing service price and successful operation in one transaction.
A lost mutation response never causes automatic resubmission or hold expiry.
An operator must reconcile blocked/uncertain holds; do not delete hold rows to
make capacity appear available. There is no safe automated cancellation flow yet.

Customer labels are limited to 50 validated name/value pairs, separate from
infrastructure metadata. Customer deletion/rebuild protection applies before any
customer cancellation/refund or destructive operation. The existing browser
termination route also checks protection under its service lock. An expired owner
can clear protection without extending the entitlement or powering on the VM.
Trusted expiry cleanup retains its established lifecycle; protection does not
make an unpaid service permanent. Each change uses the existing audited,
idempotent operation journal and lease-fenced local completion.

## Verification boundary

Official Proxmox schema access returned HTTP 403 for
`https://pve.proxmox.com/pve-docs/api-viewer/apidoc.js` in this environment on
2026-11-05. The required domain is already in the saved environment draft but
not the enforced runtime policy. Features requiring unverified upstream schemas
must not be marked accepted from invented fixtures. Continue independent billing
and existing-contract work; record concrete implemented versus pending items.
The saved draft includes `pve.proxmox.com`, `git.proxmox.com`,
`download.proxmox.com`, `docs.hetzner.com` and `api.github.com`. Saving a draft
did not apply these hosts to this runtime. Review/save/Publish the environment
settings, then recheck runtime policy and retry the previously denied operations.
