<?php
declare(strict_types=1);

// Build with the companion packager. Two console sources only; no application
// bootstrap, settings/cache changes, provider calls or database writes.
// 1. Run with no arguments to prepare and inspect drift. 2. Separately --apply.
// Installation does not activate/revalidate FPM OPcache or authorize a pilot.
if (PHP_SAPI !== 'cli' || realpath(getcwd()) !== '/home/servernetcloud/servernet_app') {
    throw new RuntimeException('Wrong runtime or app root');
}
umask(0077);
$root = getcwd();
$dir = __DIR__;
if (!preg_match('~\A/home/servernetcloud/servernet_app/storage/app/arvan-owner-console-20261018-[a-zA-Z0-9-]+\z~', $dir)
    || is_link($dir) || realpath($dir) !== $dir || (fileperms($dir) & 0777) !== 0700
    || fileowner($dir) !== posix_geteuid()) {
    throw new RuntimeException('Unsafe private release directory');
}
$mode = $argv[1] ?? 'prepare';
if (count($argv) > 2 || !in_array($mode, ['prepare', '--apply'], true)) {
    throw new RuntimeException('Only prepare or --apply is supported');
}
$lockPath = $root.'/storage/framework/arvan-console-release.lock';
if (is_link($lockPath)) { throw new RuntimeException('Unsafe release lock'); }
$lock = fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Release busy'); }
$allowed = [
    'app/Http/Controllers/Account/CloudServerController.php' => '4a5ac214d51b511fc653b4f4fab8e2a14eb8d154d17b191d57c534f4faeb806f',
    'app/Services/Cloud/ArvanConsoleBroker.php' => '48fc53a2b8796254ac036bf83f07b34c1b03085e91f319dbe414d3d18725c7bc',
];
function readSafe(string $file): string
{
    clearstatcache(true, $file);
    if (is_link($file) || !is_file($file) || realpath($file) !== $file || fileowner($file) !== posix_geteuid()) {
        throw new RuntimeException('Unsafe source: '.basename($file));
    }
    $bytes = file_get_contents($file);
    if ($bytes === false) { throw new RuntimeException('Read failed'); }
    return $bytes;
}
function exclusive(string $file, string $bytes): void
{
    $handle = fopen($file, 'x');
    if (!$handle) { throw new RuntimeException('Release file already exists or cannot be created: '.basename($file)); }
    try {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $count = fwrite($handle, substr($bytes, $offset));
            if (!$count) { throw new RuntimeException('Short release write'); }
            $offset += $count;
        }
        if (!fflush($handle) || !fsync($handle)) { throw new RuntimeException('Release flush failed'); }
    } finally { fclose($handle); }
    if (hash('sha256', readSafe($file)) !== hash('sha256', $bytes)) { throw new RuntimeException('Release verification failed'); }
}
function jsonBytes(array $value): string { return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"; }
function decode(string $value): string
{
    $bytes = base64_decode($value, true);
    if ($bytes === false) { throw new RuntimeException('Invalid payload encoding'); }
    return $bytes;
}
function patch(string $source, array $hunks): string
{
    $plain = str_replace("\r\n", "\n", $source);
    if (str_contains($plain, "\r") || (str_contains($source, "\r\n") && str_contains(str_replace("\r\n", '', $source), "\n"))) {
        throw new RuntimeException('Mixed source line endings');
    }
    $eol = str_contains($source, "\r\n") ? "\r\n" : "\n";
    foreach ($hunks as $hunk) {
        $before = str_replace("\n", $eol, decode($hunk['before_b64']));
        $after = str_replace("\n", $eol, decode($hunk['after_b64']));
        if ($before === '' || substr_count($source, $before) !== 1) { throw new RuntimeException('Non-unique live patch context'); }
        $source = str_replace($before, $after, $source);
    }
    return $source;
}
function lint(string $file): void
{
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $output, $status);
    if ($status !== 0) { throw new RuntimeException('Lint failed: '.basename($file)); }
}
$payloadBytes = readSafe($dir.'/payload.json');
$payloadHash = '__PAYLOAD_SHA256__';
if (!preg_match('/\A[a-f0-9]{64}\z/', $payloadHash) || hash('sha256', $payloadBytes) !== $payloadHash) {
    throw new RuntimeException('Unbuilt or changed payload');
}
$payload = json_decode($payloadBytes, true, 512, JSON_THROW_ON_ERROR);
if (($payload['schema'] ?? null) !== 1 || ($payload['base_commit'] ?? null) !== 'd10c54974abe3fdf399e9ec00659bc8e273c5686'
    || array_column($payload['files'] ?? [], 'path') !== array_keys($allowed)) { throw new RuntimeException('Unexpected release scope'); }
