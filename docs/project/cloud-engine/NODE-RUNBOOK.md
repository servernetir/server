# Dedicated Hetzner + Proxmox node onboarding

This is an operator runbook for an approved **new** dedicated node. Development
and simulation execute only renderers/tests. Do not run the existing Iran
inventory or production scheduler against this node. All phases preserve the
existing Hetzner Cloud fallback and existing customer services.

Engine billing supports monthly, quarterly, semiannual and yearly VPS (1/3/6/12 months). Do not enable hourly Cloud
Engine products or infer hourly support from another provider's existing flow.

## 1. Preconditions and official interfaces

Before purchase, complete [software acceptance](ACCEPTANCE.md), confirm a dated
supplier quote and obtain explicit purchase approval. After the ordered machine
is accessible, record its Robot server number, product, serials, location,
storage/network specifications, primary IP and routed subnet. Do not record API
passwords in Git. All Robot operations use the official documented Robot API:

* [Robot API](https://robot.hetzner.com/doc/webservice/en.html): physical server
  inventory, IP/subnet inventory and reverse DNS. Read the current method and
  authentication contract before each expansion; VM deletion never cancels a
  physical rental.
* [Hetzner dedicated network configuration](https://docs.hetzner.com/robot/dedicated-server/network/):
  confirm subnet delivery/routing and primary-IP gateway; no guessed uplink or MAC.
* [Proxmox VE documentation](https://pve.proxmox.com/pve-docs/),
  [official API viewer](https://pve.proxmox.com/pve-docs/api-viewer/), and
  [PVE on Debian](https://pve.proxmox.com/wiki/Installation): use supported PVE 9
  on Debian 13 or the current supported vendor image after compatibility review.

These official sites were referenced, not fetched/verified live in the restricted
development environment. Recheck current documentation before physical changes.
Required additional domains for documentation/artifacts include `docs.hetzner.com`,
`robot.hetzner.com`, `robot-ws.your-server.de`, `pve.proxmox.com`,
`download.proxmox.com` and the selected official distribution image hosts.

## 2. Hardware, installation and recovery access

1. Open independent out-of-band/KVM access and test a recovery path before any
   network or kernel change. Record the current disk layout and serials; obtain
   burn-in/SMART/NVMe health and RAM results. A read-only `preflight.py` report
   checks virtualization/RAM/device presence but does not replace these tests.
2. Verify VT-x/AMD-V, KVM availability, CPU core/thread count, ECC where purchased,
   usable mirrored storage, NIC driver/MTU, predictable uplink name and firmware.
   Reject unsupported hardware or exhausted/failed disks. No customer guest yet.
3. Install the vendor-supported base image through approved rescue/install-image
   tooling or ISO. **Disk layout and formatting require explicit node-specific
   approval and backups.** For a first node, use mirrored local NVMe/ZFS or an
   independently reviewed storage layout; do not count raw disk sum as usable.
4. Set a resolvable node FQDN and matching `/etc/hosts` management address. Configure
   the current official signed PVE repository/keyring/subscription. Verify vendor
   signing-key provenance; do not use `trusted=yes`, unsigned repositories or
   disabled TLS. Plan `install-pve.sh --plan`, review package diff, then execute
   an approved package stage. Kernel/reboot and root disk changes are separate
   attended steps. Verify booted kernel, `pveversion -v`, `pvesh get /version`,
   `qm list`, `pvesm status`, ZFS/RAID health and clock synchronization.
5. Install a verifiable management TLS certificate (private CA accepted through
   `tls_ca_file`, or a trusted issued certificate) for the configured endpoint.
   Verify SAN, chain, validity and API access without any `-k`/TLS bypass.

## 3. Single-source routed network candidate

Copy `infra/cloud-engine/onboarding/node.example.json` outside Git. Replace every
simulation address and set `simulation=false`. Its routed-bridge design assumes
the provider routes the assigned public subnet **to the node primary IP**. If
the purchased product uses a different model, stop and document a supported
model; do not paste a bridge example into an unrelated topology.

Run the renderer to an empty candidate directory and inspect its SHA manifest.
The uplink keeps its existing primary IP/default route; a portless guest bridge
gets one usable subnet IP as the guest gateway. Guest IPAM excludes network,
broadcast and gateway. Example /27: 32 addresses, 30 conventional hosts, one
guest gateway, **29 guest IPv4**, subject to actual supplier reservations.

Install only the reviewed guest-bridge fragment and forwarding settings through
an attended change with an independent console. Avoid restarting an unreviewed
management network or replacing `/etc/network/interfaces`. Verify provider route,
gateway reachability, outbound and inbound guest connectivity, DNS and MTU from
both guest and an independent external client. Do not invent a /32/onlink model
or use a floating/migratable address unless the provider explicitly supports it.

Enable the Proxmox firewall using its current nftables/iptables implementation
as supported by that PVE version. Start with restrictive host management ingress
(SSH/API only trusted operator/application/gateway sources). Preserve console
access. For every guest NIC set firewall and IP filtering; its allowed IP/MAC
must match the locked allocation. Deny spoofing, tenant-to-management and lateral
tenant access while permitting the advertised public guest protocols. Test TCP,
UDP, ICMP, IPv4 fragmentation/MTU and customer VPN workloads. Lack of IPv6
configuration means IPv6 must remain unavailable, rather than bypass isolation.

The renderer emits an actual `host.fw` candidate. Add `--vmid RESERVED_ID
--guest-ip LOCKED_IP` to render a concrete per-VM `.fw` file with `ipfilter-net0`,
MAC filtering, private/management/lateral denial and default incoming DROP.
Allowed public ports come only from the same config's explicit `public_ingress`
list (empty by default). Before installation, validate the generated file against
the actual PVE firewall compiler/API schema, inspect the resulting rules and test
traffic. Do not infer an applied firewall from a successful renderer test.

## 4. Least privilege, images and application registration

Install application migrations before enabling the updated worker/catalog. The
seven Engine migrations include
`2026_11_05_140000_create_cloud_engine_capacity_holds.php`; the new capacity
service depends on that table. Take a consistent backup first. A rollback must
stop new sales and fence workers, then reconcile every reserved resize hold and
remote mutation before reverting code. Never drop a hold table containing
unresolved operations or roll back disk growth; disk expansion is irreversible.

Create a dedicated privilege-separated Proxmox user/token with the API viewer's
minimum pool/VM/datastore permissions for the enabled actions. Start with the
documented `VM.Audit`, `VM.Allocate`, necessary `VM.Config.*`, `VM.PowerMgmt`,
`VM.Console`, `Datastore.Audit`/`Datastore.AllocateSpace` and only template/disk
permissions required by tested operations. Do not grant `Administrator`, `root@pam`
or cluster-wide `Sys.Modify`. Proxmox clone destination/template permissions must
be verified with the token, not assumed from an administrator smoke request.
Backup/host monitoring use separate principals. Keep the secret in the existing
encrypted application inventory and secure node secret files (0600), never in a
candidate JSON, URI, browser or request log.

Prepare each exact version through [image verification](../../../infra/cloud-engine/images/README.md).
Import guest subnets/IPs through the bounded IPAM mechanism. Register node with
sales disabled, accepted per-node image mappings, eligible existing CloudPlans,
resource capacities/reserves and a disjoint VMID range excluding templates.
Leave all other providers unchanged. Confirm fresh health observations are real;
manual `healthy` labels or heartbeat alone do not prove provisioning readiness.

## 5. Backup, monitoring and restore

Use an independent off-node Proxmox Backup Server/storage target. A second local
disk in the same node is not an offsite backup. Configure encryption, retention,
prune/garbage collection and verification jobs per the current PBS manual. Keep
encryption/recovery keys escrowed outside the node; test that an authorized
operator can recover them. Monitor the last successful backup and verification,
storage capacity and missing/stale jobs. Configure explicit RPO/RTO before sale.

Take application database backups consistently with the infrastructure journal,
not just guest disks. Restore tests must use an isolated VMID/network and no
customer public IP, verify boot/login/data/checksums and preserve the production
record. For host loss, retain allocations, disable new sales, restore DB/journal,
reconcile exact ownership markers and restore guest backups before reattaching
network identities. Never release an IP based on a missing node or stale backup.

Monitor node health, CPU pressure, RAM/ZFS ARC, I/O latency/space, thermal/storage
faults, network drops, time sync, API TLS expiry, operation age/lease progress,
uncertain reservations, backup verification and traffic sample gaps. A green
process heartbeat without completed operations is degraded service. Install the
collector/gateway from `infra/cloud-engine/` using their own configuration and
acceptance tests; run the engine worker with bounded locks and external failure
monitoring. No billing/overage charge is calculated from Proxmox RRD rates.

## 6. Acceptance and rollback

Complete real image, console, isolation, traffic and restore tests in the
[acceptance matrix](ACCEPTANCE.md). Run one internal paid-test order through the
existing invoice/payment pipeline using approved test payment data, then power,
rebuild, renewal/expiration and verified deletion. Record actual resource and IP
state; software mocks do not close physical acceptance.

To roll back, disable new node sales and set draining first; preserve journal,
IPs and paid services. Restore the previous application artifact/config without
dropping infrastructure tables containing live allocations. Never roll back a
schema by deleting live reservation history. Stop a new worker/gateway only after
leases/connections are drained. Restore previous network files through the
verified console; do not replay destructive install steps. A VM/storage restore
or customer migration is a separate explicitly approved operation.
