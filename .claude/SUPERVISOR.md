# Servernet Supervisor (Phase 1 — safety/permission supervisor)

A project-local Claude Code **PreToolUse** hook that screens every `Bash` and
`PowerShell` tool call before it runs. Phase 1 is safety/permission only;
project-quality review is a later phase.

## Files

| Path | Purpose |
|---|---|
| `.claude/settings.json` | Registers the PreToolUse hook (matcher: `Bash\|PowerShell`) |
| `.claude/hooks/supervisor.ps1` | The supervisor: reads hook JSON from stdin, classifies, emits the official `permissionDecision` |
| `.claude/hooks/tests/run-tests.ps1` | Isolated classifier tests (no destructive command is ever executed) |
| `.claude/logs/supervisor.log` | Append-only audit log (git-ignored) |

## Decision pipeline

1. **LOCAL hard rules** — regex rules over the sanitized command string.
   Hard `DENY` rules are final: DeepSeek never sees them and can never override them.
2. **LOCAL hard allow** — an anchored list of clearly safe read-only commands
   (`git status/diff/log`, `pwd`, `ls`, `cat`, `grep`, version checks, `docker ps`, …).
   Deliberately narrow.
3. **DeepSeek (advisory only)** — for anything not decided locally,-if
   `DEEPSEEK_API_KEY` is set. Output must be strict JSON
   `{"decision":"ALLOW|DENY|ASK","reason":"…"}`. Advisory `ALLOW` is
   **downgraded to ASK**, so unknown commands always reach the human.
4. **Fallback = ASK** on missing key, network failure, timeout, or malformed response.
5. **ASK emits no output** — Claude Code's normal permission flow asks the human.

## Hard DENY (never overridable)

- `git reset --hard`, `git clean`, `git push --force / -f`
- `rm -rf` / `Remove-Item -Recurse` targeting project paths
- destructive DB commands (`DROP DATABASE`, `DROP TABLE`, unconditional `DELETE FROM`, `migrate:fresh`, `db:wipe`)
- production deploy commands and pushes to `main`/`master`
- commands printing/exposing secrets or reading `.env`
- any attempt to modify/disable the supervisor itself (`.claude/hooks`, `Set-ExecutionPolicy`, `chmod claude`, …)

## You will still be ASKed (normal permission flow) for

- ordinary `git push`, `git commit`/`amend`
- schema-modifying migrations
- file writes/edits
- Docker destructive commands, package uninstalls
- anything outside the working directory or anything unclassifiable

## Audit log

`.claude/logs/supervisor.log` records timestamp, tool, decision, source
(`LOCAL` / `DEEPSEEK` / `FALLBACK`) and a **sanitized, truncated** command
summary. Secret-like values are redacted; API keys/auth headers are never
logged. The `logs/` directory is git-ignored.

## Environment (never committed)

`DEEPSEEK_API_KEY` — optional; set it in your shell profile or Claude Code
user-level `env`, never inside this repository.

## Tests

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .claude\hooks\tests\run-tests.ps1
```

All cases exercise the classification logic only — no destructive command is ever executed.

## Verification

After any change: restart Claude Code and run `/hooks` — the PreToolUse
entry for `Bash|PowerShell` must be listed. Until then the supervisor is
**not** active.
