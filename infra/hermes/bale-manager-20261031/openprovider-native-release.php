<?php
/** Private CLI caller for the one-file read-only adapter layer; no policy/schema operations. */
declare(strict_types=1);
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
const OP_SOURCE = '/home/servernetcloud/bale-manager-tests-20261031';
const OP_PACKAGE = OP_SOURCE.'/infra/hermes/bale-manager-20261031';
const OP_RECEIPT = '/home/servernetcloud/deploy-backups/bale-manager-openprovider-20261031/native-receipt.json';
if (PHP_SAPI !== 'cli' || getenv('HOME') !== '/home/servernetcloud' || (function_exists('posix_getuid') && posix_getuid() === 0)
    || realpath(__FILE__) !== OP_PACKAGE.'/openprovider-native-release.php') { exit(1); }
umask(0077);
$mode = $argv[1] ?? '';
if (count($argv) !== 2 || ! in_array($mode, ['deploy', 'accept', 'rollback'], true)) { exit(1); }
$locks = []; $event = null; $mutexHeld = false; $deadline = microtime(true) + 220;
try {
    require '/home/servernetcloud/servernet_app/vendor/autoload.php';
    $app = require '/home/servernetcloud/servernet_app/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
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
    $results = [];
    foreach ($mode === 'deploy' ? ['prepare', 'apply', 'verify', 'accept'] : [$mode] as $phase) {
        $remaining = (int) floor($deadline - microtime(true));
        if ($remaining < 5) { throw new RuntimeException('release_time_budget_exhausted'); }
        $process = new Process(['/usr/bin/python3', OP_PACKAGE.'/openprovider-followup.py', $phase, OP_SOURCE], OP_SOURCE,
            ['BALE_MANAGER_LOCKS_HELD' => '1', 'PYTHONDONTWRITEBYTECODE' => '1'], null, $remaining);
        $process->run();
        if (! $process->isSuccessful()) { $subprocessFailure = json_decode(trim($process->getOutput()), true); throw new RuntimeException('followup_subprocess_stopped'); }
        $raw = json_decode(trim($process->getOutput()), true, 16, JSON_THROW_ON_ERROR);
        $results[] = ['phase' => $phase, 'files' => (int) ($raw['files'] ?? 0), 'state' => (string) ($raw['phase'] ?? ''),
            'composed_files_verified' => (int) ($raw['composed_files_verified'] ?? 0)];
    }
    foreach (['/home/servernetcloud/deploy-backups', dirname(OP_RECEIPT)] as $dir) {
        if (is_link($dir) || ! is_dir($dir) || (fileperms($dir) & 0077) !== 0) { throw new RuntimeException('private_receipt_directory_invalid'); }
    }
    if (is_link(OP_RECEIPT)) { throw new RuntimeException('private_receipt_invalid'); }
    $audit = is_file(OP_RECEIPT) ? json_decode((string) file_get_contents(OP_RECEIPT), true, 32, JSON_THROW_ON_ERROR)
        : ['release' => 'bale-manager-openprovider-20261031', 'actions' => []];
    if (! is_array($audit) || ($audit['release'] ?? '') !== 'bale-manager-openprovider-20261031') { throw new RuntimeException('private_receipt_invalid'); }
    $audit['actions'][] = ['mode' => $mode, 'at' => gmdate('c'), 'results' => $results, 'external_messages_sent' => 0, 'provider_calls' => 0];
    $stage = tempnam(dirname(OP_RECEIPT), '.native-');
    if (! $stage) { throw new RuntimeException('private_receipt_write_failed'); }
    try {
        chmod($stage, 0600); $bytes = json_encode($audit, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
        if (file_put_contents($stage, $bytes) !== strlen($bytes) || ! rename($stage, OP_RECEIPT)) { throw new RuntimeException('private_receipt_write_failed'); }
    } finally { if (is_file($stage)) { unlink($stage); } }
    echo json_encode(['mode' => $mode, 'status' => 'completed', 'results' => $results, 'provider_calls' => 0, 'external_messages_sent' => 0], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    try {
        $failurePath = OP_SOURCE.'/bale-openprovider-followup-failure.json';
        $reason = is_string($subprocessFailure['reason'] ?? null) ? mb_substr($subprocessFailure['reason'], 0, 300) : 'inspect_private_followup_status';
        $failure = ['mode' => $mode, 'at' => gmdate('c'), 'exception_class' => $error::class, 'subprocess_reason' => $reason];
        if (! is_link($failurePath)) {
            $stage = tempnam(OP_SOURCE, '.followup-error-');
            if ($stage) { chmod($stage, 0600); file_put_contents($stage, json_encode($failure, JSON_THROW_ON_ERROR)); if (! rename($stage, $failurePath)) { unlink($stage); } }
        }
    } catch (Throwable) {}
    echo json_encode(['mode' => $mode, 'status' => 'stopped', 'exception_class' => $error::class,
        'reason' => $error instanceof RuntimeException && preg_match('/^[a-z_]+$/D', $error->getMessage()) ? $error->getMessage() : 'inspect_private_followup_status'], JSON_THROW_ON_ERROR).PHP_EOL;
    $failed = true;
} finally {
    $releaseFailed = false;
    if ($mutexHeld && $event !== null) { try { $event->mutex->forget($event); } catch (Throwable) { $releaseFailed = true; } }
    foreach (array_reverse($locks) as $lock) { try { $lock->release(); } catch (Throwable) { $releaseFailed = true; } }
    if ($releaseFailed) { echo json_encode(['mode' => $mode, 'status' => 'stopped', 'reason' => 'native_lock_release_unverified']).PHP_EOL; $failed = true; }
}
exit(isset($failed) ? 1 : 0);
