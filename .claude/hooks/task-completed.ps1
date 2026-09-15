# Servernet TaskCompleted gate — quality gate on task completion.
# Blocks completion while autonomous verification is missing/failed or a
# FIX/STOP decision is pending. Never pushes, deploys, resets or deletes.
# Defensive about TaskCompleted block support: emits block JSON and exits 2
# with the reason on stderr; if the installed Claude Code does not support
# blocking here, the block degrades to a non-blocking error and the Stop
# hook remains the final gate (fail-safe, never destructive).
$ErrorActionPreference = 'Stop'
$env:CLAUDE_SUPERVISOR_LIB = '1'   # dot-source the lib without its main loop
. (Join-Path (Split-Path -Parent $PSCommandPath) 'project-supervisor.ps1')

function Invoke-TaskCompletedHook {
    # Returns @{ action = allow|block ; reason }
    param([object]$InputData)

    $st = Get-AutonomousState
    if ((-not $st.autonomous_mode) -or $st.state_corrupt) {
        return @{ action = 'allow' }   # normal conversations: no interference
    }
    $cfg = Get-SupervisorConfig
    $maxBlocks = [int]$cfg.max_taskcompleted_blocks

    # Bound: never loop forever on this gate.
    if ([int]$st.taskcompleted_blocks -ge $maxBlocks) {
        return @{ action = 'allow' }
    }

    $block = $null
    if ($st.human_gate_pending) {
        $block = 'Human-only gate is pending; task cannot be marked complete automatically.'
    } elseif ($st.last_decision -eq 'STOP') {
        $block = 'Supervisor decision is STOP; task cannot be marked complete.'
    } elseif (-not $st.tests_run) {
        $block = 'Task acceptance verification was not run; run the required tests and record the results before completing.'
    } elseif ([int]$st.tests_failed -gt 0) {
        $block = ("Required verification failed ({0} failing test(s)); fix before completing." -f [int]$st.tests_failed)
    } else {
        $ws = [string](Invoke-WhitespaceCheck)
        if ($ws) { $block = 'git diff --check failed; resolve whitespace/conflict markers before completing.' }
    }

    if (-not $block) { return @{ action = 'allow' } }

    $st.taskcompleted_blocks = [int]$st.taskcompleted_blocks + 1
    Set-AutonomousState -State $st
    return @{ action = 'block'; reason = $block }
}

try {
    $raw = [Console]::In.ReadToEnd()
    $InputData = $raw | ConvertFrom-Json
    $r = Invoke-TaskCompletedHook -InputData $InputData
    if ($r.action -eq 'block') {
        $json = @{ hookSpecificOutput = @{ hookEventName = 'TaskCompleted'; decision = 'block'; reason = $r.reason } } |
            ConvertTo-Json -Depth 5 -Compress
        Write-Output $json
        [Console]::Error.WriteLine($r.reason)
        exit 2
    }
    exit 0
} catch {
    exit 0   # hooks must never break Claude Code
}
