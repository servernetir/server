# Servernet Project Supervisor — Phase 2+3 autonomous orchestration hooks.
# Handles UserPromptSubmit (explicit activation), Stop (bounded autonomous
# review loop) and SupervisorStateUpdate (orchestrator -> state writes).
# Reuses the hardened Phase 1 DeepSeek transport/parser (supervisor.ps1)
# instead of duplicating it. Never exposes credentials; all outbound text is
# passed through Convert-SanitizeSecrets.
#
# Test modes:
#   CLAUDE_SUPERVISOR_LIB=1  -> dot-source without running the main hook loop.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$env:CLAUDE_SUPERVISOR_TEST = '1'   # keep supervisor.ps1 in dot-source mode
. (Join-Path (Split-Path -Parent $PSCommandPath) 'supervisor.ps1')

$script:ProjectDir    = 'D:\Project\Servernet'
$script:ClaudeDir     = Join-Path (Split-Path -Parent (Split-Path -Parent $PSCommandPath)) ''
$script:SupervisorDir = Join-Path $script:ClaudeDir 'supervisor'
$script:ConfigFile    = Join-Path $script:SupervisorDir 'config.json'
$script:StateDir      = Join-Path $script:SupervisorDir 'state'
$script:StateFile     = Join-Path $script:StateDir 'autonomous.json'
$script:PromptDir     = Join-Path $script:SupervisorDir 'prompts'
$script:ConfigCache  = $null
# Test/isolation override: redirect ALL runtime state to a temp location so
# proofs and tests never touch the real .claude/supervisor/state.
if ($env:CLAUDE_SUPERVISOR_STATE_DIR) {
    $script:StateDir  = $env:CLAUDE_SUPERVISOR_STATE_DIR
    $script:StateFile = Join-Path $script:StateDir 'autonomous.json'
}

# ------------------------------------------------------------- config

function Get-SupervisorConfig {
    if (-not $script:ConfigCache) {
        $script:ConfigCache = Get-Content -Raw -Path $script:ConfigFile | ConvertFrom-Json
    }
    return $script:ConfigCache
}

# --------------------------------------------------------------- state

function Get-DefaultState {
    return [ordered]@{
        autonomous_mode     = $false
        session_id          = ''
        milestone           = ''
        task                = ''
        acceptance          = ''
        iteration           = 0
        tier                = ''
        agent               = ''
        routing_reason      = ''
        last_decision       = ''
        last_reason         = ''
        verify_command      = ''
        tests_run           = $false
        tests_total         = 0
        tests_passed        = 0
        tests_failed        = 0
        verify_exit         = $null
        fix_signature       = ''
        fix_cycles          = 0
        failure_signature   = ''
        repeated_failures   = 0
        escalated_full      = $false
        escalation_reason  = ''
        risk_discovered     = ''
        human_gate_pending  = $false
        human_gate_reason   = ''
        planned_next_task   = ''
        taskcompleted_blocks = 0
        state_corrupt       = $false
    }
}

function Get-AutonomousState {
    # Missing state -> rebuild minimally. Corrupt state -> fail safe
    # (never interpreted as active/PASS).
    $default = Get-DefaultState
    if (-not (Test-Path $script:StateFile)) { return $default }
    $obj = $null
    try {
        $obj = Get-Content -Raw -Path $script:StateFile | ConvertFrom-Json
    } catch {
        $default.state_corrupt = $true
        return $default
    }
    if (-not $obj) { $default.state_corrupt = $true; return $default }
    foreach ($p in $obj.PSObject.Properties) {
        if ($default.Contains($p.Name)) { $default[$p.Name] = $p.Value }
    }
    return $default
}

function Set-AutonomousState {
    # Atomic: temp file -> validated -> rename over the target.
    param([hashtable]$State)
    $dir = Split-Path -Parent $script:StateFile
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $json = ConvertTo-Json -InputObject $State -Depth 5 -Compress
    $tmp = ('{0}.{1:N}.tmp' -f $script:StateFile, [guid]::NewGuid())
    [System.IO.File]::WriteAllText($tmp, $json)
    $null = Get-Content -Raw -Path $tmp | ConvertFrom-Json   # validate before replace
    Move-Item -Force -Path $tmp -Destination $script:StateFile
}

function Initialize-AutonomousSession {
    param([string]$SessionId)
    $st = Get-DefaultState
    $st.autonomous_mode = $true
    $st.session_id = $SessionId
    Set-AutonomousState -State $st
    return $st
}

