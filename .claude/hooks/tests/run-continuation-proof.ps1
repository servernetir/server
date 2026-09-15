# Autonomous continuation proof — integration test driving the REAL hook
# entry points as child processes with FULLY ISOLATED runtime state.
#
# Isolation guarantees:
# - all state goes to a per-run temp directory (CLAUDE_SUPERVISOR_STATE_DIR),
#   never to .claude/supervisor/state;
# - DEEPSEEK_API_KEY is saved process-locally and restored exactly in finally;
# - the temp proof directory is deleted even on failure;
# - the real repository is only read (git facts), never staged or modified.
#
# Encoding note: payloads are written as UTF-8 (no BOM) files and fed via cmd
# stdin redirection — piping Persian text through the PS 5.1 console pipeline
# corrupts it. The activation phrase is loaded from supervisor config.json
# (the canonical fixture), never inlined here.

$ErrorActionPreference = 'Continue'
$script:PassCount = 0
$script:FailCount = 0
function Assert-True {
    param([string]$Name, [bool]$Condition, [string]$Detail = '')
    if ($Condition) { $script:PassCount++; Write-Output ("PASS  {0}" -f $Name) }
    else { $script:FailCount++; Write-Output ("FAIL  {0}  {1}" -f $Name, $Detail) }
}

$hook   = Join-Path $PSScriptRoot '..\project-supervisor.ps1'
$config = Get-Content -Raw -Path (Join-Path $PSScriptRoot '..\..\supervisor\config.json') | ConvertFrom-Json
$persianPhrase = [string]$config.activation_phrases[0]   # ادامه پروژه رو پیش ببر

function ConvertTo-PayloadFile {
    param([string]$Path, [hashtable]$Data)
    $json = ConvertTo-Json -InputObject $Data -Depth 5 -Compress
    [System.IO.File]::WriteAllText($Path, $json, (New-Object System.Text.UTF8Encoding($false)))
}
function Invoke-HookChild {
    # Feeds a UTF-8 payload file via cmd stdin redirect; captures stdout to a
    # file (no PS 5.1 native-stderr wrapping).
    param([string]$PayloadPath, [string]$OutPath)
    cmd /c "powershell -NoProfile -ExecutionPolicy Bypass -File `"$hook`" < `"$PayloadPath`" 1> `"$OutPath`" 2> NUL"
    # Get-Content -Raw returns $null on an EMPTY file — guard before Trim().
    $raw = Get-Content -Raw -Path $OutPath
    if ($null -eq $raw) { return '' }
    return ([string]$raw).Trim()
}

$origKey       = [string]$env:DEEPSEEK_API_KEY
$origStateDir  = [string]$env:CLAUDE_SUPERVISOR_STATE_DIR
$proofRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("sup-proof-{0:N}" -f [guid]::NewGuid())
$proofDir  = Join-Path $proofRoot 'io'
$stateDir  = Join-Path $proofRoot 'state'
New-Item -ItemType Directory -Path $proofDir, $stateDir -Force | Out-Null

