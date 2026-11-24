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
    if (count($records) !== 2 || array_column($records, 'destination') !== ['servernet_app/app/Http/Middleware/ConsoleHost.php', 'servernet_app/app/Services/PublicPageStyles.php']) throw new RuntimeException('invalid_scope');
    foreach ($records as $record) {
        $file = '/home/servernetcloud/'.$record['destination'];
        if (is_link($file) || hash_file('sha256', $file) !== $record[$phase]) throw new RuntimeException('installed_source_changed');
        if (function_exists('opcache_is_script_cached') && opcache_is_script_cached($file) && !opcache_invalidate($file, true)) throw new RuntimeException('invalidation_failed');
    }
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
    $step = 'font_bundle_contract';
    $app->instance('request', Illuminate\Http\Request::create('https://servernet.cloud/gpu'));
    $_SERVER['DOCUMENT_ROOT'] = '/home/servernetcloud/public_html';
    $source = public_asset_path('assets/css/site.css');
    $min = public_asset_path('assets/css/site.min.css');
    $site = $source && $min && filemtime($min) >= filemtime($source) ? 'assets/css/site.min.css' : 'assets/css/site.css';
    $styles = app(App\Services\PublicPageStyles::class)->inline($site);
    if (!is_array($styles) || substr_count($styles['site'], 'data:font/woff2;base64,') !== ($phase === 'after' ? 3 : 2)) throw new RuntimeException('font_contract_failed');
    echo json_encode(['release'=>$release,'phase'=>$phase,'compiled_views'=>0,'general_cache_flush'=>false,
        'public_redirect_verified'=>true,'private_routes_preserved'=>true,'embedded_fonts'=>($phase === 'after' ? 3 : 2)], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $codes = ['invalid_plan','invalid_scope','installed_source_changed','invalidation_failed','unexpected_bootstrap_cache',
        'redirect_contract_failed','private_contract_failed','font_contract_failed'];
    echo json_encode(['status'=>'stopped','step'=>$step,
        'reason'=>in_array($error->getMessage(), $codes, true) ? $error->getMessage() : 'activation_failed']);
}