function Invoke-StateUpdate {
    # Whitelisted field updates from the orchestrator (and tests).
    param([hashtable]$State, [hashtable]$Updates)
    foreach ($k in $Updates.Keys) {
        if ($State.Contains($k)) { $State[$k] = $Updates[$k] }
    }
    Set-AutonomousState -State $State
    return $State
}

# ---------------------------------------------------------- activation

function Test-IsActivationPrompt {
    # Whole-prompt exact match only: a phrase quoted inside code, docs or a
    # longer prompt must NOT activate autonomous mode.
    param([string]$Prompt)
    $cfg = Get-SupervisorConfig
    $norm = ([string]$Prompt -replace '\s+', ' ').Trim().ToLowerInvariant()
    foreach ($phrase in $cfg.activation_phrases) {
        $p = ([string]$phrase -replace '\s+', ' ').Trim().ToLowerInvariant()
        if ($norm -eq $p) { return $true }
    }
    return $false
}

# ------------------------------------------------------------ evidence

function Invoke-WhitespaceCheck {
    # Injectable seam (tests override) — real check runs git diff --check.
    $out = @(git -C $script:ProjectDir diff --check)
    return ($out -join ' ')
}

function New-Evidence {
    # REAL repository facts + recorded verification. The assistant's prose is
    # included ONLY as an untrusted, clearly-labelled claim.
    param([hashtable]$State, [string]$LastAssistantMessage = '')
    $root = $script:ProjectDir
    $branch = [string](git -C $root rev-parse --abbrev-ref HEAD)
    $head   = [string](git -C $root rev-parse HEAD)
    $status = [string]((git -C $root status --short) -join '; ')
    $stat   = [string]((git -C $root diff --stat) -join '; ')
    $staged = [string]((git -C $root diff --cached --stat) -join '; ')
    $ws     = [string](Invoke-WhitespaceCheck)
    $claim  = [string]$LastAssistantMessage
    if ($claim.Length -gt 500) { $claim = $claim.Substring(0, 500) + '…' }
    return [ordered]@{
        repo_root          = $root
        branch             = $branch
        head               = $head
        git_status_short   = (Convert-SanitizeSecrets $status)
        git_diff_check     = (Convert-SanitizeSecrets $ws)
        git_diff_stat      = (Convert-SanitizeSecrets $stat)
        staged_diff_stat   = (Convert-SanitizeSecrets $staged)
        milestone          = [string]$State.milestone
        task               = [string]$State.task
        acceptance         = [string]$State.acceptance
        review_iteration   = [int]$State.iteration
        tier               = [string]$State.tier
        agent              = [string]$State.agent
        previous_decision  = [string]$State.last_decision
        escalated_full     = [bool]$State.escalated_full
        human_gate_pending = [bool]$State.human_gate_pending
        verification       = [ordered]@{
            command  = [string]$State.verify_command
            run      = [bool]$State.tests_run
            total    = [int]$State.tests_total
            passed   = [int]$State.tests_passed
            failed   = [int]$State.tests_failed
            exit_code = $State.verify_exit
        }
        assistant_claim   = (Convert-SanitizeSecrets $claim)   # UNTRUSTED
    }
}

# ----------------------------------------------------- DeepSeek review

