# Gateway migration to Turkey — 30 October 2026

The inventoried Germany gateway workloads and their infrastructure consumers
were moved to the retained Turkey gateway. Production cutover and the checks
below completed before source retirement. The owner subsequently reported that
the Germany server was deleted; provider billing/cancellation was not
independently verified in this follow-up.

This publication updates the existing relay and DNS defaults and records the
completed runtime work. Git publication does not run an installer, Ansible,
database migration or deployment. The publication branch starts from develop
`00542fa`; concurrent website, service-deletion, Hermes and SEO work is retained.
Operational inventory, recovery archives, credentials and detailed receipts
remain private and are not part of this repository.

## Completed runtime work

- Moved the eleven identified gateway DNS records while preserving their
  original TTLs, comments and proxy modes. Updated the website nameserver
  setting with a row lock, exact-value guard and targeted cache invalidation.
- Moved the identified Iran relay, VPN backhaul and secondary-DNS consumers.
  Existing SSH identities were verified and pinned; disabled legacy services
  stayed disabled. DNS primary/secondary serials matched after retrieval.
- Transferred consistent application databases, configuration and the existing
  VPN container image. Preserved credentials, TLS and SFTP identities, service
  ownership, firewall restrictions and authentication boundaries.
- Kept the direct VPN panel and the separate Iran tunnel panel distinct. Their
  protocols, ingress and users differ; consolidation needs a compatible account
  and node migration. Completed public client checks for direct TCP/UDP and
  tunneled WebSocket/XHTTP traffic confirmed Turkey egress.
- Applied the owner's requested administrator and owned-user removal only to
  the direct panel. Database integrity and protected unrelated state matched;
  the remaining credential connected and removed credentials were rejected.
  Older snapshots must not restore intentionally removed accounts.
- Repaired the existing Drive OAuth client configuration using the same client
  ID, accounts and scopes. Verified the official rclone release signature and
  archive checksum before installation. Preserved authentication, listeners,
  sandboxing and VFS data-cache mode while correcting directory visibility.
- Stopped and disabled the inventoried source application services and timers
  before retirement. The final pre-deletion check found no application
  listeners or non-maintenance SSH consumer. Removed the migration's temporary
  jobs with exact-command guards and preserved unrelated cron entries.
- Verified a current full recovery snapshot, all four database integrity
  checks, correct ownership and an independent private copy with matching size
  and checksum. The current snapshot includes the intentional account removals
  and supersedes the earlier migration snapshots.

## Public Proxmox route correction

The public admin hostname initially returned the GPU Worker's `Not found`
response. Its tunnel was ready and its private Proxmox origin answered over
strict, verified TLS. The wildcard Worker route lacked the existing
non-GPU-service exclusion required by the Worker instructions.

Added only `admin.servernet.cloud/*` with Worker **None** through the owner's
authorized Cloudflare dashboard. All five previous routes, Access policies,
DNS, tunnel configuration, Worker executable code and secrets were preserved.
The browser then displayed the native Proxmox login form. Cookie-free requests
to the public root and version API still redirected to Cloudflare Access; the
unauthenticated GPU canary still returned its expected authentication failure.
No API-token permission was expanded.

## Acceptance and remaining limits

Verified in production during the authorized migration:

- Normal public DNS and TLS for the tested gateway services; authenticated DNS
  API and HMAC health responses; authoritative DNS serial agreement.
- Direct and tunneled VPN client egress from Iran through Turkey, including
  direct UDP and both tunnel ingress protocols.
- Private Nextcloud WebDAV write, exact-byte read and delete.
- Writes through SFTP, S3 and WebDAV with exact-byte reads across the other
  protocol servers; existing SFTP identities remained unchanged.
- A removed disposable account was rejected by all three public storage
  protocols after 371 seconds of disuse. This does not establish immediate
  revocation of active transfers or continuously used password caches.
- Current recovery-copy integrity and removal of migration-owned temporary
  jobs while retaining unrelated jobs.

Native Proxmox sign-in and console WebSocket acceptance through the public
hostname, a real authenticated GPU application/WebSocket session, an Iran GPU
client and peak-load/reboot acceptance are not established by the checks above.
Additional tunnel-origin acceptance remains a follow-up. Old VPN profiles with
the retired numerical address must be refreshed from subscriptions or
reimported; DNS cannot rewrite a cached profile.

## Source validation and recovery

The four source files changing existing gateway defaults are the relay-role
defaults, gateway installer, Iran secondary connector and gateway precheck.
The Worker change records the live route exception in its header only.
Publication validation passed Bash syntax on the three scripts, YAML parsing
and relay-default/safe-apply assertions, Worker JavaScript syntax and
`git diff --check`. A full Ansible deployment was not run for this publication.

After source deletion, reverting DNS or defaults to Germany is not a recovery
plan. Recover onto an available authorized gateway using the current private
snapshot and reconcile subsequent writes and intentional deletions. Do not
restore an earlier account/database snapshot over current state.
The Git-only change can be reverted separately; that does not undo production
cutover. The admin Worker exception can be removed independently if needed,
preserving the previous routes and Access controls, but doing so restores the
known public admin routing failure.
