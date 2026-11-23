<?php
declare(strict_types=1);
header('Content-Type: application/json');
$release = 'home-email-studio-20261123';
if (PHP_SAPI !== 'fpm-fcgi' || getenv('SERVERNET_LOCAL_RELEASE') !== $release
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
$root = '/home/servernetcloud/servernet_app';
$plan = (string) getenv('SERVERNET_STUDIO_PLAN');
if (!preg_match('~\\A/home/servernetcloud/deploy-backups/home-email-studio-20261123-[a-f0-9]{12}/prepared\\.json\\z~', $plan) || is_link($plan)) {
    throw new RuntimeException('invalid_plan');
}
$records = json_decode((string) file_get_contents($plan), true, 32, JSON_THROW_ON_ERROR);
$expected = [];
foreach (['fa', 'en', 'tr'] as $locale) {
    foreach (['home_slides', 'studio_carousel', 'transactional-email', 'studio', 'studio_download'] as $language) {
        $expected[] = 'servernet_app/lang/'.$locale.'/'.$language.'.php';
    }
    if ($locale !== 'fa') $expected[] = 'servernet_app/lang/'.$locale.'/ai_studio.php';
}
foreach (['pages/home', 'pages/hosting', 'pages/transactional-email', 'partials/studio-carousel',
    'partials/studio-carousel-controls', 'partials/studio-home', 'partials/home-email-slide',
    'partials/transactional-email-visual', 'partials/email-product-choice', 'account/ai'] as $view) {
    $expected[] = 'servernet_app/resources/views/'.$view.'.blade.php';
}
foreach (['servernet_app/public', 'public_html'] as $public) {
    foreach (['css/studio-carousel.css', 'css/email-showcase.css', 'js/studio-carousel.js'] as $asset) {
        $expected[] = $public.'/assets/'.$asset;
    }
}
$actual = array_column($records, 'destination'); sort($expected); sort($actual);
if ($expected !== $actual) throw new RuntimeException('invalid_scope');
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
$views = ['pages.home', 'pages.hosting', 'pages.transactional-email', 'partials.studio-carousel',
    'partials.studio-carousel-controls', 'partials.studio-home', 'partials.home-email-slide',
    'partials.transactional-email-visual', 'partials.email-product-choice', 'account.ai'];
foreach ($views as $view) {
    $source = $app['view']->getFinder()->find($view);
    $app['blade.compiler']->compile($source);
    $compiled = $app['blade.compiler']->getCompiledPath($source);
    if (function_exists('opcache_is_script_cached') && opcache_is_script_cached($compiled)) opcache_invalidate($compiled, true);
}
$cache = $app['cache']->store();
$generation = (int) $cache->get('page:gen', 0);
$paths = ['/', 'en', 'tr'];
foreach (['transactional-email', 'hosting/email', 'studio/download', 'ai'] as $path) {
    foreach (['', 'en/', 'tr/'] as $prefix) $paths[] = $prefix.$path;
}
foreach (['servernet.cloud', 'console.servernet.cloud'] as $host) {
    foreach (['', '/index.php'] as $base) {
        foreach ($paths as $path) $cache->forget('page:g'.$generation.':'.sha1($host.'|'.$base.'|'.$path));
    }
}
echo json_encode(['release' => $release, 'compiled_views' => count($views), 'route_cache_rebuilt' => false,
    'phase' => $phase, 'general_cache_flush' => false], JSON_THROW_ON_ERROR);