function Invoke-DeepSeekReview {
    # Independent project review. NEVER fails open: any failure becomes STOP.
    param([hashtable]$Evidence)

    $key = $env:DEEPSEEK_API_KEY
    if ([string]::IsNullOrWhiteSpace($key)) {
        return @{ decision = 'STOP'; reason = 'FALLBACK: DEEPSEEK_API_KEY not set — independent review unavailable'; source = 'FALLBACK' }
    }
    $cfg = Get-SupervisorConfig
    # Rebuild via -join: Get-Content -Raw returns a String instance that PS 5.1's
    # ConvertTo-Json serializes massively inflated (~500x). Joining lines produces
    # a fresh plain String with identical content and correct JSON size.
    $sys = (Get-Content -Path (Join-Path $script:PromptDir 'review-system.txt')) -join "`n"
    $bodyHashTable = @{
        model = $cfg.deepseek.model
        messages = @(
            @{ role = 'system'; content = $sys }
            @{ role = 'user';   content = (ConvertTo-Json -InputObject $Evidence -Depth 6 -Compress) }
        )
        response_format = @{ type = 'json_object' }
        temperature = 0
        max_tokens = [int]$cfg.deepseek.review_max_tokens
    }
    $body = ConvertTo-Json -InputObject $bodyHashTable -Depth 6 -Compress

    $maxAttempts = 2   # one bounded retry, truncation/transient only
    for ($attempt = 1; $attempt -le $maxAttempts; $attempt++) {
        try {
            $result = Invoke-DeepSeekHttp -Body $body -Key $key
            $content = ''
            $finish = ''
            try {
                $choice = $result.choices[0]
                $content = [string]$choice.message.content
                $fr = $choice.PSObject.Properties['finish_reason']
                if ($fr) { $finish = [string]$fr.Value }
            } catch {
                return @{ decision = 'STOP'; reason = 'FALLBACK: review response shell unreadable'; source = 'FALLBACK' }
            }

            $scan = Get-JsonCandidates -Text $content
            $objs = @()
            foreach ($c in $scan.Candidates) {
                $o = $null; try { $o = $c | ConvertFrom-Json } catch { $o = $null }
                if ($o) { $objs += $o }
            }
            if ($objs.Count -gt 1) {
                return @{ decision = 'STOP'; reason = 'FALLBACK: ambiguous review response (multiple JSON objects)'; source = 'FALLBACK' }
            }
            if ($objs.Count -eq 0) {
                if (($scan.Unbalanced) -or ($finish -eq 'length')) {
                    if ($attempt -lt $maxAttempts) { continue }
                    return @{ decision = 'STOP'; reason = 'FALLBACK: truncated review response'; source = 'FALLBACK' }
                }
                return @{ decision = 'STOP'; reason = 'FALLBACK: malformed review response'; source = 'FALLBACK' }
            }

            $o = $objs[0]
            $dProp = $o.PSObject.Properties['decision']
            if (-not $dProp) { return @{ decision = 'STOP'; reason = 'FALLBACK: review schema missing decision'; source = 'FALLBACK' } }
            $dec = ([string]$dProp.Value).ToUpperInvariant()
            if (@('PASS', 'FIX', 'NEXT', 'STOP') -notcontains $dec) {
                return @{ decision = 'STOP'; reason = 'FALLBACK: invalid review decision'; source = 'FALLBACK' }
            }
            $risk = 'MEDIUM'
            $rProp = $o.PSObject.Properties['risk']
            if ($rProp -and ([string]$rProp.Value)) { $risk = ([string]$rProp.Value).ToUpperInvariant() }
            if (@('LOW', 'MEDIUM', 'HIGH', 'CRITICAL') -notcontains $risk) { $risk = 'MEDIUM' }
            $reason = 'no reason provided by project supervisor'
            $rP = $o.PSObject.Properties['reason']
            if ($rP -and -not [string]::IsNullOrWhiteSpace([string]$rP.Value)) { $reason = [string]$rP.Value }
            $fix = ''
            $fP = $o.PSObject.Properties['fix_instructions']
            if ($fP) { $fix = [string]$fP.Value }
            $next = ''
            $nP = $o.PSObject.Properties['next_task']
            if ($nP) { $next = [string]$nP.Value }
            return @{
                decision = $dec; risk = $risk
                reason = (Convert-SanitizeSecrets $reason)
                fix_instructions = (Convert-SanitizeSecrets $fix)
                next_task = (Convert-SanitizeSecrets $next)
                source = 'DEEPSEEK'
            }
        } catch {
            $msg = $_.Exception.Message
            if (($attempt -lt $maxAttempts) -and (Test-TransientHttpError -Message $msg)) { continue }
            $snippet = Convert-SanitizeSecrets $msg
            if ($snippet.Length -gt 120) { $snippet = $snippet.Substring(0, 120) }
            return @{ decision = 'STOP'; reason = "FALLBACK: review API failed ($snippet)"; source = 'FALLBACK' }
        }
    }
    return @{ decision = 'STOP'; reason = 'FALLBACK: review failed'; source = 'FALLBACK' }
}

# -------------------------------------------------------------- routing

function Test-IsCriticalTask {
    param([string]$Task)
    $cfg = Get-SupervisorConfig
    $t = ([string]$Task).ToLowerInvariant()
    foreach ($k in $cfg.critical_keywords) {
        if ($t.Contains([string]$k)) { return $true }
    }
    return $false
}

