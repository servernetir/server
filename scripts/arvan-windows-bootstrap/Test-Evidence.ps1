# Self-contained synthetic tests. Entry guards are exercised without running
# host collection; all assessment inputs are synthetic.
[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'Evidence.psm1') -Force
$script:assertions = 0
function Assert-True {
    param([bool]$Condition, [string]$Label)
    $script:assertions++
    if (-not $Condition) { throw "Synthetic assertion failed: $Label" }
}
function Assert-Unproven {
    param($Evidence)
    Assert-True ($Evidence.assessment.arvan_delivery -eq 'unproven') 'delivery remains unproven'
    Assert-True ($Evidence.assessment.production_readiness -eq 'not_established') 'not production acceptance'
}

$empty = ConvertTo-ArvanWindowsBootstrapEvidence
Assert-True ($empty.rdp -eq 'unknown' -and $empty.nla -eq 'unknown') 'missing registry is unknown'
Assert-True ($empty.local_administrator_sid500 -eq 'unknown') 'missing account evidence is unknown'
Assert-True ($empty.firewall_enabled.public -eq 'unknown') 'missing firewall is unknown'
Assert-True ($empty.cloudbase.inject_user_password -eq 'unknown') 'missing flag is unknown'
Assert-Unproven $empty

$sentinel = 'DUMMY_SECRET_NEVER_OUTPUT_782341'
$malicious = ConvertTo-ArvanWindowsBootstrapEvidence @{
    ConfigText = "[DEFAULT]`npassword=$sentinel`nusername=$sentinel`ninject_user_password=$sentinel`nplugins=$sentinel`nmetadata_services=https://$sentinel.invalid/key`nfirst_logon_behaviour=$sentinel"
    ServiceState = $sentinel; ServiceStartMode = $sentinel; Sid500Presence = $sentinel
    Firewall = @{ Public = $sentinel }; RdpDenyConnections = $sentinel; NlaUserAuthentication = $sentinel
    Password = $sentinel; IP = '192.0.2.5'; UUID = $sentinel; Exception = $sentinel
}
$json = $malicious | ConvertTo-Json -Depth 8 -Compress
Assert-True (-not $json.Contains($sentinel)) 'dummy secret never appears'
Assert-True (-not $json.Contains('192.0.2.5')) 'arbitrary address never appears'
Assert-True ($malicious.cloudbase.plugins.categories.Count -eq 0) 'unknown plugin omitted'
Assert-True ($malicious.cloudbase.metadata_services.categories.Count -eq 0) 'unknown metadata URL omitted'
Assert-True ($malicious.cloudbase.inject_user_password -eq 'unknown') 'malicious flag is not true'
Assert-Unproven $malicious

$readOnlyConfig = @'
[DEFAULT]
inject_user_password=false
first_logon_behaviour=no
allow_reboot=false
stop_service_on_exit=false
metadata_services=cloudbaseinit.metadata.services.configdrive.ConfigDriveService,cloudbaseinit.metadata.services.nocloudservice.NoCloudConfigDriveService
plugins=cloudbaseinit.plugins.common.networkconfig.NetworkConfigPlugin,cloudbaseinit.plugins.common.sethostname.SetHostNamePlugin
config_drive_types=vfat,iso
config_drive_locations=cdrom,hdd
'@
$readOnly = ConvertTo-ArvanWindowsBootstrapEvidence @{ ConfigText = $readOnlyConfig }
Assert-True ($readOnly.cloudbase.inject_user_password -eq 'false') 'explicit false retained'
Assert-True ($readOnly.cloudbase.metadata_services.categories -contains 'config_drive') 'known configdrive category'
Assert-True ($readOnly.assessment.password_configuration -eq 'no_password_plugin_in_observed_list') 'network-only list not password bootstrap'
Assert-Unproven $readOnly

$candidate = ConvertTo-ArvanWindowsBootstrapEvidence @{
    ConfigText = "[DEFAULT]`ninject_user_password=false`nplugins=cloudbaseinit.plugins.windows.setuserpassword.SetUserPasswordPlugin`nmetadata_services=cloudbaseinit.metadata.services.httpservice.HttpService"
    ServiceState = 'Running'; ServiceStartMode = 'Auto'; Sid500Presence = 'present'
    RdpDenyConnections = 0; NlaUserAuthentication = 1
    Firewall = @{ Domain = $true; Private = $true; Public = $true }
}
Assert-True ($candidate.assessment.password_configuration -eq 'password_plugin_configured_only') 'candidate is configuration only'
Assert-True ($candidate.rdp -eq 'enabled' -and $candidate.nla -eq 'enabled') 'readable switches mapped'
Assert-True ($candidate.firewall_enabled.public -eq 'true') 'firewall observation mapped'
Assert-Unproven $candidate

