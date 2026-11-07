# Arvan monitoring root cause and truthful alerts — 7 November 2026

## Runtime observations

One pilot is now enrolled through the account-private transport. The actual PHP
reader accepted two fresh samples, rejected unapproved query/path requests and
survived a temporary fixed-socket-path loss as unknown followed by observed
through the same inode. A separate disposable listening-socket restoration test
passed on the application host in an isolated QA directory on the same
filesystem. These prove path loss/recovery, not SSH process supervision or
remote network reconnection.

The real minute schedule established a prospective baseline and then
`two_fresh_samples_verified=true`. The accepted natural observation at
**10:43:12 UTC (14:13:12 Tehran)** removed that pilot from the watcher's affected
set. No historical usage, wallet debit, invoice or power action was generated.

Native DAC metadata enforces a0700 parent and0600 account-owned socket. An active
connection test from another UID was not performed; this evidence concerns
ordinary nonprivileged other UIDs, not root/capability holders. Supervised restart
acceptance remains open.

A new bounded read-only authenticated inventory clarified the other three
active machines. Two did not offer SSH password authentication, and one lacked a
trusted SSH host-key pin before any password attempt. These are access failures,
not evidence that an exporter is absent. No guest password, SSH policy or guest
authorization was changed, and no raw PTY or credential entered the repository.
Existing permitted SSH keys or authenticated guest-console access are needed
before those installations can be inspected/repaired.

## Reader and alert correction

The previous watch label combined missing central enrollment with an unproven
claim about agent installation. The reader also trusted a fresh per-target
status without validating current central transport, runner state or the real
scrape time. A fresh polling timestamp could therefore extend stale evidence,
and an old green row could outlive a disabled or replaced transport.

The corrected reader requires:

- Enabled transport approval matching actual local socket metadata.
- A current valid real observation-run record, including bounded coherent counts.
- Current enrollment and identity with a hash-bound checkpoint, read coherently
  using enrollment-first shared setting locks.
- Two accepted samples, actual scrape freshness, valid guest sample fields and
  scrape time at/after transport approval. Poll time cannot refresh old data.
- A final current-transport check before returning the observation snapshot.

Local transport checks perform no remote I/O. Setting locks are released before
the final local transport verification. This is monitoring evidence, not
authorization for money or power operations; the current fleet tuple is an
observation snapshot and must not become an enforcement decision.

Bale alerts distinguish unverified central transport/runner state from target
enrollment/data gaps. Missing enrollment now says that central registration is
unverified, without claiming the guest lacks Node Exporter. Stable causes remain
coalesced for30minutes; changed causes alert immediately. Recovery still needs
at least one active target, valid central state and fresh verified evidence for
every active target. Attempts are not delivery receipts.

## Validation and release boundary

The regression fixtures use synthetic database records and a short, disposable
native Unix-socket root. They cover disabled/missing/replaced/mode-changed sockets,
invalid/stale/future runner records, transport changes during inspection,
hash-bound history corruption, fresh checks masking stale/preapproval samples,
and central-failure coalescing without false recovery.

PR280 merged as `30d188078ae6ec037e16ca61d1d7c45bd516b7f4` after all six
applicable workflows succeeded, including the SQLite/MariaDB Arvan financial
regression matrix. Both CLI classes were installed separately from publication
using an independently reviewed installer, exact before/after bytes, private
backups, native PHP lint and same-directory atomic replacement. No global cache
flush, FPM restart, finance source or customer power action was required.

The native acceptance at **11:49:33 UTC (15:19:33 Tehran)** reported current
central transport and a still-observed pilot with two fresh samples. A read-only
check at **11:51:37 UTC (15:21:37 Tehran)** confirmed the natural watcher's
11:51:13 UTC attempt signature exactly matched the corrected reader's current
three-target incident. This is an attempt record, not proof of Bale delivery.
Private acceptance receipts and guarded backups remain on the original host.

Existing app-console access for a remaining target was attempted, without
reading a guest password or changing guest access. No guest session was
established. The provider panel requires an interactive owner login in the
selected browser; trusted guest access remains a real dependency.

Fleet-wide enrollment, unattended recovery, future delivery gating and supplier
reconciliation remain unfinished. Existing minimum settlement, wallet exposure
reserves, historical exclusions and financial checkpoints are unchanged.
Guest counters are not introduced as a second charging source.
