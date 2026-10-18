# Preparation-only: invoke manually INSIDE an explicitly approved disposable
# Windows test guest. Never run on the operator PC or a customer VM.
# No network calls, changes, passwords, logs, output files or arbitrary paths.
[CmdletBinding()]
param([switch]$CollectApprovedTestGuest)

# Dot-sourcing must never inspect the caller's machine, even with the switch.
if ($MyInvocation.InvocationName -eq '.') { return }
if (-not $CollectApprovedTestGuest) {
    Write-Output 'Not collected. Use -CollectApprovedTestGuest only inside an approved disposable Windows guest.'
    return
}

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'Evidence.psm1') -Force
$observations = @{}

if ([Environment]::OSVersion.Platform -eq [PlatformID]::Win32NT) {
    try {
        # This standard-path file is a candidate, not proof the service uses
        # it. Do not inspect service command lines, logs or credential fields.
        $programFiles = [Environment]::GetFolderPath([Environment+SpecialFolder]::ProgramFiles)
        $configPath = Join-Path $programFiles 'Cloudbase Solutions\Cloudbase-Init\conf\cloudbase-init.conf'
        $file = Get-Item -LiteralPath $configPath -ErrorAction Stop
        if (-not $file.PSIsContainer -and $file.Length -le 1048576 -and
            -not ($file.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
            $observations.ConfigText = [IO.File]::ReadAllText($configPath)
        }
    } catch { } # Unknown; never emit raw exception text or paths.
    try {
        $service = Get-CimInstance -ClassName Win32_Service -Filter "Name='cloudbase-init'" -Property State, StartMode -ErrorAction Stop
        if (@($service).Count -eq 1) {
            $observations.ServiceState = $service.State
            $observations.ServiceStartMode = $service.StartMode
        }
    } catch { }
    try {
        $observations.RdpDenyConnections = Get-ItemPropertyValue -LiteralPath 'HKLM:\SYSTEM\CurrentControlSet\Control\Terminal Server' -Name fDenyTSConnections -ErrorAction Stop
    } catch { }
    try {
        $observations.NlaUserAuthentication = Get-ItemPropertyValue -LiteralPath 'HKLM:\SYSTEM\CurrentControlSet\Control\Terminal Server\WinStations\RDP-Tcp' -Name UserAuthentication -ErrorAction Stop
    } catch { }
    try {
        $profiles = Get-NetFirewallProfile -PolicyStore ActiveStore -ErrorAction Stop
        $observations.Firewall = @{}
        foreach ($profile in $profiles) {
            if (@('Domain', 'Private', 'Public') -contains [string]$profile.Name) {
                $observations.Firewall[[string]$profile.Name] = [string]$profile.Enabled
            }
        }
    } catch { }
    try {
        # Request SID only; names and account properties are not collected.
        $accounts = Get-CimInstance -ClassName Win32_UserAccount -Filter 'LocalAccount=True' -Property SID -ErrorAction Stop
        $found = @($accounts | Where-Object { $_.SID -match '^S-1-5-21-\d+-\d+-\d+-500$' }).Count -gt 0
        $observations.Sid500Presence = $(if ($found) { 'present' } else { 'not_observed' })
    } catch { }
}

ConvertTo-ArvanWindowsBootstrapEvidence -Observations $observations | ConvertTo-Json -Depth 8