function Test-IsRoutineTask {
    param([string]$Task)
    $cfg = Get-SupervisorConfig
    $t = ([string]$Task).ToLowerInvariant()
    foreach ($k in $cfg.routine_keywords) {
        if ($t.Contains([string]$k)) { return $true }
    }
    return $false
}

function Test-IsSecondarySensitive {
    param([string]$Task)
    $cfg = Get-SupervisorConfig
    $t = ([string]$Task).ToLowerInvariant()
    foreach ($k in $cfg.secondary_sensitive_keywords) {
        if ($t.Contains([string]$k)) { return $true }
    }
    return $false
}

function Invoke-DeepSeekRouting {
    # DeepSeek tier classification for ambiguous tasks only. Deterministic
    # critical/routine classification happens first and never reaches here.
    param([string]$Task)
    $cfg = Get-SupervisorConfig
    $fallbackTier = 'FLASH'
    if (Test-IsSecondarySensitive -Task $Task) { $fallbackTier = 'FULL' }

    $key = $env:DEEPSEEK_API_KEY
    if ([string]::IsNullOrWhiteSpace($key)) {
        return @{ tier = $fallbackTier; risk = 'MEDIUM'; reason = 'FALLBACK: routing key unavailable — safe default by domain'; source = 'FALLBACK' }
    }
    # Same Get-Content -Raw inflation bug as review path — rebuild via -join.
    $sys = (Get-Content -Path (Join-Path $script:PromptDir 'routing-system.txt')) -join "`n"
    $bodyHashTable = @{
        model = $cfg.deepseek.model
        messages = @(
            @{ role = 'system'; content = $sys }
            @{ role = 'user';   content = "development task: $Task" }
        )
        response_format = @{ type = 'json_object' }
        temperature = 0
        max_tokens = [int]$cfg.deepseek.routing_max_tokens
    }
    $body = ConvertTo-Json -InputObject $bodyHashTable -Depth 6 -Compress

    $maxAttempts = 2
    for ($attempt = 1; $attempt -le $maxAttempts; $attempt++) {
        try {
            $result = Invoke-DeepSeekHttp -Body $body -Key $key
            $content = ''
            try { $content = [string]$result.choices[0].message.content } catch { $content = '' }
            $scan = Get-JsonCandidates -Text $content
            $objs = @()
            foreach ($c in $scan.Candidates) {
                $o = $null; try { $o = $c | ConvertFrom-Json } catch { $o = $null }
                if ($o) { $objs += $o }
            }
            if ($objs.Count -ne 1) {
                if ((($scan.Unbalanced) -or (([string]$content) -eq '')) -and ($attempt -lt $maxAttempts)) { continue }
                return @{ tier = $fallbackTier; risk = 'MEDIUM'; reason = 'FALLBACK: ambiguous/malformed routing response'; source = 'FALLBACK' }
            }
            $o = $objs[0]
            $tProp = $o.PSObject.Properties['tier']
            if (-not $tProp) { return @{ tier = $fallbackTier; risk = 'MEDIUM'; reason = 'FALLBACK: routing schema missing tier'; source = 'FALLBACK' } }
            $tier = ([string]$tProp.Value).ToUpperInvariant()
            if (@('FLASH', 'FULL') -notcontains $tier) {
                return @{ tier = $fallbackTier; risk = 'MEDIUM'; reason = 'FALLBACK: invalid routing tier'; source = 'FALLBACK' }
            }
            $risk = 'MEDIUM'
            $rProp = $o.PSObject.Properties['risk']
            if ($rProp -and ([string]$rProp.Value)) { $risk = ([string]$rProp.Value).ToUpperInvariant() }
            if (@('LOW', 'MEDIUM', 'HIGH', 'CRITICAL') -notcontains $risk) { $risk = 'MEDIUM' }
            # HIGH/CRITICAL is always FULL; MEDIUM follows the fallback domain default.
            if (($risk -eq 'HIGH') -or ($risk -eq 'CRITICAL')) { $tier = 'FULL' }
            elseif ($risk -eq 'LOW') { $tier = 'FLASH' }
            elseif ($risk -eq 'MEDIUM' -and $tier -ne 'FULL') { if ($fallbackTier -eq 'FULL') { $tier = 'FULL' } }
            $reason = 'no reason provided'
            $rP = $o.PSObject.Properties['reason']
            if ($rP -and -not [string]::IsNullOrWhiteSpace([string]$rP.Value)) { $reason = [string]$rP.Value }
            return @{ tier = $tier; risk = $risk; reason = (Convert-SanitizeSecrets $reason); source = 'DEEPSEEK' }
        } catch {
            $msg = $_.Exception.Message
            if (($attempt -lt $maxAttempts) -and (Test-TransientHttpError -Message $msg)) { continue }
            return @{ tier = $fallbackTier; risk = 'MEDIUM'; reason = 'FALLBACK: routing API unavailable — safe default by domain'; source = 'FALLBACK' }
        }
    }
    return @{ tier = $fallbackTier; risk = 'MEDIUM'; reason = 'FALLBACK: routing failed'; source = 'FALLBACK' }
}

