<?php
/** Temporary, HTTP-denied primary script. Only the account's private FastCGI caller may run it. */
declare(strict_types=1);

const BF_RELEASE = 'bale-month-comparison-20261031';
const BF_HOME = '/home/servernetcloud';
const BF_APP = BF_HOME.'/servernet_app';
const BF_PRIMARY = BF_HOME.'/public_html/_bale_month_comparison_20261031/activate.php';
const BF_MANIFEST = BF_HOME.'/bale-month-comparison-tests-20261031/scripts/bale-month-comparison-20261031/release-manifest.json';
const BF_MANIFEST_SHA256 = '40126a640116d3f22e960af4a70efec58e314d3a9b273b1e3e71ba527313ab59';
const BF_TARGETS = [
    'lang/fa/bale-reporting.php',
    'lang/en/bale-reporting.php',
    'lang/tr/bale-reporting.php',
    'app/Services/Bale/Management/ManagerTrafficSnapshot.php',
    'app/Services/Bale/Management/BaleManagerDailyDigest.php',
];

if (PHP_SAPI !== 'fpm-fcgi' || realpath(__FILE__) !== BF_PRIMARY
    || ($_SERVER['SERVERNET_LOCAL_RELEASE'] ?? '') !== BF_RELEASE
    || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || ! in_array($_SERVER['SERVERNET_RELEASE_ACTION'] ?? '', ['observe', 'activate', 'verify'], true)
    || (function_exists('posix_geteuid') && posix_geteuid() !== getmyuid())) {
    http_response_code(404);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
$action = $_SERVER['SERVERNET_RELEASE_ACTION'];
$locks = [];
try {
    foreach (['.servernet-web-release.lock', 'servernet-release.lock'] as $name) {
        $path = BF_HOME.'/'.$name;
        if (is_link($path) || ! is_file($path) || fileowner($path) !== getmyuid()) {
            throw new RuntimeException('release_lock_invalid');
        }
        $lock = fopen($path, 'r+b');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('release_lock_busy'); }
        $locks[] = $lock;
    }
    if (is_link(BF_MANIFEST) || ! is_file(BF_MANIFEST)
        || ! hash_equals(BF_MANIFEST_SHA256, (string) hash_file('sha256', BF_MANIFEST))) {
        throw new RuntimeException('release_manifest_drift');
    }
    $manifest = json_decode((string) file_get_contents(BF_MANIFEST), true, 16, JSON_THROW_ON_ERROR);
    if (($manifest['release'] ?? '') !== BF_RELEASE || count($manifest['files'] ?? []) !== 5
        || array_column($manifest['files'], 'target') !== BF_TARGETS) {
        throw new RuntimeException('release_scope_invalid');
    }
    foreach ($manifest['files'] as $row) {
        $path = BF_APP.'/'.$row['target'];
        if (is_link($path) || ! is_file($path) || realpath($path) !== $path
            || ! hash_equals($row['after'], (string) hash_file('sha256', $path))) {
            throw new RuntimeException('runtime_hash_drift');
        }
    }
    if (! function_exists('opcache_is_script_cached') || ! function_exists('opcache_invalidate')) {
        throw new RuntimeException('opcache_functions_unavailable');
    }
    $before = [];
    foreach (BF_TARGETS as $target) { $before[$target] = opcache_is_script_cached(BF_APP.'/'.$target); }
    $invalidated = 0;
    if ($action === 'activate') {
        foreach ($before as $target => $cached) {
            if ($cached) {
                if (! opcache_invalidate(BF_APP.'/'.$target, true)) { throw new RuntimeException('target_invalidation_failed'); }
                $invalidated++;
            }
        }
    }
    $fixture = null;
    if ($action === 'verify') {
        // Composer autoload only. No application bootstrap, queries, source refresh or transport.
        require BF_APP.'/vendor/autoload.php';
        $sections = array_fill_keys(\App\Services\Bale\Management\BaleManagerDailyDigest::TOPICS, ['available' => false]);
        $sections['stats'] = ['available' => true, 'signups_24h' => 2, 'signups_30d' => 500,
            'signups_previous_30d' => 580, 'customers_total' => 1080,
            'delivered_24h' => 3, 'delivered_30d' => 50, 'delivered_previous_30d' => 58];
        $known = ['status' => 'known', 'timezone' => 'Asia/Tehran', 'preliminary' => false];
        $sections['traffic'] = ['available' => true, 'as_of' => '2026-10-31T08:55:00Z',
            'ga4' => ['periods' => [
                'yesterday' => $known + ['total_users' => 11, 'sessions' => 22,
                    'start_date' => '2026-10-30', 'end_date' => '2026-10-30'],
                'recent_30d' => $known + ['total_users' => 500, 'sessions' => 900,
                    'start_date' => '2026-10-01', 'end_date' => '2026-10-30'],
                'previous_30d' => $known + ['total_users' => 580, 'sessions' => 1100,
                    'start_date' => '2026-09-01', 'end_date' => '2026-09-30'],
            ]], 'gsc' => ['periods' => []]];
        $text = (new \App\Services\Bale\Management\BaleManagerDailyDigest())->text([
            'observed_at' => '2026-10-31T08:55:00Z', 'sections' => $sections,
        ]);
        $translationReady = true;
        foreach (['fa', 'en', 'tr'] as $locale) {
            $labels = require BF_APP.'/lang/'.$locale.'/bale-reporting.php';
            $translationReady = $translationReady && is_array($labels)
                && is_string($labels['recent_30d'] ?? null) && $labels['recent_30d'] !== ''
                && is_string($labels['previous_30d'] ?? null) && $labels['previous_30d'] !== '';
        }
        $checks = [
            'jalali_header' => str_contains($text, '1405/08/09 12:25 تهران'),
            'jalali_source_day' => str_contains($text, 'دیروز منبع: 11 کاربر یکتا (1405/08/08)'),
            'user_units' => str_contains($text, '2 کاربر') && str_contains($text, '500 کاربر'),
            'service_units' => str_contains($text, '3 سرویس') && str_contains($text, '50 سرویس'),
            'recent_users' => str_contains($text, 'یک ماه اخیر (۳۰ روز): 500 کاربر یکتا'),
            'previous_users' => str_contains($text, 'ماه پیش از آن (۳۰ روز): 580 کاربر یکتا'),
            'comparison_direction_and_percentage' => str_contains($text, 'کاهش: 80 کاربر یکتا کمتر (13.8٪ افت)'),
            'independent_not_cumulative' => ! str_contains($text, '1,080 کاربر یکتا'),
            'source_period_translations' => $translationReady,
            'section_spacing' => str_contains($text, "\n\n📊 *مشتری و تحویل* \n"),
            'unknown_not_zero' => str_contains($text, 'منبع اکنون قابل خواندن نیست.'),
            'bounded_text' => mb_strlen($text) <= 3500,
            'no_application_bootstrap' => ! in_array(BF_APP.'/bootstrap/app.php', get_included_files(), true),
        ];
        if (in_array(false, $checks, true)) { throw new RuntimeException('synthetic_fixture_failed'); }
        $fixture = ['checks' => $checks, 'report_sha256' => hash('sha256', $text), 'synthetic_only' => true];
    }
    $cached = [];
    foreach (BF_TARGETS as $target) { $cached[$target] = opcache_is_script_cached(BF_APP.'/'.$target); }
    echo json_encode(['release' => BF_RELEASE, 'action' => $action, 'status' => 'ok', 'php' => PHP_VERSION,
        'sapi' => PHP_SAPI, 'opcache' => ['enable' => (string) ini_get('opcache.enable'),
            'validate_timestamps' => (string) ini_get('opcache.validate_timestamps'),
            'revalidate_freq' => (string) ini_get('opcache.revalidate_freq')],
        'cached_before' => $before, 'cached_after' => $cached, 'invalidated' => $invalidated,
        'fixture' => $fixture, 'framework_bootstrapped' => false, 'external_calls' => 0,
        'messages_sent' => 0], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    http_response_code(503);
    echo json_encode(['release' => BF_RELEASE, 'action' => $action, 'status' => 'stopped',
        'reason' => $error instanceof RuntimeException && preg_match('/^[a-z_]+$/D', $error->getMessage())
            ? $error->getMessage() : 'inspect_private_activation_status'], JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    foreach (array_reverse($locks) as $lock) { flock($lock, LOCK_UN); fclose($lock); }
}
