Set-StrictMode -Version Latest

# Pure functions only. Importing this module performs no host inspection.
function Get-ObservedValue {
    param([System.Collections.IDictionary]$Values, [string]$Key)
    if ($null -ne $Values -and $Values.Contains($Key)) { return $Values[$Key] }
    return $null
}

function ConvertTo-BooleanEvidence {
    param($Value)
    if ($Value -is [bool]) { return $(if ($Value) { 'true' } else { 'false' }) }
    if ($Value -is [string]) {
        switch ($Value.Trim().ToLowerInvariant()) {
            'true' { return 'true' }
            'false' { return 'false' }
        }
    }
    return 'unknown'
}

function ConvertTo-BinarySwitchEvidence {
    param($Value, [switch]$Invert)
    # Registry DWORDs are numeric. A missing/invalid value is not zero.
    if ($Value -isnot [int] -and $Value -isnot [uint32] -and $Value -isnot [long]) { return 'unknown' }
    if ($Value -ne 0 -and $Value -ne 1) { return 'unknown' }
    $enabled = $Value -eq 1
    if ($Invert) { $enabled = -not $enabled }
    if ($enabled) { return 'enabled' }
    return 'disabled'
}

function Read-SelectedConfigValues {
    param($Text)
    $result = @{}
    if ($Text -isnot [string] -or $Text.Length -gt 1048576) { return $result }
    $selected = @('inject_user_password', 'first_logon_behaviour', 'allow_reboot',
        'stop_service_on_exit', 'plugins', 'metadata_services', 'config_drive_types', 'config_drive_locations')
    $section = 'default'
    $previousKey = $null
    foreach ($line in ($Text -split "`n")) {
        $clean = $line.Trim()
        if ($clean -match '^\[([^\]]+)\]$') { $section = $Matches[1].ToLowerInvariant(); $previousKey = $null; continue }
        if ($section -ne 'default' -or $clean -eq '' -or $clean -match '^[#;]') { continue }
        if ($clean -notmatch '^([a-z_]+)\s*=\s*(.*)$') {
            # We intentionally do not implement the full INI continuation
            # grammar. An incomplete list must never imply a missing plugin.
            if ($null -ne $previousKey) { $result[$previousKey] = $null }
            continue
        }
        $key = $Matches[1].ToLowerInvariant()
        $previousKey = $null
        if ($selected -notcontains $key) { continue }
        $previousKey = $key
        # Duplicates are ambiguous, not last-writer-wins evidence.
        if ($result.ContainsKey($key)) { $result[$key] = $null; continue }
        $result[$key] = $Matches[2].Trim()
    }
    return $result
}

function ConvertTo-Categories {
    param($Value, [hashtable]$Allowed)
    $categories = @()
    $status = 'unknown'
    if ($Value -is [string] -and $Value.Trim() -ne '') {
        $status = 'observed'
        foreach ($item in ($Value -split ',')) {
            $entry = $item.Trim().ToLowerInvariant()
            if ($Allowed.ContainsKey($entry)) { $categories += $Allowed[$entry] }
            else { $status = 'partial' }
        }
    }
    return [ordered]@{ evidence = $status; categories = @($categories | Sort-Object -Unique) }
}

