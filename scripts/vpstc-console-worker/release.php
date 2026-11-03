<?php
declare(strict_types=1);

// Private CLI-only release. Prepare preserves all unrelated live bytes; apply
// checks the exact prepared hashes again under both shared account release locks.
if (PHP_SAPI !== 'cli' || realpath(getcwd()) !== '/home/servernetcloud/servernet_app') {
    throw new RuntimeException('Wrong runtime or application root');
}
umask(0077);
$root = getcwd(); $dir = __DIR__; $mode = $argv[1] ?? 'prepare';
if (! in_array($mode, ['prepare', '--apply'], true) || count($argv) > 2
    || ! str_starts_with($dir, $root.'/storage/app/private/vpstc-console-')
    || is_link($dir) || realpath($dir) !== $dir || (fileperms($dir) & 0777) !== 0700) {
    throw new RuntimeException('Unsafe release directory or mode');
}
$locks = [];
foreach (['.servernet-web-release.lock', 'servernet-release.lock'] as $name) {
    $path = '/home/servernetcloud/'.$name;
    if (is_link($path)) { throw new RuntimeException('Unsafe release lock'); }
    $handle = fopen($path, 'c+b');
    if (! $handle || ! flock($handle, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Another release is active'); }
    $locks[] = $handle;
}
$allowed = ['app/Services/Cloud/VpsTcConsole.php', 'app/Services/Cloud/VpsTcConsoleBroker.php',
    'app/Console/Commands/VpsTcConsoleProbe.php', 'app/Http/Controllers/Account/CloudServerController.php', 'routes/web.php',
    'app/Http/Middleware/SecurityHeaders.php'];
function readSafe(string $path): string
{
    clearstatcache(true, $path);
    if (! is_file($path) || is_link($path) || realpath($path) !== $path || fileowner($path) !== posix_geteuid()) {
        throw new RuntimeException('Unsafe input: '.basename($path));
    }
    return file_get_contents($path);
}
function saveNew(string $path, string $bytes): void
{
    $f = fopen($path, 'x');
    if (! $f) { throw new RuntimeException('Release artifact already exists'); }
    try {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $n = fwrite($f, substr($bytes, $offset));
            if (! $n) { throw new RuntimeException('Short release write'); }
            $offset += $n;
        }
        if (! fflush($f) || ! fsync($f)) { throw new RuntimeException('Release flush failed'); }
    } finally { fclose($f); }
    if (hash_file('sha256', $path) !== hash('sha256', $bytes)) { throw new RuntimeException('Release readback failed'); }
}
function decode(string $v): string
{
    $s = base64_decode($v, true);
    if ($s === false) { throw new RuntimeException('Invalid payload'); }
    return $s;
}
function patch(string $source, array $hunks): string
{
    $eol = str_contains($source, "\r\n") ? "\r\n" : "\n";
    if (str_contains(str_replace("\r\n", '', $source), "\r")) { throw new RuntimeException('Unsupported line endings'); }
    foreach ($hunks as $h) {
        $before = str_replace("\n", $eol, decode($h['before']));
        $after = str_replace("\n", $eol, decode($h['after']));
        if ($before === '' || substr_count($source, $before) !== 1) { throw new RuntimeException('Live patch context changed'); }
        $source = str_replace($before, $after, $source);
    }
    return $source;
}
function lint(string $path): void
{
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path).' 2>&1', $output, $code);
    if ($code !== 0) { throw new RuntimeException('Lint failed: '.basename($path)); }
}
function jsonBytes(array $v): string { return json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"; }
$bytes = readSafe($dir.'/payload.json'); $payloadHash = '__PAYLOAD_SHA256__';
if (hash('sha256', $bytes) !== $payloadHash) { throw new RuntimeException('Release payload mismatch'); }
$payload = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
if (array_column($payload['files'] ?? [], 'path') === ['app/Http/Middleware/SecurityHeaders.php']) {
    $allowed = ['app/Http/Middleware/SecurityHeaders.php'];
}
if (($payload['schema'] ?? null) !== 1 || array_column($payload['files'] ?? [], 'path') !== $allowed) {
    throw new RuntimeException('Unexpected release scope');
}
if ($mode === 'prepare') {
    if (file_exists($dir.'/manifest.json') || file_exists($dir.'/apply-attempt.json')) { throw new RuntimeException('Inspect existing release'); }
    $prepared = [];
    foreach ($payload['files'] as $item) {
        $path = $item['path']; $dest = $root.'/'.$path;
        if ($item['kind'] === 'new') {
            if (file_exists($dest) || is_link($dest)) { throw new RuntimeException('Unexpected existing class'); }
            $before = null; $after = decode($item['content']); $permissions = 0644;
        } else {
            $before = readSafe($dest); $after = patch($before, $item['hunks']); $permissions = fileperms($dest) & 0777;
        }
        $prepared[$path] = compact('before', 'after', 'permissions');
    }
    $manifest = ['payload_sha256' => $payloadHash, 'files' => []];
    foreach ($prepared as $path => $v) {
        $name = basename($path);
        if ($v['before'] !== null) { saveNew($dir.'/'.$name.'.before', $v['before']); }
        saveNew($dir.'/'.$name.'.after', $v['after']); lint($dir.'/'.$name.'.after');
        $manifest['files'][$path] = ['before' => $v['before'] === null ? null : hash('sha256', $v['before']),
            'after' => hash('sha256', $v['after']), 'mode' => $v['permissions']];
    }
    saveNew($dir.'/manifest.json', jsonBytes($manifest));
    echo jsonBytes(['prepared' => true, 'manifest' => $manifest, 'installed' => false,
        'route_cache_present' => count(glob($root.'/bootstrap/cache/routes*.php')) > 0]);
    exit;
}
$manifest = json_decode(readSafe($dir.'/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
if (($manifest['payload_sha256'] ?? null) !== $payloadHash || array_keys($manifest['files']) !== $allowed
    || file_exists($dir.'/apply-attempt.json') || count(glob($root.'/bootstrap/cache/routes*.php')) > 0) {
    throw new RuntimeException('Inspect release state or route cache before applying');
}
function checkDestination(string $path, ?string $hash): void
{
    clearstatcache(true, $path);
    if ($hash === null) {
        if (file_exists($path) || is_link($path)) { throw new RuntimeException('New destination appeared'); }
    } elseif (hash('sha256', readSafe($path)) !== $hash) { throw new RuntimeException('Concurrent source change'); }
}
foreach ($manifest['files'] as $path => $v) {
    checkDestination($root.'/'.$path, $v['before']);
    if (hash('sha256', readSafe($dir.'/'.basename($path).'.after')) !== $v['after']) { throw new RuntimeException('Prepared source changed'); }
    lint($dir.'/'.basename($path).'.after');
}
saveNew($dir.'/apply-attempt.json', jsonBytes(['utc' => gmdate('c')])); $installed = [];
try {
    foreach ($manifest['files'] as $path => $v) {
        checkDestination($root.'/'.$path, $v['before']);
        $stage = $dir.'/'.basename($path).'.install';
        saveNew($stage, readSafe($dir.'/'.basename($path).'.after'));
        if (! chmod($stage, $v['mode'])) { throw new RuntimeException('Cannot preserve permissions'); }
        checkDestination($root.'/'.$path, $v['before']);
        if (! rename($stage, $root.'/'.$path)) { throw new RuntimeException('Atomic install failed'); }
        $installed[] = $path;
        if (hash_file('sha256', $root.'/'.$path) !== $v['after']) { throw new RuntimeException('Installed hash mismatch'); }
    }
    saveNew($dir.'/receipt.json', jsonBytes(['installed' => true, 'utc' => gmdate('c'), 'files' => $manifest['files'], 'enabled' => false]));
} catch (Throwable $e) {
    $blocked = [];
    foreach (array_reverse($installed) as $path) {
        try {
            $v = $manifest['files'][$path]; checkDestination($root.'/'.$path, $v['after']);
            if ($v['before'] === null) { if (! unlink($root.'/'.$path)) { throw new RuntimeException('Rollback failed'); } }
            else {
                $stage = $dir.'/'.basename($path).'.rollback'; $before = readSafe($dir.'/'.basename($path).'.before');
                if (hash('sha256', $before) !== $v['before']) { throw new RuntimeException('Backup mismatch'); }
                saveNew($stage, $before); chmod($stage, $v['mode']);
                checkDestination($root.'/'.$path, $v['after']);
                if (! rename($stage, $root.'/'.$path)) { throw new RuntimeException('Rollback failed'); }
            }
        } catch (Throwable) { $blocked[] = $path; }
    }
    saveNew($dir.'/failure.json', jsonBytes(['manual_review_required' => $blocked]));
    throw new RuntimeException('Installation failed; inspect private release result', 0, $e);
}
echo jsonBytes(['installed' => true, 'files' => $allowed, 'enabled' => false]);
