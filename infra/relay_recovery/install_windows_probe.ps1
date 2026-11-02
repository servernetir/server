#Requires -Version 5.1
#Requires -RunAsAdministrator
<#
One-time VM109 console installer, AFTER reviewing/parsing windows_probe.ps1.
Fixed paths, URL, firewall scope and SYSTEM startup task only. No credentials
or accounts are replaced. Existing probe.key is preserved; on first install,
enter a distinct random 64-hex key with secure Read-Host, matching the RAW32-byte
private PVE key. No key, BSTR text, raw curl output or credential is printed.
No global firewall/proxy/TLS/execution-policy setting is changed.
#>
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$Root = 'C:\ProgramData\ServerNet\RelayRecovery'
$Parent = 'C:\ProgramData\ServerNet'
$Runtime = Join-Path $Root 'windows_probe.ps1'
$ConfigFile = Join-Path $Root 'config.json'
$KeyFile = Join-Path $Root 'probe.key'
$Source = Join-Path $PSScriptRoot 'windows_probe.ps1'
$Prefix = 'http://10.10.10.50:19443/relay-probe/'
$TaskName = 'ServerNet-VM109-RelayProbe'
$AllowRule = 'ServerNet-VM109-RelayProbe-PVEOnly'
$BlockRule = 'ServerNet-VM109-RelayProbe-BlockOthers'
$BlockAddresses = @('0.0.0.0-10.10.10.0', '10.10.10.2-255.255.255.255')
$SystemSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-18')
$AdminSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-32-544')
$Utf8 = [Text.UTF8Encoding]::new($false, $true)
$powershell = Join-Path ([Environment]::SystemDirectory) 'WindowsPowerShell\v1.0\powershell.exe'
$arguments = '-NoLogo -NoProfile -NonInteractive -ExecutionPolicy RemoteSigned -File "C:\ProgramData\ServerNet\RelayRecovery\windows_probe.ps1"'
$netsh = Join-Path ([Environment]::SystemDirectory) 'netsh.exe'