function Invoke-Routing {
    # Deterministic-first routing. Local critical classification can never be
    # downgraded; an escalated task stays FULL for its whole active task.
    param([string]$Task, [hashtable]$State)

    $tier = $null; $risk = 'LOW'; $reason = ''; $source = 'LOCAL'
    if ([string]$State.risk_discovered) {
        $tier = 'FULL'; $risk = 'HIGH'
        $reason = ("task revealed risk mid-task: {0}" -f [string]$State.risk_discovered)
    } elseif ($State.escalated_full) {
        $tier = 'FULL'; $risk = 'HIGH'
        $reason = ('previously escalated; no same-task downgrade: {0}' -f [string]$State.escalation_reason)
    } elseif (Test-IsCriticalTask -Task $Task) {
        $tier = 'FULL'; $risk = 'HIGH'; $reason = 'deterministic critical domain keyword'
    } elseif (Test-IsRoutineTask -Task $Task) {
        $tier = 'FLASH'; $risk = 'LOW'; $reason = 'deterministic routine domain keyword'
    } else {
        $r = Invoke-DeepSeekRouting -Task $Task
        $tier = $r.tier; $risk = $r.risk; $reason = $r.reason; $source = $r.source
    }
    $cfg = Get-SupervisorConfig
    $map = $cfg.model_routing.$tier
    return @{
        tier = $tier; risk = $risk; reason = $reason; source = $source
        agent = [string]$map.agent
        model_alias = [string]$map.model_alias
        effective_target = [string]$map.effective_target
    }
}

# ---------------------------------------------------------- escalation

function Update-FixEscalation {
    # Two FIX/gate cycles with the same root-cause signature escalate to FULL.
    # NOTE: param binds IDictionary by reference (hashtable AND ordered);
    # a [hashtable] cast would silently copy an OrderedDictionary and lose
    # the mutation.
    param([System.Collections.IDictionary]$State, [string]$Signature, [string]$Reason)
    $sig = ([string]$Signature -replace '\s+', ' ').Trim().ToLowerInvariant()
    if ($sig.Length -gt 60) { $sig = $sig.Substring(0, 60) }
    if ($State.fix_signature -eq $sig) { $State.fix_cycles = [int]$State.fix_cycles + 1 }
    else { $State.fix_signature = $sig; $State.fix_cycles = 1 }
    if (($State.fix_cycles -ge 2) -and (-not $State.escalated_full)) {
        $State.escalated_full = $true
        $State.escalation_reason = "repeated same-root failure: $Reason"
    }
    return $State
}

# ------------------------------------------------------------ stop hook

