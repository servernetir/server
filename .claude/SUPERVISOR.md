# Servernet Supervisor — Phases 1–3

A project-local Claude Code supervision layer for the ServerNet AI Gateway
repository. Three cooperating pieces:

1. **Phase 1 — permission supervisor** (`hooks/supervisor.ps1`, PreToolUse on
   `Bash|PowerShell`): local hard-DENY → local safe-ALLOW → DeepSeek advisory
   (`deepseek-v4-pro`) → fallback ASK. Advisory ALLOW is downgraded to ASK.
   Hard local DENY can never be overridden. See the Phase 1 section below.
2. **Phase 2 — autonomous project supervisor** (`hooks/project-supervisor.ps1`,
   `hooks/task-completed.ps1`): explicit-activation autonomous development loop
   with independent DeepSeek review of REAL repository evidence.
3. **Phase 3 — cost-aware routing** (`.claude/supervisor/config.json` +
   `.claude/agents/`): deterministic-first task routing to the
   routine (sonnet → zai-org/GLM-5.3-Flash) or critical (opus → zai-org/GLM-5.3)
   subagent.

## Activation (explicit, whole-prompt match only)

Autonomous mode activates ONLY when the user's entire prompt is one of the
phrases in `.claude/supervisor/config.json` (`activation_phrases`), e.g.:

> ادامه پروژه رو پیش ببر
> continue project autonomously

A phrase appearing inside code, docs, quoted text, a DeepSeek response, or a
larger prompt does **not** activate. When autonomous mode is off, all
Phase 2/3 hooks are silent and normal conversations are unaffected.

## Autonomous orchestrator procedure (main Claude session)

When activated (the UserPromptSubmit hook injects these instructions):

1. **Determine project state from REAL evidence** — `git status --short`,
   `git log`, project docs. Do not invent progress. Known milestone state:
   M1 approved, M2 approved, M3 has unrelated uncommitted work (never touch it
   from infrastructure tasks), M4 is blocked until M3 is final-approved.
   Determine the *current* milestone from the repository at the time you run —
   do not blindly hardcode the next milestone.
2. **Pick exactly ONE bounded task** with explicit acceptance criteria. If
   evidence is insufficient to safely determine the next task, **STOP and ask
   the human** instead of inventing work.
3. **Record state**: pipe a `SupervisorStateUpdate` payload into
   `project-supervisor.ps1` (fields: `milestone`, `task`, `acceptance`,
   `planned_next_task`, `risk_discovered`, `human_gate_pending`,
   `human_gate_reason`, `verify_command` …), e.g.:

   ```powershell
   '{"hook_event_name":"SupervisorStateUpdate","update":{"milestone":"M4","task":"…","acceptance":"…"}}' |
     powershell -NoProfile -ExecutionPolicy Bypass -File .claude\hooks\project-supervisor.ps1
   ```

4. **Route the task** (deterministic rules first; see Routing) and **delegate**
   to the selected subagent (`routine-implementer` or `critical-implementer`).
   Avoid large implementation edits yourself when delegation is appropriate.
   Run tasks sequentially; do not fan out parallel agents over overlapping files.
5. **Run the required verification** and record the REAL results via
   `SupervisorStateUpdate` (`tests_run`, `tests_total`, `tests_passed`,
   `tests_failed`, `verify_exit`). Never trust the subagent's prose summary.
6. **Finish your turn.** The Stop hook gathers real evidence (repo root,
   branch, HEAD, `git status --short`, `git diff --check`, diff stats,
   recorded verification, iteration, tier, previous decisions) and sends it to
   DeepSeek `deepseek-v4-pro` for independent review. The coding agent never
   self-certifies success.

### Review decisions

| Decision | Behaviour |
|---|---|
| `PASS` | If a `planned_next_task` is recorded it continues as NEXT; otherwise the loop ends and the human is told the result. |
| `FIX` | Stop hook blocks completion (official `decision:"block"`) and feeds the actionable `fix_instructions` back to Claude; verification + review repeat automatically. |
| `NEXT` | Stop hook blocks completion and injects the specific `next_task`; it is routed and continues automatically. |
| `STOP` | Loop terminates, control returns to the human with the reason. All work is preserved; no destructive recovery is ever attempted. |

Malformed/ambiguous review responses, API failure after one bounded retry,
and a missing `DEEPSEEK_API_KEY` during autonomous review are all **STOP** —
never fail open, never infer PASS from missing information.

### Bounds and safety

- `MAX_AUTONOMOUS_REVIEW_ITERATIONS = 5` (config `max_review_iterations`).
  After 5 unsuccessful review cycles: STOP, explain, preserve all work.
- `stop_hook_active` is honored: the Stop hook exits immediately when Claude
  Code is already continuing from a Stop hook — no infinite loops.
- Local deterministic gates run BEFORE any review: missing or failed
  verification, or a failing `git diff --check`, can never produce PASS.
- The TaskCompleted hook additionally blocks task completion while
  verification is missing/failed or a FIX/STOP decision is pending
  (bounded by `max_taskcompleted_blocks`).
