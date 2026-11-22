<?php
declare(strict_types=1);

header('Content-Type: application/json');
if (PHP_SAPI !== 'fpm-fcgi' || getenv('SERVERNET_LOCAL_RELEASE') !== 'ollama-official-20261122'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
$planPath = getenv('SERVERNET_OLLAMA_PLAN');
$plan = json_decode((string) file_get_contents($planPath), true, 32, JSON_THROW_ON_ERROR);
$root = '/home/servernetcloud/servernet_app';
$paths = ['app/Services/Cloud/SaladOllamaImage.php', 'app/Services/Cloud/SaladOperations.php'];
if (($plan['root'] ?? '') !== $root || array_column($plan['files'] ?? [], 'path') !== $paths) {
    throw new RuntimeException('invalid_scoped_plan');
}
foreach ($plan['files'] as $row) {
    if (is_link($root.'/'.$row['path']) || hash_file('sha256', $root.'/'.$row['path']) !== $row['after_sha256']) {
        throw new RuntimeException('installed_source_changed');
    }
}
$invalidated = [];
foreach ($paths as $path) {
    if (filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL)
        && (! function_exists('opcache_invalidate') || ! opcache_invalidate($root.'/'.$path, true))) {
        throw new RuntimeException('targeted_opcache_invalidation_failed');
    }
    $invalidated[] = $path;
}
require $root.'/'.$paths[0];
require $root.'/'.$paths[1];
$version = App\Services\Cloud\SaladOllamaImage::VERSION;
if ($version !== '0.40.17') { throw new RuntimeException('loaded_version_mismatch'); }
echo json_encode(['release' => 'ollama-official-20261122', 'version' => $version,
    'invalidated' => $invalidated, 'general_cache_flush' => false], JSON_THROW_ON_ERROR);
