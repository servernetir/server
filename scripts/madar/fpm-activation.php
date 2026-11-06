<?php
/** HTTP-denied same-account primary; no bootstrap, database, scheduler or transport. */
declare(strict_types=1);

const MF_RELEASE = 'madar-20261031';
const MF_HOME = '/home/servernetcloud';
const MF_APP = MF_HOME.'/servernet_app';
const MF_PRIMARY = MF_HOME.'/public_html/_madar_release_20261031/activate.php';
const MF_TARGET = 'bdd3f3afe8911a73f9426c9a69a992a8688f53b0';
const MF_PLAN = '1b786db7a2f4508a20fb0611a074237b5cc96a9252f4010bffd1ef1fc9193242';

function madarCanonical(array $value): string
{
    $sort = function (&$item) use (&$sort): void {
        if (! is_array($item)) { return; }
        if (! array_is_list($item)) { ksort($item, SORT_STRING); }
        foreach ($item as &$child) { $sort($child); }
    };
    $sort($value);
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

if (PHP_SAPI !== 'fpm-fcgi' || realpath(__FILE__) !== MF_PRIMARY
    || ($_SERVER['SERVERNET_LOCAL_RELEASE'] ?? '') !== MF_RELEASE
    || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || ! in_array($_SERVER['SERVERNET_RELEASE_ACTION'] ?? '', ['observe', 'activate', 'verify'], true)
    || (function_exists('posix_geteuid') && posix_geteuid() !== getmyuid())) {
    http_response_code(404); exit;
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
$action = $_SERVER['SERVERNET_RELEASE_ACTION'];
$locks = [];
try {
    foreach (['.servernet-web-release.lock', 'servernet-release.lock'] as $name) {
        $path = MF_HOME.'/'.$name;
        if (is_link($path) || ! is_file($path) || fileowner($path) !== getmyuid()) { throw new RuntimeException('release_lock_invalid'); }
        $lock = fopen($path, 'r+b');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('release_lock_busy'); }
        $locks[] = $lock;
    }
    $plans = [];
    foreach (glob(MF_HOME.'/vpstc-release-bdd3f3afe891-*/plan.json') as $filename) {
        if (is_link($filename) || fileowner($filename) !== getmyuid()) { continue; }
        $p = json_decode((string) file_get_contents($filename), true, 32, JSON_THROW_ON_ERROR);
        if (($p['plan_sha256'] ?? '') === MF_PLAN) { $plans[] = [$filename, $p]; }
    }
    if (count($plans) !== 1) { throw new RuntimeException('reviewed_plan_not_unique'); }
    [$filename, $plan] = $plans[0];
    unset($plan['plan_sha256']);
    if (! hash_equals(MF_PLAN, hash('sha256', madarCanonical($plan)))
        || ($plan['target'] ?? '') !== MF_TARGET || ($plan['root'] ?? '') !== MF_APP || count($plan['files'] ?? []) !== 38) {
        throw new RuntimeException('reviewed_plan_hash_invalid');
    }
    $targets = [];
    foreach ($plan['files'] as $row) {
        $relative = $row['path'];
        $path = str_starts_with($relative, 'public/') ? MF_HOME.'/public_html/'.substr($relative, 7)
            : (str_starts_with($relative, 'runtime-assets/') ? MF_APP.'/public/'.substr($relative, 15) : MF_APP.'/'.$relative);
        if (is_link($path) || ! is_file($path) || realpath($path) !== $path
            || ! hash_equals($row['after_sha256'], (string) hash_file('sha256', $path))) {
            throw new RuntimeException('installed_file_hash_drift');
        }
        if (str_ends_with($path, '.php')) { $targets[] = $path; }
    }
    $compiled = json_decode((string) file_get_contents(dirname($filename).'/compiled-manifest.json'), true, 16, JSON_THROW_ON_ERROR);
    foreach ($compiled['files'] as $row) {
        $path = $row['path'];
        if (! str_starts_with($path, MF_APP.'/storage/framework/views/') || is_link($path) || ! is_file($path)
            || ! hash_equals($row['after_sha256'], (string) hash_file('sha256', $path))) {
            throw new RuntimeException('compiled_view_hash_drift');
        }
        $targets[] = $path;
    }
    if (! function_exists('opcache_is_script_cached') || ! function_exists('opcache_invalidate')) { throw new RuntimeException('opcache_unavailable'); }
    $cached = 0; $invalidated = 0;
    foreach ($targets as $path) {
        if (opcache_is_script_cached($path)) {
            $cached++;
            if ($action === 'activate') {
                if (! opcache_invalidate($path, true)) { throw new RuntimeException('target_invalidation_failed'); }
                $invalidated++;
            }
        }
    }
    $available = null;
    if ($action === 'verify') {
        require MF_APP.'/vendor/autoload.php';
        $available = class_exists(App\Models\MadarMission::class) && class_exists(App\Services\Madar\MadarClaims::class);
        if (! $available) { throw new RuntimeException('new_class_autoload_failed'); }
    }
    echo json_encode(['release' => MF_RELEASE, 'action' => $action, 'status' => 'ok', 'plan_sha256' => MF_PLAN,
        'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'pid' => getmypid(), 'target_files' => count($targets),
        'cached_before' => $cached, 'invalidated' => $invalidated, 'new_classes_available' => $available,
        'opcache_validate_timestamps' => (string) ini_get('opcache.validate_timestamps'),
        'opcache_revalidate_freq' => (string) ini_get('opcache.revalidate_freq'), 'utc' => gmdate('c'),
        'framework_bootstrapped' => false, 'database_writes' => 0, 'external_calls' => 0], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    http_response_code(503);
    echo json_encode(['release' => MF_RELEASE, 'action' => $action, 'status' => 'stopped',
        'reason' => $error instanceof RuntimeException && preg_match('/^[a-z_]+$/D', $error->getMessage())
            ? $error->getMessage() : 'private_activation_failed'], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    foreach (array_reverse($locks) as $lock) { flock($lock, LOCK_UN); fclose($lock); }
}
