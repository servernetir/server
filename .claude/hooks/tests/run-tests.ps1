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
Write-Output "=== DeepSeek parser & retry (mocked transport, no live API) ==="

# Deterministic seam: the real Invoke-DeepSeekHttp (dot-sourced above) is
# replaced by a mock that returns queued results or queued exceptions and
# counts every call. Never touches the network.
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

function New-MockResult {
    param([string]$Content, [string]$Finish = 'stop')
    $payload = @{ choices = @( @{ message = @{ content = $Content }; finish_reason = $Finish } ) }
    return ConvertFrom-Json -InputObject ($payload | ConvertTo-Json -Depth 5)
}

function New-Exception {
    param([string]$Message)
    return New-Object System.Exception -ArgumentList $Message
}

function Assert-DeepSeek {
    param([string]$Name, [string]$ExpectedDecision, [string]$ExpectedSource,
          [int]$ExpectedCalls, $First, $Second,
          [string]$ReasonContains = '', [string]$ReasonNotContains = '')
    $script:MockCalls = 0
    $script:MockResponses.Clear()
    $script:MockResponses.Add($First)
    if ($null -ne $Second) { $script:MockResponses.Add($Second) }
    $env:DEEPSEEK_API_KEY = 'test-only-dummy-key'
    $r = Invoke-DeepSeekClassify -Command 'git commit -m test'
    $ok = ($r.Decision -eq $ExpectedDecision) -and ($r.Source -eq $ExpectedSource) -and ($script:MockCalls -eq $ExpectedCalls)
    if ($ReasonContains) { $ok = $ok -and ($r.Reason -match $ReasonContains) }
    if ($ReasonNotContains) { $ok = $ok -and ($r.Reason -notmatch $ReasonNotContains) }
    Assert-True $Name $ok ("calls={0} got: {1}" -f $script:MockCalls, ($r | ConvertTo-Json -Compress))
    return $r
}

# Re-point the seam at the mock (dot-source defined the real transport).
function Invoke-DeepSeekHttp {
    param([string]$Body, [string]$Key)
    return Mock-Invoke-DeepSeekHttp -Body $Body -Key $Key
}
$goodAsk = New-MockResult '{"decision":"ASK","reason":"needs human review"}'
$goodDeny = New-MockResult '{"decision":"DENY","reason":"destructive command"}'
$goodAllow = New-MockResult '{"decision":"ALLOW","reason":"harmless read"}'
$truncated = New-MockResult '{"decision":"ASK","reason":"git commit is a'

Assert-DeepSeek 'parser: direct valid ASK JSON'    'ASK'  'DEEPSEEK' 1 $goodAsk
Assert-DeepSeek 'parser: direct valid DENY JSON'   'DENY' 'DEEPSEEK' 1 $goodDeny
Assert-DeepSeek 'parser: advisory ALLOW downgraded' 'ASK' 'DEEPSEEK' 1 $goodAllow 'advisory-ALLOW downgraded'
$fence = ([string][char]96) * 3
Assert-DeepSeek 'parser: fenced json block'        'DENY' 'DEEPSEEK' 1 (New-MockResult ($fence + 'json' + "`n" + '{"decision":"DENY","reason":"fenced dangerous"}' + "`n" + $fence))
Assert-DeepSeek 'parser: prose around one object'  'ASK'  'DEEPSEEK' 1 (New-MockResult 'Sure. {"decision":"ASK","reason":"wrapped in prose"} Done.')
Assert-DeepSeek 'parser: multiple objects AMBIGUOUS' 'ASK' 'FALLBACK' 1 (New-MockResult '{"decision":"ASK"} {"decision":"DENY"}') 'PARSE-AMBIGUOUS'
Assert-DeepSeek 'parser: truncated -> one retry ok' 'ASK' 'DEEPSEEK' 2 $truncated $goodAsk
Assert-DeepSeek 'parser: truncated twice -> no 3rd try' 'ASK' 'FALLBACK' 2 $truncated $truncated 'PARSE-TRUNCATED'
Assert-DeepSeek 'parser: finish_reason=length -> retry' 'ASK' 'DEEPSEEK' 2 (New-MockResult 'the rest of the response was cut off' 'length') $goodAsk
Assert-DeepSeek 'parser: malformed -> no retry'     'ASK' 'FALLBACK' 1 (New-MockResult 'I said {this is not json}') 'PARSE-MALFORMED'
Assert-DeepSeek 'parser: invalid enum -> ASK'       'ASK' 'FALLBACK' 1 (New-MockResult '{"decision":"ALLOWED","reason":"x"}') 'PARSE-INVALID-SCHEMA'
Assert-DeepSeek 'parser: missing decision -> ASK'   'ASK' 'FALLBACK' 1 (New-MockResult '{"reason":"x"}') 'PARSE-INVALID-SCHEMA'
Assert-DeepSeek 'parser: missing reason -> generic' 'DENY' 'DEEPSEEK' 1 (New-MockResult '{"decision":"DENY"}') 'no reason provided by advisory classifier'
Assert-DeepSeek 'parser: empty reason -> generic'   'ASK'  'DEEPSEEK' 1 (New-MockResult '{"decision":"ASK","reason":""}') 'no reason provided by advisory classifier'
Assert-DeepSeek 'api: transient error -> one retry ok' 'ASK' 'DEEPSEEK' 2 (New-Exception 'Unable to connect to the remote server') $goodAsk
Assert-DeepSeek 'api: 4xx error -> no retry'        'ASK' 'FALLBACK' 1 (New-Exception 'The remote server returned (400) Bad Request')
Assert-DeepSeek 'api: transient twice -> ASK'       'ASK' 'FALLBACK' 2 (New-Exception 'The operation has timed out') (New-Exception 'The operation has timed out')
Assert-DeepSeek 'secrets: error message redacted'   'ASK' 'FALLBACK' 1 (New-Exception "Authorization failed for token 'sk-live-abcdef123456'") -ReasonNotContains 'sk-live'

$env:DEEPSEEK_API_KEY = ''
$script:MockCalls = 0
$script:MockResponses.Clear()
$r = Invoke-DeepSeekClassify -Command 'git commit -m test'
Assert-True 'parser: missing API key -> ASK, no call' ($r.Decision -eq 'ASK' -and $r.Source -eq 'FALLBACK' -and $script:MockCalls -eq 0) ($r | ConvertTo-Json -Compress)

Write-Output ""
Write-Output "=== Local hard DENY short-circuits DeepSeek ==="
$tmpLog2 = Join-Path ([System.IO.Path]::GetTempPath()) ("sup-test-{0:N}.log" -f [guid]::NewGuid())
$script:LogFile = $tmpLog2
$env:DEEPSEEK_API_KEY = 'test-only-dummy-key'
$script:MockCalls = 0
$script:MockResponses.Clear()
$script:MockResponses.Add($goodAllow)
$out = Invoke-Supervisor -StdinJson '{"hook_event_name":"PreToolUse","tool_name":"Bash","tool_input":{"command":"git reset --hard HEAD"}}'
$j = $out | ConvertFrom-Json
Assert-True 'hard DENY: never invokes DeepSeek' ($script:MockCalls -eq 0 -and $j.hookSpecificOutput.permissionDecision -eq 'deny' -and $j.hookSpecificOutput.permissionDecisionReason -like 'LOCAL:*') $out
Remove-Item -Force $tmpLog2 -Confirm:$false

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