try {
    $env:CLAUDE_SUPERVISOR_STATE_DIR = $stateDir
    $stateFile = Join-Path $stateDir 'autonomous.json'

    $stopPath    = Join-Path $proofDir 'stop.json'
    $stopActive  = Join-Path $proofDir 'stop-active.json'
    $activate    = Join-Path $proofDir 'activate.json'
    $normal      = Join-Path $proofDir 'normal.json'
    $updTask     = Join-Path $proofDir 'upd-task.json'
    $updVerified = Join-Path $proofDir 'upd-verified.json'
    ConvertTo-PayloadFile $stopPath    @{ hook_event_name = 'Stop'; session_id = 'proof'; stop_hook_active = $false; last_assistant_message = 'I finished the task.' }
    ConvertTo-PayloadFile $stopActive  @{ hook_event_name = 'Stop'; session_id = 'proof'; stop_hook_active = $true;  last_assistant_message = 'done' }
    ConvertTo-PayloadFile $activate    @{ hook_event_name = 'UserPromptSubmit'; session_id = 'proof'; prompt = $persianPhrase }
    ConvertTo-PayloadFile $normal      @{ hook_event_name = 'UserPromptSubmit'; session_id = 'proof'; prompt = 'what does the router file do?' }
    ConvertTo-PayloadFile $updTask     @{ hook_event_name = 'SupervisorStateUpdate'; session_id = 'proof'; update = @{ milestone = 'MX'; task = 'proof task'; acceptance = 'tests pass' } }
    ConvertTo-PayloadFile $updVerified @{ hook_event_name = 'SupervisorStateUpdate'; session_id = 'proof'; update = @{ tests_run = $true; tests_total = 3; tests_passed = 3; tests_failed = 0; verify_exit = 0; verify_command = 'proof suite' } }
    $o1 = Join-Path $proofDir 'o1.txt'; $o2 = Join-Path $proofDir 'o2.txt'; $o3 = Join-Path $proofDir 'o3.txt'
    $o4 = Join-Path $proofDir 'o4.txt'; $o5 = Join-Path $proofDir 'o5.txt'; $o6 = Join-Path $proofDir 'o6.txt'
    $o4a = Join-Path $proofDir 'o4a.txt'; $o6a = Join-Path $proofDir 'o6a.txt'

    # 1) autonomous mode inactive -> Stop hook does nothing
    $out = Invoke-HookChild $stopPath $o1
    Assert-True 'inactive mode -> Stop hook: no output, no interference' ([string]::IsNullOrWhiteSpace($out)) $out

    # 2) Persian activation phrase -> activates + injects orchestration context
    $out = Invoke-HookChild $activate $o2
    $j = $null; try { $j = $out | ConvertFrom-Json } catch {}
    $ok = ($null -ne $j) -and ($j.hookSpecificOutput.hookEventName -eq 'UserPromptSubmit') -and
          ($j.hookSpecificOutput.additionalContext -match 'AUTONOMOUS SUPERVISOR MODE')
    Assert-True 'Persian activation -> official additionalContext output' $ok $out
    $st = $null; try { $st = Get-Content -Raw $stateFile | ConvertFrom-Json } catch {}
    Assert-True 'activation initializes isolated state' ($null -ne $st -and $st.autonomous_mode -eq $true -and $st.session_id -eq 'proof')

    # 3) normal prompt -> no output, state untouched (not hijacked)
    $out = Invoke-HookChild $normal $o3
    $st2 = $null; try { $st2 = Get-Content -Raw $stateFile | ConvertFrom-Json } catch {}
    Assert-True 'normal conversation unaffected' ([string]::IsNullOrWhiteSpace($out) -and $null -ne $st2 -and $st2.session_id -eq 'proof') $out

    # 4) active mode + unmet verification -> official FIX-style block continuation
    $null = Invoke-HookChild $updTask $o4a
    $out = Invoke-HookChild $stopPath $o4
    $j = $null; try { $j = $out | ConvertFrom-Json } catch {}
    $ok = ($null -ne $j) -and ($j.hookSpecificOutput.hookEventName -eq 'Stop') -and
          ($j.hookSpecificOutput.decision -eq 'block') -and
          ($j.hookSpecificOutput.reason -match 'verification was not run')
    Assert-True 'FIX-style gate -> official Stop decision:block with actionable reason' $ok $out
    $st = $null; try { $st = Get-Content -Raw $stateFile | ConvertFrom-Json } catch {}
    Assert-True 'block counted as a review iteration' ($null -ne $st -and [int]$st.iteration -ge 1)

    # 5) stop_hook_active -> official recursion guard: silent allow
    $out = Invoke-HookChild $stopActive $o5
    Assert-True 'stop_hook_active handled safely (no output, no loop)' ([string]::IsNullOrWhiteSpace($out)) $out

    # 6) missing DEEPSEEK_API_KEY during active review -> official STOP
    $env:DEEPSEEK_API_KEY = ''
    $null = Invoke-HookChild $updVerified $o6a
    $out = Invoke-HookChild $stopPath $o6
    $j = $null; try { $j = $out | ConvertFrom-Json } catch {}
    $ok = ($null -ne $j) -and ($j.systemMessage -match 'DEEPSEEK_API_KEY not set')
    $st = $null; try { $st = Get-Content -Raw $stateFile | ConvertFrom-Json } catch {}
    Assert-True 'missing key -> STOP output, autonomous mode ended' ($ok -and $null -ne $st -and $st.autonomous_mode -eq $false) $out
} finally {
    # exact restoration, even on failure
    if ([string]::IsNullOrEmpty($origKey)) { $env:DEEPSEEK_API_KEY = '' } else { $env:DEEPSEEK_API_KEY = $origKey }
    if ([string]::IsNullOrEmpty($origStateDir)) { $env:CLAUDE_SUPERVISOR_STATE_DIR = '' } else { $env:CLAUDE_SUPERVISOR_STATE_DIR = $origStateDir }
    [System.IO.Directory]::Delete($proofRoot, $true)
}

Write-Output ""
Write-Output ('RESULT: {0} passed, {1} failed' -f $script:PassCount, $script:FailCount)
if ($script:FailCount -gt 0) { exit 1 } else { exit 0 }