function Invoke-StopHook {
    # Returns @{ action = allow|block|stop|stop-success ; reason ; system_message }
    param([object]$InputData)

    if (Get-InputValue -Obj $InputData -Property 'stop_hook_active') {
        return @{ action = 'allow' }   # official recursion guard
    }
    $st = Get-AutonomousState
    if ((-not $st.autonomous_mode) -or $st.state_corrupt) {
        return @{ action = 'allow' }   # not active / corrupt -> never interfere
    }
    $cfg = Get-SupervisorConfig
    $maxIter = [int]$cfg.max_review_iterations

    # Human-only gate pending: STOP, tell the human, preserve all work.
    if ($st.human_gate_pending) {
        $reason = ('Human-only gate pending: {0}' -f [string]$st.human_gate_reason)
        $st.autonomous_mode = $false
        $st.last_decision = 'STOP'; $st.last_reason = $reason
        Set-AutonomousState -State $st
        return @{ action = 'stop'; reason = $reason }
    }

    # Iteration bound exhausted: STOP and return control to the human.
    if ([int]$st.iteration -ge $maxIter) {
        $reason = ("Autonomous review iteration bound reached ({0}) — returning control to the human. All work is preserved; nothing was reset." -f $maxIter)
        $st.autonomous_mode = $false
        $st.last_decision = 'STOP'; $st.last_reason = $reason
        Set-AutonomousState -State $st
        return @{ action = 'stop'; reason = $reason }
    }

    # Local deterministic verification gates (never consult the model):
    # failed/missing tests or a dirty whitespace check can never produce PASS.
    $gate = $null
    if (-not $st.tests_run) {
        $gate = 'Required verification was not run. Run the task acceptance tests, record the results (SupervisorStateUpdate), then finish.'
    } elseif ([int]$st.tests_failed -gt 0) {
        $gate = ("Required verification failed ({0} failing test(s)). Fix the failures, rerun the tests, then finish." -f [int]$st.tests_failed)
    } else {
        $ws = [string](Invoke-WhitespaceCheck)
        if ($ws) { $gate = 'git diff --check failed (whitespace/conflict markers). Resolve it, then finish.' }
    }
    if ($gate) {
        $st.iteration = [int]$st.iteration + 1
        $null = Update-FixEscalation -State $st -Signature $gate -Reason 'verification gate'
        $st.last_decision = 'FIX'; $st.last_reason = $gate
        Set-AutonomousState -State $st
        return @{ action = 'block'; reason = $gate }
    }

    $lastMsg = [string](Get-InputValue -Obj $InputData -Property 'last_assistant_message')
    $evidence = New-Evidence -State $st -LastAssistantMessage $lastMsg
    $review = Invoke-DeepSeekReview -Evidence $evidence

    switch ($review.decision) {
        'PASS' {
            if (-not [string]::IsNullOrWhiteSpace([string]$st.planned_next_task)) {
                # A next explicitly-known task remains: continue as NEXT.
                $next = [string]$st.planned_next_task
                $st.iteration = 0; $st.fix_cycles = 0; $st.fix_signature = ''
                $st.repeated_failures = 0; $st.failure_signature = ''
                $st.escalated_full = $false; $st.escalation_reason = ''
                $st.task = $next; $st.tier = ''; $st.agent = ''
                $st.tests_run = $false; $st.tests_total = 0; $st.tests_passed = 0
                $st.tests_failed = 0; $st.verify_exit = $null; $st.verify_command = ''
                $st.last_decision = 'NEXT'; $st.last_reason = "accepted; next task: $next"
                $st.planned_next_task = ''
                Set-AutonomousState -State $st
                return @{ action = 'block'; reason = ("Supervisor PASS. Next bounded task: {0}" -f $next) }
            }
            $reason = ('Autonomous supervisor PASS: {0}' -f [string]$review.reason)
            $st.autonomous_mode = $false
            $st.last_decision = 'PASS'; $st.last_reason = [string]$review.reason
            Set-AutonomousState -State $st
            return @{ action = 'stop-success'; reason = $reason }
        }
        'FIX' {
            $instr = [string]$review.fix_instructions
            if ([string]::IsNullOrWhiteSpace($instr)) { $instr = [string]$review.reason }
            $st.iteration = [int]$st.iteration + 1
            $null = Update-FixEscalation -State $st -Signature $instr -Reason ([string]$review.reason)
            $st.last_decision = 'FIX'; $st.last_reason = [string]$review.reason
            Set-AutonomousState -State $st
            return @{ action = 'block'; reason = ("Supervisor FIX: {0}" -f $instr) }
        }
        'NEXT' {
            $next = [string]$review.next_task
            if ([string]::IsNullOrWhiteSpace($next)) {
                # NEXT without a task is ambiguous -> STOP, never guess.
                $reason = 'FALLBACK: NEXT decision without a next task — ambiguous review'
                $st.autonomous_mode = $false
                $st.last_decision = 'STOP'; $st.last_reason = $reason
                Set-AutonomousState -State $st
                return @{ action = 'stop'; reason = $reason }
            }
            $st.iteration = 0; $st.fix_cycles = 0; $st.fix_signature = ''
            $st.repeated_failures = 0; $st.failure_signature = ''
            $st.escalated_full = $false; $st.escalation_reason = ''
            $st.task = $next; $st.tier = ''; $st.agent = ''
            $st.tests_run = $false; $st.tests_total = 0; $st.tests_passed = 0
            $st.tests_failed = 0; $st.verify_exit = $null; $st.verify_command = ''
            $st.last_decision = 'NEXT'; $st.last_reason = "next task: $next"
            Set-AutonomousState -State $st
            return @{ action = 'block'; reason = ("Supervisor NEXT. Next bounded task: {0}" -f $next) }
        }
        default {   # STOP and every failure fallback
            $reason = ('Autonomous supervisor STOP: {0}' -f [string]$review.reason)
            $st.autonomous_mode = $false
            $st.last_decision = 'STOP'; $st.last_reason = [string]$review.reason
            Set-AutonomousState -State $st
            return @{ action = 'stop'; reason = $reason }
        }
    }
}

