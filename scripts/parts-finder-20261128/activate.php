<?php
declare(strict_types=1);

header('Content-Type: application/json');
$release = 'parts-finder-20261128';
if (PHP_SAPI !== 'fpm-fcgi' || getenv('SERVERNET_LOCAL_RELEASE') !== $release
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
$step = 'manifest';
try {
    $root = '/home/servernetcloud/servernet_app';
    $plan = (string) getenv('SERVERNET_SEARCH_PLAN');
    $phase = (string) getenv('SERVERNET_SEARCH_PHASE');
    if (!preg_match('~\A/home/servernetcloud/deploy-backups/parts-finder-20261128-[a-f0-9]{12}/prepared\.json\z~', $plan)
        || is_link($plan) || !in_array($phase, ['before','after'], true)) throw new RuntimeException('invalid_plan');
    $records = json_decode((string) file_get_contents($plan), true, 32, JSON_THROW_ON_ERROR);
    $expected = ['app/Services/Shop/PartFinder.php', 'app/Http/Controllers/PartsShopController.php',
        'app/Http/Controllers/SiteController.php', 'lang/en/part_finder.php', 'lang/fa/part_finder.php', 'lang/tr/part_finder.php',
        'resources/views/pages/contact.blade.php', 'resources/views/pages/parts-index.blade.php', 'resources/views/pages/part-detail.blade.php',
        'resources/views/partials/hardware-stock-quote.blade.php', 'resources/views/partials/part-finder.blade.php', 'resources/views/partials/part-quote.blade.php'];
    $expected = array_map(fn ($path) => 'servernet_app/'.$path, $expected);
    $actual = array_column($records, 'destination'); sort($expected); sort($actual);
    if ($expected !== $actual) throw new RuntimeException('invalid_scope');
    foreach ($records as $row) {
        if ($phase === 'before' && $row['before'] === null) continue; // New dependencies retire after old views activate.
        $file = '/home/servernetcloud/'.$row['destination'];
        if (is_link($file) || hash_file('sha256', $file) !== $row[$phase]) throw new RuntimeException('installed_source_changed');
        if (function_exists('opcache_is_script_cached') && opcache_is_script_cached($file) && !opcache_invalidate($file, true)) {
            throw new RuntimeException('invalidation_failed');
        }
    }
    $step = 'bootstrap';
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if ($app->configurationIsCached() || $app->routesAreCached()) throw new RuntimeException('unexpected_bootstrap_cache');
    $step = 'compile_views';
    $views = ['pages.parts-index', 'pages.part-detail', 'pages.contact', 'partials.hardware-stock-quote'];
    if ($phase === 'after') $views = array_merge($views, ['partials.part-finder', 'partials.part-quote']);
    foreach ($views as $view) {
        $source = $app['view']->getFinder()->find($view);
        $app['blade.compiler']->compile($source);
        $compiled = $app['blade.compiler']->getCompiledPath($source);
        if (function_exists('opcache_is_script_cached') && opcache_is_script_cached($compiled) && !opcache_invalidate($compiled, true)) {
            throw new RuntimeException('compiled_invalidation_failed');
        }
    }
    // Purge only hub/contact and the representative product. Other part pages roll over at normal TTL.
    $step = 'targeted_page_cache';
    $cache = $app['cache']->store();
    $generation = (int) $cache->get('page:gen', 0);
    $paths = [];
    foreach (['','en/','tr/'] as $prefix) foreach (['parts','contact','parts/gpu/gpu-tesla-p4'] as $path) $paths[] = $prefix.$path;
    foreach (['servernet.cloud','console.servernet.cloud'] as $host) foreach (['','/index.php'] as $base) {
        foreach ($paths as $path) $cache->forget('page:g'.$generation.':'.sha1($host.'|'.$base.'|'.$path));
    }
    echo json_encode(['release'=>$release,'phase'=>$phase,'compiled_views'=>6,'recompiled'=>count($views),
        'general_cache_flush'=>false,'targeted_paths'=>count($paths)], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $codes=['invalid_plan','invalid_scope','installed_source_changed','invalidation_failed','unexpected_bootstrap_cache','compiled_invalidation_failed'];
    echo json_encode(['status'=>'stopped','step'=>$step,'reason'=>in_array($error->getMessage(),$codes,true)?$error->getMessage():'activation_failed']);
}