$currentPlugin = ConvertTo-ArvanWindowsBootstrapEvidence @{
    ConfigText = "[DEFAULT]`nplugins=cloudbaseinit.plugins.common.setuserpassword.SetUserPasswordPlugin"
    Sid500Presence = 'PRESENT'
}
Assert-True ($currentPlugin.cloudbase.plugins.categories -contains 'set_user_password') 'current password plugin class recognized'
Assert-True ($currentPlugin.assessment.password_configuration -eq 'password_plugin_configured_only') 'current plugin remains configuration only'
Assert-True ($currentPlugin.local_administrator_sid500 -ceq 'present') 'SID500 enum normalized'
Assert-Unproven $currentPlugin

$multiline = ConvertTo-ArvanWindowsBootstrapEvidence @{
    ConfigText = "[DEFAULT]`nplugins=cloudbaseinit.plugins.common.networkconfig.NetworkConfigPlugin`n  cloudbaseinit.plugins.common.setuserpassword.SetUserPasswordPlugin`nmetadata_services=cloudbaseinit.metadata.services.httpservice.HttpService`n  $sentinel"
}
Assert-True ($multiline.cloudbase.plugins.evidence -eq 'unknown') 'multiline plugin list not truncated into evidence'
Assert-True ($multiline.assessment.password_configuration -eq 'unknown') 'multiline list does not claim password plugin absence'
Assert-True ($multiline.cloudbase.metadata_services.evidence -eq 'unknown') 'multiline metadata list unknown'
Assert-True (-not (($multiline | ConvertTo-Json -Depth 8).Contains($sentinel))) 'multiline secret not exposed'
Assert-Unproven $multiline

$unsafe = ConvertTo-ArvanWindowsBootstrapEvidence @{ RdpDenyConnections = 0; NlaUserAuthentication = 0; Firewall = @{ Public = $false } }
Assert-True ($unsafe.assessment.concerns -contains 'nla_disabled') 'disabled NLA detected'
Assert-True ($unsafe.assessment.concerns -contains 'firewall_profile_disabled') 'disabled firewall detected'
Assert-Unproven $unsafe

$duplicate = ConvertTo-ArvanWindowsBootstrapEvidence @{ ConfigText = "[DEFAULT]`ninject_user_password=true`ninject_user_password=false`nplugins=cloudbaseinit.plugins.windows.setuserpassword.SetUserPasswordPlugin,$sentinel`n[other]`nallow_reboot=true" }
Assert-True ($duplicate.cloudbase.inject_user_password -eq 'unknown') 'duplicate flags ambiguous'
Assert-True ($duplicate.cloudbase.allow_reboot -eq 'unknown') 'other sections ignored'
Assert-True ($duplicate.cloudbase.plugins.evidence -eq 'partial') 'partial plugin list not asserted complete'
Assert-True ($duplicate.assessment.password_configuration -eq 'unknown') 'partial list not claimed effective'
Assert-True (-not (($duplicate | ConvertTo-Json -Depth 8).Contains($sentinel))) 'partial list redacts unknown value'
Assert-Unproven $duplicate

# Parse the collector. Reject accidental mutating commands
# or network methods in this small preparation tool.
$tokens = $null; $parseErrors = $null
$collector = [Management.Automation.Language.Parser]::ParseFile((Join-Path $PSScriptRoot 'Collect-Evidence.ps1'), [ref]$tokens, [ref]$parseErrors)
Assert-True (@($parseErrors).Count -eq 0) 'collector parses'
$commands = @($collector.FindAll({ param($node) $node -is [Management.Automation.Language.CommandAst] }, $true) | ForEach-Object { $_.GetCommandName() })
$allowed = @('Set-StrictMode', 'Import-Module', 'Join-Path', 'Get-Item', 'Get-CimInstance',
    'Get-ItemPropertyValue', 'Get-NetFirewallProfile', 'Where-Object',
    'ConvertTo-ArvanWindowsBootstrapEvidence', 'ConvertTo-Json', 'Write-Output')
Assert-True (@($commands | Where-Object { $allowed -notcontains $_ }).Count -eq 0) 'collector command allowlist'
Assert-True (-not $collector.Extent.Text.Contains('Win32_Product')) 'no installer enumeration'
$calls = @($collector.FindAll({ param($node) $node -is [Management.Automation.Language.InvokeMemberExpressionAst] }, $true) | ForEach-Object { $_.Member.Value })
Assert-True (@($calls | Where-Object { @('GetFolderPath', 'ReadAllText') -notcontains $_ }).Count -eq 0) 'collector method allowlist'
$guardResult = & (Join-Path $PSScriptRoot 'Collect-Evidence.ps1')
Assert-True ($guardResult -eq 'Not collected. Use -CollectApprovedTestGuest only inside an approved disposable Windows guest.') 'default invocation performs no collection'
$dotSourceResult = & { . (Join-Path $PSScriptRoot 'Collect-Evidence.ps1') -CollectApprovedTestGuest }
Assert-True ($null -eq $dotSourceResult) 'dot-source performs no collection even with switch'
Write-Output ("PASS: {0} synthetic assertions; host collection was not executed." -f $script:assertions)
