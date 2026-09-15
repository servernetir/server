# Phase 3 routing tests — deterministic, mocked DeepSeek for ambiguous cases.
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

# --- mocked DeepSeek transport (routing ambiguity only) --------------------
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
    param([string]$Content)
    $payload = @{ choices = @( @{ message = @{ content = $Content }; finish_reason = 'stop' } ) }
    return ConvertFrom-Json -InputObject ($payload | ConvertTo-Json -Depth 5)
}
function New-Exception { param([string]$Message) return New-Object System.Exception -ArgumentList $Message }
function Use-MockResult { param($First, $Second)
    $script:MockCalls = 0
    $script:MockResponses.Clear()
    $script:MockResponses.Add($First)
    if ($null -ne $Second) { $script:MockResponses.Add($Second) }
}

$env:DEEPSEEK_API_KEY = 'test-only-dummy-key'
$cleanState = Get-DefaultState

function Get-Tier {
    param([string]$Task, [hashtable]$State = $null)
    if ($null -eq $State) { $State = $cleanState }
    return Invoke-Routing -Task $Task -State $State
}

Write-Output "=== deterministic routine -> FLASH ==="
$t = Get-Tier 'Update the admin dashboard button label and CSS'
Assert-True 'ordinary UI task -> FLASH' ($t.tier -eq 'FLASH' -and $t.agent -eq 'routine-implementer' -and $t.model_alias -eq 'sonnet' -and $t.effective_target -eq 'zai-org/GLM-5.3-Flash')
$t = Get-Tier 'Expand the README documentation section'
Assert-True 'documentation -> FLASH' ($t.tier -eq 'FLASH')
$t = Get-Tier 'Add routine CRUD endpoints for the tag entity'
Assert-True 'routine CRUD -> FLASH' ($t.tier -eq 'FLASH')

Write-Output "=== deterministic critical -> FULL ==="
$t = Get-Tier 'Fix wallet balance rounding'
Assert-True 'wallet task -> FULL' ($t.tier -eq 'FULL' -and $t.agent -eq 'critical-implementer' -and $t.model_alias -eq 'opus' -and $t.effective_target -eq 'zai-org/GLM-5.3')
$t = Get-Tier 'Correct the billing cycle date handling'
Assert-True 'billing task -> FULL' ($t.tier -eq 'FULL')
$t = Get-Tier 'Add reservation slot locking'
Assert-True 'reservation task -> FULL' ($t.tier -eq 'FULL')
$t = Get-Tier 'Fix settlement batch totals'
Assert-True 'settlement task -> FULL' ($t.tier -eq 'FULL')
$t = Get-Tier 'Implement the reconciliation report'
Assert-True 'reconciliation -> FULL' ($t.tier -eq 'FULL')
$t = Get-Tier 'Resolve race condition in concurrent checkouts'
Assert-True 'concurrency -> FULL' ($t.tier -eq 'FULL')
$t = Get-Tier 'Harden security headers'
Assert-True 'security -> FULL' ($t.tier -eq 'FULL')
$t = Get-Tier 'Fix auth token expiry validation'
Assert-True 'auth-sensitive task -> FULL' ($t.tier -eq 'FULL')
$t = Get-Tier 'Improve provider failover ordering'
Assert-True 'provider failover -> FULL' ($t.tier -eq 'FULL')
$t = Get-Tier 'Add a money-safe migration for the wallet table'
Assert-True 'money migration -> FULL' ($t.tier -eq 'FULL')

Write-Output "=== local critical cannot be downgraded ==="
Use-MockResult (New-MockResult '{"tier":"FLASH","risk":"LOW","reason":"looks simple"}')
$t = Get-Tier 'Adjust the wallet reservation flow label'
Assert-True 'local critical classification beats DeepSeek FLASH' ($t.tier -eq 'FULL' -and $script:MockCalls -eq 0)

Write-Output "=== ambiguous fallback when routing API unavailable ==="
Use-MockResult (New-Exception 'The remote server returned (500) Internal Server Error')
Use-MockResult (New-Exception 'The remote server returned (500) Internal Server Error')
$t = Get-Tier 'Improve handling of edge cases in the invoice flow'
Assert-True 'ambiguous financial + API fail -> FULL' ($t.tier -eq 'FULL' -and $t.source -eq 'FALLBACK')
$t = Get-Tier 'Adjust spacing on the landing page header'
Assert-True 'ordinary safe ambiguity + API fail -> FLASH' ($t.tier -eq 'FLASH' -and $t.source -eq 'FALLBACK')

Write-Output "=== DeepSeek-routed ambiguity ==="
Use-MockResult (New-MockResult '{"tier":"FULL","risk":"HIGH","reason":"touches dispute handling"}')
$t = Get-Tier 'Improve handling of disputed customer cases'
Assert-True 'DeepSeek HIGH risk -> FULL' ($t.tier -eq 'FULL' -and $t.source -eq 'DEEPSEEK')
Use-MockResult (New-MockResult '{"tier":"FLASH","risk":"LOW","reason":"cosmetic change"}')
$t = Get-Tier 'Polish the footer alignment details'
Assert-True 'DeepSeek LOW risk -> FLASH' ($t.tier -eq 'FLASH' -and $t.source -eq 'DEEPSEEK')

