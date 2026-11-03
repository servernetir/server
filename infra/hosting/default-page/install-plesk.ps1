$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$expectedBefore = '79CEF63863F30A890FCA38BF1A244FEC1D2AD72C6623F5D20E24C2D1879CEE1F'
$expectedAfter = '3A4BCDBE665D5471B3230AD419D5AF22E5772BA4E63E462F09448F9337167BBB'
$vhosts = [Environment]::GetEnvironmentVariable('plesk_vhosts', 'Machine')
if (-not $vhosts) { $vhosts = $env:plesk_vhosts }
if (-not $vhosts) { throw 'Vhosts root missing' }
$skeleton = [IO.Path]::GetFullPath((Join-Path $vhosts '.skel\0'))
$target = [IO.Path]::GetFullPath((Join-Path $skeleton 'httpdocs\index.html'))
if ((Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash -ne $expectedBefore) { throw 'Skeleton changed' }
if ((Get-Item -LiteralPath $target).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Unexpected link' }
$backup = Join-Path $env:ProgramData 'ServerNet\hosting-defaults-20261103'
New-Item -ItemType Directory -Path $backup -Force | Out-Null
$beforeFile = Join-Path $backup 'skeleton-index-before.html'
if (Test-Path -LiteralPath (Join-Path $backup 'skeleton-installed.json')) { throw 'Already installed; read receipt' }
if ((Test-Path -LiteralPath $beforeFile) -and (Get-FileHash -LiteralPath $beforeFile -Algorithm SHA256).Hash -ne $expectedBefore) { throw 'Existing backup mismatch' }
function OtherFiles {
    @(Get-ChildItem -LiteralPath $skeleton -File -Recurse | Where-Object { $_.FullName -ne $target } | Sort-Object FullName | ForEach-Object {
        @{ path = $_.FullName.Substring($skeleton.Length); sha = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash }
    }) | ConvertTo-Json -Compress
}
$othersBefore = OtherFiles
$client = New-Object Net.WebClient
try { $content = $client.DownloadData('https://servernet.cloud/assets/hosting-default/index.html') } finally { $client.Dispose() }
$temp = Join-Path $backup 'skeleton-index-next.html'
[IO.File]::WriteAllBytes($temp, $content)
if ((Get-FileHash -LiteralPath $temp -Algorithm SHA256).Hash -ne $expectedAfter) { throw 'Source changed' }
if (-not (Test-Path -LiteralPath $beforeFile)) { Copy-Item -LiteralPath $target -Destination $beforeFile }
if ((Get-FileHash -LiteralPath $beforeFile -Algorithm SHA256).Hash -ne $expectedBefore) { throw 'Backup mismatch' }
$othersBefore | Set-Content -LiteralPath (Join-Path $backup 'other-files-before.json') -Encoding UTF8
if ((Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash -ne $expectedBefore) { throw 'Concurrent skeleton change' }
$replaceBackup = Join-Path $backup 'skeleton-index-replaced.html'
if (Test-Path -LiteralPath $replaceBackup) { throw 'Previous replace exists; reconcile first' }
[IO.File]::Replace($temp, $target, $replaceBackup)
if ((Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash -ne $expectedAfter) { throw 'Readback failed' }
if ((OtherFiles) -ne $othersBefore) { throw 'Other template files changed' }
$receipt = @{ installed = $true; native_skeleton = $true; before = $expectedBefore; after = $expectedAfter; other_files_unchanged = $true; at = (Get-Date).ToUniversalTime().ToString('o') }
$receipt | ConvertTo-Json -Compress | Set-Content -LiteralPath (Join-Path $backup 'skeleton-installed.json') -Encoding UTF8
$receipt | ConvertTo-Json -Compress
