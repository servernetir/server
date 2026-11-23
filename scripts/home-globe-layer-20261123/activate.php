<?php
declare(strict_types=1);
header('Content-Type: application/json');
if (PHP_SAPI !== 'fpm-fcgi' || getenv('SERVERNET_LOCAL_RELEASE') !== 'home-globe-layer-20261123'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
$root = '/home/servernetcloud/servernet_app';
$plan = (string) getenv('SERVERNET_STUDIO_PLAN');
if (!preg_match('~\A/home/servernetcloud/deploy-backups/home-globe-layer-20261123-[a-f0-9]{12}/prepared\.json\z~', $plan) || is_link($plan)) {
    throw new RuntimeException('invalid_plan');
}
$records = json_decode((string) file_get_contents($plan), true, 32, JSON_THROW_ON_ERROR);
$expected = ['servernet_app/public/assets/css/studio-carousel.css', 'public_html/assets/css/studio-carousel.css'];
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
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (['partials.home-service-slides', 'partials.studio-carousel', 'partials.studio-carousel-controls', 'pages.home'] as $view) {
    $source = $app['view']->getFinder()->find($view);
    $app['blade.compiler']->compile($source);
    $compiled = $app['blade.compiler']->getCompiledPath($source);
    if (function_exists('opcache_is_script_cached') && opcache_is_script_cached($compiled)) opcache_invalidate($compiled, true);
}
$cache = Illuminate\Support\Facades\Cache::store();
$generation = (int) $cache->get('page:gen', 0);
foreach (['servernet.cloud', 'console.servernet.cloud'] as $host) {
    foreach (['', '/index.php'] as $base) {
        foreach (['/', 'en', 'tr'] as $path) $cache->forget('page:g'.$generation.':'.sha1($host.'|'.$base.'|'.$path));
    }
}
echo json_encode(['release' => 'home-globe-layer-20261123', 'compiled_views' => 4,
    'studio_configured' => app(App\Services\Studio\StudioAuthorization::class)->configured(),
    'general_cache_flush' => false], JSON_THROW_ON_ERROR);