Write-Output "=== escalation ==="
# repeated same verification failure twice -> escalate (driven via Stop-hook gate)
$script:StateDir = Join-Path ([System.IO.Path]::GetTempPath()) ("sup3-{0:N}" -f [guid]::NewGuid())
New-Item -ItemType Directory -Path $script:StateDir -Force | Out-Null
$script:StateFile = Join-Path $script:StateDir 'autonomous.json'
$st = Get-DefaultState
$st.autonomous_mode = $true; $st.task = 'fix failing test'
$st.tests_run = $true; $st.tests_failed = 2
Set-AutonomousState -State $st
$in = ConvertFrom-Json -InputObject '{"stop_hook_active":false,"last_assistant_message":"done"}'
$null = Invoke-StopHook -InputData $in   # gate cycle 1
$null = Invoke-StopHook -InputData $in   # gate cycle 2 (same signature)
$st = Get-AutonomousState
Assert-True 'repeated same verification failure twice -> escalated FULL' ($st.escalated_full -eq $true -and $st.fix_cycles -ge 2)
$t = Invoke-Routing -Task 'Fix the failing validation in the contact form' -State $st
Assert-True 'escalated task routes FULL despite routine wording' ($t.tier -eq 'FULL')

Remove-Item -Recurse -Force $script:StateDir -Confirm:$false

# two DeepSeek FIX cycles for the same root issue -> escalate
$script:StateDir = Join-Path ([System.IO.Path]::GetTempPath()) ("sup3b-{0:N}" -f [guid]::NewGuid())
New-Item -ItemType Directory -Path $script:StateDir -Force | Out-Null
$script:StateFile = Join-Path $script:StateDir 'autonomous.json'
$st = Get-DefaultState
$st.autonomous_mode = $true; $st.task = 'parser edge case'
$st.tests_run = $true; $st.tests_failed = 0
Set-AutonomousState -State $st
$fixReview = New-MockResult '{"decision":"FIX","reason":"defect","fix_instructions":"Fix the off-by-one in the loop bound","next_task":"","risk":"MEDIUM"}'
Use-MockResult $fixReview
$null = Invoke-StopHook -InputData $in
Use-MockResult $fixReview
$null = Invoke-StopHook -InputData $in
$st = Get-AutonomousState
Assert-True 'two FIX cycles same root issue -> escalated FULL' ($st.escalated_full -eq $true)
Remove-Item -Recurse -Force $script:StateDir -Confirm:$false

# risk discovered mid-task
$st = Get-DefaultState
$st.risk_discovered = 'task now touches wallet reservation concurrency'
$t = Invoke-Routing -Task 'Adjust the display formatting' -State $st
Assert-True 'risk discovered mid-task -> FULL' ($t.tier -eq 'FULL')

# critical task never automatically downgrades in same task
$st = Get-DefaultState
$st.escalated_full = $true; $st.escalation_reason = 'repeated failures'
$t = Invoke-Routing -Task 'Rename the local variable in the docs helper' -State $st
$t2 = Invoke-Routing -Task 'Rename the local variable in the docs helper' -State $st
Assert-True 'no same-task downgrade (stays FULL)' ($t.tier -eq 'FULL' -and $t2.tier -eq 'FULL')

Write-Output "=== agent definitions and model mapping ==="
$agentsDir = Join-Path (Split-Path -Parent (Split-Path -Parent $PSScriptRoot)) 'agents'
$routine = Get-Content -Raw (Join-Path $agentsDir 'routine-implementer.md')
$critical = Get-Content -Raw (Join-Path $agentsDir 'critical-implementer.md')
Assert-True 'routine agent frontmatter -> sonnet' ($routine -match '(?m)^model:\s*sonnet\s*$' -and $routine -match '(?m)^name:\s*routine-implementer\s*$')
Assert-True 'critical agent frontmatter -> opus' ($critical -match '(?m)^model:\s*opus\s*$' -and $critical -match '(?m)^name:\s*critical-implementer\s*$')

$cfg = Get-SupervisorConfig
$flashTarget = [string]$cfg.model_routing.FLASH.effective_target
$fullTarget = [string]$cfg.model_routing.FULL.effective_target
Assert-True 'flash alias maps to GLM-5.3-Flash (config)' ($flashTarget -eq 'zai-org/GLM-5.3-Flash')
Assert-True 'full alias maps to GLM-5.3 (config)' ($fullTarget -eq 'zai-org/GLM-5.3')
$envModel = [string]$env:ANTHROPIC_MODEL
Assert-True 'session env confirms sonnet-alias effective target (or unset)' ($envModel -eq '' -or $envModel -match 'GLM-5.3')

Write-Output ""
Write-Output ('RESULT: {0} passed, {1} failed' -f $global:PassCount, $global:FailCount)
if ($global:FailCount -gt 0) { exit 1 } else { exit 0 }
