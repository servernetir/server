@echo off
rem ServerNet Windows turnkey — runs once at the very end of OOBE as SYSTEM.
rem Sets the built-in Administrator password from the cloud-init drive and
rem guarantees RDP. Independent of Cloudbase-Init.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0sn-firstboot.ps1" >> "%SystemRoot%\Temp\sn-firstboot.log" 2>&1
exit /b 0
