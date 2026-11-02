#Requires -Version 5.1
<#
VM109 fixed read-only guest probe. Run as SYSTEM via the reviewed installer.
No network-supplied path, URL, command, argv, credentials or administration.
Only private PVE 10.10.10.1 may call POST /relay-probe/ on 10.10.10.50:19443.
HMAC authenticates BEFORE durable nonce claim and BEFORE starting fixed curl.
ValidateOnly verifies the public wire self-test, ACLs/config/firewall and exits;
it never starts the listener or curl. Native PS5.1 parser/runtime must be checked
on Windows; static tests on Linux do not establish Windows execution success.
#>
param([switch]$ValidateOnly)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$Root = 'C:\ProgramData\ServerNet\RelayRecovery'
$ConfigFile = Join-Path $Root 'config.json'
$KeyFile = Join-Path $Root 'probe.key'
$NonceFile = Join-Path $Root 'nonces.json'
$LockFile = Join-Path $Root 'nonces.lock'
$Prefix = 'http://10.10.10.50:19443/relay-probe/'
$Path = '/relay-probe/'
$Utf8 = [Text.UTF8Encoding]::new($false, $true)
$MaxBody = 8192
$MaxNonces = 256
$SystemSid = 'S-1-5-18'
$AdminSid = 'S-1-5-32-544'
$AllowRule = 'ServerNet-VM109-RelayProbe-PVEOnly'
$BlockRule = 'ServerNet-VM109-RelayProbe-BlockOthers'
$BlockAddresses = @('0.0.0.0-10.10.10.0', '10.10.10.2-255.255.255.255')

function Get-UnixTime { return [DateTimeOffset]::UtcNow.ToUnixTimeSeconds() }

function Assert-PrivateAcl([string]$File, [bool]$Directory = $false, [bool]$Protected = $true) {
    $item = Get-Item -LiteralPath $File -Force
    if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0 -or $item.PSIsContainer -ne $Directory) { throw 'private_path' }
    $acl = Get-Acl -LiteralPath $File
    if ($Protected -and -not $acl.AreAccessRulesProtected) { throw 'private_inheritance' }
    if ($acl.GetOwner([Security.Principal.SecurityIdentifier]).Value -notin @($SystemSid, $AdminSid)) { throw 'private_owner' }
    foreach ($rule in $acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier])) {
        if ($rule.IdentityReference.Value -notin @($SystemSid, $AdminSid) -or $rule.AccessControlType -ne 'Allow') { throw 'private_acl' }
    }
}

function Assert-Firewall {
    if (@(Get-NetFirewallProfile -PolicyStore ActiveStore | Where-Object { [string]$_.Enabled -ne 'True' -or [string]$_.AllowLocalFirewallRules -eq 'False' }).Count -ne 0) { throw 'firewall_disabled_or_gpo_excluded' }
    foreach ($spec in @(@($AllowRule, 'Allow'), @($BlockRule, 'Block'))) {
        $rules = @(Get-NetFirewallRule -PolicyStore ActiveStore -Name $spec[0] -ErrorAction Stop)
        if ($rules.Count -ne 1 -or $rules[0].Enabled -ne 'True' -or $rules[0].Direction -ne 'Inbound' -or $rules[0].Action -ne $spec[1] -or [int]$rules[0].Profile -notin @(0, 7) -or [string]$rules[0].PrimaryStatus -ne 'OK' -or @($rules[0].EnforcementStatus).Count -ne 1 -or [string]$rules[0].EnforcementStatus[0] -ne 'Full') { throw 'firewall_rule_not_effective' }
        $port = $rules[0] | Get-NetFirewallPortFilter
        $address = $rules[0] | Get-NetFirewallAddressFilter
        if ([string]$port.Protocol -notin @('TCP', '6') -or [string]$port.LocalPort -ne '19443' -or @($address.LocalAddress).Count -ne 1 -or [string]$address.LocalAddress[0] -ne '10.10.10.50') { throw 'firewall_scope' }
        $expected = if ($spec[1] -eq 'Allow') { @('10.10.10.1') } else { $BlockAddresses }
        if (@(Compare-Object @($address.RemoteAddress | Sort-Object) @($expected | Sort-Object)).Count -ne 0) { throw 'firewall_remote' }
    }
}

