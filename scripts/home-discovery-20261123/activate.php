<?php
declare(strict_types=1);

header('Content-Type: application/json');
$release = 'home-discovery-20261123';
if (PHP_SAPI !== 'fpm-fcgi' || getenv('SERVERNET_LOCAL_RELEASE') !== $release
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
$step = 'manifest';
try {
$root = '/home/servernetcloud/servernet_app';
$plan = (string) getenv('SERVERNET_STUDIO_PLAN');
if (!preg_match('~\A/home/servernetcloud/deploy-backups/home-discovery-20261123-[a-f0-9]{12}/prepared\.json\z~', $plan) || is_link($plan)) {
    throw new RuntimeException('invalid_plan');
}
$records = json_decode((string) file_get_contents($plan), true, 32, JSON_THROW_ON_ERROR);
$views = ['pages.home', 'pages.ai.home', 'pages.ai.studio', 'partials.home-discovery',
    'partials.home-discovery-style', 'partials.atlas-network', 'partials.atlas-seo', 'partials.atlas-home-globe'];
$expected = ['servernet_app/app/Support/HomeServiceDirectory.php',
    'servernet_app/app/Http/Controllers/Ai/StudioLandingController.php',
    'servernet_app/app/Http/Controllers/SiteController.php', 'servernet_app/routes/web.php',
    'servernet_app/config/seo.php', 'servernet_app/app/Support/RobotsPolicy.php',
    'servernet_app/public/robots.txt', 'public_html/robots.txt',
    'public_html/assets/js/atlas-home-globe.js', 'servernet_app/public/assets/css/studio-carousel.css',
    'public_html/assets/css/studio-carousel.css'];
foreach (['fa','en','tr'] as $locale) {
    foreach (['home_discovery','studio_landing'] as $language) $expected[] = 'servernet_app/lang/'.$locale.'/'.$language.'.php';
}
foreach ($views as $view) $expected[] = 'servernet_app/resources/views/'.str_replace('.', '/', $view).'.blade.php';
$actual = array_column($records, 'destination'); sort($expected); sort($actual);
if ($expected !== $actual) throw new RuntimeException('invalid_scope');
$phase = getenv('SERVERNET_STUDIO_PHASE');
if (!in_array($phase, ['before','after'], true)) throw new RuntimeException('invalid_phase');
$step = 'source_checks';
foreach ($records as $row) {
    $path = '/home/servernetcloud/'.$row['destination'];
    $hash = $phase === 'before' && $row['before'] === null ? $row['after'] : $row[$phase];
    if (is_link($path) || !is_file($path) || hash_file('sha256', $path) !== $hash) throw new RuntimeException('installed_source_changed');
    if (str_ends_with($path, '.php') && function_exists('opcache_is_script_cached') && opcache_is_script_cached($path)) {
        if (!opcache_invalidate($path, true)) throw new RuntimeException('targeted_opcache_invalidation_failed');
    }
}
$step = 'bootstrap';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
if ($app->configurationIsCached() || $app->routesAreCached()) throw new RuntimeException('unexpected_bootstrap_cache');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$step = 'route_registry';
$app->make(Illuminate\Contracts\Http\Kernel::class);
$router = $app['router'];
foreach ([''=>'', 'en.'=>'/en', 'tr.'=>'/tr'] as $name => $prefix) {
    $registered = $router->getRoutes()->getByName($name.'ai.studio');
    if (($registered !== null) !== ($phase === 'after')) throw new RuntimeException('studio_route_contract_changed');
    if ($registered) {
        $route = $router->getRoutes()->match(Illuminate\Http\Request::create('https://servernet.cloud'.$prefix.'/ai/studio', 'GET'));
        if ($route->getName() !== $name.'ai.studio' || !in_array('HEAD', $route->methods(), true)) throw new RuntimeException('studio_public_route_not_matched');
        foreach ($router->gatherRouteMiddleware($route) as $middleware) {
            if (str_starts_with($middleware, Illuminate\Auth\Middleware\Authenticate::class)) throw new RuntimeException('studio_landing_requires_auth');
        }
    }
}
$step = 'crawler_policy';
if ($phase === 'after') {
    $robots = App\Support\RobotsPolicy::render((array) config('seo.crawler_policy'));
    foreach ([$root.'/public/robots.txt', '/home/servernetcloud/public_html/robots.txt'] as $path) {
        if (!hash_equals($robots, (string) file_get_contents($path))) throw new RuntimeException('crawler_policy_mismatch');
    }
}
$step = 'compile_views';
foreach ($views as $view) {
    $source = $app['view']->getFinder()->find($view);
    $app['blade.compiler']->compile($source);
    $compiled = $app['blade.compiler']->getCompiledPath($source);
    if (function_exists('opcache_is_script_cached') && opcache_is_script_cached($compiled)) opcache_invalidate($compiled, true);
}
$step = 'homepage_cache';
$cache = $app['cache']->store();
$generation = (int) $cache->get('page:gen', 0);
$paths = ['/', 'en', 'tr', 'llms.txt', 'sitemap.xml', 'sitemap-index.xml', 'sitemaps/ai.xml'];
foreach (['', 'en/', 'tr/'] as $prefix) foreach (['ai','ai/studio'] as $path) $paths[] = $prefix.$path;
foreach (['servernet.cloud','console.servernet.cloud'] as $host) {
    foreach (['','/index.php'] as $base) {
        foreach ($paths as $path) $cache->forget('page:g'.$generation.':'.sha1($host.'|'.$base.'|'.$path));
    }
}
echo json_encode(['release'=>$release, 'compiled_views'=>count($views), 'phase'=>$phase,
    'route_cache_rebuilt'=>false, 'general_cache_flush'=>false, 'studio_public_route'=>$phase === 'after'], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    // Report only a controlled failure code and source position, never log data,
    // configuration values, stack arguments or exception payloads.
    $known = ['invalid_plan','invalid_scope','invalid_phase','installed_source_changed',
        'targeted_opcache_invalidation_failed','unexpected_bootstrap_cache','studio_route_contract_changed',
        'studio_public_route_not_matched','studio_landing_requires_auth','crawler_policy_mismatch'];
    $reason = in_array($error->getMessage(), $known, true) ? $error->getMessage() : get_class($error);
    echo json_encode(['release'=>$release, 'status'=>'stopped','step'=>$step,'reason'=>$reason,
        'file'=>basename($error->getFile()),'line'=>$error->getLine()], JSON_THROW_ON_ERROR);
}
