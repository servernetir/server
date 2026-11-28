<?php
declare(strict_types=1);

header('Content-Type: application/json');
$release = 'symfony-patch-20261128';
if (PHP_SAPI !== 'fpm-fcgi' || getenv('SERVERNET_LOCAL_RELEASE') !== $release
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
$step = 'manifest';
try {
    $root = '/home/servernetcloud/servernet_app';
    $file = (string) getenv('SERVERNET_SEARCH_PLAN');
    $phase = (string) getenv('SERVERNET_SEARCH_PHASE');
    if (!preg_match('~\A/home/servernetcloud/deploy-backups/symfony-patch-20261128-[a-f0-9]{12}/prepared\.json\z~', $file)
        || is_link($file) || !in_array($phase, ['before','after'], true)) throw new RuntimeException('invalid_plan');
    $plan = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
    if (hash_file('sha256', $root.'/composer.lock') !== $plan['lock_'.$phase]) throw new RuntimeException('lock_mismatch');
    $step = 'opcache';
    foreach ($plan['invalidate'] as $p) {
        if (!preg_match('~\A(?:autoload\.php|(?:composer|symfony/(?:http-foundation|http-kernel|mailer|routing))/[A-Za-z0-9_.\-/]+\.php)\z~', $p)
            || in_array('..', explode('/', $p), true)) throw new RuntimeException('invalid_scope');
        $path = $root.'/vendor/'.$p;
        if (function_exists('opcache_is_script_cached') && opcache_is_script_cached($path)
            && !opcache_invalidate($path, true)) throw new RuntimeException('invalidation_failed');
    }
    $step = 'runtime';
    require $root.'/vendor/autoload.php';
    $expected = $phase === 'after' ? $plan['new_versions'] : $plan['old_versions'];
    foreach ($expected as $name => $version) {
        if (Composer\InstalledVersions::getPrettyVersion($name) !== $version) throw new RuntimeException('runtime_version_mismatch');
    }
    foreach ([Symfony\Component\HttpFoundation\Request::class, Symfony\Component\HttpKernel\HttpKernel::class,
        Symfony\Component\Mailer\Mailer::class, Symfony\Component\Routing\Generator\UrlGenerator::class] as $class) {
        if (!class_exists($class)) throw new RuntimeException('runtime_class_missing');
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if ($app->configurationIsCached() || $app->routesAreCached()) throw new RuntimeException('unexpected_bootstrap_cache');
    echo json_encode(['release'=>$release,'phase'=>$phase,'compiled_views'=>0,'general_cache_flush'=>false,
        'packages'=>$expected,'opcache_paths'=>count($plan['invalidate'])], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $codes = ['invalid_plan','lock_mismatch','invalid_scope','invalidation_failed','runtime_version_mismatch',
        'runtime_class_missing','unexpected_bootstrap_cache'];
    echo json_encode(['release'=>$release,'status'=>'stopped','step'=>$step,
        'reason'=>in_array($error->getMessage(), $codes, true) ? $error->getMessage() : 'activation_failed']);
}
