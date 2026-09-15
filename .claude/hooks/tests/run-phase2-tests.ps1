# Phase 2 autonomous-supervisor tests — deterministic, mocked DeepSeek.
# Read-only with respect to the repository: real git facts are READ, never
# staged/committed/modified by the tested code paths.
$ErrorActionPreference = 'Stop'
$env:CLAUDE_SUPERVISOR_LIB = '1'
. (Join-Path $PSScriptRoot '..\project-supervisor.ps1')

$global:PassCount = 0
$global:FailCount = 0
function Assert-True {
    param([string]$Name, [bool]$Condition, [string]$Detail = '')
    if ($Condition) { $script:PassCount++; Write-Output ("PASS  {0}" -f $Name) }
    else { $script:FailCount++; Write-Output ("FAIL  {0}  {1}" -f $Name, $Detail) }
}

# --- temp runtime state (never the repo's real state) --------------------
$script:StateDir = Join-Path ([System.IO.Path]::GetTempPath()) ("sup2-{0:N}" -f [guid]::NewGuid())
New-Item -ItemType Directory -Path $script:StateDir -Force | Out-Null
$script:StateFile = Join-Path $script:StateDir 'autonomous.json'

# --- mocked DeepSeek transport --------------------------------------------
$script:MockCalls = 0
$script:MockResponses = New-Object System.Collections.Generic.List[object]
function Mock-Invoke-DeepSeekHttp {
    param([string]$Body, [string]$Key)
    $script:MockCalls++
    $next = $script:MockResponses[0]
    $script:MockResponses.RemoveAt(0)
    if ($next -is [System.Exception]) { throw $next }
    return $next
}
function Invoke-DeepSeekHttp {
    param([string]$Body, [string]$Key)
    return Mock-Invoke-DeepSeekHttp -Body $Body -Key $Key
}
function New-MockResult {
    param([string]$Content, [string]$Finish = 'stop')
    $payload = @{ choices = @( @{ message = @{ content = $Content }; finish_reason = $Finish } ) }
    return ConvertFrom-Json -InputObject ($payload | ConvertTo-Json -Depth 5)
}
function New-Exception { param([string]$Message) return New-Object System.Exception -ArgumentList $Message }
function Use-MockResult { param($First, $Second)
    $script:MockCalls = 0
    $script:MockResponses.Clear()
    $script:MockResponses.Add($First)
    if ($null -ne $Second) { $script:MockResponses.Add($Second) }
}

$reviewPass = New-MockResult '{"decision":"PASS","reason":"acceptance criteria met","fix_instructions":"","next_task":"","risk":"LOW"}'
$reviewFix  = New-MockResult '{"decision":"FIX","reason":"defect found","fix_instructions":"Fix the failing assertion in FooTest","next_task":"","risk":"MEDIUM"}'
$reviewNext = New-MockResult '{"decision":"NEXT","reason":"task accepted","fix_instructions":"","next_task":"Implement the coupon expiry job","risk":"LOW"}'
$reviewStop = New-MockResult '{"decision":"STOP","reason":"human gate required","fix_instructions":"","next_task":"","risk":"HIGH"}'

function New-Input {
    param([bool]$StopActive = $false, [string]$LastMessage = 'I finished the task.')
    return ConvertFrom-Json -InputObject (@{ stop_hook_active = $StopActive; last_assistant_message = $LastMessage } | ConvertTo-Json)
}
function New-ActiveState {
    param([hashtable]$Overrides = @{})
    $st = Get-DefaultState
    $st.autonomous_mode = $true
    $st.session_id = 'test-session'
    $st.milestone = 'M4'
    $st.task = 'bounded test task'
    $st.acceptance = 'tests pass'
    $st.tier = 'FLASH'; $st.agent = 'routine-implementer'
    $st.tests_run = $true; $st.tests_total = 10; $st.tests_passed = 10; $st.tests_failed = 0; $st.verify_exit = 0
    $st.verify_command = 'php artisan tests'
    foreach ($k in $Overrides.Keys) { $st[$k] = $Overrides[$k] }
    Set-AutonomousState -State $st
    return $st
}

$env:DEEPSEEK_API_KEY = 'test-only-dummy-key'

Write-Output "=== activation ==="
Assert-True 'inactive -> Stop hook does nothing' ((Invoke-StopHook -InputData (New-Input)).action -eq 'allow' -and $script:MockCalls -eq 0)
Assert-True 'Persian activation phrase activates' (Test-IsActivationPrompt 'ادامه پروژه رو پیش ببر')
Assert-True 'English activation activates' (Test-IsActivationPrompt 'continue project autonomously')
Assert-True 'other activation phrases activate' ((Test-IsActivationPrompt 'supervisor mode') -and (Test-IsActivationPrompt 'continue milestone'))
Assert-True 'quoted phrase inside prompt does NOT activate' (-not (Test-IsActivationPrompt 'echo "continue project autonomously"'))
Assert-True 'phrase inside code does NOT activate' (-not (Test-IsActivationPrompt 'git commit -m "continue project autonomously"'))

