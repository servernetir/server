#Requires -Version 5.1
<#
Offline native parser/contract checks. This does not install a task, read a
private enrollment/key, change access, start a listener or run curl. Invoke in
a separate PowerShell process. The optional fixture contains only public test
key bytes (0..31), synthetic identity and loopback enrollment, never live state.
#>
param([switch]$EmitWireFixture)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$Runtime = Join-Path (Split-Path -Parent $PSScriptRoot) 'windows_probe.ps1'
$tokens = $null; $parseErrors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile($Runtime, [ref]$tokens, [ref]$parseErrors)
if (@($parseErrors).Count -ne 0) { throw 'runtime_native_parse_failed' }
$names = @('Get-Hex', 'Get-Hash', 'Get-Signature', 'Test-FixedEqual', 'Assert-Fields', 'Test-Integer', 'Test-CandidateProxyIPv4', 'Get-CandidateBindingHash', 'ConvertTo-CandidateProxy', 'ConvertTo-CandidateRequest', 'New-CandidateReply')
$definitions = @($ast.FindAll({ param($node) $node -is [System.Management.Automation.Language.FunctionDefinitionAst] }, $false))
$selected = @()
foreach ($name in $names) {
    $matches = @($definitions | Where-Object { $_.Name -ceq $name })
    if ($matches.Count -ne 1) { throw 'test_function_definition_missing_or_duplicate' }
    $selected += $matches[0]
}
# Preserve complete function declarations: function Name($Arg) stores its
# parameters outside Body, so loading Body alone would silently lose them.
# The loader AST must contain ONLY these whitelisted declarations; the runtime
# entry point, configuration reads, listener and probe functions are excluded.
$declarations = ($selected | ForEach-Object { $_.Extent.Text }) -join "`n"
$loader = [ScriptBlock]::Create($declarations)
if (@($loader.Ast.EndBlock.Statements | Where-Object { $_ -isnot [System.Management.Automation.Language.FunctionDefinitionAst] }).Count -ne 0) { throw 'test_loader_contains_runtime_statements' }
. $loader
$Utf8 = [Text.UTF8Encoding]::new($false, $true)
$Path = '/relay-probe/'
function Get-UnixTime { return [long]1800000000 }
$script:Checks = 0
function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:Checks++
}
function Assert-Rejected([scriptblock]$Action, [string]$Code) {
    $rejected = $false
    try { & $Action | Out-Null } catch { $rejected = $true }
    Assert-True $rejected $Code
}
function Copy-TestObject($Value) { return $Value | ConvertTo-Json -Depth 12 -Compress | ConvertFrom-Json }

$enrollment = [PSCustomObject][ordered]@{ agent_id = 'ir-agent'; enabled = $true; proxy_binding_sha256 = '04f6342cc8daf6e22e5a3ed08ff67f9eba8062a03dd3a8397f9cb97b39bb8e76'; proxy_host = '127.0.0.1'; proxy_port = 10829; schema = 'servernet.relay.vm-candidate-proxy.v1' }
Assert-True ((Get-CandidateBindingHash $enrollment) -ceq $enrollment.proxy_binding_sha256) 'python_binding_vector_mismatch'
$secondBindingVector = Copy-TestObject $enrollment
$secondBindingVector.proxy_port = 19081
Assert-True ((Get-CandidateBindingHash $secondBindingVector) -ceq '97c326d5bbf6f92eb8729448f714458e69b6837f028f66fd234ee0a7329e7ce7') 'python_second_binding_vector_mismatch'
$proxy = ConvertTo-CandidateProxy $enrollment 'ir-agent'
Assert-True ($proxy.proxy_host -ceq '127.0.0.1' -and $proxy.proxy_port -eq 10829) 'fixed_enrollment_not_preserved'
foreach ($value in @('127.0.0.2', '8.8.8.8', '169.254.1.1', 'example.com', '10.0.0.1;whoami', '10.0.0.01', '::1')) {
    Assert-True (-not (Test-CandidateProxyIPv4 $value)) 'unsafe_proxy_address_accepted'
}
foreach ($value in @('127.0.0.1', '10.0.0.1', '172.16.0.1', '172.31.255.254', '192.168.1.1')) {
    Assert-True (Test-CandidateProxyIPv4 $value) 'private_proxy_address_rejected'
}
foreach ($change in @(
    { param($v) $v.enabled = $false },
    { param($v) $v.enabled = 'true' },
    { param($v) $v.agent_id = 'other-agent' },
    { param($v) $v.proxy_host = '8.8.8.8' },
    { param($v) $v.proxy_port = 80 },
    { param($v) $v.proxy_port = 10829.0 },
    { param($v) $v.proxy_binding_sha256 = '0' * 64 },
    { param($v) $v | Add-Member -NotePropertyName url -NotePropertyValue 'https://example.com' }
)) {
    $bad = Copy-TestObject $enrollment
    & $change $bad
    Assert-Rejected { ConvertTo-CandidateProxy $bad 'ir-agent' } 'invalid_enrollment_accepted'
}

