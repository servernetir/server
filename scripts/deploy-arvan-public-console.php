<?php
// One-time, hash-guarded release. Run from the production app using PHP 8.4.
// Requires the reviewed broker.php beside this script; never downloads credentials.
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || realpath(getcwd()) !== '/home/servernetcloud/servernet_app') {
    throw new RuntimeException('Wrong runtime or destination');
}
umask(0077);
$root = getcwd();
$dir = __DIR__;
$lock = fopen($root.'/storage/framework/arvan-console-release.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Release locked'); }
if (is_file($dir.'/receipt.json')) { throw new RuntimeException('Already installed; inspect receipt'); }
$expected = [
    'app/Services/Cloud/ArvanConsoleBroker.php' => '5a6b7c34a8d4af22a2fd564b09da9cd580ab7613d24d25742078ad1a57b2aced',
    'app/Http/Controllers/Account/CloudServerController.php' => '9ace23cffd4a56d4dcd2ecc062d4d47c1e701085c6bbf5a4214322545fe11398',
    'app/Services/Cloud/ArvanClient.php' => 'eb97661aef3ba1f85e7435969d5e7d2df15c2e5e829ccda596a597c81db07c23',
    'app/Http/Middleware/SecurityHeaders.php' => '6d40683b9a9b9636ac0726e7f2f4952036a309692b15f9811901b887f6c8a481',
];
$old = [];
foreach ($expected as $path => $hash) {
    $old[$path] = file_get_contents($root.'/'.$path);
    if (!hash_equals($hash, hash('sha256', $old[$path]))) { throw new RuntimeException('Changed destination: '.$path); }
}
function once(string $source, string $search, string $replacement): string {
    if (substr_count($source, $search) !== 1) { throw new RuntimeException('Patch context mismatch'); }
    return str_replace($search, $replacement, $source);
}
$new = $old;
$new['app/Services/Cloud/ArvanConsoleBroker.php'] = file_get_contents($dir.'/broker.php');
if (hash('sha256', $new['app/Services/Cloud/ArvanConsoleBroker.php']) !== 'b077af236ee7e9f64debcc74411aaa762ca0ee8adc1ce2e63d26fa593a79fe3d') { throw new RuntimeException('Wrong broker artifact'); }
$path = 'app/Http/Controllers/Account/CloudServerController.php';
$new[$path] = once($new[$path], '        $r = $driver->console((string) $instance->provider_ref);', <<<'PATCH'
        if ($instance->provider === 'arvan') {
            $broker = app(\App\Services\Cloud\ArvanConsoleBroker::class);
            if (! $broker->enabled() || ! $broker->eligible($service)) {
                return back()->withErrors(__('ui.cx_console_na'));
            }
        }

        $r = $driver->console((string) $instance->provider_ref);
PATCH);
$new[$path] = once($new[$path], "if (\$instance->provider === 'arvan' && (\$r['transport'] ?? null) === 'external')", "if (\$instance->provider === 'arvan')");
$new[$path] = preg_replace('~            \$this->log\(\$service, __\(\x27ui\.act_cloud_console\x27\)\);\s*return redirect\(\)->away\(\$r\[\x27url\x27\]\)\s*->header\(\x27Cache-Control\x27, \x27no-store\x27\)\s*->header\(\x27Referrer-Policy\x27, \x27no-referrer\x27\);~', "            return back()->withErrors(__('ui.cx_console_na'));", $new[$path], -1, $count);
if ($count !== 1) { throw new RuntimeException('Unexpected redirect context'); }
$path = 'app/Services/Cloud/ArvanClient.php';
if (!str_contains($new[$path], "'console' => app(ArvanConsoleBroker::class)->enabled(),")) { throw new RuntimeException('Capability contract mismatch'); }
// This production driver already uses the safe HTTPS/external transport contract.
if (!str_contains($new[$path], "'transport' => 'external'")) { throw new RuntimeException('Driver contract mismatch'); }
$path = 'app/Http/Middleware/SecurityHeaders.php';
$new[$path] = once($new[$path], "        \$response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');", <<<'PATCH'
        if ($response->headers->get('Referrer-Policy') !== 'no-referrer') {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
PATCH);
foreach ($new as $path => $source) {
    if ($source === $old[$path]) { continue; }
    $stage = $dir.'/'.basename($path).'.new';
    file_put_contents($stage, $source);
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($stage).' 2>&1', $out, $status);
    if ($status !== 0) { throw new RuntimeException('Lint failed: '.$path); }
}
// All checks and lint pass before any production file changes.
$receipt = ['utc' => gmdate('c'), 'files' => []];
foreach ($new as $path => $source) {
    if ($source === $old[$path]) { continue; }
    if (hash_file('sha256', $root.'/'.$path) !== $expected[$path]) { throw new RuntimeException('Concurrent change'); }
    file_put_contents($dir.'/'.basename($path).'.before', $old[$path]);
    $stage = $dir.'/'.basename($path).'.new';
    chmod($stage, fileperms($root.'/'.$path) & 0777);
    if (!rename($stage, $root.'/'.$path)) { throw new RuntimeException('Install failed: '.$path); }
    $receipt['files'][$path] = ['before' => $expected[$path], 'after' => hash_file('sha256', $root.'/'.$path)];
}
file_put_contents($dir.'/receipt.json', json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode(['installed' => array_keys($new), 'enabled' => false, 'web_opcache_reset' => false]).PHP_EOL;
