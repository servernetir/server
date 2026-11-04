# Native snapshot restore: researched workflow, not an installed cutover

Research date: 2026-11-02. No provider operation was performed by this research.
This is a proposed next implementation path; it does not certify the current
relay's cloud-init, disks, boot guard or application restore behavior.

## Decision

A native disk snapshot can replace most per-application package/file importers
when all persistent application state is on the source server's local disk.
It does **not** remove the need for an early boot guard, IP/configuration
rebinding, exact provider identity checks, external dependency cutover and
application acceptance tests. The snapshot is a complete OS clone, not a
ready-to-promote generic image.

## Verified provider contract

The official current OpenAPI document is
<https://docs.hetzner.cloud/cloud.spec.json>; reference UI:
<https://docs.hetzner.cloud/reference/cloud>. The public schema was fetched on
2026-11-02; SHA-256
`a01f19e86d04c57dfbae1c5eef739883b580d9031fd138690779349de426653b`.

| Operation | Verified behavior |
| --- | --- |
| `POST /servers/{id}/actions/shutdown` | Sends graceful ACPI. Action success means delivery, not successful guest shutdown; independently require `server.status=off`. |
| `POST /servers/{id}/actions/create_image` | Explicit `type=snapshot`, description and labels are supported. Capture returned image/action IDs before polling. |
| `POST /servers/{id}/actions/rebuild` | Overwrites the target disk and powers the server off before rebuilding. Request selects `image` and optional `user_data`. |
| rebuild `user_data` | At most 32 KiB. **Omission reuses the candidate's previous user_data.** Always provide the intended restore configuration explicitly. |
| `GET /images/{id}` | Check exact ID, snapshot type, available status, architecture, source ID, labels and disk size. `image_size` is billed stored GB; `disk_size` is contained disk GB. |
| `DELETE /images/{id}` | Supported for snapshot/backup images. Cleanup must target the exact journaled owned image ID. |

