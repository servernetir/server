# Araz chat rendering and uncertain-result indicator

Status: source candidate; **not installed or verified in production**.

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
- PHP CLI is absent in this executor. Native-launcher lint and compilation of
  the Blade view remain required on the authorized target before installation.

No real AI request or customer/provider call was made by these tests.

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

On 2026-11-09, the available browser showed the admin and cPanel login pages;
there was no authenticated production session. Today's worker/engine logs,
queue state, persisted results and upstream request failures have not been read.
Inspect those with minimal redacted output and correlate the original request
IDs before any runtime repair. Do not resend an uncertain command as a diagnostic.
