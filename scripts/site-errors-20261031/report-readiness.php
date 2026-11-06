<?php
declare(strict_types=1);

// Explicit owner-requested Google report preparation. No notification is sent.
if (PHP_SAPI !== 'cli') { exit(1); }
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
$app = require '/home/servernetcloud/servernet_app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$status = app(App\Services\Bale\Management\ManagerGoogleReporting::class)->status();
$traffic = app(App\Services\Bale\Management\ManagerTrafficSnapshot::class)->refresh();
$result = ['utc' => gmdate('c'), 'google_connected' => (bool) ($status['connected'] ?? false),
    'traffic_status' => $traffic['status'] ?? 'unknown', 'sources' => []];
foreach (['ga4', 'gsc'] as $source) {
    $data = $traffic[$source] ?? [];
    $result['sources'][$source] = ['status' => $data['status'] ?? 'unknown', 'periods' => []];
    foreach ($data['periods'] ?? [] as $period => $values) {
        $result['sources'][$source]['periods'][$period] = array_intersect_key($values, array_flip([
            'status', 'start_date', 'end_date', 'timezone', 'reason', 'total_users', 'sessions', 'clicks', 'impressions', 'preliminary',
        ]));
    }
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
