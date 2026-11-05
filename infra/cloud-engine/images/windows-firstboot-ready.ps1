# Trusted, image-builder-owned post-CloudbaseInit readiness finalizer.
# Never invoke with a plain-text password on the command line. The initializer
# supplies InitialPassword as a SecureString in the same protected PowerShell
# process, after obtaining it through the approved CloudbaseInit metadata path.
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][ValidatePattern('^windows-(2019|2022|2025)([.-][a-zA-Z0-9.-]+)?$')][string]$ImageKey,
    [Parameter(Mandatory = $true)][ValidatePattern('^[a-zA-Z0-9][a-zA-Z0-9-]{0,14}$')][string]$ExpectedHostname,
    [Parameter(Mandatory = $true)][ValidatePattern('^[a-zA-Z0-9_.-]{1,20}$')][string]$Username,
    [Parameter(Mandatory = $true)][Security.SecureString]$InitialPassword,
    [Parameter(Mandatory = $true)][string]$ExpectedIpv4,
    [Parameter(Mandatory = $true)][string]$ExpectedGateway
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$MarkerDirectory = 'C:\ProgramData\ServerNet'
$Marker = Join-Path $MarkerDirectory 'cloud-engine-ready.json'

# A template must never retain this marker. Remove a stale candidate before any
# checks so an interrupted initialization cannot leave apparent success behind.
if (Test-Path -LiteralPath $Marker) { Remove-Item -LiteralPath $Marker -Force }

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = [Security.Principal.WindowsPrincipal]::new($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Readiness finalizer requires the trusted elevated image initializer.'
}
$expectedBuild = if ($ImageKey -match '^windows-2019') { '17763' } elseif ($ImageKey -match '^windows-2022') { '20348' } else { '26100' }
$os = Get-CimInstance -ClassName Win32_OperatingSystem
$computer = Get-CimInstance -ClassName Win32_ComputerSystem
if ([string]$os.BuildNumber -ne $expectedBuild -or [string]$os.ProductType -eq '1') {
    throw 'Unsupported Windows Server build or workstation image.'
}
if (-not [string]::Equals([string]$computer.Name, $ExpectedHostname, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'The expected guest hostname has not been applied.'
}

# This is a post-service task, not a CloudbaseInit local-script plugin waiting on
# its own running service. Service completion alone is insufficient; the actual
# credential, hostname and network outcomes below are independently verified.
$service = Get-CimInstance -ClassName Win32_Service -Filter "Name='cloudbase-init'"
if ($null -eq $service -or $service.State -ne 'Stopped' -or [int]$service.ExitCode -ne 0) {
    throw 'CloudbaseInit has not completed its configured one-shot run.'
}
foreach ($value in @($ExpectedIpv4, $ExpectedGateway)) {
    $parsed = [Net.IPAddress]::Parse($value)
    if ($parsed.AddressFamily -ne [Net.Sockets.AddressFamily]::InterNetwork) {
        throw 'Only the accepted static IPv4 networking contract is supported.'
    }
}
$address = Get-NetIPAddress -AddressFamily IPv4 | Where-Object {
    $_.IPAddress -eq $ExpectedIpv4 -and $_.PrefixOrigin -eq 'Manual' -and $_.AddressState -eq 'Preferred'
} | Select-Object -First 1
if ($null -eq $address) { throw 'The expected static guest IPv4 is not active.' }
$route = Get-NetRoute -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' | Where-Object {
    $_.InterfaceIndex -eq $address.InterfaceIndex -and $_.NextHop -eq $ExpectedGateway
} | Select-Object -First 1
if ($null -eq $route) { throw 'The expected default route has not been applied.' }

# Verify a real local account login. A cloud-init config write, a nonempty
# password parameter or a previous marker cannot substitute for this check.
Add-Type -AssemblyName System.DirectoryServices.AccountManagement
$context = [DirectoryServices.AccountManagement.PrincipalContext]::new(
    [DirectoryServices.AccountManagement.ContextType]::Machine, $computer.Name)
$bstr = [IntPtr]::Zero
$plain = $null
try {
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($InitialPassword)
    $plain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    if (-not $context.ValidateCredentials($Username, $plain)) {
        throw 'The configured initial guest credentials did not authenticate.'
    }
} finally {
    $plain = $null
    if ($bstr -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
    $context.Dispose()
}

New-Item -ItemType Directory -Path $MarkerDirectory -Force | Out-Null
$acl = [Security.AccessControl.DirectorySecurity]::new()
$acl.SetAccessRuleProtection($true, $false)
foreach ($sid in @('S-1-5-18', 'S-1-5-32-544')) {
    $identitySid = [Security.Principal.SecurityIdentifier]::new($sid)
    $rule = [Security.AccessControl.FileSystemAccessRule]::new($identitySid, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')
    $acl.AddAccessRule($rule)
}
Set-Acl -LiteralPath $MarkerDirectory -AclObject $acl
$writtenAt = [DateTime]::UtcNow.ToString('o')
$payload = [ordered]@{
    schema_version = 1
    image_key = $ImageKey
    os_build = [string]$os.BuildNumber
    hostname = [string]$computer.Name
    initialized = $true
    credential_setup = $true
    ipv4 = $ExpectedIpv4
    written_at = $writtenAt
    completed_at_utc = $writtenAt
}
$temporary = Join-Path $MarkerDirectory ([Guid]::NewGuid().ToString('N') + '.tmp')
try {
    [IO.File]::WriteAllText($temporary, ($payload | ConvertTo-Json -Compress), [Text.UTF8Encoding]::new($false))
    Move-Item -LiteralPath $temporary -Destination $Marker -Force
} finally {
    if (Test-Path -LiteralPath $temporary) { Remove-Item -LiteralPath $temporary -Force }
}
Write-Output 'ServerNet guest readiness marker recorded.'