foreach ($payload['files'] as $item) {
    $base = decode($item['base_b64']);
    $patched = decode($item['patched_b64']);
    if ($item['expected_live_sha256'] !== $allowed[$item['path']] || hash('sha256', $base) !== $item['base_sha256']
        || hash('sha256', $patched) !== $item['patched_sha256'] || patch($base, $item['hunks']) !== $patched) {
        throw new RuntimeException('Git base-to-patched source contract failed');
    }
}
if ($mode === 'prepare') {
    foreach (['manifest.json', 'apply-attempt.json', 'receipt.json', 'failure.json'] as $name) {
        if (file_exists($dir.'/'.$name) || is_link($dir.'/'.$name)) { throw new RuntimeException('Existing release state; do not rerun'); }
    }
    $manifest = ['payload_sha256' => $payloadHash, 'base_commit' => $payload['base_commit'], 'files' => []];
    // Validate all live inputs before creating any backup/stage artifacts.
    $prepared = [];
    foreach ($payload['files'] as $item) {
        $path = $item['path'];
        $before = readSafe($root.'/'.$path);
        if (hash('sha256', $before) !== $allowed[$path]) { throw new RuntimeException('Changed live source: '.$path); }
        $after = patch($before, $item['hunks']);
        $prepared[$path] = [$before, $after, $item];
    }
    foreach ($prepared as $path => [$before, $after, $item]) {
        $name = basename($path);
        foreach (['before' => $before, 'after' => $after, 'git-base' => decode($item['base_b64']), 'git-patched' => decode($item['patched_b64'])] as $suffix => $bytes) {
            exclusive($dir.'/'.$name.'.'.$suffix, $bytes);
        }
        lint($dir.'/'.$name.'.after');
        $manifest['files'][$path] = ['before' => hash('sha256', $before), 'after' => hash('sha256', $after),
            'mode' => fileperms($root.'/'.$path) & 0777, 'group' => filegroup($root.'/'.$path), 'owner' => fileowner($root.'/'.$path)];
    }
    exclusive($dir.'/manifest.json', jsonBytes($manifest));
    echo jsonBytes(['prepared' => true, 'manifest' => $manifest, 'installed' => false]);
    exit;
}
$manifest = json_decode(readSafe($dir.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
if (($manifest['payload_sha256'] ?? null) !== $payloadHash || array_keys($manifest['files'] ?? []) !== array_keys($allowed)) {
    throw new RuntimeException('Prepared manifest mismatch');
}
foreach ($payload['files'] as $item) {
    $path = $item['path']; $record = $manifest['files'][$path]; $name = basename($path);
    $before = readSafe($dir.'/'.$name.'.before'); $after = readSafe($dir.'/'.$name.'.after');
    if ($record['before'] !== $allowed[$path] || hash('sha256', $before) !== $record['before']
        || hash('sha256', $after) !== $record['after'] || patch($before, $item['hunks']) !== $after
        || hash('sha256', readSafe($root.'/'.$path)) !== $record['before']
        || fileowner($root.'/'.$path) !== $record['owner'] || filegroup($root.'/'.$path) !== $record['group']
        || (fileperms($root.'/'.$path) & 0777) !== $record['mode']) { throw new RuntimeException('Prepared or live source changed'); }
    lint($dir.'/'.$name.'.after');
}
if (file_exists($dir.'/receipt.json') || file_exists($dir.'/failure.json')) { throw new RuntimeException('Inspect existing release result'); }
exclusive($dir.'/apply-attempt.json', jsonBytes(['utc' => gmdate('c'), 'payload_sha256' => $payloadHash]));
$installed = [];
try {
    foreach ($manifest['files'] as $path => $record) {
        $destination = $root.'/'.$path;
        if (hash('sha256', readSafe($destination)) !== $record['before']) { throw new RuntimeException('Concurrent source change'); }
        $stage = $dir.'/'.basename($path).'.install';
        exclusive($stage, readSafe($dir.'/'.basename($path).'.after'));
        if (hash('sha256', readSafe($stage)) !== $record['after']
            || !chgrp($stage, $record['group']) || !chmod($stage, $record['mode'])
            || stat($stage)['dev'] !== stat(dirname($destination))['dev']) { throw new RuntimeException('Unsafe atomic stage'); }
        if (hash('sha256', readSafe($destination)) !== $record['before'] || !rename($stage, $destination)) { throw new RuntimeException('Install failed or concurrent change'); }
        $installed[] = $path;
        if (hash('sha256', readSafe($destination)) !== $record['after']) { throw new RuntimeException('Installed hash mismatch'); }
    }
    exclusive($dir.'/receipt.json', jsonBytes(['installed' => true, 'utc' => gmdate('c'), 'manifest' => $manifest,
        'web_opcache_activation' => 'pending', 'provider_calls' => 0, 'database_writes' => 0, 'settings_or_cache_writes' => 0]));
} catch (Throwable $error) {
    $rolledBack = []; $blocked = [];
    foreach (array_reverse($installed) as $path) {
        try {
            $record = $manifest['files'][$path]; $destination = $root.'/'.$path;
            if (hash('sha256', readSafe($destination)) !== $record['after']) { throw new RuntimeException('Concurrent change'); }
            $stage = $dir.'/'.basename($path).'.rollback';
            exclusive($stage, readSafe($dir.'/'.basename($path).'.before'));
            if (hash('sha256', readSafe($stage)) !== $record['before']
                || !chgrp($stage, $record['group']) || !chmod($stage, $record['mode'])
                || hash('sha256', readSafe($destination)) !== $record['after'] || !rename($stage, $destination)
                || hash('sha256', readSafe($destination)) !== $record['before']) { throw new RuntimeException('Rollback failed'); }
            $rolledBack[] = $path;
        } catch (Throwable $rollbackError) { $blocked[] = $path; }
    }
    exclusive($dir.'/failure.json', jsonBytes(['utc' => gmdate('c'), 'rolled_back' => $rolledBack, 'manual_review_required' => $blocked]));
    throw new RuntimeException('Apply failed; inspect private failure manifest; do not rerun', 0, $error);
}
echo jsonBytes(['installed' => true, 'web_opcache_activation' => 'pending', 'files' => array_keys($allowed)]);
