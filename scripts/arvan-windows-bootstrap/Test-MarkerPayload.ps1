# Offline only. Parses guest script text; NEVER executes that generated script.
[CmdletBinding()]
param()
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$script:assertions = 0
function Assert-True {
    param([bool]$Condition, [string]$Label)
    $script:assertions++
    if (-not $Condition) { throw "Marker preparation assertion failed: $Label" }
}
function Assert-Rejected {
    param([scriptblock]$Action, [string]$Label)
    $rejected = $false
    try { & $Action | Out-Null } catch { $rejected = $true }
    Assert-True $rejected $Label
}
function Get-FolderSnapshot {
    # Read-only verification that generation does not create/overwrite local files.
    $rows = Get-ChildItem -LiteralPath $PSScriptRoot -File | Sort-Object Name | ForEach-Object {
        $_.Name + ':' + (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash
    }
    return $rows -join "`n"
}
$before = Get-FolderSnapshot
Import-Module (Join-Path $PSScriptRoot 'MarkerPayload.psm1') -Force
$marker = '019a01e8-1234-4567-89ab-0123456789ab'
$payload = New-ArvanWindowsMarkerPayload -MarkerId $marker
Assert-True ($payload.StartsWith("#ps1_sysnative`n")) 'exact first-line user-data marker'
Assert-True (-not $payload.Contains("`r")) 'deterministic LF serialization'
Assert-True ($payload -ceq (New-ArvanWindowsMarkerPayload -MarkerId $marker.ToUpperInvariant())) 'canonical identifier'
Assert-True ($payload -ceq (New-ArvanWindowsMarkerPayload -MarkerId $marker)) 'deterministic regeneration'
Assert-True ($payload.Contains($marker + '.json')) 'nonce-bound fixed filename'
Assert-True ($payload.Contains('[Environment]::GetFolderPath(''CommonApplicationData'')')) 'only standard ProgramData root'
Assert-True (-not $payload.Contains('$env:')) 'no environment-value collection'
Assert-True ($payload.Contains("'ServerNet'" ) -and $payload.Contains("'BootstrapProbe'")) 'fixed diagnostic subdirectories'
Assert-True (-not $payload.Contains('__MARKER_ID__')) 'no unresolved placeholder'
Assert-True ((New-ArvanWindowsMarkerPayload -MarkerId ('AB' * 16)).Contains(('ab' * 16) + '.json')) '32-hex accepted and normalized'

foreach ($bad in @('', ' ', "`n", '..\escape', '0123456789abcdef', ('a' * 33),
        ($marker + "`n"), ($marker + "`r`n"), ($marker + ';Get-Process'),
        ($marker + "'"), '$(''injection'')', ('a' * 31 + 'g'), (([string][char]0xFF21) * 32))) {
    Assert-Rejected { New-ArvanWindowsMarkerPayload -MarkerId $bad } 'invalid/injection input rejected'
}
Assert-Rejected { New-ArvanWindowsMarkerPayload -MarkerId $null } 'null rejected'
Assert-Rejected { New-ArvanWindowsMarkerPayload -MarkerId $marker -OutputPath 'unused' } 'no output path argument'
Assert-Rejected { New-ArvanWindowsMarkerPayload -MarkerId $marker -Script 'unused' } 'no arbitrary script argument'

$tokens = $null; $parseErrors = $null
$ast = [Management.Automation.Language.Parser]::ParseInput($payload, [ref]$tokens, [ref]$parseErrors)
Assert-True ($parseErrors.Count -eq 0) 'generated guest text parses without execution'
$commandNames = @($ast.FindAll({ param($n) $n -is [Management.Automation.Language.CommandAst] }, $true) | ForEach-Object { $_.GetCommandName() })
Assert-True ($commandNames.Count -eq 1 -and $commandNames[0] -ceq 'Set-StrictMode') 'no network/process/password cmdlets'
$methodNames = @($ast.FindAll({ param($n) $n -is [Management.Automation.Language.InvokeMemberExpressionAst] }, $true) | ForEach-Object { $_.Member.Value })
$allowedMethods = @('GetFolderPath', 'IsNullOrEmpty', 'IsPathRooted', 'Combine', 'Exists', 'GetAttributes', 'CreateDirectory', 'GetBytes', 'new', 'Write', 'Dispose')
Assert-True (@($methodNames | Where-Object { $_ -notin $allowedMethods }).Count -eq 0) 'fixed narrow method allowlist'
Assert-True (@($methodNames | Where-Object { $_ -ceq 'new' }).Count -eq 1) 'one stream constructor only'
Assert-True ($payload.Contains('[IO.FileMode]::CreateNew')) 'exclusive create forbids overwrite'
Assert-True (-not ($payload -match 'FileMode\]::(?:Create\b|Open|Append|Truncate)')) 'no destructive/append file mode'
Assert-True ($payload.Contains('if ([IO.File]::Exists($markerPath)) { return }')) 'existing marker returns unchanged'
Assert-True ($payload.IndexOf('if ([IO.File]::Exists($markerPath)) { return }') -lt $payload.IndexOf('[IO.FileStream]::new')) 'preservation guard precedes creation'
Assert-True ($payload.Contains('[IO.FileAttributes]::ReparsePoint')) 'existing reparse directory rejected'
Assert-True ($payload.Contains('$stream.Dispose()')) 'stream disposed'
Assert-True ($payload.Contains('if ($null -ne $stream -or -not [IO.File]::Exists($markerPath)) { throw }')) 'write failures remain failures and preserve partial data'
Assert-True (-not ($payload -match 'https?://|Invoke-Expression|ScriptBlock\]::Create|Start-Process|Set-LocalUser|net\.exe|Registry|WebClient|HttpClient|TcpClient')) 'no network/execution/security mutation surface'

$record = '{"schema":1,"marker":"' + $marker + '"}'
Assert-True (Test-ArvanWindowsMarkerRecord -ExpectedMarkerId $marker -MarkerText $record) 'exact canonical record validates'
Assert-True (Test-ArvanWindowsMarkerRecord -ExpectedMarkerId $marker.ToUpperInvariant() -MarkerText $record) 'expected ID canonicalized'
$invalidRecords = @('', 'null', '{}', '[]', ($record + "`n"), ($record + '{}'),
    $record.Replace('"schema":1', '"schema":2'), $record.Replace('"schema":1', '"schema":"1"'),
    $record.Replace($marker, ('a' * 32)), $record.Replace('"schema":1', '"schema":1,"schema":1'),
    $record.Replace('"schema":1', '"schema":1,"password":"DUMMY_SECRET"'), ('x' * 129))
foreach ($invalid in $invalidRecords) {
    $result = Test-ArvanWindowsMarkerRecord -ExpectedMarkerId $marker -MarkerText $invalid
    Assert-True ($result -is [bool] -and -not $result) 'bad/extra/credential-bearing record rejected without echo'
}
Assert-True (-not (Test-ArvanWindowsMarkerRecord -ExpectedMarkerId $marker -MarkerText $null)) 'null record false'

$wrapper = Join-Path $PSScriptRoot 'New-MarkerPayload.ps1'
Assert-True ((& $wrapper -MarkerId $marker) -ceq $payload) 'wrapper emits exactly generator text'
Assert-True (@(. $wrapper -MarkerId $marker).Count -eq 0) 'dot-source guard is inert'
Assert-Rejected { & $wrapper } 'default invocation cannot generate without identifier'
Assert-Rejected { & $wrapper -MarkerId $marker -OutputPath 'unused' } 'wrapper cannot write an output path'
Assert-True ($before -ceq (Get-FolderSnapshot)) 'generation and tests leave existing source files unchanged'
Write-Output ("Marker preparation: {0} offline assertions passed; guest payload NOT executed." -f $script:assertions)
