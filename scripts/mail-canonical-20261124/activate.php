<?php
declare(strict_types=1);

header('Content-Type: application/json');
$release = 'mail-canonical-20261124';
if (PHP_SAPI !== 'fpm-fcgi' || getenv('SERVERNET_LOCAL_RELEASE') !== $release
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
$step = 'manifest';
try {
    $root = '/home/servernetcloud/servernet_app';
    $plan = (string) getenv('SERVERNET_SEARCH_PLAN');
    $phase = (string) getenv('SERVERNET_SEARCH_PHASE');
    if (!preg_match('~\A/home/servernetcloud/deploy-backups/mail-canonical-20261124-[a-f0-9]{12}/prepared\.json\z~', $plan)
        || is_link($plan) || !in_array($phase, ['after','before'], true)) throw new RuntimeException('invalid_plan');
    $records = json_decode((string) file_get_contents($plan), true, 32, JSON_THROW_ON_ERROR);
    if (count($records) !== 1 || $records[0]['destination'] !== 'servernet_app/app/Http/Middleware/ConsoleHost.php') throw new RuntimeException('invalid_scope');
    $file = $root.'/app/Http/Middleware/ConsoleHost.php';
    if (is_link($file) || hash_file('sha256', $file) !== $records[0][$phase]) throw new RuntimeException('installed_source_changed');
    if (function_exists('opcache_is_script_cached') && opcache_is_script_cached($file) && !opcache_invalidate($file, true)) throw new RuntimeException('invalidation_failed');
    $step = 'bootstrap';
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if ($app->configurationIsCached() || $app->routesAreCached()) throw new RuntimeException('unexpected_bootstrap_cache');
    $step = 'public_redirect_contract';
    $middleware = new App\Http\Middleware\ConsoleHost();
    foreach (['/privacy','/en/lookup/global-http?source=legacy%20link','/tr/terms'] as $path) {
        $response = $middleware->handle(Illuminate\Http\Request::create('https://mail.servernet.cloud'.$path), fn () => response('', 204));
        $expected = $phase === 'after' ? 301 : 204;
        if ($response->getStatusCode() !== $expected) throw new RuntimeException('redirect_contract_failed');
        if ($phase === 'after' && $response->headers->get('Location') !== 'https://servernet.cloud'.$path) throw new RuntimeException('redirect_contract_failed');
    }
    $step = 'private_and_write_contract';
    foreach (['/webmail','/autodiscover/autodiscover.xml','/v1/models','/en/account','/privacy-admin'] as $path) {
        $response = $middleware->handle(Illuminate\Http\Request::create('https://mail.servernet.cloud'.$path), fn () => response('', 204));
        if ($response->getStatusCode() !== 204) throw new RuntimeException('private_contract_failed');
    }
    $response = $middleware->handle(Illuminate\Http\Request::create('https://mail.servernet.cloud/contact', 'POST'), fn () => response('', 204));
    if ($response->getStatusCode() !== 204) throw new RuntimeException('private_contract_failed');
    echo json_encode(['release'=>$release,'phase'=>$phase,'compiled_views'=>0,'general_cache_flush'=>false,
        'public_redirect_verified'=>true,'private_routes_preserved'=>true], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $codes = ['invalid_plan','invalid_scope','installed_source_changed','invalidation_failed','unexpected_bootstrap_cache',
        'redirect_contract_failed','private_contract_failed'];
    echo json_encode(['status'=>'stopped','step'=>$step,
        'reason'=>in_array($error->getMessage(), $codes, true) ? $error->getMessage() : 'activation_failed']);
}
