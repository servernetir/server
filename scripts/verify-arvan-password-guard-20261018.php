<?php
declare(strict_types=1);

// CLI-only synthetic/catalog acceptance. Never invokes a provider lifecycle method.
if (PHP_SAPI !== 'cli' || realpath(getcwd()) !== '/home/servernetcloud/servernet_app'
    || !preg_match('~^/home/servernetcloud/servernet_app/storage/app/arvan-password-guard-20261018-[A-Za-z0-9]{6}$~D', __DIR__)
    || is_link(__DIR__) || (fileperms(__DIR__) & 0777) !== 0700
    || fileowner(__DIR__) !== posix_geteuid()) {
    throw new RuntimeException('Wrong private release context');
}
umask(0077);
$manifest = json_decode(file_get_contents(__DIR__.'/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
$paths = ['app/Services/Cloud/ArvanClient.php', 'app/Services/Cloud/CloudProvisioner.php'];
$result = ['utc' => gmdate('c'), 'scope' => 'synthetic_inputs_and_read_only_catalog', 'installed_hashes_match' => true];
foreach ($paths as $path) {
    if (is_link($path) || hash_file('sha256', $path) !== ($manifest['files'][$path]['after'] ?? null)
        || hash_file('sha256', __DIR__.'/'.basename($path).'.before') !== ($manifest['files'][$path]['before'] ?? null)) {
        throw new RuntimeException('Installed or backup hash mismatch');
    }
}
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Http::preventStrayRequests();
$client = (new ReflectionClass(App\Services\Cloud\ArvanClient::class))->newInstanceWithoutConstructor();
$passwordMethod = new ReflectionMethod($client, 'responsePassword');
$passed = 0;
foreach ([null, '', ' ', [], 42, true, "bad\nvalue", "bad\0value", str_repeat('x', 4097)] as $value) {
    if ($passwordMethod->invoke($client, ['password' => $value]) !== null) {
        throw new RuntimeException('Invalid synthetic response accepted');
    }
    $passed++;
}
if ($passwordMethod->invoke($client, ['password' => 'Synthetic-only-check9!']) !== 'Synthetic-only-check9!') {
    throw new RuntimeException('Valid synthetic response changed');
}
$result['synthetic_password_cases_passed'] = $passed + 1;
$provisioner = (new ReflectionClass(App\Services\Cloud\CloudProvisioner::class))->newInstanceWithoutConstructor();
$guard = new ReflectionMethod($provisioner, 'supportsAutomaticPasswordReset');
$checks = ['windows_blocked' => 0, 'linux_allowed' => 0, 'ambiguous_blocked' => 0];
foreach (App\Models\CloudImage::where('provider', 'arvan')->get(['key', 'family'])->groupBy('key') as $key => $images) {
    $families = $images->pluck('family')->map(fn ($family) => strtolower((string) $family))->unique();
    // PHP coerces numeric group keys to integers; preserve the catalog string
    // so MySQL does not compare numeric prefixes of unrelated image keys.
    $instance = new App\Models\CloudInstance(['provider' => 'arvan', 'image_key' => (string) $images->first()->key]);
    if ($families->count() !== 1) {
        if ($guard->invoke($provisioner, $instance)) { throw new RuntimeException('Ambiguous family allowed'); }
        $checks['ambiguous_blocked']++;
        continue;
    }
    // Restrict live acceptance to known families; aliases have separate local tests.
    if (!in_array($families->first(), ['windows', 'ubuntu', 'debian', 'centos', 'rocky', 'fedora'], true)) { continue; }
    $expected = $families->first() !== 'windows';
    if ($guard->invoke($provisioner, $instance) !== $expected) { throw new RuntimeException('Catalog guard mismatch'); }
    $checks[$expected ? 'linux_allowed' : 'windows_blocked']++;
}
if ($checks['windows_blocked'] < 1 || $checks['linux_allowed'] < 1) { throw new RuntimeException('Insufficient catalog coverage'); }
$unknown = new App\Models\CloudInstance(['provider' => 'arvan', 'image_key' => 'synthetic-nonexistent-20261018']);
if ($guard->invoke($provisioner, $unknown)) { throw new RuntimeException('Unknown family allowed'); }
$unknown->provider = 'hetzner';
if (!$guard->invoke($provisioner, $unknown)) { throw new RuntimeException('Other-provider behavior changed'); }
$checks['unknown_blocked'] = $checks['non_arvan_preserved'] = true;
$result['guard_checks'] = $checks;
$flags = Illuminate\Support\Facades\DB::table('settings')->whereIn('key', [
    'arvan_console_worker_enabled', 'arvan_hourly_sales_enabled', 'arvan_windows_ready_image_refs',
])->pluck('value', 'key');
$result['public_console'] = $flags['arvan_console_worker_enabled'] ?? null;
$result['hourly_sales'] = $flags['arvan_hourly_sales_enabled'] ?? null;
$result['windows_allowlist_unchanged'] = hash('sha256', (string) ($flags['arvan_windows_ready_image_refs'] ?? ''))
    === 'cc9e1285e69a6a37cde1b114b19c860293fae75e3f311bce3ac146744206b845';
if ($result['public_console'] !== '1' || $result['hourly_sales'] !== '1' || !$result['windows_allowlist_unchanged']) {
    throw new RuntimeException('Sales/console baseline changed; inspect without rewriting');
}
$result['provider_calls'] = 0;
$result['customer_database_writes'] = 0;
$result['unattended_windows_setup_tested'] = false;
if (file_exists(__DIR__.'/verify-cli.json')) { throw new RuntimeException('Acceptance exists; inspect instead of rerunning'); }
file_put_contents(__DIR__.'/verify-cli.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode($result, JSON_UNESCAPED_SLASHES).PHP_EOL;