The [2026-01-16 changelog](https://docs.hetzner.cloud/changelog)
confirms rebuild user-data support. The same log recommends resource ownership
checks through `action.resources[]`; use global `GET /actions/{id}` for polling
rather than new dependencies on deprecated per-resource action lookup routes.

The [snapshot FAQ](https://docs.hetzner.com/cloud/servers/backups-snapshots/faq/)
confirms that snapshots survive deletion of their source, can be restored in
other locations and require matching CPU architecture. Attached Hetzner Volumes
are excluded. Source/candidate must use the same actual Hetzner project unless
an explicitly supported project transfer has separately completed. Docker named
volumes on the local root filesystem are different from attached cloud Volumes;
inventory actual mounts rather than judging by the word “volume”.

No explicit IP-retention or post-rebuild power-on guarantee appears in the
rebuild description. Same-server disk-rebuild semantics imply unchanged assigned
Primary IP resources, but implementation must verify the server ID, IPv4/IPv6
Primary IP IDs and addresses before/after. Observe actual power state and invoke
poweron only when required and authorized; do not guess its sequence.

Choose candidate disk capacity against the source disk / snapshot `disk_size`,
not compressed snapshot bytes or filesystem-used space. A smaller plan may be
incompatible even when almost no disk space is used. Source: provider-hosted
[image-building guide](https://community.hetzner.com/tutorials/custom-os-images-with-packer/),
supported by the [server FAQ's disk downgrade restriction](https://docs.hetzner.com/cloud/servers/faq/).
Retain x86 for an x86 snapshot; ARM is not an interchangeable cheaper target.

## Proposed state machine and hard barriers

1. **Prepare while the active relay is healthy.** Inventory mounts, app units,
   Docker storage, timers, hooks, hard-coded addresses, cloud-init version and
   all consumers. Install and exercise a generic generation guard before the
   snapshot workflow is enabled. The guard must gate Docker and every registered
   stateful service/timer until the exact restored instance is rebound. A unit
   being skipped by a condition is not proof that dependent services are blocked.
   Preserve a tested independent recovery checkpoint as a fallback.
2. **Trial candidate without source disruption.** Create the cheapest compatible
   eligible candidate, journal its server/Primary IP identities, and test the
   actual Proxmox/CT path through an isolated trial tunnel. Do not change live
   consumers or DNS merely because TCP/ping succeeded.
3. **Start a durable restore transaction.** Record source/candidate IDs, initial
   candidate IP IDs, expected source host-key fingerprints, desired bundle,
   source application checkpoint and a unique restore epoch. Reserve snapshot
   storage cost and confirm quota before stopping the source.
4. **Fence and take the final checkpoint.** Stop/quiesce registered writers using
   independently reachable control, request graceful shutdown, and repeatedly
   verify the exact source is off. The source must stay fenced until a deliberate
   rollback or final retirement. Create one final snapshot with explicit ownership
   labels; wait for its action and available status. A timeout is reconciled by
   exact IDs/labels/source identity, never followed by a blind second snapshot.
5. **Rebuild the same candidate under quarantine.** Keep a provider firewall / an
   already tested early boot guard in place before the cloned OS can start
   outbound timers or applications. Rebuild from the exact final snapshot ID with
   explicit bounded restore user-data. The initial candidate disk and its initial
   SSH keys/agent files are gone. Restore the independent control path first;
   then rebind network, identity and registered application address fields.
6. **Reset acceptance.** Invalidate pre-rebuild candidate health duration and all
   trial consumer receipts. Require fresh measurements bound to snapshot ID and
   restore epoch, not just the unchanged provider server ID/generation. A passing
   pre-rebuild network trial does not prove the restored OS or applications work.
7. **Stage and verify the full relay.** Start only the rebound generation. Check
   database integrity, exact users/settings/key continuity and functional tests
   for all registered relay/DNS/storage/GPU services. Check Proxmox, CT113 and the
   actual VM109 guest independently. A missing guest test cannot be inferred from
   the host route. Validate reads and writes through the intended new paths.
   Earn a new complete acceptance window after restore (policy proposal: 20 minutes).
8. **Cut over dependencies.** Apply registered Iran routes, relays and consumers,
   DNS-primary/TSIG peers, Cloudflare record plans, panel metadata and any actual
   registrar child-host/glue objects. Preserve proxy/TTL metadata. Verify API state
   and authoritative DNS; a proxied Cloudflare answer cannot prove origin identity,
   so require an end-to-end origin challenge too. Scan only authorized complete
   registries for remaining old-generation references.
   Commit the logical service/exit association atomically only after these checks.
9. **Observe and retire.** Earn a post-cutover observation window (proposal:
   1 hour) and known DNS TTL expiry. Delete only the old journaled resource after all retirement gates
   and a new durable checkpoint pass; reconcile actual absence and residual IPs.
   Keep the final snapshot until a replacement durable recovery checkpoint is
   verified, then remove only its explicitly owned image ID under retention policy.

These phases outlive a 30-second core intent. They need a durable restore journal,
fresh scoped sub-permits and read-only reconciliation for each ambiguous effect.
They must not be hidden inside one synchronous command or an unconditional
“all checks passed” recipe.

## Cloud-init and SSH identity policy

According to [cloud-init first-boot detection](https://docs.cloud-init.io/en/24.4/explanation/first_boot.html),
default cache checking compares current and cached instance IDs. A source clone
on a different candidate ID should be treated as a new instance. Disabled
cloud-init, `manual_cache_clean:true`, datasource failures and custom configuration
can invalidate that expectation; inspect and test the actual installed source.

An existing `/etc/machine-id` is not automatically made unique merely because the
cloud server ID changed. [cloud-init clean --machine-id](https://docs.cloud-init.io/en/24.4/reference/cli.html)
is the documented cloning preparation. Choose and test how this happens before
the restored system's normal first boot; do not improvise by changing production
identity during an incident. [systemd machine-id](https://www.freedesktop.org/software/systemd/man/latest/machine-id.html)
explains the distinction between an existing ID and an uninitialized image.

The [SSH module](https://docs.cloud-init.io/en/24.4/reference/modules.html#ssh)
normally removes host keys on first-instance configuration. If the intended policy
is continuity of the logical relay's known source host identity, use effective
`ssh_deletekeys:false` before the SSH module runs, retain the source's existing
keys, and compare the previously trusted **source** fingerprints after the verified
restore. Do not reuse the initial trial candidate's now-overwritten fingerprint
or trust a fresh unchecked ssh-keyscan. Keep source fenced to avoid simultaneous
copies of logical service credentials. Unique new host keys are also possible,
but require a separately authenticated pin transition; do not silently accept them.

[Hetzner's access FAQ](https://docs.hetzner.com/cloud/servers/faq/)
describes account SSH-key injection plus persistence of authorized_keys copied
inside official-image snapshots. Existing authorized operators are not removed
by rebuilding. Treat host keys, login authorization and application/SFTP keys as
separate policies.

Network regeneration depends on the actual snapshot configuration.
[Hetzner networking](https://docs.hetzner.com/cloud/servers/static-configuration/)
and [cloud-init boot stages](https://docs.cloud-init.io/en/24.4/explanation/boot.html)
do not promise correction of arbitrary netplan files, IP-bound app listeners,
allowlists or advertised addresses. Use documented `/hetzner/v1/*` metadata routes.
If normal bootstrap fails, [Rescue](https://docs.hetzner.com/cloud/servers/getting-started/rescue-system/)
can provide independent RAM-boot access for controlled offline repair, with its own
verified identity, journal and expiry handling.

## Real blockers and budget implications

- A corrupt/unreadable source disk, unavailable provider snapshot subsystem or
  failure to obtain a final consistent checkpoint cannot be solved by this path.
  Restore a previously tested independent checkpoint only under an explicit RPO
  policy; never label stale recovery as a zero-data-loss migration.
- State on remote filesystems, attached cloud Volumes or services outside the
  root disk needs separate protection. Cloud snapshots are not an independent
  provider-outage recovery strategy by themselves.
- A source image that has not passed a guarded clone/rebind drill is not a
  verified restore capability. Keep purchase/promotion automation closed until
  that drill is recorded.
- Snapshot storage has a separate recurring cost. Read actual pricing and reserve
  a conservative disk-sized bound before creation; replace it with billed
  `image_size` when available. A stopped old server still costs money. Retention,
  image quota, overlap VM-hours and dangling Primary IPs all need explicit caps.
- Full cloning removes most import code but cannot eliminate all-service tests,
  structured address rebinding or external DNS/consumer APIs.
