<?php
/** Private CLI caller for this four-file report release. Never schedule or serve over HTTP. */
declare(strict_types=1);

use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

const BR_SOURCE = '/home/servernetcloud/bale-report-layout-tests-20261031';
const BR_APP = '/home/servernetcloud/servernet_app';
const BR_PACKAGE = BR_SOURCE.'/scripts/bale-report-layout-20261031';
const BR_BASE_COMMIT = '0861aab6d5452db065a7e1cbaaa3c9d3cd0bddeb';
const BR_TARGETS = [
    'app/Services/Bale/Management/ManagerBusinessCalendarSnapshot.php',
    'app/Services/Bale/BaleSender.php',
    'app/Services/Bale/Management/BaleManagerDailyDigest.php',
    'app/Services/Bale/Admin/AdminBaleRouter.php',
];
const BR_FILE_COUNT = 4;

if (PHP_SAPI !== 'cli' || getenv('HOME') !== '/home/servernetcloud'
    || (function_exists('posix_getuid') && posix_getuid() === 0)
    || realpath(__FILE__) !== BR_PACKAGE.'/native-release.php') { exit(1); }
umask(0077);
$phase = $argv[1] ?? '';
if (count($argv) !== 2 || ! in_array($phase, ['prepare', 'apply', 'verify', 'rollback'], true)) { exit(1); }

$locks = []; $event = null; $mutexHeld = false;
$deadline = microtime(true) + 220;
try {
    $manifestPath = BR_PACKAGE.'/release-manifest.json';
    if (is_link($manifestPath) || ! is_file($manifestPath) || filesize($manifestPath) > 65536) {
        throw new RuntimeException('release_manifest_invalid');
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 16, JSON_THROW_ON_ERROR);
    if (! is_array($manifest) || ($manifest['release'] ?? '') !== 'bale-report-layout-20261031'
        || ($manifest['base_commit'] ?? '') !== BR_BASE_COMMIT
        || ! is_array($manifest['files'] ?? null) || count($manifest['files']) !== BR_FILE_COUNT
        || array_column($manifest['files'], 'target') !== BR_TARGETS) {
        throw new RuntimeException('release_manifest_scope_invalid');
    }
    require BR_APP.'/vendor/autoload.php';
    $app = require BR_APP.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    // Same native-worker lock contract as the pinned installer's original caller.
    foreach (['hermes:support-review', 'hermes:operations-review', 'hermes:business-review'] as $key) {
        $lock = Cache::lock($key, 2700);
        if (! $lock->get()) { throw new RuntimeException('native_worker_busy'); }
        $locks[] = $lock;
    }
    $matches = array_values(array_filter(app(Schedule::class)->events(), fn ($item) => preg_match('/(?:^|\s|[\x27\x22])bale:work(?:$|\s|[\x27\x22])/D', (string) ($item->command ?? '')) === 1));
    if (count($matches) !== 1 || ! $matches[0]->withoutOverlapping || $matches[0]->expiresAt !== 5
        || ! ($matches[0]->mutex instanceof CacheEventMutex)) { throw new RuntimeException('bale_worker_schedule_changed'); }
    $event = $matches[0];
    if (! $event->mutex->create($event)) { throw new RuntimeException('bale_worker_busy'); }
    $mutexHeld = true;

    $remaining = (int) floor($deadline - microtime(true));
    if ($remaining < 5) { throw new RuntimeException('release_time_budget_exhausted'); }
    $process = new Process(['/usr/bin/python3', BR_PACKAGE.'/deploy.py', $phase, BR_SOURCE], BR_SOURCE,
        ['BALE_MANAGER_LOCKS_HELD' => '1', 'PYTHONDONTWRITEBYTECODE' => '1'], null, $remaining);
    $process->run();
    if (! $process->isSuccessful()) { throw new RuntimeException('release_subprocess_stopped'); }
    $result = json_decode(trim($process->getOutput()), true, 16, JSON_THROW_ON_ERROR);
    $expectedState = ['prepare' => 'prepared', 'apply' => 'verified', 'verify' => 'verified', 'rollback' => 'rolled_back'][$phase];
    if (! is_array($result) || ($result['release'] ?? '') !== 'bale-report-layout-20261031'
        || ($result['phase'] ?? '') !== $expectedState || ($result['files'] ?? null) !== BR_FILE_COUNT
        || ($result['messages_sent'] ?? null) !== 0 || ($result['schema_or_settings_changed'] ?? null) !== false
        || ($result['framework_bootstrapped'] ?? null) !== false
        || ($result['cron_unchanged'] ?? null) !== ($phase === 'rollback' ? null : true)) {
        throw new RuntimeException('release_result_invalid');
    }
    echo json_encode(['release' => 'bale-report-layout-20261031', 'phase' => $phase, 'state' => $expectedState,
        'files' => BR_FILE_COUNT, 'messages_sent' => 0, 'provider_calls' => 0, 'schema_or_settings_changed' => false], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['release' => 'bale-report-layout-20261031', 'phase' => $phase, 'status' => 'stopped',
        'exception_class' => $error::class, 'reason' => $error instanceof RuntimeException && preg_match('/^[a-z_]+$/D', $error->getMessage())
            ? $error->getMessage() : 'inspect_private_release_status'], JSON_THROW_ON_ERROR).PHP_EOL;
    $failed = true;
} finally {
    $releaseFailed = false;
    if ($mutexHeld && $event !== null) { try { $event->mutex->forget($event); } catch (Throwable) { $releaseFailed = true; } }
    foreach (array_reverse($locks) as $lock) {
        try { if (! $lock->release()) { $releaseFailed = true; } } catch (Throwable) { $releaseFailed = true; }
    }
    if ($releaseFailed) {
        echo json_encode(['release' => 'bale-report-layout-20261031', 'phase' => $phase, 'status' => 'stopped', 'reason' => 'native_lock_release_unverified'], JSON_THROW_ON_ERROR).PHP_EOL;
        $failed = true;
    }
}
exit(isset($failed) ? 1 : 0);
