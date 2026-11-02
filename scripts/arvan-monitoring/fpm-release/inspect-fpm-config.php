<?php
declare(strict_types=1);

// CLI configuration-source inspection only. Never bootstraps the app or connects to a socket.
if (PHP_SAPI !== 'cli' || realpath(getcwd()) !== '/home/servernetcloud/servernet_app') { exit(1); }
$home = '/home/servernetcloud';
$prefix = '/opt/cpanel/ea-php84/root';
$allowed = ['user', 'group', 'listen', 'listen.owner', 'listen.group', 'listen.mode', 'chroot', 'chdir',
    'security.limit_extensions', 'php_admin_value[doc_root]', 'php_admin_value[open_basedir]',
    'php_admin_value[opcache.enable]', 'php_admin_value[opcache.validate_timestamps]', 'php_admin_value[opcache.revalidate_freq]',
    'php_admin_flag[opcache.enable]', 'php_admin_flag[opcache.validate_timestamps]',
    'php_value[opcache.enable]', 'php_value[opcache.validate_timestamps]', 'php_value[opcache.revalidate_freq]',
    'opcache.enable', 'opcache.validate_timestamps', 'opcache.revalidate_freq', 'opcache.file_update_protection'];
function fpmSelected(string $path, array $allowed): array {
    if (!is_file($path) || !is_readable($path) || is_link($path) || realpath($path) !== $path) {
        return ['status' => 'unavailable_or_symlink'];
    }
    if (filesize($path) > 1024 * 1024) { return ['status' => 'oversize']; }
    $source = (string) file_get_contents($path); $selected = [];
    foreach (preg_split('/\r?\n/', $source) as $line) {
        if (!preg_match('/^\s*([^;#=\r\n]+?)\s*=\s*(.*?)\s*$/D', $line, $m)) { continue; }
        $key = trim($m[1]);
        if (in_array($key, $allowed, true)) { $selected[$key][] = trim($m[2]); }
    }
    return ['status' => 'read', 'sha256' => hash('sha256', $source), 'mtime_utc' => gmdate('c', filemtime($path)), 'selected' => $selected];
}
$result = ['utc' => gmdate('c'), 'mode' => 'configuration_sources_only',
    'effective_fpm_ini_verified' => false, 'external_or_socket_calls' => 0, 'files_changed' => 0, 'pools' => [], 'ini_sources' => []];
foreach (['servernet.cloud', 'console.servernet.cloud'] as $host) {
    $path = $prefix.'/etc/php-fpm.d/'.$host.'.conf';
    $row = fpmSelected($path, $allowed);
    if (($row['status'] ?? '') === 'read'
        && array_map(fn ($v) => trim($v, "\"'"), $row['selected']['user'] ?? []) === ['servernetcloud']) {
        $listen = $row['selected']['listen'] ?? [];
        if (count($listen) === 1) {
            $socket = trim($listen[0], "\"'");
            if (preg_match('~\A'.preg_quote($prefix, '~').'/usr/var/run/php-fpm/[a-f0-9]{20,80}\.sock\z~D', $socket)
                && !is_link($socket)) {
                $stat = @lstat($socket);
                $row['socket'] = $stat ? ['path' => $socket, 'is_unix_socket' => ($stat['mode'] & 0170000) === 0140000,
                    'owner_uid' => $stat['uid'], 'mode' => decoct($stat['mode'] & 0777)] : ['status' => 'unavailable'];
            }
        }
    }
    $result['pools'][$host] = $row;
}
$ini = [$prefix.'/etc/php.ini', ...(glob($prefix.'/etc/php.d/*.ini') ?: []), $home.'/public_html/.user.ini'];
foreach ($ini as $path) {
    $row = fpmSelected($path, $allowed);
    if (($row['status'] ?? '') === 'read' && $row['selected'] === []) { continue; }
    $result['ini_sources'][$path] = $row;
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
