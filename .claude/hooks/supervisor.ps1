# Servernet Supervisor — PreToolUse hook
# Reads the Claude Code hook JSON from stdin, classifies the command and
# emits the official PreToolUse permissionDecision output.
#
# Classification is testable in isolation: set $env:CLAUDE_SUPERVISOR_TEST='1'
# and dot-source this file, then call Get-SupervisorDecision with a command
# string. Commands are never executed by this script or by the tests.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$script:ProjectDir = 'D:\Project\Servernet'
$script:LogDir     = Join-Path (Split-Path -Parent $PSCommandPath) '..\logs'
$script:LogFile    = Join-Path $script:LogDir 'supervisor.log'
$script:DeepSeekUrl = 'https://api.deepseek.com/chat/completions'
$script:DeepSeekModel = 'deepseek-v4-pro'
$script:DeepSeekTimeoutSec = 8
$script:DeepSeekMaxTokens = 300

# ------------------------------------------------------------------ rules

# HARD DENY —LOCAL only, DeepSeek can never override these.
$script:DenyRules = @(
    @{ Pattern = 'git\s+(-[a-z]*\s+)*reset[^|;&]*(--hard|-[a-z]*h[a-z]*)';            Reason = 'git reset --hard' },
    @{ Pattern = 'git\s+clean';                                                       Reason = 'git clean deletes untracked files' },
    @{ Pattern = 'git\s+push\b[^;|&]*(--force(=-with-lease)?|\s-f\b|-f\s|-[a-z]*f[a-z]*\s)'; Reason = 'git push --force' },

    @{ Pattern = "(^|[;&|`"']|\s)rm\s+(-[a-z]*r[a-z]*)+\s+[^;|&]*";                   Reason = 'rm -rf'; IsRm = $true },
    @{ Pattern = "(^|[;&|`"']|\s)remove-item\s+[^;|&]*-[a-z]*r[a-z]*"; Reason = 'Remove-Item -Recurse'; IsRm = $true },

    @{ Pattern = '\b(drop\s+database|drop\s+schema|truncate\s+[`"'']?\w+[`"'']?\s*$|delete\s+from\s+\S+\s*;?\s*$|drop\s+table)\b'; Reason = 'destructive DB command' },
    @{ Pattern = '\b(mariadb-dump|mysqldump|mysqladmin)\b.*\b(drop|shutdown)\b';      Reason = 'destructive DB admin command' },

    @{ Pattern = '(^|[;&|])[^;|&]*(artisan\s+migrate:fresh|db:wipe|php artisan\s+migrate:rollback\s+--step=0)'; Reason = 'schema-wiping migrate command (hard-denied, use ASK path instead)' },

    @{ Pattern = '\b(dep\s+deploy|envoyer|deployer|cap\s+production|cap\s+deploy|php\s+artisan\s+down|up\-?service)\b'; Reason = 'production deploy command' },
    @{ Pattern = 'git\s+push\s+([^;|&]*)\b(main|master)\b';                           Reason = 'push to the production/main branch' },

    @{ Pattern = '(\bprintenv\b|(^|\s)env($|\s)|export\s+|setx\b|echo\s+\$\w*(KEY|TOKEN|SECRET|PASSWORD))'; Reason = 'prints/exposes environment secrets' },
    @{ Pattern = '(\btype\b|\bcat\b|\bget-content\b|\bless\b|\bmore\b|\bhead\b|\btail\b)[^;|&]*\.env\b'; Reason = 'reads .env secret file' },
    @{ Pattern = '\b(b64decode|base64\s+-d|openssl\s+enc)\b.*\b(AUTH_TOKEN|API_KEY|SECRET|PASSWORD|credentials)\b'; Reason = 'decodes/exposes secrets' },

    @{ Pattern = '(\brm\b|\bremove-item\b|\bmv\b|\bmove-item\b|\bset-content\b|\bout-file\b|\btruncate\b|\becho\s*>|\bsed\s+-i\b)[^;|&]*\.claude[/\\]'; Reason = 'modifies/deletes the supervisor itself' },
    @{ Pattern = '\b(set-executionpolicy|bypass-supervisor|disable-supervisor)\b';    Reason = 'attempts to bypass the supervisor' },
    @{ Pattern = '\bchmod\b.*\b(claude|supervisor)\b';                                Reason = 'changes supervisor permissions' }
)

# HARD ALLOW — clearly safe read-only inspection commands only.
$script:AllowPatterns = @(
    '^\s*pwd\s*$'
    '^\s*git\s+status(\s+[^;|&]*)?$'
    '^\s*git\s+diff(\s+[^;|&]*)?$'
    '^\s*git\s+log(\s+[^;|&]*)?$'
    '^\s*git\s+branch\s+--show-current\s*$'
    '^\s*git\s+rev-parse\s+[^;|&]*$'
    '^\s*git\s+remote\s+-?v\s*$'
    '^\s*git\s+show\s+[^;|&]*$'
    '^\s*git\s+worktree\s+list\s*$'
    '^\s*(ls|ls\s+-[a-z]+|dir|get-childitem)(\s+[^;|&]*)?$'
    '^\s*(cat|type|get-content(\s+-totalcount\s+\d+)?)\s+[^;|&]*$'
    '^\s*(grep|rg|select-string)(\s+-[^;|&]*)?\s+[^;|&]*$'
    '^\s*find\s+[^;|&]*$'
    '^\s*php\s+(-v|--version)\s*$'
    '^\s*composer\s+(-V|--version|show|licenses)\b[^;|&]*$'
    '^\s*docker\s+(-v|--version|ps|info|images)\s*$'
    '^\s*(which|where)\s+\S+\s*$'
    '^\s*head\s+-\d+\s+[^;|&]*$'
    '^\s*tail\s+(-\d+|-n\s+\d+|-f)?\s+[^;|&]*$'
    '^\s*wc\s+-[lcw]?\s*[^;|&]*$'
    '^\s*uptime\s*$'
    '^\s*echo\s+"?\$?$'
    '^\s*claude\s+--version\s*$'
    '^\s*npm\s+(-v|--version)\s*$'
    '^\s*node\s+(-v|--version)\s*$'
)

# ASK — unclassified commands fall through to DeepSeek, then to the human.

# ------------------------------------------------------------ classification

function Test-IsProjectPath {
    param([string]$Path)
    if ($Path -notmatch '\S') { return $true }
    $p = $Path.Trim().Trim('"', "'")
    # Relative path -> runs inside cwd -> treat as project path.
    if ($p -notmatch '^[a-zA-Z]:[/\\]' -and $p -notmatch '^[/\\]') { return $true }
    $lower = $p.ToLowerInvariant()
    $Flags = [System.Globalization.CultureInfo]::InvariantCulture
    $lpLower = $lower
    foreach ($marker in @('d:\project', 'd:/project', '/d/project', '/c/project', 'c:\project', 'servernet')) {
        if ($lpLower.Contains($marker)) { return $true }
    }
    return $false
}

function Get-SupervisorDecision {
    # Returns a hashtable: Decision (ALLOW|DENY|ASK), Reason, Source.
    # This is the testable core: pure logic, no side effects.
    param([string]$Command, [string]$Cwd = '')

    $c = $Command.Trim()
    if ($c.Length -gt 4000) { $c = $c.Substring(0, 4000) }
    # Invariant casing: this host runs under tr-TR, where ToLower() maps
    # the 'I' in e.g. 'Remove-Item' to dotless 'ı' and would break matching.
    $lp = $c.ToLowerInvariant()

    # ---- HARD DENY check first (overrides everything) ----
    foreach ($rule in $script:DenyRules) {
        if ($rule.ContainsKey('IsRm')) {
            if ([regex]::IsMatch($lp, $rule.Pattern)) {
                $cmd = $lp -replace '^\s*(rm|remove-item)\s+', ''
                if (Test-IsProjectPath -Path ($cmd -split '\s+' | Select-Object -Last 1)) {
                    return @{ Decision = 'DENY'; Reason = ("LOCAL: {0} targets a project path" -f $rule.Reason); Source = 'LOCAL' }
                }
                continue
            }
        }
        if ([regex]::IsMatch($lp, $rule.Pattern)) {
            return @{ Decision = 'DENY'; Reason = ("LOCAL: {0}" -f $rule.Reason); Source = 'LOCAL' }
        }
    }

    # ---- HARD ALLOW: only clearly safe read-only operations ----
    foreach ($pat in $script:AllowPatterns) {
        if ($c -match $pat) {
            return @{ Decision = 'ALLOW'; Reason = 'LOCAL: read-only inspection command'; Source = 'LOCAL' }
        }
    }

    return @{ Decision = 'ASK'; Reason = 'LOCAL: no confident classification'; Source = 'LOCAL' }
}

# ------------------------------------------------------------------ helpers

function Write-SupervisorLog {
    param([string]$Tool, [string]$Command, [hashtable]$Decision)
    try {
        if (-not (Test-Path $script:LogDir)) {
            New-Item -ItemType Directory -Path $script:LogDir -Force | Out-Null
        }
        # Sanitized summary: truncate, scrub secret-looking values.
        $summary = $Command -replace '\s+', ' '
        if ($summary.Length -gt 200) { $summary = $summary.Substring(0, 200) + '…' }
        $summary = [regex]::Replace($summary, '(?i)(key|token|secret|password|authorization|bearer)\s*[=:]?\s*["'']?[a-z0-9_\-.]{10,}', '$1=<redacted>')
        $summary = [regex]::Replace($summary, '(?i)\bsk-[a-z0-9_\-.]{6,}', '<redacted>')
        $reason = $Decision.Reason
        if ($reason.Length -gt 200) { $reason = $reason.Substring(0, 200) + '…' }
        $line = ('{0} | tool={1} | decision={2} | source={3} | reason={4} | cmd={5}' -f `
            (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Tool, $Decision.Decision, $Decision.Source, $reason, $summary)
        Add-Content -Path $script:LogFile -Value $line -Encoding utf8
    } catch {
        # Logging must never break the hook; swallow errors.
    }
}

# ------------------------------------------------- response sanitisation

function Convert-SanitizeSecrets {
    # Scrubs secret-looking values from any text before it is logged,
    # emitted, or embedded in a fallback reason. Mirrors the redaction
    # rules used by Write-SupervisorLog.
    param([string]$Text)
    if ([string]::IsNullOrEmpty($Text)) { return $Text }
    $t = [regex]::Replace($Text, '(?i)(key|token|secret|password|authorization|bearer)\s*[=:]?\s*["'']?[a-z0-9_\-.]{10,}', '$1=<redacted>')
    $t = [regex]::Replace($t, '(?i)\bsk-[a-z0-9_\-.]{6,}', '<redacted>')
    return $t
}

function Test-TransientHttpError {
    # True for clearly transient transport/API failures only (timeouts,
    # connection errors, HTTP 5xx). Never true for structured 4xx responses.
    param([string]$Message)
    return ($Message -match '(?i)(timed out|unable to connect|the remote name|connection refused|response status code 5\d\d|\(5\d\d\) )')
}

# ------------------------------------------------------------------ parsers

function Get-JsonCandidates {
    # Scans text for top-level balanced `{ ... }` candidates, remaining
    # escape- and string-aware inside JSON. Returns the complete candidates
    # plus a flag telling whether the text ends mid-object or mid-string
    # (the truncation signal from the content itself).
    param([string]$Text)
    $candidates = New-Object System.Collections.Generic.List[string]
    $depth = 0; $inString = $false; $escaped = $false; $start = -1
    $chars = $Text.ToCharArray()
    for ($i = 0; $i -lt $chars.Length; $i++) {
        $ch = $chars[$i]
        if ($inString) {
            if ($escaped) { $escaped = $false }
            elseif ($ch -eq '\') { $escaped = $true }
            elseif ($ch -eq '"') { $inString = $false }
            continue
        }
        if ($ch -eq '"') { $inString = $true; continue }
        if ($ch -eq '{') {
            if ($depth -eq 0) { $start = $i }
            $depth++
        } elseif ($ch -eq '}') {
            if ($depth -gt 0) {
                $depth--
                if ($depth -eq 0 -and $start -ge 0) {
                    $candidates.Add($Text.Substring($start, $i - $start + 1))
                    $start = -1
                }
            }
        }
    }
    return @{ Candidates = $candidates; Unbalanced = ($depth -gt 0 -or $inString -or ($start -ge 0)) }
}

function ConvertFrom-DeepSeekContent {
    # Parses one model message into the canonical decision schema.
    # Status is exactly one of:
    #   OK | PARSE-TRUNCATED | PARSE-MALFORMED | PARSE-AMBIGUOUS | PARSE-INVALID-SCHEMA
    # On OK, Decision is the validated enum value and Reason is always
    # non-empty (generic substitute for missing/blank reasons).
    param([string]$Content, [string]$FinishReason = '')

    if ([string]::IsNullOrWhiteSpace($Content)) {
        return @{ Status = 'PARSE-TRUNCATED' }   # output cut before any JSON
    }

    $scan = Get-JsonCandidates -Text $Content
    $parsedList = @()
    foreach ($cand in $scan.Candidates) {
        $obj = $null
        try { $obj = $cand | ConvertFrom-Json } catch { $obj = $null }
        if ($obj) { $parsedList += $obj }
    }

    if ($parsedList.Count -gt 1) { return @{ Status = 'PARSE-AMBIGUOUS' } }
    if ($parsedList.Count -eq 1) {
        $obj = $parsedList[0]
        # StrictMode-safe property access: never throw on a missing field.
        $dProp = $obj.PSObject.Properties['decision']
        if (-not $dProp) { return @{ Status = 'PARSE-INVALID-SCHEMA' } }
        $decision = ([string]$dProp.Value).ToUpperInvariant()
        if (($decision -ne 'ALLOW') -and ($decision -ne 'DENY') -and ($decision -ne 'ASK')) {
            return @{ Status = 'PARSE-INVALID-SCHEMA' }
        }
        $reason = 'no reason provided by advisory classifier'
        $rProp = $obj.PSObject.Properties['reason']
        if ($rProp -and -not [string]::IsNullOrWhiteSpace([string]$rProp.Value)) {
            $reason = [string]$rProp.Value
        }
        if ($reason.Length -gt 150) { $reason = $reason.Substring(0, 150) }
        return @{ Status = 'OK'; Decision = $decision; Reason = $reason }
    }

    # Nothing parsed from the content — classify the failure.
    if ($scan.Unbalanced) { return @{ Status = 'PARSE-TRUNCATED' } }
    if ($scan.Candidates.Count -gt 0) { return @{ Status = 'PARSE-MALFORMED' } }
    if ($FinishReason -eq 'length') { return @{ Status = 'PARSE-TRUNCATED' } }
    return @{ Status = 'PARSE-MALFORMED' }
}

function Invoke-DeepSeekHttp {
    # Real HTTP transport for the advisory call. Kept as a seam so tests can
    # override this exact function after dot-sourcing (deterministic mocks,
    # no live API in automated suites). Never logs or returns credentials.
    param([string]$Body, [string]$Key)
    return Invoke-RestMethod -Uri $script:DeepSeekUrl -Method Post -TimeoutSec $script:DeepSeekTimeoutSec `
        -ContentType 'application/json' -Headers @{ Authorization = "Bearer $Key" } -Body $Body
}

function Invoke-DeepSeekClassify {
    # Advisory-only classification. Returns the parsed decision or a
    # fallback-ASK object on any failure. Never returns ALLOW for commands
    # already hard-denied (DENY is decided before this runs), and no
    # parser/API failure can ever be converted into ALLOW.
    param([string]$Command)

    $key = $env:DEEPSEEK_API_KEY
    if ([string]::IsNullOrWhiteSpace($key)) {
        return @{ Decision = 'ASK'; Reason = 'FALLBACK: DEEPSEEK_API_KEY not set'; Source = 'FALLBACK' }
    }

    $sysPrompt = @'
You are a safety supervisor for shell commands a coding agent wants to run.
Classify the given command strictly as one of ALLOW / DENY / ASK:
- ALLOW: only clearly safe, non-destructive, read-only or informational actions.
- DENY: destructive, history-rewriting, forced pushes, secret exposure, or disabling safety tooling.
- ASK: anything you cannot confidently classify as safe (including writes, installs, migrations, docker cleanup, anything ambiguous).
Respond ONLY with strict JSON: {"decision":"ALLOW|DENY|ASK","reason":"short reason"}
'@

    $maxAttempts = 2   # exactly ONE retry, and only for transient/truncated
    $lastFail = ''
    for ($attempt = 1; $attempt -le $maxAttempts; $attempt++) {
        try {
            $bodyHashTable = @{
                model = $script:DeepSeekModel
                messages = @(
                    @{ role = 'system'; content = $sysPrompt },
                    @{ role = 'user'; content = "cwd-independent command: $Command" }
                )
                response_format = @{ type = 'json_object' }
                temperature = 0
                max_tokens = $script:DeepSeekMaxTokens
            }
            $body = ConvertTo-Json -InputObject $bodyHashTable -Depth 5 -Compress

            $result = Invoke-DeepSeekHttp -Body $body -Key $key

            $content = $null
            $finish = ''
            try {
                $choice = $result.choices[0]
                $content = [string]$choice.message.content
                $fr = $choice.PSObject.Properties['finish_reason']
                if ($fr) { $finish = [string]$fr.Value }   # StrictMode-safe
            } catch {
                return @{ Decision = 'ASK'; Reason = 'FALLBACK: DeepSeek response rejected (response shell unreadable)'; Source = 'FALLBACK' }
            }

            $parsed = ConvertFrom-DeepSeekContent -Content $content -FinishReason $finish

            if ($parsed.Status -eq 'OK') {
                $decision = $parsed.Decision
                $reason = Convert-SanitizeSecrets -Text $parsed.Reason
                # Advisory only: DeepSeek cannot upgrade an ASK into ALLOW for
                # anything that is not demonstrably read-only.
                if ($decision -eq 'ALLOW') {
                    return @{ Decision = 'ASK'; Reason = "advisory-ALLOW downgraded to ASK: $reason"; Source = 'DEEPSEEK' }
                }
                return @{ Decision = $decision; Reason = "DEEPSEEK: $reason"; Source = 'DEEPSEEK' }
            }

            if (($parsed.Status -eq 'PARSE-TRUNCATED') -or ($finish -eq 'length')) {
                $lastFail = "DeepSeek truncated response (PARSE-TRUNCATED, finish_reason=$finish)"
                if ($attempt -lt $maxAttempts) { continue }   # one retry only
                break
            }
            # MALFORMED / AMBIGUOUS / INVALID-SCHEMA: deterministic failures — no retry.
            $lastFail = ("DeepSeek response rejected ({0})" -f $parsed.Status)
            break
        } catch {
            $snippet = Convert-SanitizeSecrets -Text ($_.Exception.Message)
            if ($snippet.Length -gt 120) { $snippet = $snippet.Substring(0, 120) }
            if (($attempt -lt $maxAttempts) -and (Test-TransientHttpError -Message $_.Exception.Message)) {
                $lastFail = "DeepSeek transient API failure ($snippet)"
                continue   # one retry for clearly transient transport errors
            }
            $lastFail = ("DeepSeek API failed ({0})" -f $snippet)
            break
        }
    }
    return @{ Decision = 'ASK'; Reason = "FALLBACK: $lastFail"; Source = 'FALLBACK' }
}

# PS 5.1 has no ConvertFrom-Json -AsHashtable; several helpers below use
# a PSCustomObject with case-insensitive property access instead.

function Get-InputValue {
    param([object]$Obj, [string]$Property)
    if ($null -eq $Obj) { return $null }
    $p = $Obj.PSObject.Properties[$Property]
    if ($p) { return $p.Value }
    return $null
}

function Get-ToolCommand {
    param([object]$InputData)
    $toolInput = Get-InputValue -Obj $InputData -Property 'tool_input'
    if ($toolInput -is [string]) {
        try { $toolInput = $toolInput | ConvertFrom-Json } catch { $toolInput = $null }
    }
    foreach ($k in @('command', 'cmd', 'file_path', 'notebook_path', 'url', 'path')) {
        $v = Get-InputValue -Obj $toolInput -Property $k
        if ($v) { return [string]$v }
    }
    return ''
}

# ------------------------------------------------------------------ main

function Emit-SupervisorOutput {
    # Emits the official PreToolUse permissionDecision JSON for one decision.
    # ASK must emit "ask" explicitly so Claude Code raises a HUMAN permission
    # prompt in auto mode instead of falling through to the auto classifier.
    param([string]$Decision, [string]$Reason)
    @{ hookSpecificOutput = @{
            hookEventName = 'PreToolUse'
            permissionDecision = $Decision.ToLowerInvariant()
            permissionDecisionReason = $Reason
        }
    } | ConvertTo-Json -Depth 5 -Compress | Write-Output
}

function Invoke-Supervisor {
    param([string]$StdinJson)

    $InputData = $null
    try { $InputData = $StdinJson | ConvertFrom-Json } catch { $InputData = $null }

    $eventName = [string](Get-InputValue -Obj $InputData -Property 'hook_event_name')
    $toolName  = [string](Get-InputValue -Obj $InputData -Property 'tool_name')
    $cwd       = [string](Get-InputValue -Obj $InputData -Property 'cwd')
    $command   = Get-ToolCommand -InputData $InputData

    if ($command -eq '') {
        # No command to classify — explicitly ask the human instead of
        # emitting nothing (which would fall through to the auto classifier).
        Write-SupervisorLog -Tool $toolName -Command '<empty>' `
            -Decision @{ Decision = 'ASK'; Reason = 'LOCAL: no command in tool input'; Source = 'LOCAL' }
        Emit-SupervisorOutput -Decision 'ASK' -Reason 'LOCAL: no command in tool input'
        return
    }

    $combo = "$command"
    $decision = Get-SupervisorDecision -Command $combo -Cwd $cwd
    if ($decision.Decision -eq 'ASK') {
        # DeepSeek is advisory; local DENY has already been applied above.
        $ds = Invoke-DeepSeekClassify -Command $command
        $decision = $ds
    }

    Write-SupervisorLog -Tool $toolName -Command $command -Decision $decision

    switch ($decision.Decision) {
        'ALLOW' { Emit-SupervisorOutput -Decision 'ALLOW' -Reason $decision.Reason }
        'DENY'  { Emit-SupervisorOutput -Decision 'DENY'  -Reason $decision.Reason }
        default {
            # ASK: emit "ask" so the HUMAN permission prompt is shown.
            Emit-SupervisorOutput -Decision 'ASK' -Reason $decision.Reason
        }
    }
}

# Test mode: dot-source with $env:CLAUDE_SUPERVISOR_TEST='1' — no main run.
if (-not $env:CLAUDE_SUPERVISOR_TEST) {
    try {
        $raw = [Console]::In.ReadToEnd()
        Invoke-Supervisor -StdinJson $raw
    } catch {
        exit 0   # never break Claude Code on hook errors
    }
}