$upIn = ConvertFrom-Json -InputObject '{"prompt":"ادامه پروژه رو پیش ببر","session_id":"sess-1"}'
$up = Invoke-UserPromptSubmit -InputData $upIn
$stAfter = Get-AutonomousState
Assert-True 'UserPromptSubmit injects orchestration context' ($null -ne $up -and $up.hookSpecificOutput.additionalContext -match 'AUTONOMOUS SUPERVISOR MODE' -and $up.hookSpecificOutput.hookEventName -eq 'UserPromptSubmit')
Assert-True 'activation initializes state' ($stAfter.autonomous_mode -eq $true -and $stAfter.session_id -eq 'sess-1' -and (Test-Path $script:StateFile))
$normalUp = Invoke-UserPromptSubmit -InputData (ConvertFrom-Json -InputObject '{"prompt":"explain the router file","session_id":"sess-2"}')
Assert-True 'normal conversation unaffected by UserPromptSubmit' ($null -eq $normalUp -and $stAfter.session_id -ne 'sess-2')

Write-Output "=== review decisions ==="
Remove-Item -Force $script:StateFile -ErrorAction SilentlyContinue
$null = New-ActiveState
Use-MockResult $reviewPass
$r = Invoke-StopHook -InputData (New-Input)
Assert-True 'PASS allows completion' ($r.action -eq 'stop-success' -and $script:MockCalls -eq 1 -and (-not (Get-AutonomousState).autonomous_mode))

$null = New-ActiveState
Use-MockResult $reviewFix
$r = Invoke-StopHook -InputData (New-Input)
$st = Get-AutonomousState
Assert-True 'FIX blocks and feeds instructions back' ($r.action -eq 'block' -and $r.reason -match 'FooTest' -and $st.iteration -eq 1 -and $st.last_decision -eq 'FIX')

$null = New-ActiveState
Use-MockResult $reviewNext
$r = Invoke-StopHook -InputData (New-Input)
$st = Get-AutonomousState
Assert-True 'NEXT blocks and injects next task' ($r.action -eq 'block' -and $r.reason -match 'coupon expiry job' -and $st.task -eq 'Implement the coupon expiry job' -and $st.iteration -eq 0)

$null = New-ActiveState
Use-MockResult $reviewStop
$r = Invoke-StopHook -InputData (New-Input)
Assert-True 'STOP returns control to human' ($r.action -eq 'stop' -and $r.reason -match 'human gate' -and (-not (Get-AutonomousState).autonomous_mode))

Write-Output "=== fail-safe review outcomes ==="
$null = New-ActiveState
Use-MockResult (New-MockResult 'I am afraid I cannot produce JSON right now.')
$r = Invoke-StopHook -InputData (New-Input)
Assert-True 'malformed review -> STOP' ($r.action -eq 'stop' -and (-not (Get-AutonomousState).autonomous_mode))

$null = New-ActiveState
Use-MockResult (New-MockResult '{"decision":"PASS","reason":"a"} {"decision":"STOP","reason":"b"}')
$r = Invoke-StopHook -InputData (New-Input)
Assert-True 'ambiguous review -> STOP (never PASS)' ($r.action -eq 'stop' -and $r.reason -match 'ambiguous')

$null = New-ActiveState
Use-MockResult (New-Exception 'The remote server returned (401) Unauthorized')
$r = Invoke-StopHook -InputData (New-Input)
Assert-True 'API failure -> STOP' ($r.action -eq 'stop' -and $r.reason -match 'FALLBACK')

$null = New-ActiveState
$env:DEEPSEEK_API_KEY = ''
Use-MockResult $reviewPass
$r = Invoke-StopHook -InputData (New-Input)
Assert-True 'missing key -> STOP, no call' ($r.action -eq 'stop' -and $script:MockCalls -eq 0 -and $r.reason -match 'DEEPSEEK_API_KEY')
$env:DEEPSEEK_API_KEY = 'test-only-dummy-key'

Write-Output "=== bounds and recursion ==="
$null = New-ActiveState @{ iteration = 5 }
Use-MockResult $reviewFix
$r = Invoke-StopHook -InputData (New-Input)
Assert-True 'iteration bound stops at 5' ($r.action -eq 'stop' -and $r.reason -match 'iteration bound' -and (-not (Get-AutonomousState).autonomous_mode))

$null = New-ActiveState
Use-MockResult $reviewFix
$r = Invoke-StopHook -InputData (New-Input -StopActive $true)
Assert-True 'stop_hook_active handled safely' ($r.action -eq 'allow' -and $script:MockCalls -eq 0)

