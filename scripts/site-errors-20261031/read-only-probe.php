<?php
// Explicit diagnostic: production reads and existing-account inventory GETs only.
// Output contains status/count projections, never credentials, bodies or customer details.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
$root = '/home/servernetcloud/servernet_app';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'mail.default' => 'array']);
Illuminate\Support\Facades\DB::connection()->beforeExecuting(static function ($sql): void {
    if (! preg_match('/^\s*(select|show|describe|explain)\b/i', $sql)) { throw new RuntimeException('diagnostic_write_blocked'); }
});
$allowedHost = parse_url((string) App\Models\Setting::get('cloud_vpstc_api_origin'), PHP_URL_HOST);
$http = [];
Illuminate\Support\Facades\Http::globalRequestMiddleware(static function ($request) use ($allowedHost) {
    $host = $request->getUri()->getHost(); $path = $request->getUri()->getPath(); $method = $request->getMethod();
    $allowed = $host === 'robot-ws.your-server.de' && $method === 'GET' && $path === '/server';
    $allowed = $allowed || ($host === $allowedHost && (($method === 'GET' && $path === '/api/service')
        || ($method === 'POST' && in_array($path, ['/api/login', '/api/token'], true))));
    if (! $allowed) { throw new RuntimeException('diagnostic_http_blocked'); }
    return $request;
});
Illuminate\Support\Facades\Http::globalResponseMiddleware(static function ($response) use (&$http) {
    $http[] = ['status' => $response->getStatusCode(), 'content_type' => $response->getHeaderLine('Content-Type')];
    return $response;
});
$result = ['utc' => gmdate('c')];
try {
    $robot = app(App\Services\Cloud\HetznerRobotClient::class);
    $reply = (new ReflectionMethod($robot, 'req'))->invoke($robot, 'GET', '/server');
    $result['robot'] = ['configured' => $robot->isConfigured(), 'ok' => $reply['ok'], 'http_status' => $reply['status'],
        'error_code' => $reply['body']['error']['code'] ?? null, 'count' => $reply['ok'] ? count((array) $reply['body']) : null];
} catch (Throwable $e) { $result['robot'] = ['exception_class' => $e::class]; }
try {
    $api = app(App\Services\Cloud\VpsTcApi::class); $reply = $api->services();
    $result['vpstc_api'] = ['configured' => $api->isConfigured(), 'ok' => $reply['ok'], 'code' => $reply['code'] ?? null,
        'count' => isset($reply['data']['services']) && is_array($reply['data']['services']) ? count($reply['data']['services']) : null,
        'total' => $reply['data']['total'] ?? null, 'has_next' => filled($reply['data']['next'] ?? $reply['data']['next_page'] ?? null)];
} catch (Throwable $e) { $result['vpstc_api'] = ['exception_class' => $e::class]; }
try {
    $result['google'] = array_intersect_key(app(App\Services\Bale\Management\ManagerGoogleReporting::class)->status(), array_flip(['connected', 'reason', 'configured']));
} catch (Throwable $e) { $result['google'] = ['exception_class' => $e::class]; }
$result['http'] = $http;
$result['database_writes'] = 0;
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
