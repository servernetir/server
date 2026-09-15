# End-to-end stdin smoke test: feeds hook JSON payloads to supervisor.ps1
# as a child process and captures its output. Now validates the OFFICIAL
# PreToolUse JSON: every decision (allow/deny/ask) must emit output, and ASK
# must emit permissionDecision = "ask" so the human prompt is raised.
# The deny payloads live in this file, not in any caller's command.
$ErrorActionPreference = 'Stop'
$scriptPath = Join-Path $PSScriptRoot '..\supervisor.ps1'

$payloads = [ordered]@{
    allow = '{"hook_event_name":"PreToolUse","tool_name":"Bash","tool_input":{"command":"git status"}}'
    deny  = '{"hook_event_name":"PreToolUse","tool_name":"Bash","tool_input":{"command":"git reset --hard HEAD"}}'
    ask   = '{"hook_event_name":"PreToolUse","tool_name":"Bash","tool_input":{"command":"composer uninstall vendor/pkg"}}'
    askPush = '{"hook_event_name":"PreToolUse","tool_name":"Bash","tool_input":{"command":"git push origin feature/ai-gateway"}}'
}

$fail = 0
foreach ($name in $payloads.Keys) {
    $out = $payloads[$name] | & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $scriptPath 2>&1 | Out-String
    $out = $out.Trim()
    try {
        $j = $out | ConvertFrom-Json
        $dec = [string]$j.hookSpecificOutput.permissionDecision
        $ev  = [string]$j.hookSpecificOutput.hookEventName
        $expect = 'ask'; if ($name -eq 'allow') { $expect = 'allow' }; if ($name -eq 'deny') { $expect = 'deny' }
        $pass = ($ev -eq 'PreToolUse') -and ($dec -eq $expect) -and ($out -ne '')
    } catch {
        $dec = '<invalid JSON>'; $pass = $false
    }
    if (-not $pass) { $fail++ }
    Write-Output ("== {0} (decision={1}, expected={2}) ==`n{3}" -f $name, $dec, $expect, $out)
}
Write-Output ("E2E RESULT: {0} failed" -f $fail)
if ($fail -gt 0) { exit 1 }
exit 0