function Get-Hex([byte[]]$Bytes) { return [BitConverter]::ToString($Bytes).Replace('-', '').ToLowerInvariant() }
function Get-Hash([byte[]]$Body) {
    $hash = [Security.Cryptography.SHA256]::Create()
    try { return Get-Hex ($hash.ComputeHash($Body)) } finally { $hash.Dispose() }
}
function Get-Signature([byte[]]$Key, [string]$Method, [string]$Timestamp, [string]$Nonce, [byte[]]$Body) {
    $text = @('SN-VM-PROBE/1', $Method, $Path, $Timestamp, $Nonce, (Get-Hash $Body)) -join "`n"
    $mac = [Security.Cryptography.HMACSHA256]::new($Key)
    try { return Get-Hex ($mac.ComputeHash($Utf8.GetBytes($text))) } finally { $mac.Dispose() }
}
function Test-FixedEqual([string]$Left, [string]$Right) {
    if ($Left.Length -ne 64 -or $Right.Length -ne 64) { return $false }
    $difference = 0
    for ($i = 0; $i -lt 64; $i++) { $difference = $difference -bor ([int][char]$Left[$i] -bxor [int][char]$Right[$i]) }
    return $difference -eq 0
}
function New-Nonce {
    $bytes = [byte[]]::new(16)
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes); return Get-Hex $bytes } finally { $rng.Dispose() }
}
function Assert-WireSelfTest {
    # Public fixture; this is never the installation key.
    $signature = Get-Signature ([byte[]](0..31)) 'POST' '1800000000' ('0' * 32) ($Utf8.GetBytes('{"op":"probe"}'))
    if ($signature -cne '3e25b4f0216aa9b4bb5c2cc811d9d25059908c6cdf8d148795516fab29d93577') { throw 'wire_self_test' }
}
function Assert-Fields($Object, [string[]]$Expected) {
    if ($null -eq $Object -or $Object -isnot [PSCustomObject] -or @(Compare-Object @($Object.PSObject.Properties.Name | Sort-Object) @($Expected | Sort-Object)).Count -ne 0) { throw 'body_fields' }
}
function Test-Integer($Value, [long]$Maximum) {
    return ($Value -is [int] -or $Value -is [long]) -and $Value -ge 1 -and $Value -le $Maximum
}
function Test-PublicIPv4($Value) {
    if ($Value -isnot [string]) { return $false }
    $address = $null
    if (-not [Net.IPAddress]::TryParse($Value, [ref]$address) -or $address.AddressFamily -ne [Net.Sockets.AddressFamily]::InterNetwork -or $address.ToString() -cne $Value) { return $false }
    $b = $address.GetAddressBytes()
    if ($b[0] -in @(0, 10, 127) -or $b[0] -ge 224 -or ($b[0] -eq 100 -and $b[1] -ge 64 -and $b[1] -le 127) -or ($b[0] -eq 169 -and $b[1] -eq 254) -or ($b[0] -eq 172 -and $b[1] -ge 16 -and $b[1] -le 31) -or ($b[0] -eq 192 -and $b[1] -eq 168) -or ($b[0] -eq 192 -and $b[1] -eq 0) -or ($b[0] -eq 198 -and $b[1] -in @(18, 19)) -or ($b[0] -eq 198 -and $b[1] -eq 51 -and $b[2] -eq 100) -or ($b[0] -eq 203 -and $b[1] -eq 0 -and $b[2] -eq 113)) { return $false }
    return $true
}

function Read-Body($Request) {
    if ($Request.ContentLength64 -lt 1 -or $Request.ContentLength64 -gt $MaxBody -or $Request.Headers['Transfer-Encoding']) { throw 'body_size' }
    $buffer = [byte[]]::new([int]$Request.ContentLength64)
    $offset = 0; $timer = [Diagnostics.Stopwatch]::StartNew()
    while ($offset -lt $buffer.Length) {
        $remaining = 1500 - [int]$timer.ElapsedMilliseconds
        if ($remaining -le 0) { throw 'body_timeout' }
        $task = $Request.InputStream.ReadAsync($buffer, $offset, $buffer.Length - $offset)
        if (-not $task.Wait($remaining)) { throw 'body_timeout' }
        $count = $task.GetAwaiter().GetResult()
        if ($count -le 0) { throw 'body_closed' }
        $offset += $count
    }
    return ,$buffer
}

