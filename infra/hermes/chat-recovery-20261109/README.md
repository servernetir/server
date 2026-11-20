# Araz chat rendering and uncertain-result indicator

Status: the one-view UI change is **installed and verified by hash and three public locale responses**. Engine response recovery and authenticated Bale interaction remain unverified. See the [production receipt](../../../docs/project/ARAZ-CHAT-RECOVERY-2026-11-09.md).

The reported miniapp screenshot shows several uncertain outcomes and a processing
indicator. The last verified view source in the repository is
`assistant-20261102/chat-polish/hermes.blade.php`; the active web application's
miniapp view is not tracked under `website/resources/views/bale`.

That source rebuilds every message on each three-second state read. All bubbles
therefore replay their entrance animation and the chat scrolls even when the
server returns identical state. It also uses pending and uncertain outcomes to
drive the same processing indicator. These are reproducible source defects;
they do not establish why today's model requests have uncertain outcomes.

The candidate keeps message nodes keyed by message ID and content, updates only
changed bubbles without replaying their animation, and scrolls only when the
message list changes. Uncertain results retain their warning and read-only
late-result polling, with the existing command guard, but do not claim active
processing. A state read begun before a submission cannot overwrite the newly
submitted message. Polling cannot start during submission. Authentication,
history, memory, command IDs and the prohibition on automatic replay remain.

## Validation

- `node infra/hermes/chat-recovery-20261109/test-ui.cjs`: 42 behavioral cases
  passed for fa/en/tr, including node identity, unchanged-poll scroll, status
  changes, late results, submission/state races and clearing cached history.
- Running those same tests with `ARAZ_VIEW` pointing to the baseline fails at
  `unchanged polls retain existing bubble`, reproducing the rendering defect.
- `python3 infra/hermes/chat-recovery-20261109/test-deploy.py`: seven disposable
  deployment guard tests passed, including preservation of concurrent edits.
- Chromium test was attempted but its process could not start in this executor:
  local socket creation returned `Operation not permitted`. Browser behavior and
  mobile appearance are **not verified**. `test-browser.cjs` is provided for a
  capable environment (`CHROMIUM_PATH` selects the installed executable).
- PHP CLI is absent in the executor. On the authorized production host, the
  native launcher and compiled candidate passed PHP lint, the candidate view
  compiled, seven deployment tests and 42 Node behavior cases passed, and the
  installed view was compiled and verified. The initial target-side Node run
  lacked its baseline fixture; the pinned fixture was staged and the rerun passed.

No real AI request or customer/provider call was made by the automated tests. A separate short engine canary was attempted through guest-exec; the management call timed out without a definitive result and was not replayed.

## Deployment and remaining diagnosis

The manifest contains exactly one target:
`servernet_app/resources/views/bale/hermes.blade.php`. The before-hash is the
documented chat-polish after-hash; it is a precondition, not a fresh observation.
Inspect the live source first. If it differs, reconcile the newer changes and
prepare a reviewed manifest; never overwrite or change the expected hash blindly.

Stage this directory and the pinned deployment core in the private source root
specified by `native-release.php`. Use the existing account's PHP CLI to lint
the launcher, then execute `prepare`, `apply`, `verify` under that launcher's
native locks. The core additionally holds shared file locks, saves a private
backup and checks source/target hashes. Compile only the affected Blade view
through the existing authorized mechanism and verify the mobile miniapp.
No cache flush, model reset, schema or service/network change is included.

For rollback, use the same launcher with `rollback`; the core refuses newer
target edits. Recompile the affected view. Do not restore job/history databases.

The connected Chrome session supplied an existing WHM terminal for installation
and Proxmox access for read-only runtime diagnosis. Three original requests
returned persisted failed_or_uncertain results. Engine logs contained a 405
GET/POST route error; current unauthenticated requests show no redirect.
The QEMU guest agent later stopped responding, the VM remains running, existing
SSH key authentication is denied, and noVNC remains Connecting. The short canary
has no definitive receipt. Restore authenticated in-guest access and reconcile
that canary before further model tests. Do not resend an uncertain command.
