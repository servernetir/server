<?php
declare(strict_types=1);

header('Content-Type: application/json');
$release = 'search-growth-20261124';
if (PHP_SAPI !== 'fpm-fcgi' || getenv('SERVERNET_LOCAL_RELEASE') !== $release
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
$step = 'manifest';
try {
    $root = '/home/servernetcloud/servernet_app';
    $plan = (string) getenv('SERVERNET_SEARCH_PLAN');
    $phase = (string) getenv('SERVERNET_SEARCH_PHASE');
    if (!preg_match('~\A/home/servernetcloud/deploy-backups/search-growth-20261124-[a-f0-9]{12}/prepared\.json\z~', $plan)
        || is_link($plan) || !in_array($phase, ['before', 'after'], true)) throw new RuntimeException('invalid_plan');
    $records = json_decode((string) file_get_contents($plan), true, 32, JSON_THROW_ON_ERROR);
    $expected = ['app/Services/PublicPageStyles.php', 'config/blog.php', 'config/growth.php',
        'resources/views/layouts/site.blade.php', 'resources/views/pages/ai/home.blade.php',
        'resources/views/pages/blog-post.blade.php', 'resources/views/pages/gpu.blade.php',
        'resources/views/pages/n8n.blade.php', 'resources/views/partials/seo-python-fit.blade.php',
        'resources/views/partials/search-growth-guide.blade.php', 'resources/views/partials/gpu-styles.blade.php',
        'resources/views/partials/chat.blade.php', 'resources/views/partials/vps-plan-table.blade.php',
        'lang/fa/search-growth.php', 'lang/en/search-growth.php', 'lang/tr/search-growth.php',
        'resources/content/growth/python-hosting-django-flask-deployment.json'];
    $expected = array_map(fn ($path) => 'servernet_app/'.$path, $expected);
    $actual = array_column($records, 'destination'); sort($expected); sort($actual);
    if ($expected !== $actual) throw new RuntimeException('invalid_scope');
    foreach ($records as $row) {
        $file = '/home/servernetcloud/'.$row['destination'];
        $hash = $phase === 'before' && $row['before'] !== null ? $row['before'] : $row['after'];
        if (is_link($file) || !is_file($file) || hash_file('sha256', $file) !== $hash) throw new RuntimeException('installed_source_changed');
        if (str_ends_with($file, '.php') && function_exists('opcache_is_script_cached') && opcache_is_script_cached($file)
            && !opcache_invalidate($file, true)) throw new RuntimeException('targeted_opcache_invalidation_failed');
    }
    $step = 'bootstrap';
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if ($app->configurationIsCached() || $app->routesAreCached()) throw new RuntimeException('unexpected_bootstrap_cache');
    $step = 'compile_views';
    $views = ['layouts.site', 'pages.ai.home', 'pages.blog-post', 'pages.gpu', 'pages.n8n',
        'partials.seo-python-fit', 'partials.search-growth-guide', 'partials.gpu-styles', 'partials.growth-guides',
        'partials.chat', 'partials.vps-plan-table'];
    foreach ($views as $view) {
        $source = $app['view']->getFinder()->find($view);
        $app['blade.compiler']->compile($source);
        $compiled = $app['blade.compiler']->getCompiledPath($source);
        if (function_exists('opcache_is_script_cached') && opcache_is_script_cached($compiled)) opcache_invalidate($compiled, true);
    }
    $step = 'page_cache';
    $cache = $app['cache']->store();
    $generation = (int) $cache->get('page:gen', 0);
    $paths = ['/','en','tr'];
    $targets = ['gpu','vps/hourly','vps/philippines','vps/uae','hosting/linux','hosting/python','ai','cloud/n8n',
        'blog/php-version-upgrade-guide','blog/gpu-server-selection','blog/gpu-servers-for-ai',
        'blog/how-to-choose-a-vps','blog/hourly-vs-monthly-billing','blog/python-hosting-django-flask-deployment'];
    foreach (['','en/','tr/'] as $prefix) foreach ($targets as $target) $paths[] = $prefix.$target;
    foreach (['servernet.cloud','console.servernet.cloud'] as $host) foreach (['','/index.php'] as $base) {
        foreach ($paths as $path) $cache->forget('page:g'.$generation.':'.sha1($host.'|'.$base.'|'.$path));
    }
    $step = 'style_contract';
    if ($phase === 'after') {
        $app->instance('request', Illuminate\Http\Request::create('https://servernet.cloud/gpu'));
        if (app(App\Services\PublicPageStyles::class)->inline('assets/css/site.css') === null) throw new RuntimeException('live_asset_bundle_invalid');
    }
    echo json_encode(['release'=>$release, 'compiled_views'=>count($views), 'phase'=>$phase,
        'general_cache_flush'=>false, 'targeted_paths'=>count($paths)], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $known = ['invalid_plan','invalid_scope','installed_source_changed','targeted_opcache_invalidation_failed',
        'unexpected_bootstrap_cache','live_asset_bundle_invalid'];
    $reason = in_array($error->getMessage(), $known, true) ? $error->getMessage() : 'activation_failed';
    echo json_encode(['status'=>'stopped','step'=>$step,'reason'=>$reason,'file'=>basename($error->getFile()),'line'=>$error->getLine()]);
}