function ConvertTo-ArvanWindowsBootstrapEvidence {
    [CmdletBinding()]
    param([System.Collections.IDictionary]$Observations = @{})

    $config = Read-SelectedConfigValues (Get-ObservedValue $Observations 'ConfigText')
    $plugins = ConvertTo-Categories (Get-ObservedValue $config 'plugins') @{
        'cloudbaseinit.plugins.windows.createuser.createuserplugin' = 'create_user'
        'cloudbaseinit.plugins.windows.setuserpassword.setuserpasswordplugin' = 'set_user_password'
        'cloudbaseinit.plugins.common.setuserpassword.setuserpasswordplugin' = 'set_user_password'
        'cloudbaseinit.plugins.common.userdata.userdataplugin' = 'user_data'
        'cloudbaseinit.plugins.common.localscripts.localscriptsplugin' = 'local_scripts'
        'cloudbaseinit.plugins.common.mtu.mtuplugin' = 'mtu'
        'cloudbaseinit.plugins.common.sethostname.sethostnameplugin' = 'host_name'
        'cloudbaseinit.plugins.common.networkconfig.networkconfigplugin' = 'network_config'
        'cloudbaseinit.plugins.windows.extendvolumes.extendvolumesplugin' = 'extend_volumes'
        'cloudbaseinit.plugins.common.sshpublickeys.setusersshpublickeysplugin' = 'ssh_public_keys'
    }
    $metadata = ConvertTo-Categories (Get-ObservedValue $config 'metadata_services') @{
        'cloudbaseinit.metadata.services.configdrive.configdriveservice' = 'config_drive'
        'cloudbaseinit.metadata.services.nocloudservice.nocloudconfigdriveservice' = 'nocloud_config_drive'
        'cloudbaseinit.metadata.services.httpservice.httpservice' = 'openstack_http'
        'cloudbaseinit.metadata.services.ec2service.ec2service' = 'ec2'
        'cloudbaseinit.metadata.services.maasservice.maasservice' = 'maas'
        'cloudbaseinit.metadata.services.gceservice.gceservice' = 'gce'
    }
    $driveTypes = ConvertTo-Categories (Get-ObservedValue $config 'config_drive_types') @{ 'vfat' = 'vfat'; 'iso' = 'iso' }
    $driveLocations = ConvertTo-Categories (Get-ObservedValue $config 'config_drive_locations') @{ 'cdrom' = 'cdrom'; 'hdd' = 'hdd' }
    $firstLogon = Get-ObservedValue $config 'first_logon_behaviour'
    if ($firstLogon -isnot [string] -or @('no', 'always', 'clear_text_injected_only') -notcontains $firstLogon.ToLowerInvariant()) {
        $firstLogon = 'unknown'
    } else { $firstLogon = $firstLogon.ToLowerInvariant() }
    $serviceState = Get-ObservedValue $Observations 'ServiceState'
    if ($serviceState -is [string] -and @('running', 'stopped', 'paused') -contains $serviceState.ToLowerInvariant()) {
        $serviceState = $serviceState.ToLowerInvariant()
    } else { $serviceState = 'unknown' }
    $serviceStart = Get-ObservedValue $Observations 'ServiceStartMode'
    if ($serviceStart -is [string] -and @('auto', 'manual', 'disabled') -contains $serviceStart.ToLowerInvariant()) {
        $serviceStart = $serviceStart.ToLowerInvariant()
    } else { $serviceStart = 'unknown' }
    $sid500 = Get-ObservedValue $Observations 'Sid500Presence'
    if ($sid500 -isnot [string] -or @('present', 'not_observed') -notcontains $sid500.ToLowerInvariant()) {
        $sid500 = 'unknown'
    } else { $sid500 = $sid500.ToLowerInvariant() }
    $firewall = Get-ObservedValue $Observations 'Firewall'
    if ($firewall -isnot [System.Collections.IDictionary]) { $firewall = @{} }
    $profileEvidence = [ordered]@{}
    foreach ($profile in @('Domain', 'Private', 'Public')) {
        $profileEvidence[$profile.ToLowerInvariant()] = ConvertTo-BooleanEvidence (Get-ObservedValue $firewall $profile)
    }
    $rdp = ConvertTo-BinarySwitchEvidence (Get-ObservedValue $Observations 'RdpDenyConnections') -Invert
    $nla = ConvertTo-BinarySwitchEvidence (Get-ObservedValue $Observations 'NlaUserAuthentication')
    $concerns = @()
    if ($nla -eq 'disabled') { $concerns += 'nla_disabled' }
    if (@($profileEvidence.Values) -contains 'false') { $concerns += 'firewall_profile_disabled' }
    if ($rdp -eq 'unknown' -or $nla -eq 'unknown' -or @($profileEvidence.Values) -contains 'unknown') { $concerns += 'security_observations_incomplete' }
    $passwordConfig = 'unknown'
    if ($plugins.evidence -eq 'observed') {
        if ($plugins.categories -contains 'set_user_password') { $passwordConfig = 'password_plugin_configured_only' }
        else { $passwordConfig = 'no_password_plugin_in_observed_list' }
    }

    # Only fixed keys, enums and categories leave this function. Never return
    # raw config, paths, account names, SIDs, addresses, keys or exceptions.
    return [ordered]@{
        schema_version = 1
        scope = 'read_only_local_guest_observations'
        config_source = 'standard_path_candidate_not_verified_effective_config'
        cloudbase = [ordered]@{
            service_state = $serviceState
            service_start_mode = $serviceStart
            inject_user_password = ConvertTo-BooleanEvidence (Get-ObservedValue $config 'inject_user_password')
            first_logon_behaviour = $firstLogon
            allow_reboot = ConvertTo-BooleanEvidence (Get-ObservedValue $config 'allow_reboot')
            stop_service_on_exit = ConvertTo-BooleanEvidence (Get-ObservedValue $config 'stop_service_on_exit')
            plugins = $plugins
            metadata_services = $metadata
            config_drive_types = $driveTypes
            config_drive_locations = $driveLocations
        }
        local_administrator_sid500 = $sid500
        rdp = $rdp
        nla = $nla
        firewall_enabled = $profileEvidence
        assessment = [ordered]@{
            password_configuration = $passwordConfig
            concerns = @($concerns)
            arvan_delivery = 'unproven'
            production_readiness = 'not_established'
        }
    }
}

Export-ModuleMember -Function ConvertTo-ArvanWindowsBootstrapEvidence
