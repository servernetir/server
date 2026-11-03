#Requires -Version 5.1
#Requires -RunAsAdministrator
<#
Public, isolated native filesystem regression. Extract only nonce/ACL/hash
functions from the adjacent source, never its runtime entry point, listener,
curl, real key/config or installed nonce state. No production path is selected.
#>
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
$Runtime=Join-Path (Split-Path -Parent $PSScriptRoot) 'windows_probe.ps1'
$tokens=$null; $errors=$null
$ast=[Management.Automation.Language.Parser]::ParseFile($Runtime,[ref]$tokens,[ref]$errors)
if(@($errors).Count -ne 0){throw 'runtime_native_parse_failed'}
$names=@('Assert-PrivateAcl','Get-Hex','Get-Hash','New-Nonce','Assert-Fields','Test-Integer','Claim-Nonce')
$definitions=@($ast.FindAll({param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst]},$false))
foreach($name in $names) {
    $selected=@($definitions | Where-Object {$_.Name -ceq $name})
    if($selected.Count -ne 1){throw 'test_function_scope'}
    . ([ScriptBlock]::Create($selected[0].Extent.Text))
}
$SystemSid='S-1-5-18'; $AdminSid='S-1-5-32-544'
$Utf8=[Text.UTF8Encoding]::new($false,$true); $MaxNonces=256
$script:Now=[long]1800000000; $script:Checks=0
function Get-UnixTime {return $script:Now}
function Assert-Test([bool]$Value,[string]$Code) {if(-not $Value){throw $Code};$script:Checks++}
function Assert-Rejected([scriptblock]$Action,[string]$Code) {
    $rejected=$false
    try {& $Action} catch {if($_.Exception.Message -ceq $Code){$rejected=$true}else{throw}}
    Assert-Test $rejected 'expected_rejection_missing'
}
function Read-TestState {return ($Utf8.GetString([IO.File]::ReadAllBytes($NonceFile)) | ConvertFrom-Json)}
$Parent=[Environment]::GetFolderPath('CommonApplicationData')
$Root=Join-Path $Parent ('ServerNet-ProbeNonceTest-'+[Guid]::NewGuid().ToString('N'))
$NonceFile=Join-Path $Root 'nonces.json'; $LockFile=Join-Path $Root 'nonces.lock'
try {
    if((Get-Item -LiteralPath $Parent -Force).Attributes -band [IO.FileAttributes]::ReparsePoint){throw 'test_parent_reparse'}
    [void][IO.Directory]::CreateDirectory($Root)
    $acl=[Security.AccessControl.DirectorySecurity]::new()
    $acl.SetOwner([Security.Principal.SecurityIdentifier]::new($AdminSid));$acl.SetAccessRuleProtection($true,$false)
    foreach($sid in @($SystemSid,$AdminSid)) {
        [void]$acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new(
            [Security.Principal.SecurityIdentifier]::new($sid),[Security.AccessControl.FileSystemRights]::FullControl,
            ([Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [Security.AccessControl.InheritanceFlags]::ObjectInherit),
            [Security.AccessControl.PropagationFlags]::None,[Security.AccessControl.AccessControlType]::Allow))
    }
    Set-Acl -LiteralPath $Root -AclObject $acl
    Assert-PrivateAcl $Root $true
    $claims=@(('1'*32),('2'*32),('3'*32))
    for($i=0;$i -lt 3;$i++) {
        Claim-Nonce $claims[$i] $script:Now
        $state=Read-TestState
        Assert-Test (@($state.entries).Count -eq ($i+1)) 'atomic_replace_dropped_nonce'
        Assert-Test (@($state.entries | Where-Object {$_.nonce -ceq $claims[$i]}).Count -eq 1) 'new_nonce_missing'
    }
    $before=Get-Hash ([IO.File]::ReadAllBytes($NonceFile))
    foreach($nonce in $claims){Assert-Rejected {Claim-Nonce $nonce $script:Now} 'request_replay'}
    Assert-Test ((Get-Hash ([IO.File]::ReadAllBytes($NonceFile))) -ceq $before) 'replay_changed_nonce_state'
    $MaxNonces=3
    Assert-Rejected {Claim-Nonce ('4'*32) $script:Now} 'nonce_capacity'
    $MaxNonces=256; $script:Now-=3
    Assert-Rejected {Claim-Nonce ('4'*32) $script:Now} 'nonce_state'
    $script:Now+=3
    Assert-Test ((Get-Hash ([IO.File]::ReadAllBytes($NonceFile))) -ceq $before) 'rejected_claim_changed_state'
    $script:Now+=31
    Claim-Nonce ('4'*32) $script:Now
    $state=Read-TestState
    Assert-Test (@($state.entries).Count -eq 1 -and $state.entries[0].nonce -ceq ('4'*32)) 'expired_entries_not_pruned'
    Assert-Rejected {Claim-Nonce ('4'*32) $script:Now} 'request_replay'
    $saved=[IO.File]::ReadAllBytes($NonceFile)
    [IO.File]::WriteAllBytes($NonceFile,$Utf8.GetBytes('{"schema":"invalid"}'))
    Assert-Rejected {Claim-Nonce ('5'*32) $script:Now} 'body_fields'
    Assert-Test ($Utf8.GetString([IO.File]::ReadAllBytes($NonceFile)) -ceq '{"schema":"invalid"}') 'invalid_state_overwritten'
    [IO.File]::WriteAllBytes($NonceFile,$saved)
    [ordered]@{ok=$true;native_parser=$true;checks=$script:Checks;public_fixture_only=$true;
        real_key_used=$false;listener_started=$false;atomic_replacements=3;replays_rejected=4} | ConvertTo-Json -Compress
} finally {
    if((Split-Path -Parent $Root) -ceq $Parent -and (Split-Path -Leaf $Root) -match '^ServerNet-ProbeNonceTest-[0-9a-f]{32}$' -and (Test-Path -LiteralPath $Root)) {
        if(-not ((Get-Item -LiteralPath $Root -Force).Attributes -band [IO.FileAttributes]::ReparsePoint)) {Remove-Item -LiteralPath $Root -Recurse -Force}
    }
}
