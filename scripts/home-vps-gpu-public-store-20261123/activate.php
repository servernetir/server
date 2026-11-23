<?php
declare(strict_types=1);
header('Content-Type: application/json');
$release = 'home-vps-gpu-public-store-20261123';
if (PHP_SAPI !== 'fpm-fcgi' || getenv('SERVERNET_LOCAL_RELEASE') !== $release
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
$root = '/home/servernetcloud/servernet_app';
$plan = (string) getenv('SERVERNET_STUDIO_PLAN');
if (!preg_match('~\A/home/servernetcloud/deploy-backups/home-vps-gpu-public-store-20261123-[a-f0-9]{12}/prepared\.json\z~', $plan) || is_link($plan)) {
    throw new RuntimeException('invalid_plan');
}
$records = json_decode((string) file_get_contents($plan), true, 32, JSON_THROW_ON_ERROR);
$expected = ['servernet_app/app/Services/Cloud/CloudStoreDraft.php', 'servernet_app/app/Http/Controllers/Account/CloudStoreGuestController.php', 'servernet_app/app/Http/Middleware/CloudStoreBrowse.php', 'servernet_app/resources/views/layouts/cloud-store.blade.php', 'servernet_app/resources/views/partials/home-gpu-slide.blade.php', 'servernet_app/lang/fa/cloud_store_guest.php', 'servernet_app/lang/en/cloud_store_guest.php', 'servernet_app/lang/tr/cloud_store_guest.php', 'servernet_app/public/assets/css/home-gpu-slide.css', 'public_html/assets/css/home-gpu-slide.css', 'servernet_app/public/assets/css/cloud-store-public.css', 'public_html/assets/css/cloud-store-public.css', 'servernet_app/lang/fa/home_slides.php', 'servernet_app/lang/en/home_slides.php', 'servernet_app/lang/tr/home_slides.php', 'servernet_app/public/assets/css/studio-carousel.css', 'public_html/assets/css/studio-carousel.css', 'servernet_app/public/assets/js/studio-carousel.js', 'public_html/assets/js/studio-carousel.js', 'servernet_app/app/Http/Controllers/Account/CloudStoreController.php', 'servernet_app/resources/views/account/cloud-store.blade.php', 'servernet_app/resources/views/partials/studio-carousel-controls.blade.php', 'servernet_app/resources/views/pages/home.blade.php', 'servernet_app/routes/web.php'];
if (array_column($records, 'destination') !== $expected) throw new RuntimeException('invalid_scope');
$phase = getenv('SERVERNET_STUDIO_PHASE');
if (!in_array($phase, ['before', 'after'], true)) throw new RuntimeException('invalid_phase');
foreach ($records as $row) {
    $path = '/home/servernetcloud/'.$row['destination'];
    $hash = $phase === 'before' && $row['before'] === null ? $row['after'] : $row[$phase];
    if (is_link($path) || !is_file($path) || hash_file('sha256', $path) !== $hash) throw new RuntimeException('installed_source_changed');
    if (str_ends_with($path, '.php') && function_exists('opcache_is_script_cached') && opcache_is_script_cached($path)) {
        if (!opcache_invalidate($path, true)) throw new RuntimeException('targeted_opcache_invalidation_failed');
    }
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
// Invalidate both before boot and after rebuilding so each owned pool sees fresh routes.
$routeCache = $app->getCachedRoutesPath();
if (function_exists('opcache_is_script_cached') && opcache_is_script_cached($routeCache)) opcache_invalidate($routeCache, true);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$rebuilt = false;
if ($app->routesAreCached()) {
    if (Illuminate\Support\Facades\Artisan::call('route:cache') !== 0) throw new RuntimeException('route_cache_failed');
    if (function_exists('opcache_is_script_cached') && opcache_is_script_cached($routeCache)) opcache_invalidate($routeCache, true);
    $rebuilt = true;
}
foreach (['pages.home', 'partials.home-gpu-slide', 'partials.studio-carousel-controls', 'partials.studio-carousel', 'account.cloud-store', 'layouts.cloud-store'] as $view) {
    $source = $app['view']->getFinder()->find($view);
    $app['blade.compiler']->compile($source);
    $compiled = $app['blade.compiler']->getCompiledPath($source);
    if (function_exists('opcache_is_script_cached') && opcache_is_script_cached($compiled)) opcache_invalidate($compiled, true);
}
// Match current source routes in an isolated router; never dispatch a purchase.
$fresh = require $root.'/bootstrap/app.php';
$fresh->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$router = $fresh['router'];
foreach (['', '/en', '/tr'] as $prefix) {
    foreach (['GET', 'POST'] as $method) {
        $request = Illuminate\Http\Request::create('https://console.servernet.cloud'.$prefix.'/account/cloud-store', $method);
        $route = $router->getRoutes()->match($request);
        $middleware = $router->gatherRouteMiddleware($route);
        $auth = (bool) array_filter($middleware, fn ($m) => str_starts_with($m, Illuminate\Auth\Middleware\Authenticate::class.':'));
        if ($auth !== ($method === 'POST' || $phase === 'before')) throw new RuntimeException('store_auth_contract_failed');
        if (!in_array(App\Http\Middleware\EnsureCustomerActive::class, $middleware, true)
            || !in_array(App\Http\Middleware\EnforceCustomerIp::class, $middleware, true)) throw new RuntimeException('account_guard_missing');
    }
}
$cache = $fresh['cache']->store();
$generation = (int) $cache->get('page:gen', 0);
foreach (['servernet.cloud', 'console.servernet.cloud'] as $host) {
    foreach (['', '/index.php'] as $base) {
        foreach (['/', 'en', 'tr'] as $path) $cache->forget('page:g'.$generation.':'.sha1($host.'|'.$base.'|'.$path));
    }
}
echo json_encode(['release' => $release, 'compiled_views' => 6, 'route_cache_rebuilt' => $rebuilt,
    'auth_contract' => $phase, 'general_cache_flush' => false], JSON_THROW_ON_ERROR);
