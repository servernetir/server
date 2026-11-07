# Arvan private collector acceptance — 7 November 2026

## Confirmed runtime changes

The pilot guest's existing loopback-only exporter was repaired with an owned
systemd drop-in that adds the stat collector to its original netdev-only
configuration. The real guest boot time matches the kernel, network counters
and collector-success metrics are present, and the exporter still listens on
loopback only. No guest password, SSH authorization or firewall was changed.

The central collector now has a separate `servernet-arvan` scrape job with
explicit netdev/stat collection and the reconciled identity labels. Its legacy
job and existing private tunnel remain intact. The query-only proxy uses the
existing unprivileged collector account and listens on loopback only.

Native collector installation was accepted after the response-header and
PromQL fixes in PRs #273 and #274. A fresh read-only recheck at
**2026-11-07 08:31:20 UTC** confirmed:

- Ten raw-metric/timestamp rows from the actual proxy and live Prometheus.
- Five coherent scrape timestamps with approximately 9.54 seconds of age.
- Rejection of both an unapproved query and an unapproved path.
- The same running unprivileged proxy PID and the legacy target still up.
- No financial or lifecycle action.

Installed query-proxy SHA-256:
`23e7aa08dedd8618c3b63dd14535bacfd837604017a56bacb1589bb31fa2e35b`.

The application CLI query builder was installed separately with a before-hash
guard, private fsynced backup, native PHP lint, preserved ownership/group/mode
and same-directory atomic replacement. A fresh PHP process exercised its
canonical query through a fake unavailable transport and confirmed all nine
explicit union separators and the expected unavailable result. This is a
query-contract check, not a successful app-to-collector request.

Installed PHP query-builder SHA-256:
`a34aedb8497b7789351c2d22056a628563750e1442aec07962402c1caab6f8cc`.

## Restricted transport preparation

Dedicated nonroot forwarding accounts were installed on the jump host and
collector after independent review. The public key is constrained to one
source address and one destination at each hop. SSH permits local TCP
forwarding only; sessions, PTY, agent/X11/tunnel and remote/stream-local
forwarding are disabled. Alternate authorized-key commands and trusted user
CAs must be absent. Three existing root contexts produced unchanged effective
policies, sshd configuration validation passed, and SSH remained active after
reload. No root login credential or guest fleet authorization was added.

The first application connection failed closed at host-key verification.
Trusted native console fingerprints identified a transcription error in the
manually prepared public-key material. The jump key was corrected only after a
bounded key scan matched its independently obtained trusted fingerprint.
The collector public key was recovered as hexadecimal through the trusted
console and independently reconstructed to the same native fingerprint.
Applying that verified collector key and retrying the actual application
connection is still pending.

These installed SSH restrictions do not themselves prove PAM/public-key
authentication, a working forward or supervised operation.

## Still pending — do not report fleet coverage

At this checkpoint the cPanel session expired and displayed an invalid-session
cookie login page. Work requiring the application terminal awaits reauthentication
in the same selected Chrome browser.

The application Unix socket, real PHP-over-UDS acceptance, rejection from another
local UID, supervision, operator transport/enrollment descriptors and two fresh
registry samples have **not** been accepted. The observation runner has not been
enabled by this work. Other fleet guests still require current authenticated
access, enrollment and independent acceptance; a failed SSH attempt is not
evidence that an exporter is absent.

Delivery gating, monitoring-specific holds and supplier reconciliation remain
separate unfinished work. Existing minimum settlement, wallet exposure reserves,
financial checkpoints, historical exclusions and held supplier costs were not
modified by the collector/query work. Guest observations are not a second
financial debit source; see [the financial/coverage limits](ARVAN-TRAFFIC-MINIMUM-2026-11-05.md).

## Operational recovery

Private installation manifests, backups, native receipts and exact file
metadata remain on their respective hosts. Preserve these records. Inspect
current hashes and process state before retrying an interrupted operation;
successful installers must not be blindly rerun.

Restore only task-owned files whose current bytes still match the recorded
after version, and preserve newer concurrent changes. Keep existing root
sessions and the QGA recovery channel available while validating SSH access.
Do not weaken host-key verification, unlock a guest password, grant broad
forwarding, reset checkpoints or report missing telemetry as zero usage.