$scope = [ordered]@{ agent_id = 'ir-agent'; config_hash = 'a' * 64; generation = [long]2; incident_id = 'incident-1'; jobs_hash = 'b' * 64; revision = [long]7 }
$target = [ordered]@{ host = '9.9.9.9'; port = 22; provider_id = '51' }
$candidate = [PSCustomObject][ordered]@{ dependency_baseline_sha256 = 'e' * 64; path_profile = 'candidate_socks_v1'; proxy_binding_sha256 = $proxy.proxy_binding_sha256; registry_sha256 = 'd' * 64; resource_config_hash = 'a' * 64; resource_identity = [PSCustomObject][ordered]@{ generation = 2; project_id = 'project-1'; provider = 'hetzner'; provider_id = '51' }; restore_epoch = 1; restore_hash = 'c' * 64; stage_profile = 'restored' }
$canonical = ConvertTo-CandidateRequest $candidate $scope $target $proxy
$request = [ordered]@{ candidate = $canonical; op = 'candidate_probe'; scope = $scope; target = $target } | ConvertTo-Json -Depth 8 -Compress
$requestBytes = $Utf8.GetBytes($request)
Assert-True ((Get-Hash $requestBytes) -ceq 'e9b6e60a9ab34c2d3e24a997499a6092765ee37222a8834c7d487abd2404e617') 'python_request_canonical_vector_mismatch'
Assert-True ((Get-Signature ([byte[]](0..31)) 'POST' '1800000000' ('1' * 32) $requestBytes) -ceq 'd4397532a04791f9222863f08324a35c098316b464f9bb1df896d37ed717fdae') 'python_request_hmac_vector_mismatch'
foreach ($change in @(
    { param($v) $v.path_profile = 'default_path_v1' },
    { param($v) $v.proxy_binding_sha256 = '0' * 64 },
    { param($v) $v.registry_sha256 = 'bad' },
    { param($v) $v.dependency_baseline_sha256 = $null },
    { param($v) $v.resource_identity.generation = 3 },
    { param($v) $v.resource_identity.generation = 2.0 },
    { param($v) $v.resource_identity.generation = $true },
    { param($v) $v.resource_identity.provider_id = '52' },
    { param($v) $v.resource_identity.provider = 'other' },
    { param($v) $v.resource_identity.project_id = 'bad project' },
    { param($v) $v.restore_epoch = $null },
    { param($v) $v.restore_epoch = $true },
    { param($v) $v.restore_hash = 'BAD' },
    { param($v) $v.stage_profile = 'trial' },
    { param($v) $v | Add-Member -NotePropertyName command -NotePropertyValue 'whoami' }
)) {
    $bad = Copy-TestObject $candidate
    & $change $bad
    Assert-Rejected { ConvertTo-CandidateRequest $bad $scope $target $proxy } 'invalid_candidate_request_accepted'
}
$trial = Copy-TestObject $candidate
$trial.stage_profile = 'trial'; $trial.restore_epoch = $null; $trial.restore_hash = $null
$trialCanonical = ConvertTo-CandidateRequest $trial $scope $target $proxy
Assert-True ($null -eq $trialCanonical.restore_epoch -and $null -eq $trialCanonical.restore_hash) 'trial_epoch_not_null'

$observation = @{ source_ip = '10.10.10.50'; egress_ip = '9.9.9.9' }
$reply = New-CandidateReply $canonical $scope $target ('1' * 32) $observation 'source_ip_hmac_v1'
$replyBytes = $Utf8.GetBytes(($reply | ConvertTo-Json -Depth 8 -Compress))
Assert-True ((Get-Hash $replyBytes) -ceq 'b68c9b3afcd5f4cd18d9aeb786c7bd632ed2bdc17eb127c330d9e02f038fbf51') 'python_response_canonical_vector_mismatch'
$signature = Get-Signature ([byte[]](0..31)) 'RESPONSE' '1800000000' ('2' * 32) $replyBytes
Assert-True ($signature -ceq '764c3ec8dcf2773b33225aa2d5ea0944b6997a62d5f26b232fdf08754de57e1c') 'python_response_hmac_vector_mismatch'
Assert-True ($reply.schema -ceq 'servernet.relay.vm-candidate-probe.v1' -and $reply.path_profile -ceq 'candidate_socks_v1' -and $reply.ok -and $reply.read_only) 'candidate_response_scope_mismatch'
$wrongSource = New-CandidateReply $canonical $scope $target ('1' * 32) @{ source_ip = '10.10.10.20'; egress_ip = '9.9.9.9' } 'source_ip_hmac_v1'
$wrongEgress = New-CandidateReply $canonical $scope $target ('1' * 32) @{ source_ip = '10.10.10.50'; egress_ip = '8.8.8.8' } 'source_ip_hmac_v1'
Assert-True (-not $wrongSource.ok -and -not $wrongSource.probe_ok -and -not $wrongSource.tunnel_ok) 'wrong_source_became_candidate_success'
Assert-True (-not $wrongEgress.ok -and -not $wrongEgress.probe_ok -and -not $wrongEgress.tunnel_ok) 'wrong_egress_became_candidate_success'
$defaultPolicy = New-CandidateReply $canonical $scope $target ('1' * 32) $observation 'windows_firewall_v1'
Assert-True (-not $defaultPolicy.Contains('access_policy')) 'default_policy_response_changed'

if ($EmitWireFixture) {
    [ordered]@{ checks = $script:Checks; native_parser = $true; live_probe = $false; request_body_base64 = [Convert]::ToBase64String($requestBytes); response_body_base64 = [Convert]::ToBase64String($replyBytes); response_headers = [ordered]@{ 'X-VM-Timestamp' = '1800000000'; 'X-VM-Nonce' = '22222222222222222222222222222222'; 'X-VM-Signature' = $signature } } | ConvertTo-Json -Depth 5 -Compress
} else {
    [ordered]@{ checks = $script:Checks; native_parser = $true; live_probe = $false; ok = $true } | ConvertTo-Json -Compress
}