# ----------------------------------------------------- UserPromptSubmit

function Invoke-UserPromptSubmit {
    param([object]$InputData)
    $prompt = [string](Get-InputValue -Obj $InputData -Property 'prompt')
    if (-not (Test-IsActivationPrompt -Prompt $prompt)) { return $null }   # normal conversation: no effect
    $session = [string](Get-InputValue -Obj $InputData -Property 'session_id')
    $null = Initialize-AutonomousSession -SessionId $session
    $ctx = @'
AUTONOMOUS SUPERVISOR MODE ACTIVATED (project-local DeepSeek supervisor).
You are now the orchestrator. Do NOT implement large changes yourself. Follow the procedure in .claude/SUPERVISOR.md:
1. Inspect ACTUAL project state (git status/log, docs) and determine the current milestone; if evidence is insufficient to safely determine the next task, STOP and ask the human.
2. Pick exactly ONE bounded task with explicit acceptance criteria.
3. Record milestone/task/acceptance in supervisor state via SupervisorStateUpdate (see SUPERVISOR.md), and route the task: deterministic rules first (.claude/supervisor/config.json), DeepSeek only for ambiguity. Delegate to the routed subagent (routine-implementer or critical-implementer).
4. Run the required verification, record the REAL results via SupervisorStateUpdate (tests_run, tests_passed, tests_failed, verify_exit), then finish your turn.
5. The Stop hook sends real evidence to DeepSeek for independent review: FIX -> you receive fix instructions and continue; NEXT -> you receive the next task and continue; PASS/STOP -> the loop ends. The coding agent never self-certifies success.
Human-only gates (push, deploy, destructive production actions) always stop for the human.
'@
    return @{
        hookSpecificOutput = @{ hookEventName = 'UserPromptSubmit'; additionalContext = $ctx }
        systemMessage = 'Autonomous supervisor mode activated.'
    }
}

# ---------------------------------------------------------------- main

if (-not $env:CLAUDE_SUPERVISOR_LIB) {
    try {
        $raw = [Console]::In.ReadToEnd()
        $InputData = $raw | ConvertFrom-Json
        $event = [string]$InputData.hook_event_name
        switch ($event) {
            'UserPromptSubmit' {
                $out = Invoke-UserPromptSubmit -InputData $InputData
                if ($out) { $out | ConvertTo-Json -Depth 5 -Compress | Write-Output }
                exit 0
            }
            'Stop' {
                $r = Invoke-StopHook -InputData $InputData
                switch ($r.action) {
                    'block' {
                        @{ hookSpecificOutput = @{ hookEventName = 'Stop'; decision = 'block'; reason = $r.reason } } |
                            ConvertTo-Json -Depth 5 -Compress | Write-Output
                        exit 0
                    }
                    'allow' { exit 0 }
                    default {
                        # stop / stop-success: allow termination, tell the human why.
                        @{ systemMessage = $r.reason } | ConvertTo-Json -Depth 3 -Compress | Write-Output
                        exit 0
                    }
                }
            }
            'SupervisorStateUpdate' {
                # Orchestrator -> state CLI. Whitelisted fields only.
                $st = Get-AutonomousState
                if (($st.autonomous_mode) -and (-not $st.state_corrupt)) {
                    $upd = Get-InputValue -Obj $InputData -Property 'update'
                    $ht = @{}
                    if ($upd) { foreach ($p in $upd.PSObject.Properties) { $ht[$p.Name] = $p.Value } }
                    $null = Invoke-StateUpdate -State $st -Updates $ht
                }
                exit 0
            }
            default { exit 0 }
        }
    } catch {
        exit 0   # hooks must never break Claude Code
    }
}
