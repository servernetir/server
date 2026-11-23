<?php
declare(strict_types=1);

header('Content-Type: application/json');
$release = 'home-hardware-hero-20261123';
if (PHP_SAPI !== 'fpm-fcgi' || getenv('SERVERNET_LOCAL_RELEASE') !== $release
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
$step = 'manifest';
try {
    $root = '/home/servernetcloud/servernet_app';
    $plan = (string) getenv('SERVERNET_PARTS_PLAN');
    if (!preg_match('~\A/home/servernetcloud/deploy-backups/home-hardware-hero-20261123-[a-f0-9]{12}/prepared\.json\z~', $plan) || is_link($plan)) {
        throw new RuntimeException('invalid_plan');
    }
    $records = json_decode((string) file_get_contents($plan), true, 32, JSON_THROW_ON_ERROR);
    $expected = [];
    foreach (['assets/img/home-parts-exploded.webp','assets/img/home-parts-cutout.svg', 'assets/css/home-parts-slide.css', 'assets/js/studio-carousel.js'] as $asset) {
        foreach (['servernet_app/public', 'public_html'] as $public) $expected[] = $public.'/'.$asset;
    }
    foreach (['fa','en','tr'] as $locale) $expected[] = 'servernet_app/lang/'.$locale.'/home_slides.php';
    foreach (['pages/home','partials/studio-carousel-controls','partials/home-parts-slide'] as $view) $expected[] = 'servernet_app/resources/views/'.$view.'.blade.php';
    $actual = array_column($records, 'destination'); sort($expected); sort($actual);
    if ($expected !== $actual) throw new RuntimeException('invalid_scope');
    $phase = getenv('SERVERNET_PARTS_PHASE');
    if (!in_array($phase, ['before','after'], true)) throw new RuntimeException('invalid_phase');
    $step = 'source_checks';
    foreach ($records as $row) {
        $path = '/home/servernetcloud/'.$row['destination'];
        // Newly added dependencies remain until the old layout is recompiled.
        $hash = $phase === 'before' && $row['before'] === null ? $row['after'] : $row[$phase];
        if (is_link($path) || !is_file($path) || hash_file('sha256', $path) !== $hash) throw new RuntimeException('installed_source_changed');
        if (str_ends_with($path, '.php') && function_exists('opcache_is_script_cached') && opcache_is_script_cached($path)) {
            if (!opcache_invalidate($path, true)) throw new RuntimeException('targeted_opcache_invalidation_failed');
        }
    }
    $step = 'bootstrap';
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if ($app->configurationIsCached() || $app->routesAreCached()) throw new RuntimeException('unexpected_bootstrap_cache');
    $step = 'compile_views';
    $views = ['pages.home', 'partials.studio-carousel-controls'];
    if ($phase === 'after') $views[] = 'partials.home-parts-slide';
    foreach ($views as $view) {
        $source = $app['view']->getFinder()->find($view);
        $app['blade.compiler']->compile($source);
        $compiled = $app['blade.compiler']->getCompiledPath($source);
        if (function_exists('opcache_is_script_cached') && opcache_is_script_cached($compiled)) {
            if (!opcache_invalidate($compiled, true)) throw new RuntimeException('targeted_opcache_invalidation_failed');
        }
    }
    $step = 'homepage_cache';
    $cache = $app['cache']->store();
    $generation = (int) $cache->get('page:gen', 0);
    foreach (['servernet.cloud','console.servernet.cloud'] as $host) {
        foreach (['','/index.php'] as $base) {
            foreach (['/', 'en', 'tr'] as $path) $cache->forget('page:g'.$generation.':'.sha1($host.'|'.$base.'|'.$path));
        }
    }
    echo json_encode(['release'=>$release, 'phase'=>$phase, 'compiled_views'=>count($views),
        'route_cache_rebuilt'=>false, 'general_cache_flush'=>false], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $known = ['invalid_plan','invalid_scope','invalid_phase','installed_source_changed',
        'targeted_opcache_invalidation_failed','unexpected_bootstrap_cache'];
    $reason = in_array($error->getMessage(), $known, true) ? $error->getMessage() : get_class($error);
    echo json_encode(['release'=>$release, 'status'=>'stopped','step'=>$step,'reason'=>$reason,
        'file'=>basename($error->getFile()),'line'=>$error->getLine()], JSON_THROW_ON_ERROR);
}
