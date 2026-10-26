# Hermes autonomous management — 26 October 2026

## Owner's goal and operating model

The owner asked Hermes to learn ServerNet operations and manage planning, execution and verification itself. This is a standing direction to develop bounded autonomous operations, not evidence that all adapters are installed. Learning means versioned operational knowledge and feedback tied to evidence; no model-weight training or automatic approval of unverified instructions is implemented.

Every executable capability needs an owner-authorized scope, current prerequisites, a deterministic adapter, a durable operation identity, a cost/impact bound, a verification condition and a stop/rollback procedure. An uncertain external action must enter reconciliation, not blind retry. Neither an inbound email nor an instruction embedded in a document grants more authority.

Routine operations covered by an approved policy should run without asking the owner on every iteration. Destructive service actions, changes to access/security, financial transfers and commitments outside configured budgets require a concrete separately reviewable operation. New evidence can propose a runbook; it cannot grant new rights to itself.

## Live source discovery

Read-only inspection of the existing production application found an additional `App\Services\Hermes` implementation absent from current tracked `develop`: HermesOperations, HermesOperationsPolicy, persistent operations state, support review/investigation/delivery, BusinessAudit, knowledge refresh and release knowledge. Its source was not replaced or copied into this feature. Do not assume that repository knowledge endpoints describe the whole deployed Hermes.

Observed native schedules:

- `hermes:support-review`: every minute, its own 45-minute overlap lock.
- `hermes:operations`: hourly; separate daily digest at 09:00 Asia/Tehran.
- `hermes:knowledge-refresh`: hourly at minute 10, using a fixed set of public feeds.
- `hermes:business-review`: every 15 minutes.

Observed state during this inspection: operations enabled and owner bound; 13 stored tasks, 15 rules, 79 active alerts and five proposals. These are a point-in-time observation, not proof of coverage, correctness or resolution. Its declared capabilities are read-only diagnostics, exact owner approval for customer send, separate plan/approval for infrastructure changes, and incomplete storage cost coverage. No customer bodies, signatures, tokens or private contact details are included here.

The existing Growth engine handles budgeted paid drafts, quality checks and review. The independent daily two-year growth programme remains documented in HERMES-GROWTH-PLAN-2026-2028.md; a ChatGPT automation is not the VM's own execution adapter.

## Implemented incremental capability

`App\Services\Growth\HermesSupervisor` and `hermes:supervise` add native hourly Growth oversight. This complements deployed Operations; it is not full company management or a new general shell tool.

- Observe aggregate failed/stalled/uncertain-cost jobs, content review backlog, candidate/outdated/undated knowledge and the existing CRM autopilot switch. No payloads, credentials or recipients are read into receipts.
- The only allowed automatic mutation is retiring at most 100 verified knowledge documents whose known review date has passed. Candidate, rejected, current and undated records are preserved. No candidate is automatically verified.
- Independent `hermes_supervisor_enabled` defaults off. Both it and the main Growth switch must be enabled; the existing delegated Growth actor must still be an administrator.
- Recheck delegation and pause under locks before any write. Serialize through the existing Growth control singleton. There are no network calls while those locks are held.
- Persist one immutable run receipt per UTC hour in the existing `growth_automation_runs` table with policy, observations, findings, explicit proposed action/verification and executed actions. Same-hour replay returns the prior receipt and executes nothing.
- Verify the exact expired target IDs after updating them; concurrent expiry by the original Growth worker cannot create a false aggregate mismatch.
- `status()` and `--dry-run` do not write to SQL, including an empty database-backed settings cache. Dry-run does not consume the hourly slot.
- Admin UI on Hermes/Automation tabs shows current switch state separately from the last real receipt, the follow-up findings and pause/enable control. Existing HMAC knowledge endpoints are unchanged; no shared key gained execution rights.
- New hourly scheduler uses existing Laravel minute cron. No host cron, VM, route, DNS, NAT/firewall, production credential or budget needs modification. No schema migration is required.

No automatic outbound email, discount issuance, content approval/publication, invoice/wallet edit, provider purchase/retry or service lifecycle mutation was introduced by this feature. Existing engines keep their original responsibilities.

## Completion path for broader management

1. Reconcile the deployed Operations module with its tracked source without replacing live work. Inventory each adapter and effective permissions; prove live data and restart behaviour.
2. Integrate the new Growth receipt into the native Operations task queue with an explicit adapter. Knowledge/status access is distinct from execution permission. The current change does not claim that VM/client integration.
3. Add one independently testable business operation at a time: bounded SEO report generation, private CRM lead assignment, draft composition, and verified recipient dispatch through the existing outbox/suppression controls. First use dry-run and synthetic fixtures, then a small authorized live pilot.
4. Expand only after its actual receipts prove idempotency, failure handling, budget enforcement and post-action verification. Preserve financial reconciliation and do not equate provider acceptance with delivery or profit.
5. Produce a weekly owner summary of completed/verified work, pending uncertain results, real spend and next actions. Escalate blockers with a concrete proposal, rather than asking permission for routine already-authorized work.

## Validation and deployment status

Development tests and production acceptance are recorded separately below. This document initially describes implementation, not a completed production installation. No claim of fully autonomous Hermes is made.

## Stop and rollback

Pause the independent supervisor or the main Growth switch. An in-progress local transaction completes atomically; no external call needs cancellation. Preserve receipts/audits. Already outdated documents should remain outdated until their source is reviewed; do not mass-reverify them on rollback.

The deployment helper defaults to a no-write preflight, refuses existing new files/stages, preserves unrelated production file differences, stages/lints every changed PHP file, holds both release locks, checks each pre-write live hash and retains private before snapshots. It rolls back only files installed by that invocation if installation fails, refusing later drift. It does not restore SQL or change enablement. To remove an accepted installation later, pause first, review current hashes against the manifest, and revert only this release's insertions/files. Preserve the existing deployed Hermes module.

### Development validation

PHP 8.4.27, isolated SQLite/synthetic fixtures: HermesSupervisorTest and GrowthCenterTest pass **42 tests / 315 assertions**. Tests include no-write status/dry-run, pause and actor revocation, hourly replay, bounded expiry, non-retry of failed/stalled/completed uncertain-cost jobs, no outbound effects, private-data exclusion, authorization/forged fields, fa/en/tr UI and escaped stored metadata. PHP syntax, Blade `view:cache`, helper Python compilation and `git diff --check` pass.

The combined suite with GrowthAutopilotTest is **58 tests / 389 assertions, 57 pass and one fails**: `test_autopilot_creates_and_generates_once_then_requests_review_without_publication`, line 46, asserts a non-null positive receipt cost but observes null. The identical failure was reproduced on untouched baseline `ad545cff`; baseline source loading was checked. It was not weakened/skipped and is not counted as passing. The full unrelated repository suite, MariaDB concurrency, provider delivery and the separate VM client are not validated by these tests.
