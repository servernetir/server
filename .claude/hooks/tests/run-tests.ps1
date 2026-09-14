# Servernet Supervisor test suite (read-only: classification logic only).
# Uses Windows PowerShell 5.1-compatible syntax.
$ErrorActionPreference = 'Stop'
$env:CLAUDE_SUPERVISOR_TEST = '1'
. (Join-Path $PSScriptRoot '..\supervisor.ps1')

$global:PassCount = 0
$global:FailCount = 0

function Assert-Classify {
    param([string]$Name, [string]$Expected, [string]$Command)
    $r = Get-SupervisorDecision -Command $Command
    $got = $r.Decision
    if ($got -eq $Expected) {
        $script:PassCount++
        Write-Output ("PASS  {0,-30} -> {1}" -f $Name, $got)
    } else {
        $script:FailCount++
        Write-Output ("FAIL  {0,-30} expected={1} got={2} reason={3}" -f $Name, $Expected, $got, $r.Reason)
    }
}

function Assert-True {
    param([string]$Name, [bool]$Condition, [string]$Detail = '')
    if ($Condition) {
        $script:PassCount++
        Write-Output ("PASS  {0}" -f $Name)
    } else {
        $script:FailCount++
        Write-Output ("FAIL  {0}  {1}" -f $Name, $Detail)
    }
}

Write-Output "=== ALLOW (safe read-only) ==="
Assert-Classify 'pwd'             'ALLOW' 'pwd'
Assert-Classify 'git status'      'ALLOW' 'git status'
Assert-Classify 'git status -sb'  'ALLOW' 'git status -sb'
Assert-Classify 'git diff'        'ALLOW' 'git diff'
Assert-Classify 'git diff HEAD'   'ALLOW' 'git diff HEAD'
Assert-Classify 'git log -5'      'ALLOW' 'git log --oneline -5'
Assert-Classify 'ls'              'ALLOW' 'ls'
Assert-Classify 'cat file'        'ALLOW' 'cat website/README.md'
Assert-Classify 'grep'            'ALLOW' 'grep -r "admin" website/app'
Assert-Classify 'php version'     'ALLOW' 'php -v'
Assert-Classify 'docker ps'       'ALLOW' 'docker ps'
Assert-Classify 'git rev-parse'   'ALLOW' 'git rev-parse --short HEAD'
Assert-Classify 'worktree list'   'ALLOW' 'git worktree list'

Write-Output ""
Write-Output "=== DENY (hard local rules) ==="
Assert-Classify 'git reset --hard'        'DENY' 'git reset --hard HEAD~1'
Assert-Classify 'git clean'               'DENY' 'git clean -fd'
Assert-Classify 'git push --force'        'DENY' 'git push --force origin feature/ai-gateway'
Assert-Classify 'git push -f'             'DENY' 'git push -f origin feature/ai-gateway'
Assert-Classify 'rm -rf project'          'DENY' 'rm -rf website'
Assert-Classify 'rm -rf absolute'         'DENY' 'rm -rf D:/Project/Servernet/website'
Assert-Classify 'Remove-Item -Recurse'    'DENY' 'Remove-Item -Recurse -Force .claude'
Assert-Classify 'drop database'           'DENY' 'mysql -e "DROP DATABASE servernet"'
Assert-Classify 'migrate:fresh'           'DENY' 'php artisan migrate:fresh --force'
Assert-Classify 'db wipe'                 'DENY' 'php artisan db:wipe'
Assert-Classify 'deploy'                  'DENY' 'dep deploy production'
Assert-Classify 'push to main'            'DENY' 'git push origin main'
Assert-Classify 'cat .env'                'DENY' 'cat website/.env'
Assert-Classify 'printenv secrets'        'DENY' 'printenv'
Assert-Classify 'tamper supervisor'       'DENY' 'rm .claude/hooks/supervisor.ps1'
Assert-Classify 'disable supervisor'      'DENY' 'set-executionpolicy bypass'
Assert-Classify 'API-fail can NOT deny-override' 'DENY' 'git reset --hard && echo ok'

Write-Output ""
Write-Output "=== ASK (defer to human / DeepSeek) ==="
Assert-Classify 'git push normal'      'ASK' 'git push origin feature/ai-gateway'
Assert-Classify 'git commit'           'ASK' 'git commit -m "something"'
Assert-Classify 'git commit --amend'   'ASK' 'git commit --amend --no-edit'
Assert-Classify 'file write'           'ASK' 'out-file -Path tmp.txt -Value x'
Assert-Classify 'unknown command'      'ASK' 'super-tool --run everything'
Assert-Classify 'package uninstall'    'ASK' 'composer uninstall vendor/pkg'
Assert-Classify 'docker dangerous'     'ASK' 'docker system prune -af'

