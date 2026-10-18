<?php
declare(strict_types=1);

// Narrow, one-time release of a7fda484. No provider calls or database writes.
if (PHP_SAPI !== 'cli' || realpath(getcwd()) !== '/home/servernetcloud/servernet_app') {
    throw new RuntimeException('Wrong runtime or app root');
}
umask(0077);
$root = getcwd();
$dir = __DIR__;
if (!str_starts_with($dir, '/home/servernetcloud/servernet_app/storage/app/arvan-password-guard-20261018-')) {
    throw new RuntimeException('Use a dedicated private release directory');
}
if (is_link($dir) || realpath($dir) !== $dir || (fileperms($dir) & 0777) !== 0700
    || fileowner($dir) !== posix_geteuid()) {
    throw new RuntimeException('Unsafe release directory');
}
$lock = fopen($root.'/storage/framework/arvan-console-release.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Release busy');
}
$expected = [
    'app/Services/Cloud/ArvanClient.php' => '3c887daabc8ed845bf2c80bb40560f77eab9bee62b1bd631e005196ae396b73e',
    'app/Services/Cloud/CloudProvisioner.php' => '1477ba7a7006d4540ae0b3b8635eab48b2edcfb609570e1995c75565c4f159c7',
];
function replaceOnce(string $source, string $search, string $replacement): string
{
    $eol = str_contains($source, "\r\n") ? "\r\n" : "\n";
    $search = str_replace("\n", $eol, str_replace("\r\n", "\n", $search));
    $replacement = str_replace("\n", $eol, str_replace("\r\n", "\n", $replacement));
    if (substr_count($source, $search) !== 1) {
        throw new RuntimeException('Patch context mismatch');
    }
    return str_replace($search, $replacement, $source);
}
$old = $new = [];
foreach ($expected as $path => $hash) {
    if (is_link($root.'/'.$path) || hash_file('sha256', $root.'/'.$path) !== $hash) {
        throw new RuntimeException('Changed destination: '.$path);
    }
    $old[$path] = $new[$path] = file_get_contents($root.'/'.$path);
}
$p = 'app/Services/Cloud/ArvanClient.php';
$new[$p] = replaceOnce($new[$p], '$out[\'root_password\'] = $server[\'password\'] ?? null;', '$out[\'root_password\'] = $this->responsePassword($server);');
$new[$p] = replaceOnce($new[$p], '    public function power(string $ref, string $action): array', <<<'PATCH'
    private function responsePassword(array $server): ?string
    {
        $password = $server['password'] ?? null;

        return is_string($password) && trim($password) !== '' && strlen($password) <= 4096
            && ! preg_match('/[\x00-\x1f\x7f]/', $password) ? $password : null;
    }

    public function power(string $ref, string $action): array
PATCH);
$p = 'app/Services/Cloud/CloudProvisioner.php';
$new[$p] = replaceOnce($new[$p], '        if (! $keyOnly && blank($password) && filled($result[\'ref\'] ?? null)', <<<'PATCH'
        if (! $keyOnly && $this->supportsAutomaticPasswordReset($instance)
            && blank($password) && filled($result['ref'] ?? null)
PATCH);
$new[$p] = replaceOnce($new[$p], '$instance->provider === \'lightnode\' && $instance->isWindows() ? \'Administrator\' : \'root\'', 'in_array($instance->provider, [\'arvan\', \'lightnode\'], true) && $instance->isWindows() ? \'Administrator\' : \'root\'');
$new[$p] = replaceOnce($new[$p], <<<'PATCH'
        if (! $instance->hasPassword()
            && ! ($instance->provider === 'arvan' && $instance->isWindows())) {
PATCH, <<<'PATCH'
        if (! $instance->hasPassword() && $this->supportsAutomaticPasswordReset($instance)) {
PATCH);
$new[$p] = replaceOnce($new[$p], '    private function planWithImage(string $slug, string $imageKey, bool $hourly = false): ?CloudPlan', <<<'PATCH'
    private function supportsAutomaticPasswordReset(CloudInstance $instance): bool
    {
        if ($instance->provider !== 'arvan') {
            return true;
        }

        // The provider endpoint is Linux-only. Missing/ambiguous catalogue
        // metadata must not be interpreted as permission to reset Windows.
        $families = CloudImage::where('provider', 'arvan')->where('key', $instance->image_key)
            ->pluck('family')->map(fn ($family) => strtolower((string) $family))->unique();

        return $families->count() === 1 && in_array($families->first(), [
            'ubuntu', 'debian', 'centos', 'centos-stream', 'rocky', 'rocky-linux',
            'almalinux', 'alma-linux', 'fedora', 'alpine', 'arch', 'arch-linux',
            'opensuse', 'opensuse-leap', 'opensuse-tumbleweed', 'rhel',
            'red-hat-enterprise-linux', 'oracle-linux', 'linux',
        ], true);
    }

    private function planWithImage(string $slug, string $imageKey, bool $hourly = false): ?CloudPlan
PATCH);
// Only this helper insertion changes comment adjacency; preserve the original
// planWithImage docblock by placing it after the new helper, exactly as in Git.
$new[$p] = replaceOnce($new[$p], "    /** پلنِ هم‌اسلاگ روی زیرساختی که این سیستم‌عامل را دارد */\n    private function supportsAutomaticPasswordReset", '    private function supportsAutomaticPasswordReset');
$new[$p] = replaceOnce($new[$p], '    private function planWithImage(string $slug, string $imageKey, bool $hourly = false): ?CloudPlan', "    /** پلنِ هم‌اسلاگ روی زیرساختی که این سیستم‌عامل را دارد */\n    private function planWithImage(string \$slug, string \$imageKey, bool \$hourly = false): ?CloudPlan");

if (is_file($dir.'/receipt.json')) {
    throw new RuntimeException('Receipt exists; inspect instead of rerunning');
}
$manifest = ['code_commit' => 'a7fda4842f9c9188c72242715f719ba1b8478444', 'files' => []];
foreach ($new as $path => $source) {
    $name = basename($path);
    foreach (['before' => $old[$path], 'after' => $source] as $suffix => $bytes) {
        $file = $dir.'/'.$name.'.'.$suffix;
        if (is_file($file)) {
            if (hash_file('sha256', $file) !== hash('sha256', $bytes)) {
                throw new RuntimeException('Unexpected release file: '.$name.'.'.$suffix);
            }
        } else {
            if (file_put_contents($file, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new RuntimeException('Short stage write');
            }
        }
        if (is_link($file) || hash_file('sha256', $file) !== hash('sha256', $bytes)) {
            throw new RuntimeException('Release file verification failed');
        }
    }
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($dir.'/'.$name.'.after').' 2>&1', $lint, $status);
    if ($status !== 0) { throw new RuntimeException('Lint failed: '.$name); }
    $manifest['files'][$path] = ['before' => $expected[$path], 'after' => hash('sha256', $source)];
}
file_put_contents($dir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
if (!in_array('--apply', $argv, true)) {
    echo json_encode(['prepared' => true, 'utc' => gmdate('c'), 'manifest' => $manifest], JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit;
}
$installed = [];
try {
    foreach ($expected as $path => $hash) {
        if (hash_file('sha256', $root.'/'.$path) !== $hash) { throw new RuntimeException('Concurrent change'); }
    }
    foreach ($new as $path => $source) {
        if (hash_file('sha256', $root.'/'.$path) !== $expected[$path]) { throw new RuntimeException('Concurrent change'); }
        $stage = $dir.'/'.basename($path).'.install';
        if (!copy($dir.'/'.basename($path).'.after', $stage)) { throw new RuntimeException('Stage copy failed'); }
        if (hash_file('sha256', $stage) !== $manifest['files'][$path]['after']
            || !chmod($stage, fileperms($root.'/'.$path) & 0777)) { throw new RuntimeException('Stage verification failed'); }
        if (!rename($stage, $root.'/'.$path)) { throw new RuntimeException('Install failed'); }
        $installed[] = $path;
        if (hash_file('sha256', $root.'/'.$path) !== $manifest['files'][$path]['after']) { throw new RuntimeException('Installed hash mismatch'); }
    }
} catch (Throwable $e) {
    foreach (array_reverse($installed) as $path) {
        if (hash_file('sha256', $root.'/'.$path) !== $manifest['files'][$path]['after']) { throw new RuntimeException('Concurrent update; manual rollback required'); }
        $stage = $dir.'/'.basename($path).'.rollback';
        if (!copy($dir.'/'.basename($path).'.before', $stage)
            || hash_file('sha256', $stage) !== $expected[$path]
            || !chmod($stage, fileperms($root.'/'.$path) & 0777)) { throw new RuntimeException('Rollback stage failed'); }
        if (!rename($stage, $root.'/'.$path) || hash_file('sha256', $root.'/'.$path) !== $expected[$path]) {
            throw new RuntimeException('Rollback failed');
        }
    }
    throw $e;
}
$receipt = ['installed' => true, 'utc' => gmdate('c'), 'manifest' => $manifest, 'web_opcache_activation' => 'pending', 'provider_calls' => 0, 'database_writes' => 0];
file_put_contents($dir.'/receipt.json', json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode($receipt, JSON_UNESCAPED_SLASHES).PHP_EOL;
