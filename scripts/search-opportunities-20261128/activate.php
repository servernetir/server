<?php
declare(strict_types=1);

header('Content-Type: application/json');
$release = 'search-opportunities-20261128';
if (PHP_SAPI !== 'fpm-fcgi' || getenv('SERVERNET_LOCAL_RELEASE') !== $release
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
$step = 'manifest';
try {
    $root = '/home/servernetcloud/servernet_app';
    $plan = (string) getenv('SERVERNET_SEARCH_PLAN');
    $phase = (string) getenv('SERVERNET_SEARCH_PHASE');
    if (!preg_match('~\A/home/servernetcloud/deploy-backups/search-opportunities-20261128-[a-f0-9]{12}/prepared\.json\z~', $plan)
        || is_link($plan) || !in_array($phase, ['before','after'], true)) throw new RuntimeException('invalid_plan');
    $records = json_decode((string) file_get_contents($plan), true, 32, JSON_THROW_ON_ERROR);
    $expected = ['config/growth.php', 'config/lookup.php', 'config/webtools.php', 'lang/en/ui.php', 'lang/fa/ui.php', 'lang/tr/ui.php',
        'resources/content/tools-seo.php', 'resources/views/pages/lookup.blade.php', 'resources/views/partials/tool-next-step.blade.php', 'routes/web.php'];
    $expected = array_map(fn ($path) => 'servernet_app/'.$path, $expected);
    $actual = array_column($records, 'destination'); sort($expected); sort($actual);
    if ($expected !== $actual) throw new RuntimeException('invalid_scope');
    foreach ($records as $row) {
        $file = '/home/servernetcloud/'.$row['destination'];
        if (is_link($file) || hash_file('sha256', $file) !== $row[$phase]) throw new RuntimeException('installed_source_changed');
        if (function_exists('opcache_is_script_cached') && opcache_is_script_cached($file) && !opcache_invalidate($file, true)) throw new RuntimeException('invalidation_failed');
    }
    $step = 'bootstrap';
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if ($app->configurationIsCached() || $app->routesAreCached()) throw new RuntimeException('unexpected_bootstrap_cache');
    $step = 'compile_views';
    $views = ['pages.lookup', 'partials.tool-next-step'];
    foreach ($views as $view) {
        $source = $app['view']->getFinder()->find($view);
        $app['blade.compiler']->compile($source);
        $compiled = $app['blade.compiler']->getCompiledPath($source);
        if (function_exists('opcache_is_script_cached') && opcache_is_script_cached($compiled) && !opcache_invalidate($compiled, true)) throw new RuntimeException('compiled_invalidation_failed');
    }
    $step = 'copy_contract';
    $changed = str_contains(trans('ui.hv_meta_t', [], 'en'), 'Pay-as-You-Go');
    if ($changed !== ($phase === 'after')) throw new RuntimeException('copy_contract_failed');
    if ($phase === 'after') {
        if (!str_contains(trans('ui.gpu_meta_t', [], 'tr'), 'Ekran Kartı Kiralama')
            || !str_contains(config('lookup.types.ping.fa.meta_d'), 'TCP')
            || count(config('growth.article-php-version-upgrade-guide', [])) !== 2) throw new RuntimeException('copy_contract_failed');
        $toolCopy = require $root.'/resources/content/tools-seo.php';
        foreach (['fa','en','tr'] as $locale) if (count($toolCopy['domain-ideas'][$locale]['faq']) < 7) throw new RuntimeException('copy_contract_failed');
    }
    $step = 'targeted_page_cache';
    $cache = $app['cache']->store();
    $generation = (int) $cache->get('page:gen', 0);
    $paths = ['/','en','tr'];
    foreach (['','en/','tr/'] as $prefix) foreach (['gpu','vps/hourly','tools/domain-ideas','tools/speedtest',
        'lookup/ping','lookup/global-ping','webtools','webtools/image-cropper','webtools/svg-to-png','blog/php-version-upgrade-guide',
        'blog/iranian-discord-servers','blog/wordpress-speed-optimization'] as $target) $paths[] = $prefix.$target;
    foreach (['servernet.cloud','console.servernet.cloud'] as $host) foreach (['','/index.php'] as $base) {
        foreach ($paths as $path) $cache->forget('page:g'.$generation.':'.sha1($host.'|'.$base.'|'.$path));
    }
    echo json_encode(['release'=>$release,'phase'=>$phase,'compiled_views'=>count($views),'general_cache_flush'=>false,
        'targeted_paths'=>count($paths)], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $codes=['invalid_plan','invalid_scope','installed_source_changed','invalidation_failed','unexpected_bootstrap_cache','compiled_invalidation_failed','copy_contract_failed'];
    echo json_encode(['status'=>'stopped','step'=>$step,'reason'=>in_array($error->getMessage(),$codes,true)?$error->getMessage():'activation_failed']);
}