Write-Output ""
Write-Output "=== DeepSeek fallback behaviour ==="
$env:DEEPSEEK_API_KEY = ''
$r = Invoke-DeepSeekClassify -Command 'curl https://example.com'
Assert-True 'missing API key -> ASK,FALLBACK' ($r.Decision -eq 'ASK' -and $r.Source -eq 'FALLBACK') ($r | ConvertTo-Json -Compress)

$env:DEEPSEEK_API_KEY = 'test-only-dummy-key'
$script:DeepSeekUrl = 'http://127.0.0.1:9/v1'   # unreachable on purpose
$r = Invoke-DeepSeekClassify -Command 'git commit -m test'
Assert-True 'API failure -> ASK,FALLBACK'      ($r.Decision -eq 'ASK' -and $r.Source -eq 'FALLBACK') ($r | ConvertTo-Json -Compress)
Assert-True 'malformed/unreachable -> not ALLOW' ($r.Decision -ne 'ALLOW') ($r | ConvertTo-Json -Compress)

Write-Output ""
Write-Output "=== Advisory limitation ==="
Assert-True 'deepseek never downgrades decision pipeline' `
    ((Get-SupervisorDecision -Command 'git clean -fd').Source -eq 'LOCAL')

Write-Output ""
Write-Output "=== Audit log sanitisation ==="
$tmpLog = Join-Path ([System.IO.Path]::GetTempPath()) ("sup-test-{0:N}.log" -f [guid]::NewGuid())
$script:LogFile = $tmpLog
$fakeKey = 'sk-xlq-test-abcdef1234567890'
Write-SupervisorLog -Tool 'Bash' -Command "curl -H `"Authorization: Bearer $fakeKey`" sk-demo-tkn" `
    -Decision @{ Decision='ASK'; Reason='test reason'; Source='FALLBACK' }
$logContent = [System.IO.File]::ReadAllText($tmpLog)
Assert-True 'no secret in log: raw key'     (-not $logContent.Contains($fakeKey)) $logContent
Assert-True 'no secret in log: sk- prefix'  (-not $logContent.Contains('sk-')) $logContent
Remove-Item -Force $tmpLog -Confirm:$false

Write-Output ""
Write-Output "=== E2E (stdin hook JSON -> official PreToolUse output) ==="
# Dot-source in child-safe test mode; run the real main path per payload.
function Assert-E2E {
    param([string]$Name, [string]$Expected, [string]$Payload)
    $scriptPath = Join-Path $PSScriptRoot '..\supervisor.ps1'
    # The main suite sets this; the child must run its real main path.
    Remove-Item Env:\CLAUDE_SUPERVISOR_TEST -ErrorAction SilentlyContinue
    $out = $Payload | & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $scriptPath 2>&1 | Out-String
    $out = $out.Trim()
    $passed = $false; $detail = $out
    try {
        $j = $out | ConvertFrom-Json
        $ev  = $j.hookSpecificOutput.hookEventName
        $dec = $j.hookSpecificOutput.permissionDecision
        $hasReason = -not [string]::IsNullOrWhiteSpace([string]$j.hookSpecificOutput.permissionDecisionReason)
        $passed = ($ev -eq 'PreToolUse') -and ($dec -eq $Expected) -and $hasReason -and ($out -ne '')
        $detail = "event=$ev decision=$dec"
    } catch {
        $passed = $false; $detail = "not JSON: $out"
    }
    if ($passed) {
        $script:PassCount++
        Write-Output ("PASS  {0,-30} -> {1}" -f $Name, $Expected)
    } else {
        $script:FailCount++
        Write-Output ("FAIL  {0,-30} expected={1} got=[{2}]" -f $Name, $Expected, $detail)
    }
}

Assert-E2E 'e2e safe read -> allow' 'allow' '{"hook_event_name":"PreToolUse","tool_name":"Bash","tool_input":{"command":"git status"}}'
Assert-E2E 'e2e hard deny -> deny'  'deny'  '{"hook_event_name":"PreToolUse","tool_name":"Bash","tool_input":{"command":"git reset --hard HEAD"}}'
Assert-E2E 'e2e git push -> ask'    'ask'   '{"hook_event_name":"PreToolUse","tool_name":"Bash","tool_input":{"command":"git push origin feature/ai-gateway"}}'
Assert-E2E 'e2e unknown -> ask'     'ask'   '{"hook_event_name":"PreToolUse","tool_name":"Bash","tool_input":{"command":"composer uninstall vendor/pkg"}}'

Write-Output ""
Write-Output ('RESULT: {0} passed, {1} failed' -f $global:PassCount, $global:FailCount)
if ($global:FailCount -gt 0) { exit 1 } else { exit 0 }
