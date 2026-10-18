# Emits text on stdout only. No file-output, execution, API or approval switch.
[CmdletBinding()]
param([AllowNull()][AllowEmptyString()][string]$MarkerId)

if ($MyInvocation.InvocationName -eq '.') { return }
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
Import-Module (Join-Path $PSScriptRoot 'MarkerPayload.psm1') -Force
New-ArvanWindowsMarkerPayload -MarkerId $MarkerId
