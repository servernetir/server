<?php
declare(strict_types=1);

// Separate, read-only layout probe. No application/bootstrap, socket connections or writes.
if (PHP_SAPI !== 'cli' || realpath(getcwd()) !== '/home/servernetcloud/servernet_app') { exit(1); }
$version = $argv[1] ?? 'ea-php84';
if (!preg_match('/\Aea-php[0-9]{2,3}\z/D', $version)) { exit(1); }
$prefix = '/opt/cpanel/'.$version.'/root';
$poolDir = $prefix.'/etc/php-fpm.d';
$userdataDir = '/var/cpanel/userdata/servernetcloud';
$allowed = ['user', 'group', 'listen', 'listen.owner', 'listen.group', 'listen.mode', 'chroot', 'chdir',
    'security.limit_extensions', 'php_admin_value[doc_root]', 'php_admin_value[open_basedir]',
    'php_admin_value[opcache.enable]', 'php_admin_value[opcache.validate_timestamps]', 'php_admin_value[opcache.revalidate_freq]',
    'php_admin_value[opcache.file_update_protection]', 'php_admin_value[opcache.restrict_api]',
    'php_admin_flag[opcache.enable]', 'php_admin_flag[opcache.validate_timestamps]',
    'php_value[opcache.enable]', 'php_value[opcache.validate_timestamps]', 'php_value[opcache.revalidate_freq]'];
function layoutMetadata(string $path): array {
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if (!$stat) { return ['status' => 'unavailable', 'readable' => false]; }
    $type = match ($stat['mode'] & 0170000) { 0100000 => 'regular', 0120000 => 'symlink', 0040000 => 'directory', 0140000 => 'unix_socket', default => 'other' };
    $real = @realpath($path);
    return ['status' => 'observed', 'type' => $type, 'realpath' => $real === false ? null : $real,
        'readable' => is_readable($path), 'mode' => decoct($stat['mode'] & 0777), 'uid' => $stat['uid'], 'gid' => $stat['gid']];
}
function layoutPoolSettings(string $path, string $poolDir, string $prefix, array $allowed): array {
    $real = @realpath($path); $realDir = @realpath($poolDir);
    // Symlinks are reported, but inspected only inside this observed version's pool directory.
    if (!$real || !$realDir || !str_starts_with($real, $realDir.'/') || !is_file($real) || !is_readable($real)) {
        return ['status' => 'not_readable_regular_pool_in_observed_directory'];
    }
    clearstatcache(true, $real);
    $before = @stat($real);
    if (!$before || $before['size'] > 1024 * 1024) { return ['status' => 'unavailable_or_oversize']; }
    $bytes = @file_get_contents($real); clearstatcache(true, $real); $after = @stat($real);
    if (!is_string($bytes) || !$after) { return ['status' => 'read_failed']; }
    foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) {
        if ($before[$key] !== $after[$key]) { return ['status' => 'changed_during_read']; }
    }
    $selected = [];
    foreach (preg_split('/\r?\n/', $bytes) as $line) {
        if (!preg_match('/^\s*([^;#=\r\n]+?)\s*=\s*(.*?)\s*$/D', $line, $match)) { continue; }
        $key = trim($match[1]);
        if (in_array($key, $allowed, true)) { $selected[$key][] = trim($match[2]); }
    }
    if (array_map(static fn ($v) => trim($v, "\"'"), $selected['user'] ?? []) !== ['servernetcloud']) {
        return ['status' => 'account_pool_not_confirmed'];
    }
    $result = ['status' => 'confirmed_account_pool', 'sha256' => hash('sha256', $bytes), 'selected' => $selected];
    $listen = $selected['listen'] ?? [];
    if (count($listen) === 1) {
        $socket = trim($listen[0], "\"'");
        if (preg_match('~\A(?:'.preg_quote($prefix, '~').'/usr/var/run/php-fpm/|/home/servernetcloud/)[A-Za-z0-9_.-]+\.sock\z~D', $socket)) {
            $result['observed_listen_socket'] = ['path' => $socket] + layoutMetadata($socket);
        }
    }
    return $result;
}
$result = ['utc' => gmdate('c'), 'mode' => 'read_only_layout_discovery', 'version_directory' => $version,
    'version_source' => isset($argv[1]) ? 'explicit_coordinator_argument' : 'previously_observed_cli_version_only',
    'effective_fpm_ini_verified' => false, 'socket_connections' => 0, 'network_calls' => 0, 'files_changed' => 0,
    'directories' => []];
foreach ([$poolDir, $userdataDir] as $dir) {
    $row = layoutMetadata($dir); $row['matching_entries'] = [];
    $names = @scandir($dir);
    $row['listing_status'] = is_array($names) ? 'listed_matching_names_only' : 'unavailable';
    foreach (is_array($names) ? $names : [] as $name) {
        if (!str_contains(strtolower($name), 'servernet') || str_contains($name, '/') || str_contains($name, "\0")) { continue; }
        $entry = layoutMetadata($dir.'/'.$name);
        if ($dir === $poolDir) { $entry['pool'] = layoutPoolSettings($dir.'/'.$name, $poolDir, $prefix, $allowed); }
        // Account userdata is metadata-only: do not print customer/config contents.
        $row['matching_entries'][$name] = $entry;
    }
    $result['directories'][$dir] = $row;
}
$home = '/home/servernetcloud';
$result['observed_home_socket_basenames'] = ['directory' => layoutMetadata($home), 'entries' => []];
$homeNames = @scandir($home);
$result['observed_home_socket_basenames']['listing_status'] = is_array($homeNames) ? 'listed_matching_names_only' : 'unavailable';
foreach (is_array($homeNames) ? $homeNames : [] as $name) {
    if (!str_contains(strtolower($name), 'servernet') || !preg_match('/\A[A-Za-z0-9_.-]+\.php_fpm\.sock\z/D', $name)) { continue; }
    $row = layoutMetadata($home.'/'.$name);
    $row['mapping_evidence'] = 'observed_basename_only_not_pool_configuration';
    $possible = ['servernet_cloud.php_fpm.sock' => 'servernet.cloud', 'console_servernet_cloud.php_fpm.sock' => 'console.servernet.cloud'];
    if (isset($possible[$name])) { $row['possible_vhost_from_documented_scrubbed_name'] = $possible[$name]; }
    $result['observed_home_socket_basenames']['entries'][$name] = $row;
}
$result['known_user_ini_metadata'] = layoutMetadata('/home/servernetcloud/public_html/.user.ini');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