function Assert-NoReparse([string]$File) {
    if ((Get-Item -LiteralPath $File -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'private_path_reparse' }
}
function Assert-TrustedExisting([string]$File) {
    Assert-NoReparse $File
    $acl = Get-Acl -LiteralPath $File
    if ($acl.GetOwner([Security.Principal.SecurityIdentifier]).Value -notin @($SystemSid.Value, $AdminSid.Value)) { throw 'private_parent_owner_review_required' }
    $writes = [Security.AccessControl.FileSystemRights]::Write -bor [Security.AccessControl.FileSystemRights]::Delete -bor [Security.AccessControl.FileSystemRights]::DeleteSubdirectoriesAndFiles -bor [Security.AccessControl.FileSystemRights]::ChangePermissions -bor [Security.AccessControl.FileSystemRights]::TakeOwnership
    foreach ($rule in $acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier])) {
        if ($rule.AccessControlType -eq 'Allow' -and $rule.IdentityReference.Value -notin @($SystemSid.Value, $AdminSid.Value) -and ($rule.FileSystemRights -band $writes) -ne 0) { throw 'private_parent_writable_review_required' }
    }
}
function Set-PrivateAcl([string]$File, [bool]$Directory = $false) {
    Assert-NoReparse $File
    $acl = if ($Directory) { [Security.AccessControl.DirectorySecurity]::new() } else { [Security.AccessControl.FileSecurity]::new() }
    $acl.SetOwner($AdminSid); $acl.SetAccessRuleProtection($true, $false)
    foreach ($sid in @($SystemSid, $AdminSid)) {
        $rule = if ($Directory) {
            [Security.AccessControl.FileSystemAccessRule]::new($sid, [Security.AccessControl.FileSystemRights]::FullControl, ([Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [Security.AccessControl.InheritanceFlags]::ObjectInherit), [Security.AccessControl.PropagationFlags]::None, [Security.AccessControl.AccessControlType]::Allow)
        } else {
            [Security.AccessControl.FileSystemAccessRule]::new($sid, [Security.AccessControl.FileSystemRights]::FullControl, [Security.AccessControl.AccessControlType]::Allow)
        }
        [void]$acl.AddAccessRule($rule)
    }
    Set-Acl -LiteralPath $File -AclObject $acl
}
function Ensure-FirewallRule([string]$Name, [string]$Action, [string[]]$Remote) {
    $existing = @(Get-NetFirewallRule -Name $Name -ErrorAction SilentlyContinue)
    if ($existing.Count -gt 0) {
        if ($existing.Count -ne 1 -or $existing[0].Direction -ne 'Inbound' -or $existing[0].Action -ne $Action -or [int]$existing[0].Profile -notin @(0, 7)) { throw 'firewall_name_conflict' }
        $port = $existing[0] | Get-NetFirewallPortFilter
        $address = $existing[0] | Get-NetFirewallAddressFilter
        if ([string]$port.Protocol -notin @('TCP', '6') -or [string]$port.LocalPort -ne '19443' -or @($address.LocalAddress).Count -ne 1 -or [string]$address.LocalAddress[0] -ne '10.10.10.50' -or @(Compare-Object @($address.RemoteAddress | Sort-Object) @($Remote | Sort-Object)).Count -ne 0) { throw 'firewall_scope_conflict' }
        Set-NetFirewallRule -Name $Name -Enabled True
    } else {
        New-NetFirewallRule -Name $Name -DisplayName $Name -Direction Inbound -Action $Action -Protocol TCP -LocalAddress '10.10.10.50' -LocalPort 19443 -RemoteAddress $Remote -Profile Any -Enabled True | Out-Null
    }
}
function Hex-Nibble([int]$Code) {
    if ($Code -ge 48 -and $Code -le 57) { return $Code - 48 }
    if ($Code -ge 65 -and $Code -le 70) { return $Code - 55 }
    if ($Code -ge 97 -and $Code -le 102) { return $Code - 87 }
    throw 'key_hex_format'
}

if (-not (Test-Path -LiteralPath $Source -PathType Leaf)) { throw 'reviewed_runtime_missing' }
# Actual native parser validation precedes every installation mutation.
$tokens = $null; $parseErrors = $null
[void][System.Management.Automation.Language.Parser]::ParseFile($Source, [ref]$tokens, [ref]$parseErrors)
if (@($parseErrors).Count -ne 0) { throw 'runtime_parse_failed' }
if (-not [Environment]::Is64BitProcess) { throw 'requires_64_bit_powershell' }
if (@(Get-NetFirewallProfile -PolicyStore ActiveStore | Where-Object { [string]$_.Enabled -ne 'True' -or [string]$_.AllowLocalFirewallRules -eq 'False' }).Count -ne 0) { throw 'firewall_disabled_or_gpo_excluded_review_required' }
$addresses = @(Get-NetIPAddress -IPAddress '10.10.10.50' -AddressFamily IPv4 -ErrorAction Stop)
if ($addresses.Count -ne 1 -or $addresses[0].AddressState -ne 'Preferred') { throw 'vm109_fixed_address_missing' }
# Preflight namespace ownership BEFORE creating/changing firewall rules. A
# foreign HTTP.sys queue may share a port even when its URL prefix differs.
$existingTask = Get-ScheduledTask -TaskName $TaskName -TaskPath '\' -ErrorAction SilentlyContinue
if ($null -ne $existingTask -and (@($existingTask.Actions).Count -ne 1 -or $existingTask.Actions[0].Execute -cne $powershell -or $existingTask.Actions[0].Arguments -cne $arguments -or $existingTask.Principal.UserId -notin @('SYSTEM', 'S-1-5-18', 'NT AUTHORITY\SYSTEM'))) { throw 'scheduled_task_name_conflict' }
$reservations = & $netsh http show urlacl 2>$null
if ($LASTEXITCODE -ne 0) { throw 'urlacl_inventory_unavailable' }
$portPrefixes = @([regex]::Matches(($reservations -join "`n"), '(?i)https?://[^\s]+') | ForEach-Object { $_.Value } | Where-Object { $_ -match ':19443/' })
if (@($portPrefixes | Where-Object { $_ -cne $Prefix }).Count -ne 0) { throw 'foreign_port_prefix_conflict' }
$reservePrefix = $portPrefixes.Count -eq 0
if (-not $reservePrefix) {
    if ($null -eq $existingTask) { throw 'existing_prefix_owner_unverified' }
    $reservation = & $netsh http show urlacl "url=$Prefix" 2>$null
    $sddl = [regex]::Match(($reservation -join "`n"), 'D:\([^\r\n]+').Value.Trim()
    if ($LASTEXITCODE -ne 0 -or $sddl -cne 'D:(A;;GX;;;SY)') { throw 'urlacl_existing_conflict' }
}
if ($null -ne $existingTask) {
    # Validate the new reviewed runtime against the existing private config
    # before stopping the exact owned monitor. No listener or curl is started.
    & $Source -ValidateOnly | Out-Null
    if ($existingTask.State -eq 'Running') {
        Stop-ScheduledTask -TaskName $TaskName -TaskPath '\'
        $timer = [Diagnostics.Stopwatch]::StartNew()
        while ((Get-ScheduledTask -TaskName $TaskName -TaskPath '\').State -eq 'Running') {
            if ($timer.ElapsedMilliseconds -gt 5000) { throw 'old_owned_task_did_not_stop' }
            Start-Sleep -Milliseconds 100
        }
    }
}
$timer = [Diagnostics.Stopwatch]::StartNew()
while (@(Get-NetTCPConnection -State Listen -ErrorAction Stop | Where-Object { $_.LocalPort -eq 19443 }).Count -ne 0) {
    if ($null -eq $existingTask -or $timer.ElapsedMilliseconds -gt 5000) { throw 'port19443_owner_unverified' }
    Start-Sleep -Milliseconds 100
}
Assert-NoReparse 'C:\ProgramData'
if (Test-Path -LiteralPath $Parent) { Assert-TrustedExisting $Parent } else { [void][IO.Directory]::CreateDirectory($Parent); Set-PrivateAcl $Parent $true }
if (Test-Path -LiteralPath $Root) { Assert-TrustedExisting $Root } else { [void][IO.Directory]::CreateDirectory($Root) }
Set-PrivateAcl $Root $true
foreach ($file in @($Runtime, $ConfigFile, $KeyFile)) { if (Test-Path -LiteralPath $file) { Assert-TrustedExisting $file } }
$sourceBytes = [IO.File]::ReadAllBytes($Source)
[IO.File]::WriteAllBytes($Runtime, $sourceBytes)
Set-PrivateAcl $Runtime
if (-not (Test-Path -LiteralPath $ConfigFile)) {
    $config = [ordered]@{ schema = 'servernet.relay.windows-probe.v1'; agent_id = 'pve-ir' } | ConvertTo-Json -Compress
    [IO.File]::WriteAllBytes($ConfigFile, $Utf8.GetBytes($config))
}
Set-PrivateAcl $ConfigFile
if (Test-Path -LiteralPath $KeyFile) {
    if ((Get-Item -LiteralPath $KeyFile).Length -ne 32) { throw 'existing_probe_key_invalid_preserved' }
} else {
    $secure = Read-Host 'Distinct VM109 probe key (64 hex characters, hidden)' -AsSecureString
    $pointer = [IntPtr]::Zero; $bytes = [byte[]]::new(32)
    try {
        if ($secure.Length -ne 64) { throw 'key_length' }
        $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
        for ($i = 0; $i -lt 32; $i++) {
            $first = Hex-Nibble ([Runtime.InteropServices.Marshal]::ReadInt16($pointer, $i * 4))
            $second = Hex-Nibble ([Runtime.InteropServices.Marshal]::ReadInt16($pointer, $i * 4 + 2))
            $bytes[$i] = [byte](16 * $first + $second)
        }
        $file = [IO.File]::Open($KeyFile, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
        try { $file.Write($bytes, 0, $bytes.Length); $file.Flush($true) } finally { $file.Dispose() }
    } finally {
        if ($pointer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
        [Array]::Clear($bytes, 0, $bytes.Length); $secure.Dispose()
    }
}
Set-PrivateAcl $KeyFile
Ensure-FirewallRule $BlockRule 'Block' $BlockAddresses
Ensure-FirewallRule $AllowRule 'Allow' @('10.10.10.1')
if ($reservePrefix) {
    & $netsh http add urlacl "url=$Prefix" 'sddl=D:(A;;GX;;;SY)' | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'urlacl_install_failed' }
}
# Validate wire, private ACLs and actual effective firewall before activation.
& $Runtime -ValidateOnly | Out-Null
$action = New-ScheduledTaskAction -Execute $powershell -Argument $arguments
$trigger = New-ScheduledTaskTrigger -AtStartup
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -RestartCount 999 -RestartInterval ([TimeSpan]::FromMinutes(1)) -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
Register-ScheduledTask -TaskName $TaskName -TaskPath '\' -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
Start-ScheduledTask -TaskName $TaskName -TaskPath '\'
$timer = [Diagnostics.Stopwatch]::StartNew()
while ((Get-ScheduledTask -TaskName $TaskName -TaskPath '\').State -ne 'Running') {
    if ($timer.ElapsedMilliseconds -gt 5000) { throw 'new_task_not_running' }
    Start-Sleep -Milliseconds 100
}
Write-Output 'VM109 probe installed; end-to-end signed PVE validation is still required.'
