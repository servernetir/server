<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$live = '/home/servernetcloud/servernet_app';
require $live.'/vendor/autoload.php';
$app = require $live.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$s = app(App\Services\Cloud\CloudPricingSnapshot::class);
echo json_encode(['at' => gmdate('c'), 'snapshot_current' => $s->current(),
    'excluded_providers' => $s->excludedProviders(),
    'sellable' => App\Models\CloudPlan::sellable()->count(),
    'gpu' => app(App\Services\Cloud\GpuSalesHealth::class)->snapshot(),
    'watch' => is_file(storage_path('app/gpu-sales-watch.json')) ? json_decode(file_get_contents(storage_path('app/gpu-sales-watch.json')), true) : null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