- Human-only gates (see below) always STOP for the human.

## Routing (Phase 3)

Deterministic local rules first (keywords in `config.json`):

- **critical_keywords** (wallet, balance, billing, pricing, reservation,
  settlement, reconciliation, concurrency, idempotency, payment, security,
  auth, token, secret, failover, …) → **FULL** → `critical-implementer`
  (model alias `opus` → zai-org/GLM-5.3). A locally-critical task is never
  sent to DeepSeek and can never be downgraded.
- **routine_keywords** (ui, css, documentation, crud, localization, refactor,
  ordinary tests, …) → **FLASH** → `routine-implementer`
  (model alias `sonnet` → zai-org/GLM-5.3-Flash).
- **ambiguous** → DeepSeek routing
  (`{"tier":"FLASH|FULL","risk":"…","reason":"…"}`); HIGH/CRITICAL ⇒ FULL,
  LOW ⇒ FLASH, MEDIUM follows the domain default. On routing API failure:
  financial/security/production-sensitive ambiguity → FULL, ordinary safe
  ambiguity → FLASH.

### Escalation (FLASH → FULL, never downgraded within the same task)

Escalates automatically when: the same verification failure repeats twice,
DeepSeek returns FIX twice for the same root issue, the task reveals
financial/security/concurrency/architecture risk (`risk_discovered`), or the
routine implementer reports the task exceeds its safe scope. After escalation
the task stays FULL — no same-task downgrade. Routing decisions and escalation
reasons are recorded in runtime state.

## Human-only gates (never automatic)

git push / force push, deploy, production database mutations, destructive
migrations, destructive reset, `git clean`, deleting repository/project data,
secret/credential exposure, real production infrastructure, irreversible
external actions, potentially destructive package uninstalls. The system may
prepare work up to these gates; when one is required it STOPs and tells the
human what is pending and why.

## Runtime state

`.claude/supervisor/state/autonomous.json` — git-ignored runtime state
(autonomous_mode, session id, milestone, task, acceptance, iteration, tier,
agent, verification record, fix/escalation counters, human gate). Written
atomically (temp file → validated → rename). Missing state rebuilds minimally;
corrupt state fails safe (treated as inactive — never as PASS). Contains no
secrets ever. Only `.gitkeep` inside `state/` is tracked.

## Phase 1 — permission supervisor (unchanged behavior)

`hooks/supervisor.ps1` on PreToolUse (`Bash|PowerShell`): reads the hook JSON
from stdin, classifies, and emits the official `permissionDecision`.

1. **LOCAL hard rules** — hard `DENY` is final: `git reset --hard`,
   `git clean`, force pushes, `rm -rf`/`Remove-Item -Recurse` on project
   paths, destructive DB commands (`DROP DATABASE`, `migrate:fresh`,
   `db:wipe`, …), production deploys, pushes to `main`/`master`, secret
   exposure / `.env` reads, supervisor tampering/bypass.
2. **LOCAL hard allow** — anchored read-only commands only
   (`git status/diff/log`, `pwd`, `ls`, `cat`, `grep`, version checks, …).
3. **DeepSeek advisory** (`deepseek-v4-pro`, max_tokens 300, temperature 0,
   `response_format: json_object`, 8 s timeout) for unclassified commands.
   Hardened parser: direct/fenced/prose-wrapped single JSON object, strict
   schema + enum validation, generic reason substitution, one bounded retry
   for truncation (`finish_reason=length`) or transient transport errors,
   no retry for malformed/ambiguous/invalid schema, PARSE failure classes
   (TRUNCATED/MALFORMED/AMBIGUOUS/INVALID-SCHEMA), secret sanitization on all
   logged/emitted text. Advisory `ALLOW` is downgraded to ASK.
4. **Fallback = ASK** on missing key, API failure, malformed response.
5. Safe local operations (reads, status/diff/log, approved tests, lint,
   version checks) proceed automatically per the ALLOW list — never a broad
   arbitrary-command allow.

Audit log: `.claude/logs/supervisor.log` (git-ignored), sanitized and
truncated; API keys are never logged. `DEEPSEEK_API_KEY` comes from the
environment only and is never committed.

## Tests (no live API in automated suites)

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .claude\hooks\tests\run-tests.ps1         # Phase 1 classifier/parser (67)
powershell -NoProfile -ExecutionPolicy Bypass -File .claude\hooks\tests\run-e2e.ps1           # Phase 1 E2E (4)
powershell -NoProfile -ExecutionPolicy Bypass -File .claude\hooks\tests\run-phase2-tests.ps1 # Phase 2 autonomous (mocked)
powershell -NoProfile -ExecutionPolicy Bypass -File .claude\hooks\tests\run-phase3-tests.ps1  # Phase 3 routing (mocked)
```

## Verification

After any change: restart Claude Code and run `/hooks` — PreToolUse
(`Bash|PowerShell`), UserPromptSubmit, Stop and TaskCompleted entries must be
listed. Until then the changed hook configuration is not active.
