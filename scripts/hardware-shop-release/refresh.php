<?php
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
$app = require '/home/servernetcloud/servernet_app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Artisan::call('view:clear');
App\Http\Middleware\PageCache::purge();
$token = (string) env('DEPLOY_TOKEN', '');
if ($token === '') { fwrite(STDERR, "Deployment authentication unavailable\n"); exit(2); }
// Existing credential stays in memory and in the HTTPS POST body, never in URLs/log output.
$response = Illuminate\Support\Facades\Http::timeout(40)->asJson()
    ->post('https://servernet.cloud/system/opcache', ['token' => $token]);
$data = $response->json();
echo json_encode(['status' => $response->status(), 'opcache_reset' => $data['opcache_reset'] ?? null,
    'cleared' => $data['cleared'] ?? []], JSON_THROW_ON_ERROR), "\n";
