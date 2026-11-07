# Arvan monitoring and Windows access — 2 November 2026

## Release scope and status

Base: `d757b2ebc3a7b64f57cafdaa5b61151669fbb994` (`develop`). This release prepares a guarded Linux exporter installer and corrects Windows console-first guidance/readiness. It does **not** implement complete automatic fleet enrollment, traffic charging, or monitoring suspension. Installation, central collection, billing reconciliation, and real Windows first sign-in remain open acceptance items. A Git merge is not a production installation receipt.

The owner explicitly requested monitoring on existing and future Arvan servers, including high-consumption accounts, and usable Windows credentials or a ServerNet console after purchase. No customer data, credentials, provider resource IDs, or private usage exports belong in this public release record.

## Evidence and limits

- Live admin health on 2 November showed a successful financial-meter receipt at 16:01:12 Tehran: 212 IRT in three invoices, nine held items and one unallocated item. This is saved receipt evidence, not independent verification of every wallet debit or complete traffic attribution.
- `finance:meter-arvan-traffic --apply` is scheduled every two hours. Its runtime meter/source/command implementations are absent from this Git snapshot. Recover and review the live source before changing settlement; do not substitute the reviewed one-off settlement command for the recurring meter.
- Current `ArvanClient::createServer()` has no monitoring enrollment/bootstrap hook. Provider status returns no cumulative billable traffic; `metrics()` is unavailable. Existing evidence establishes only a pilot exporter target, not fleet coverage.
- Provider support ticket `1E7-326F1777-008D`, reread on 2 November, recommends per-server monitoring for detailed usage. It does not establish a hard spending cap or resolve all historical aggregate attribution. A regional traffic package is not proof of automatic stopping after exhaustion.
- [Official Windows connection instructions](https://docs.arvancloud.ir/fa/cloud-server/instance/connection/) specify first access through console, selecting a password, checking/enabling Remote Desktop, then connecting as Administrator. Do not invent a returned password or use Linux reset for Windows.

## Code behavior

- Arvan Windows ready notices require running state, an IP, and a readable saved password or an enabled/eligible console. Console eligibility is configuration/identity evidence, not a live connection or RDP logon test.
- Missing-password customers with an eligible console see localized first-use steps and a CSRF-protected POST console launch. An unavailable console retains the incomplete-access warning. Existing password reveal behavior is preserved; passwords are not automatically shown.
- The Linux installer defaults to an inventory/plan. Applying requires an explicit plan digest, a second inventory under a lock, and authorized root access inside the matching guest. It refuses to overwrite unrelated exporters/configuration, uses a separate loopback-only service, and tests real receive/transmit counters. See `scripts/arvan-monitoring/README.md` for its exact supported scope.
- The installer does not create SSH access, open a firewall, register a central target, debit a wallet, or make customer telemetry authoritative for provider invoices. Windows exporter installation is not included in this Linux artifact.

## Remaining production work

1. Obtain permitted production app access, recover the live recurring meter/source, compare hashes and historical paid baselines, and inspect each held/unallocated reason. Preserve disputed/manual-debit exclusions until separately reconciled; begin new measured periods with a verified baseline.
2. Read the current central collector configuration and exact target identity/transport. Inventory all active/suspended Arvan instances against the provider and their actual OS. Use existing authorized guest access; do not rebuild, reset a customer's password, or install a new privileged fleet key silently.
3. Install the compatible Linux/Windows exporter in each guest, register its private target, verify multiple increasing samples at the collector, and record enrollment against the exact service/provider instance and installation generation. A running local exporter alone is not enrollment acceptance.
4. Add a validated provider-supported bootstrap/enrollment channel for creation, adoption and rebuild. Do not send guessed `user_data` or interpret a create timeout as permission to purchase another VM. New deliveries must wait for the required verified enrollment; an unconfigured policy must remain explicit in admin visibility.
5. Add a dedicated monitoring hold after repeated individual target failure while the central collector is healthy. Reuse confirmed provider power-off, block customer/automatic power-on bypass, and preserve staff/credit holds. Do not reuse hourly-credit markers, their 24-hour deletion workflow, or mass-suspend on a central outage. An off VM cannot repair its exporter: restoration needs a bounded maintenance boot path.
6. Use customer telemetry for early exposure checks, taking counter resets, interface replacement and restarts into account. Root users can alter guest telemetry. Reconcile the provider financial data with provisional exposure using unique periods/identities; never charge both independently or attribute tenant totals to one guessed owner. Validate billing direction, GB units, shared allowance and tariff before live deductions.
7. Test Windows console/OOBE/password choice and RDP with NLA intact on the same identified VM. A valid console setting or grant is insufficient. No new paid fixture was created in this release; any paid test needs a current all-component quote and a bounded cleanup plan within the owner's authorization.

## Validation and rollback

CI adds installer stub tests and the Windows onboarding/password/console/layout regression suites on isolated PHP 8.4 with SQLite and MariaDB. Record actual outcomes in the release/PR; locally available PHP 8.3 can lint but cannot execute the locked Laravel dependency set. No production test may run the scheduler, financial meter, or provisioning queue as a diagnostic shortcut.

Deploy only the model, console broker, account view and three changed language files after live hash comparison, private backups and reconciliation of concurrent changes. Install translations and the broker helper before the model and view; reverse this order on a guarded rollback to avoid a missing helper during partial activation. The installer stays outside the web release. Restore only this release's exact previous files if no newer change exists; do not roll back the entire application or financial data. Guest removal/rollback requires the installer's exact managed-file inventory and must preserve unrelated monitoring.

## Pilot acceptance — 7 November 2026

One existing Linux guest was inspected through its pre-existing pinned root
access. The authenticated inventory found an active legacy exporter on loopback,
not an absent exporter. Its effective command disabled default collectors and
enabled only netdev. A separate existing collector tunnel was active.

The pilot exporter was repaired using a new owned systemd drop-in that adds
`--collector.stat` to its exact verified command. The base unit and pinned
binary were preserved. Only the exporter was restarted. Current service/owner,
provider reference/IP uniqueness, known-host pins, canonical root-owned
executable ancestry and exact pre-change unit/binary hashes were checked before
mutation. No password reset, guest SSH key, firewall or public listener was
introduced.

The private receipt `stat-repair-v2-20261107.json` records nested repair status
`verified`, with boot, network and loopback checks all true. Actual
`node_boot_time_seconds` matched `/proc/stat` btime, selected-interface RX/TX
were present, netdev/stat collectors succeeded, and the sole exporter listener
remained IPv4 loopback. A preceding attempt refused before creating a drop-in;
it must not be described as an installation. The repair's guarded rollback was
reviewed but was not exercised on the live guest because acceptance succeeded.

Local verification included complete assembled remote compilation, actual
systemd payload newlines, representative effective-command parsing and metric
fixtures. Wrong boot identity, duplicate metrics, incomplete result schema and
out-of-scope guests were rejected. Host-side PHP lint and full assembled remote
compilation passed before execution. Independent review covered executable
ancestry, bounded commands, rollback identity/configuration checks and the
requirement to accept the nested result rather than the outer SSH exit code.

### Central collection is still incomplete

A fresh native collector check confirmed the old tunnel and Prometheus were
active. The tunnel exposes the new boot metric. However, the effective legacy
scrape URL explicitly requests only `collect[]=netdev`; its boot-time query
therefore still had no series. Legacy job labels also differ from the strict
application observation contract. Neither an exporter process nor this tunnel
is accepted application enrollment.

A separate identity-bound job, query-only proxy and supervised account-private
Unix forward were prepared for review, **not installed**. The collector
installer still needs complete tracked-artifact rollback and a guarded path for
native acceptance failure. Do not execute that intermediate draft. An
application-private forward key and prospective enrollment identity were
created, but no public key was authorized on the collector/jump host and no
new access, transport approval or enrollment was activated.

The connected browser became unavailable before further collector work. Before
resuming, obtain a fresh native inventory, preserve the working legacy
exporter/tunnel, inspect the private staged artifacts, and reconcile current
application ownership again. Do not rerun the successful repair as a blind
retry or erase generation history.

The other fleet guests still require authenticated inventory/installation or
repair. New-delivery enrollment, missing-monitoring lifecycle enforcement and
historical financial reconciliation remain open. Guest telemetry is still
nonbillable operational evidence; supplier settlement and the historical owner
exclusion remain separate. This pilot does not establish fleet coverage or a
hard loss cap.