function Claim-Nonce([string]$Nonce, [long]$Timestamp) {
    $lock = [IO.File]::Open($LockFile, [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
    $temporary = Join-Path $Root ('nonces-' + (New-Nonce) + '.tmp')
    try {
        Assert-PrivateAcl $LockFile $false $false
        $now = Get-UnixTime
        if ($now - $Timestamp -gt 30 -or $Timestamp - $now -gt 5) { throw 'request_expired' }
        $entries = @(); $high = $now
        if (Test-Path -LiteralPath $NonceFile) {
            Assert-PrivateAcl $NonceFile $false $false
            if ((Get-Item -LiteralPath $NonceFile).Length -gt 65536) { throw 'nonce_state_size' }
            $state = $Utf8.GetString([IO.File]::ReadAllBytes($NonceFile)) | ConvertFrom-Json
            Assert-Fields $state @('schema', 'high_water', 'entries')
            if ($state.schema -cne 'servernet.relay.vm-nonces.v1' -or -not (Test-Integer $state.high_water 999999999999) -or $now -lt $state.high_water - 2 -or @($state.entries).Count -gt $MaxNonces) { throw 'nonce_state' }
            $high = [Math]::Max($now, [long]$state.high_water)
            $seen = @{}
            foreach ($entry in @($state.entries)) {
                Assert-Fields $entry @('nonce', 'expires_at')
                if ($entry.nonce -isnot [string] -or $entry.nonce -cnotmatch '\A[0-9a-f]{32}\z' -or -not (Test-Integer $entry.expires_at 999999999999) -or $seen.ContainsKey($entry.nonce)) { throw 'nonce_state' }
                $seen[$entry.nonce] = $true
                if ([long]$entry.expires_at -ge $now) { $entries += $entry }
            }
        }
        if (@($entries | Where-Object { $_.nonce -ceq $Nonce }).Count -ne 0) { throw 'request_replay' }
        if ($entries.Count -ge $MaxNonces) { throw 'nonce_capacity' }
        $entries += [PSCustomObject]@{ nonce = $Nonce; expires_at = $Timestamp + 30 }
        $body = $Utf8.GetBytes(([ordered]@{ schema = 'servernet.relay.vm-nonces.v1'; high_water = $high; entries = @($entries) } | ConvertTo-Json -Depth 5 -Compress))
        $stream = [IO.File]::Open($temporary, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
        try { $stream.Write($body, 0, $body.Length); $stream.Flush($true) } finally { $stream.Dispose() }
        if (Test-Path -LiteralPath $NonceFile) { [IO.File]::Replace($temporary, $NonceFile, $null) } else { [IO.File]::Move($temporary, $NonceFile) }
        # Flush the published NTFS file before any guest probe is executed.
        $stream = [IO.File]::Open($NonceFile, [IO.FileMode]::Open, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
        try { $stream.Flush($true) } finally { $stream.Dispose() }
    } finally {
        if (Test-Path -LiteralPath $temporary) { Remove-Item -LiteralPath $temporary -Force }
        $lock.Dispose()
    }
}

function Invoke-FixedGuestProbe {
    $answer = @{ source_ip = $null; egress_ip = $null }
    $process = $null
    $started = $false
    try {
        $addresses = @(Get-NetIPAddress -IPAddress '10.10.10.50' -AddressFamily IPv4 -ErrorAction Stop)
        if ($addresses.Count -ne 1 -or $addresses[0].AddressState -ne 'Preferred') { return $answer }
        $answer.source_ip = '10.10.10.50'
        $start = [Diagnostics.ProcessStartInfo]::new()
        $start.FileName = Join-Path ([Environment]::SystemDirectory) 'curl.exe'
        # -q MUST be first: no curlrc. No proxy or network-supplied arguments.
        $start.Arguments = '-q -4 --noproxy "*" --proxy "" --interface 10.10.10.50 -fsS --max-time 4 --connect-timeout 3 --max-filesize 128 --proto "=https" https://api.ipify.org'
        $start.UseShellExecute = $false; $start.CreateNoWindow = $true
        $start.RedirectStandardOutput = $true; $start.RedirectStandardError = $true
        foreach ($name in @('HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'NO_PROXY', 'http_proxy', 'https_proxy', 'all_proxy', 'no_proxy', 'CURL_CA_BUNDLE', 'SSL_CERT_FILE', 'SSL_CERT_DIR')) { [void]$start.EnvironmentVariables.Remove($name) }
        $process = [Diagnostics.Process]::new(); $process.StartInfo = $start
        [void]$process.Start()
        $started = $true
        $outBuffer = [byte[]]::new(129); $errBuffer = [byte[]]::new(257)
        $outTask = $process.StandardOutput.BaseStream.ReadAsync($outBuffer, 0, $outBuffer.Length)
        $errTask = $process.StandardError.BaseStream.ReadAsync($errBuffer, 0, $errBuffer.Length)
        $outDone = $false; $errDone = $false; $outBytes = [IO.MemoryStream]::new(); $errCount = 0
        $timer = [Diagnostics.Stopwatch]::StartNew()
        try {
            while (-not $outDone -or -not $errDone -or -not $process.HasExited) {
                if ($timer.ElapsedMilliseconds -gt 4800) { throw 'curl_timeout' }
                if (-not $outDone -and $outTask.IsCompleted) {
                    $count = $outTask.GetAwaiter().GetResult()
                    if ($count -eq 0) { $outDone = $true } else {
                        if ($outBytes.Length + $count -gt 128) { throw 'curl_output' }
                        $outBytes.Write($outBuffer, 0, $count)
                        $outTask = $process.StandardOutput.BaseStream.ReadAsync($outBuffer, 0, $outBuffer.Length)
                    }
                }
                if (-not $errDone -and $errTask.IsCompleted) {
                    $count = $errTask.GetAwaiter().GetResult(); $errCount += $count
                    if ($errCount -gt 1024) { throw 'curl_error_output' }
                    if ($count -eq 0) { $errDone = $true } else { $errTask = $process.StandardError.BaseStream.ReadAsync($errBuffer, 0, $errBuffer.Length) }
                }
                [Threading.Thread]::Sleep(10)
            }
            if ($process.ExitCode -ne 0) { return $answer }
            $value = $Utf8.GetString($outBytes.ToArray()).Trim()
            if (Test-PublicIPv4 $value) { $answer.egress_ip = $value }
        } finally { $outBytes.Dispose() }
    } catch {
        # Never expose stderr, key bytes, commands, environment or raw output.
    } finally {
        if ($null -ne $process) {
            if ($started -and -not $process.HasExited) { $process.Kill(); [void]$process.WaitForExit(1000) }
            $process.Dispose()
        }
    }
    return $answer
}

function Send-Json($Response, [int]$Status, [byte[]]$Body, $Headers = $null) {
    $Response.StatusCode = $Status; $Response.ContentType = 'application/json; charset=utf-8'
    $Response.ContentLength64 = $Body.Length; $Response.KeepAlive = $false
    if ($null -ne $Headers) { foreach ($name in $Headers.Keys) { $Response.Headers[$name] = $Headers[$name] } }
    $Response.OutputStream.Write($Body, 0, $Body.Length)
}

Assert-WireSelfTest
if (-not $ValidateOnly -and [Security.Principal.WindowsIdentity]::GetCurrent().User.Value -cne $SystemSid) { throw 'system_task_required' }
foreach ($directory in @('C:\ProgramData', 'C:\ProgramData\ServerNet', $Root)) {
    if ((Get-Item -LiteralPath $directory -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'private_parent_reparse' }
}
Assert-PrivateAcl $Root $true
Assert-PrivateAcl $ConfigFile
Assert-PrivateAcl $KeyFile
Assert-PrivateAcl (Join-Path $Root 'windows_probe.ps1')
$Config = $Utf8.GetString([IO.File]::ReadAllBytes($ConfigFile)) | ConvertFrom-Json
Assert-Fields $Config @('schema', 'agent_id')
if ($Config.schema -cne 'servernet.relay.windows-probe.v1' -or $Config.agent_id -isnot [string] -or $Config.agent_id -cnotmatch '\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,95}\z') { throw 'config_schema' }
$Key = [IO.File]::ReadAllBytes($KeyFile)
if ($Key.Length -ne 32) { throw 'key_size' }
Assert-Firewall
if ($ValidateOnly) { Write-Output 'configuration_valid'; return }

$listener = [Net.HttpListener]::new(); $listener.Prefixes.Add($Prefix)
$listener.IgnoreWriteExceptions = $true
$listener.TimeoutManager.HeaderWait = [TimeSpan]::FromSeconds(2)
$listener.TimeoutManager.EntityBody = [TimeSpan]::FromSeconds(2)
$listener.TimeoutManager.IdleConnection = [TimeSpan]::FromSeconds(2)
try {
    $listener.Start()
    while ($listener.IsListening) {
        $context = $listener.GetContext()
        try {
            $request = $context.Request
            if ($request.RemoteEndPoint.Address.ToString() -cne '10.10.10.1' -or $request.LocalEndPoint.Address.ToString() -cne '10.10.10.50' -or $request.HttpMethod -cne 'POST' -or $request.RawUrl -cne $Path) { throw 'request_endpoint' }
            $body = Read-Body $request
            $timestamp = $request.Headers['X-VM-Timestamp']; $nonce = $request.Headers['X-VM-Nonce']; $signature = $request.Headers['X-VM-Signature']
            if ($null -eq $timestamp -or $null -eq $nonce -or $null -eq $signature -or $timestamp -cnotmatch '\A[1-9][0-9]{9,11}\z' -or $nonce -cnotmatch '\A[0-9a-f]{32}\z' -or $signature -cnotmatch '\A[0-9a-f]{64}\z') { throw 'request_auth' }
            $now = Get-UnixTime; $stamp = [long]$timestamp
            if ($now - $stamp -gt 30 -or $stamp - $now -gt 5 -or -not (Test-FixedEqual (Get-Signature $Key 'POST' $timestamp $nonce $body) $signature)) { throw 'request_auth' }
            $data = $Utf8.GetString($body) | ConvertFrom-Json
            Assert-Fields $data @('op', 'scope', 'target')
            Assert-Fields $data.scope @('agent_id', 'incident_id', 'generation', 'revision', 'config_hash', 'jobs_hash')
            Assert-Fields $data.target @('host', 'port', 'provider_id')
            $s = $data.scope; $t = $data.target
            if ($data.op -cne 'probe' -or $s.agent_id -cne $Config.agent_id -or $s.incident_id -isnot [string] -or $s.incident_id -cnotmatch '\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,95}\z' -or -not (Test-Integer $s.generation 9007199254740991) -or -not (Test-Integer $s.revision 9007199254740991) -or $s.config_hash -isnot [string] -or $s.config_hash -cnotmatch '\A[0-9a-f]{64}\z' -or $s.jobs_hash -isnot [string] -or $s.jobs_hash -cnotmatch '\A[0-9a-f]{64}\z') { throw 'request_scope' }
            if (-not (Test-PublicIPv4 $t.host) -or -not (Test-Integer $t.port 65535) -or $t.port -notin @(22, 443) -or $t.provider_id -isnot [string] -or $t.provider_id -cnotmatch '\A[1-9][0-9]{0,19}\z') { throw 'request_target' }
            $scope = [ordered]@{ agent_id = $s.agent_id; config_hash = $s.config_hash; generation = [long]$s.generation; incident_id = $s.incident_id; jobs_hash = $s.jobs_hash; revision = [long]$s.revision }
            $target = [ordered]@{ host = $t.host; port = [int]$t.port; provider_id = $t.provider_id }
            $canonicalBody = [ordered]@{ op = 'probe'; scope = $scope; target = $target } | ConvertTo-Json -Depth 6 -Compress
            # Reject duplicate keys, hidden fields or ambiguous/noncanonical JSON.
            if ($Utf8.GetString($body) -cne $canonicalBody) { throw 'request_canonical_json' }
            Claim-Nonce $nonce $stamp
            $observation = Invoke-FixedGuestProbe
            $good = $observation.source_ip -ceq '10.10.10.50' -and $observation.egress_ip -ceq $t.host
            $reply = [ordered]@{ schema = 'servernet.relay.vm-probe.v1'; op = 'probe'; request_nonce = $nonce; scope = $scope; target = $target; observed_at = (Get-UnixTime); read_only = $true; execution_origin = 'guest'; source_ip = $observation.source_ip; egress_ip = $observation.egress_ip; tunnel_ok = [bool]$good; probe_ok = [bool]$good; ok = [bool]$good }
            $raw = $Utf8.GetBytes(($reply | ConvertTo-Json -Depth 6 -Compress))
            $responseTimestamp = [string](Get-UnixTime); $responseNonce = New-Nonce
            $headers = @{ 'X-VM-Timestamp' = $responseTimestamp; 'X-VM-Nonce' = $responseNonce; 'X-VM-Signature' = (Get-Signature $Key 'RESPONSE' $responseTimestamp $responseNonce $raw) }
            Send-Json $context.Response 200 $raw $headers
        } catch {
            try { Send-Json $context.Response 403 ($Utf8.GetBytes('{"ok":false,"code":"probe_rejected"}')) } catch { }
        } finally {
            $context.Request.InputStream.Close(); $context.Response.Close()
        }
    }
} finally { $listener.Close(); [Array]::Clear($Key, 0, $Key.Length) }
