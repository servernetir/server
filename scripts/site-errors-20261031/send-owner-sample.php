<?php
declare(strict_types=1);

// One explicit owner request, not a scheduled job or a retry of today's report.
if (PHP_SAPI !== 'cli' || (getenv('HOME') ?: '') !== '/home/servernetcloud') { exit(1); }
$root = '/home/servernetcloud/servernet_app';
$manifest = json_decode(file_get_contents(__DIR__.'/release-manifest.json'), true, 16, JSON_THROW_ON_ERROR);
foreach ($manifest['files'] as $row) {
    if (hash_file('sha256', $root.'/'.$row['target']) !== $row['after']) { throw new RuntimeException('sample_runtime_drift'); }
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$gate = app(App\Services\Bale\Admin\AdminBaleGate::class);
$user = $gate->boundUser();
if (! $gate->enabled() || ! $user || ! $user->isAdmin()) { throw new RuntimeException('sample_owner_unavailable'); }
$paths = ['daily_claim' => storage_path('app/private/bale-manager-digest/'.now('Asia/Tehran')->format('Y-m-d').'.json'),
    'cost_baseline' => storage_path('app/private/bale/management-catalog-costs-v1.json')];
$hashes = static fn () => array_map(static fn ($path) => is_file($path) ? hash_file('sha256', $path) : null, $paths);
$before = $hashes();
$exit = Illuminate\Support\Facades\Artisan::call('bale:manager-digest', [
    '--sample-request' => 'owner-request-20261031-after-errors', '--operator-user-id' => (string) $user->id,
]);
$delivery = json_decode(trim(Illuminate\Support\Facades\Artisan::output()), true, 16, JSON_THROW_ON_ERROR);
$after = $hashes();
echo json_encode(['utc' => gmdate('c'), 'exit_code' => $exit, 'delivery' => $delivery,
    'daily_claim_unchanged' => $before['daily_claim'] === $after['daily_claim'],
    'cost_baseline_unchanged' => $before['cost_baseline'] === $after['cost_baseline']], JSON_THROW_ON_ERROR).PHP_EOL;
