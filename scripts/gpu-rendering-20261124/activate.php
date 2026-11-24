<?php
declare(strict_types=1);

header('Content-Type: application/json');
$release = 'gpu-rendering-20261124';
if (PHP_SAPI !== 'fpm-fcgi' || getenv('SERVERNET_LOCAL_RELEASE') !== $release
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
$step = 'manifest';
try {
    $root = '/home/servernetcloud/servernet_app';
    $plan = (string) getenv('SERVERNET_SEARCH_PLAN');
    $phase = (string) getenv('SERVERNET_SEARCH_PHASE');
    if (!preg_match('~\A/home/servernetcloud/deploy-backups/gpu-rendering-20261124-[a-f0-9]{12}/prepared\.json\z~', $plan)
        || is_link($plan) || !in_array($phase, ['after','before'], true)) throw new RuntimeException('invalid_plan');
    $records = json_decode((string) file_get_contents($plan), true, 32, JSON_THROW_ON_ERROR);
    if (count($records) !== 1 || $records[0]['destination'] !== 'servernet_app/app/Services/PublicPageStyles.php') throw new RuntimeException('invalid_scope');
    $file = $root.'/app/Services/PublicPageStyles.php';
    if (is_link($file) || hash_file('sha256', $file) !== $records[0][$phase]) throw new RuntimeException('installed_source_changed');
    if (function_exists('opcache_is_script_cached') && opcache_is_script_cached($file) && !opcache_invalidate($file, true)) throw new RuntimeException('invalidation_failed');
    $step = 'bootstrap';
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if ($app->configurationIsCached() || $app->routesAreCached()) throw new RuntimeException('unexpected_bootstrap_cache');
    $step = 'style_contract';
    $_SERVER['DOCUMENT_ROOT'] = '/home/servernetcloud/public_html';
    $source = public_asset_path('assets/css/site.css');
    $min = public_asset_path('assets/css/site.min.css');
    $site = $source && $min && filemtime($min) >= filemtime($source) ? 'assets/css/site.min.css' : 'assets/css/site.css';
    foreach (['gpu', 'en/gpu', 'tr/gpu'] as $path) {
        $app->instance('request', Illuminate\Http\Request::create('https://servernet.cloud/'.$path));
        $styles = app(App\Services\PublicPageStyles::class)->inline($site);
        if (($styles === null) !== ($phase === 'after')) throw new RuntimeException('gpu_contract_failed');
        if (app(App\Services\PublicPageStyles::class)->auxiliary('assets/css/gpu-alternatives.css') === null) throw new RuntimeException('gpu_contract_failed');
    }
    $app->instance('request', Illuminate\Http\Request::create('https://servernet.cloud/hosting/python'));
    $styles = app(App\Services\PublicPageStyles::class)->inline($site);
    if (!is_array($styles) || substr_count($styles['site'], 'data:font/woff2;base64,') !== 3) throw new RuntimeException('other_public_contract_failed');
    $step = 'targeted_page_cache';
    $cache = $app['cache']->store();
    $generation = (int) $cache->get('page:gen', 0);
    foreach (['servernet.cloud','console.servernet.cloud'] as $host) foreach (['','/index.php'] as $base) {
        foreach (['gpu','en/gpu','tr/gpu'] as $path) $cache->forget('page:g'.$generation.':'.sha1($host.'|'.$base.'|'.$path));
    }
    echo json_encode(['release'=>$release,'phase'=>$phase,'compiled_views'=>0,'general_cache_flush'=>false,
        'targeted_paths'=>3,'gpu_external_styles'=>($phase === 'after'),'other_public_bundle_preserved'=>true], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $known = ['invalid_plan','invalid_scope','installed_source_changed','invalidation_failed','unexpected_bootstrap_cache',
        'gpu_contract_failed','other_public_contract_failed'];
    echo json_encode(['status'=>'stopped','step'=>$step,
        'reason'=>in_array($error->getMessage(), $known, true) ? $error->getMessage() : 'activation_failed']);
}
