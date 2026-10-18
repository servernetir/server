# Pure preparation only: returns a future guest script; never executes it.
Set-StrictMode -Version Latest

function ConvertTo-ValidatedMarkerId {
    param([AllowNull()][AllowEmptyString()][string]$MarkerId)
    if ([string]::IsNullOrEmpty($MarkerId) -or
        -not [regex]::IsMatch($MarkerId, '\A(?:[0-9a-fA-F]{32}|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})\z')) {
        throw 'MarkerId must be a non-secret UUID or 32 hexadecimal characters.'
    }
    return $MarkerId.ToLowerInvariant()
}

function New-ArvanWindowsMarkerPayload {
    [CmdletBinding()]
    param([AllowNull()][AllowEmptyString()][string]$MarkerId)
    $id = ConvertTo-ValidatedMarkerId -MarkerId $MarkerId
    # Single-quoted template: no local environment expansion or host collection.
    $template = @'
#ps1_sysnative
# Future explicitly approved disposable Windows guest only. Not password setup.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$programData = [Environment]::GetFolderPath('CommonApplicationData')
if ([string]::IsNullOrEmpty($programData) -or -not [IO.Path]::IsPathRooted($programData)) {
    throw 'Standard ProgramData path is unavailable.'
}
$parentPath = [IO.Path]::Combine($programData, 'ServerNet')
$probePath = [IO.Path]::Combine($parentPath, 'BootstrapProbe')
foreach ($directoryPath in @($programData, $parentPath, $probePath)) {
    if ([IO.File]::Exists($directoryPath)) { throw 'Diagnostic directory is not a directory.' }
    if ([IO.Directory]::Exists($directoryPath)) {
        if (([IO.File]::GetAttributes($directoryPath) -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
            throw 'Diagnostic reparse directories are not allowed.'
        }
    } else {
        [void][IO.Directory]::CreateDirectory($directoryPath)
    }
}
$markerPath = [IO.Path]::Combine($probePath, '__MARKER_ID__.json')
# An existing marker, even a damaged one, is preserved; it is not fresh proof.
if ([IO.File]::Exists($markerPath)) { return }
$bytes = [Text.Encoding]::UTF8.GetBytes('{"schema":1,"marker":"__MARKER_ID__"}')
$stream = $null
try {
    # CreateNew also prevents overwrite if another invocation wins the race.
    $stream = [IO.FileStream]::new($markerPath, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
    $stream.Write($bytes, 0, $bytes.Length)
} catch [IO.IOException] {
    if ($null -ne $stream -or -not [IO.File]::Exists($markerPath)) { throw }
    # A competing exclusive create won. Preserve its file for independent validation.
} finally {
    if ($null -ne $stream) { $stream.Dispose() }
}
'@
    return $template.Replace('__MARKER_ID__', $id).Replace("`r`n", "`n") + "`n"
}

function Test-ArvanWindowsMarkerRecord {
    [CmdletBinding()]
    param(
        [AllowNull()][AllowEmptyString()][string]$ExpectedMarkerId,
        [AllowNull()][AllowEmptyString()][string]$MarkerText
    )
    $id = ConvertTo-ValidatedMarkerId -MarkerId $ExpectedMarkerId
    # Only a boolean is emitted; unknown fields/values and parse errors never leak.
    # Exact canonical bytes also reject duplicate JSON properties and extra data.
    if ([string]::IsNullOrEmpty($MarkerText) -or $MarkerText.Length -gt 128) { return $false }
    $expected = '{"schema":1,"marker":"' + $id + '"}'
    return [string]::Equals($MarkerText, $expected, [StringComparison]::Ordinal)
}

Export-ModuleMember -Function New-ArvanWindowsMarkerPayload, Test-ArvanWindowsMarkerRecord