Write-Output "=== deterministic local gates (never PASS) ==="
$null = New-ActiveState @{ tests_run = $false }
Use-MockResult $reviewPass
$r = Invoke-StopHook -InputData (New-Input)
Assert-True 'missing tests cannot produce PASS' ($r.action -eq 'block' -and $script:MockCalls -eq 0)

$null = New-ActiveState @{ tests_failed = 3; tests_passed = 7 }
Use-MockResult $reviewPass
$r = Invoke-StopHook -InputData (New-Input)
Assert-True 'failed tests cannot produce PASS' ($r.action -eq 'block' -and $script:MockCalls -eq 0 -and $r.reason -match 'failing test')

function Invoke-WhitespaceCheck { return 'whitespace-error.txt: trailing whitespace.' }
$null = New-ActiveState
Use-MockResult $reviewPass
$r = Invoke-StopHook -InputData (New-Input)
Assert-True 'git diff --check failure cannot produce PASS' ($r.action -eq 'block' -and $script:MockCalls -eq 0)

Write-Output "=== human gate ==="
$null = New-ActiveState @{ human_gate_pending = $true; human_gate_reason = 'git push required' }
Use-MockResult $reviewPass
$r = Invoke-StopHook -InputData (New-Input)
Assert-True 'human-only gate causes STOP' ($r.action -eq 'stop' -and $r.reason -match 'git push required')

Write-Output "=== evidence ==="
$null = New-ActiveState
$ev = New-Evidence -State (Get-AutonomousState) -LastAssistantMessage 'All tests pass, trust me.'
$realHead = [string](git -C $script:ProjectDir rev-parse HEAD)
$realBranch = [string](git -C $script:ProjectDir rev-parse --abbrev-ref HEAD)
Assert-True 'evidence uses real git facts' ($ev.head -eq $realHead -and $ev.branch -eq $realBranch -and $ev.repo_root -eq $script:ProjectDir)
Assert-True 'evidence separates untrusted assistant claim' ($ev.assistant_claim -match 'trust me' -and $ev.verification.run -eq $true -and $ev.verification.failed -eq 0)

$null = New-ActiveState @{ tests_run = $false }
$ev = New-Evidence -State (Get-AutonomousState) -LastAssistantMessage 'Everything is verified and passing.'
Assert-True 'evidence does not trust assistant claims' ($ev.verification.run -eq $false -and $ev.assistant_claim -match 'verified')

$ev = New-Evidence -State (Get-AutonomousState) -LastAssistantMessage 'used key sk-live-abcdef123456 to test'
Assert-True 'secrets redacted from evidence' ($ev.assistant_claim -match '<redacted>' -and $ev.assistant_claim -notmatch 'sk-live')

Write-Output "=== state safety ==="
[System.IO.File]::WriteAllText($script:StateFile, '{"autonomous_mode": tru')
$st = Get-AutonomousState
Assert-True 'corrupt state fails safe' ($st.state_corrupt -eq $true -and $st.autonomous_mode -eq $false)
$r = Invoke-StopHook -InputData (New-Input)
Assert-True 'corrupt state never interferes or PASSes' ($r.action -eq 'allow' -and $script:MockCalls -eq 0)

Remove-Item -Force $script:StateFile -ErrorAction SilentlyContinue
$st = Get-AutonomousState
Assert-True 'missing state rebuilds safely' ($st.autonomous_mode -eq $false -and $st.iteration -eq 0)

$st = New-ActiveState
$readBack = Get-AutonomousState
$tmpLeft = @(Get-ChildItem -Path $script:StateDir -Filter '*.tmp' -ErrorAction SilentlyContinue)
Assert-True 'atomic state update succeeds' ($readBack.task -eq 'bounded test task' -and $tmpLeft.Count -eq 0)

Write-Output "=== repository protection ==="
$stagedNow = [string]((git -C $script:ProjectDir diff --cached --name-only) -join ' ')
$porcelain = [string]((git -C $script:ProjectDir status --porcelain) -join ' | ')
Assert-True 'dirty unrelated files preserved (nothing staged)' ($stagedNow -eq '' -and $porcelain -match 'M website/app/Console/Commands/AiReservationStress.php')
Assert-True 'M3 files never automatically staged' (($stagedNow -notmatch 'AiReservationStress') -and ($stagedNow -notmatch 'AiReservations.php') -and ($stagedNow -notmatch 'm3-verify'))

$statePath = Join-Path $script:ProjectDir '.claude\supervisor\state\autonomous.json'
git -C $script:ProjectDir check-ignore -q $statePath
Assert-True 'runtime state is ignored by git' ($LASTEXITCODE -eq 0)

Remove-Item -Recurse -Force $script:StateDir -Confirm:$false
Write-Output ""
Write-Output ('RESULT: {0} passed, {1} failed' -f $global:PassCount, $global:FailCount)
if ($global:FailCount -gt 0) { exit 1 } else { exit 0 }
