# =============================================================================
#  ServerNet Windows turnkey first-boot.
#  Reads the plaintext 'password:' line from the cloud-init config drive and
#  applies it to the BUILT-IN Administrator (well-known RID 500), then makes
#  sure RDP is reachable. Deliberately independent of Cloudbase-Init, whose
#  CreateUser/SetUserPassword plugins fail on boot-1 with NERR_NotPrimary 2226
#  ("only allowed on the primary domain controller").
# =============================================================================
$ErrorActionPreference = 'SilentlyContinue'
function Log($m) { "$(Get-Date -Format s)  $m" | Out-File -Append "$env:SystemRoot\Temp\sn-firstboot.log" -Encoding utf8 }

Log "sn-firstboot start"

# 1) Locate the cloud-init drive (small CDFS/removable volume carrying user-data)
#    and pull the plaintext password line the panel wrote.
$pw = $null
foreach ($drv in (Get-CimInstance Win32_LogicalDisk | Where-Object { $_.DriveType -eq 5 -or $_.DriveType -eq 2 })) {
    foreach ($name in @('user-data', 'openstack\latest\user_data', 'user_data')) {
        $p = Join-Path $drv.DeviceID $name
        if (Test-Path $p) {
            $line = Select-String -Path $p -Pattern '^\s*password:\s*(.+)$' | Select-Object -First 1
            if ($line) { $pw = $line.Matches[0].Groups[1].Value.Trim().Trim('"').Trim("'"); break }
        }
    }
    if ($pw) { break }
}

if (-not $pw) {
    Log "no password found on cloud-init drive; leaving account as-is"
}
else {
    # 2) Resolve the built-in Administrator by RID 500 (locale/rename proof).
    $admin = Get-LocalUser | Where-Object { $_.SID.Value -like 'S-1-5-*-500' } | Select-Object -First 1
    if ($admin) {
        try {
            $adsi = [ADSI]"WinNT://./$($admin.Name),user"
            $adsi.SetPassword($pw)
            $adsi.SetInfo()
            Log "set password on built-in $($admin.Name) via ADSI"
        }
        catch { Log "ADSI SetPassword failed: $_" }
        cmd /c "net user `"$($admin.Name)`" /active:yes" | Out-Null
    }
    else { Log "built-in Administrator (RID 500) not found" }
}

# 3) Guarantee RDP is enabled and reachable.
Set-ItemProperty 'HKLM:\System\CurrentControlSet\Control\Terminal Server' -Name fDenyTSConnections -Value 0
Set-ItemProperty 'HKLM:\System\CurrentControlSet\Control\Terminal Server\WinStations\RDP-Tcp' -Name UserAuthentication -Value 0
Enable-NetFirewallRule -DisplayGroup 'Remote Desktop'
Set-Service TermService -StartupType Automatic
Start-Service TermService

Log "sn-firstboot done"
