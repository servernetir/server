<?php
/** Private CLI-only caller. Never schedule or expose over HTTP. */
declare(strict_types=1);

use App\Models\Setting;
use App\Models\User;
use App\Services\Bale\Admin\AdminBaleGate;
use App\Services\Bale\BaleSender;
use App\Services\Bale\Management\BaleManagerDailyDigest;
use App\Services\Bale\Management\ProviderBalanceSnapshot;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

const BM_SOURCE = '/home/servernetcloud/bale-manager-tests-20261031';
const BM_APP = '/home/servernetcloud/servernet_app';
const BM_PACKAGE = BM_SOURCE.'/infra/hermes/bale-manager-20261031';
const BM_RECEIPT = '/home/servernetcloud/deploy-backups/bale-manager-20261031/native-receipt.json';
const BM_FLAG = 'bale_manager_policy_v2_enabled';
const BM_COLUMNS = ['evidence_path', 'evidence_name', 'evidence_mime', 'evidence_sha256', 'evidence_size'];

if (PHP_SAPI !== 'cli' || getenv('HOME') !== '/home/servernetcloud'
    || (function_exists('posix_getuid') && posix_getuid() === 0)
    || realpath(__FILE__) !== BM_PACKAGE.'/native-release.php') { exit(1); }
umask(0077);
$mode = $argv[1] ?? '';
if (count($argv) !== 2 || ! in_array($mode, ['deploy', 'schema', 'enable', 'accept', 'rollback', 'observe-balances'], true)) { exit(1); }
$deadline = microtime(true) + 220;

function bmPolicy(): array
{
    $row = Setting::query()->where('key', BM_FLAG)->first();
    return ['exists' => $row !== null, 'value' => $row?->value];
}
function bmOff(): void
{
    if (bmPolicy()['value'] === '1') { throw new RuntimeException('policy_must_be_off'); }
}
function bmAudit(?array $data = null): array
{
    foreach (['/home/servernetcloud/deploy-backups', dirname(BM_RECEIPT)] as $dir) {
        if (is_link($dir) || ! is_dir($dir) || (fileperms($dir) & 0077) !== 0) { throw new RuntimeException('private_receipt_directory_invalid'); }
    }
    if (is_link(BM_RECEIPT)) { throw new RuntimeException('private_receipt_invalid'); }
    if ($data === null) {
        if (! is_file(BM_RECEIPT)) { return []; }
        $read = json_decode((string) file_get_contents(BM_RECEIPT), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($read) || ($read['release'] ?? '') !== 'bale-manager-20261031'
            || ! is_array($read['prior_policy'] ?? null) || ($read['prior_policy']['value'] ?? null) === '1') {
            throw new RuntimeException('private_receipt_invalid');
        }
        return $read;
    }
    $stage = tempnam(dirname(BM_RECEIPT), '.native-');
    if (! $stage) { throw new RuntimeException('private_receipt_write_failed'); }
    try {
        chmod($stage, 0600);
        $bytes = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
        if (file_put_contents($stage, $bytes) !== strlen($bytes) || ! rename($stage, BM_RECEIPT)) { throw new RuntimeException('private_receipt_write_failed'); }
    } finally { if (is_file($stage)) { unlink($stage); } }
    return $data;
}
function bmProcess(array $command): void
{
    global $deadline;
    $remaining = (int) floor($deadline - microtime(true));
    if ($remaining < 5) { throw new RuntimeException('release_time_budget_exhausted'); }
    $process = new Process($command, BM_SOURCE, ['BALE_MANAGER_LOCKS_HELD' => '1'], null, $remaining);
    $process->run();
    if (! $process->isSuccessful()) {
        $failure = ['program' => basename($command[1] ?? ''), 'phase' => $command[2] ?? '',
            'exit_code' => $process->getExitCode(), 'stdout' => mb_substr($process->getOutput(), 0, 8000),
            'stderr' => mb_substr($process->getErrorOutput(), 0, 2000), 'at' => gmdate('c')];
        $path = BM_SOURCE.'/bale-release-failure.json';
        if (! is_link($path)) { file_put_contents($path, json_encode($failure, JSON_THROW_ON_ERROR), LOCK_EX); chmod($path, 0600); }
        throw new RuntimeException('release_subprocess_stopped');
    }
    // Never echo subprocess output: failure details and schema backups are private.
}
function bmFiles(string $phase): void
{
    bmProcess(['/usr/bin/python3', BM_PACKAGE.'/deploy.py', $phase, BM_SOURCE, BM_PACKAGE.'/release-manifest.json']);
}
function bmSchema(): bool
{
    return Schema::hasTable('bank_transfer_receipts') && Schema::hasColumns('bank_transfer_receipts', BM_COLUMNS);
}
function bmOwner(): array
{
    // Direct reads avoid Gate::binding()'s mutable malformed-binding recovery.
    $enabled = Setting::query()->where('key', AdminBaleGate::KEY_ENABLED)->value('value') === '1';
    $raw = Setting::query()->where('key', AdminBaleGate::KEY_BIND)->value('value');
    try { $bind = is_string($raw) ? json_decode(Crypt::decryptString($raw), true, 16, JSON_THROW_ON_ERROR) : null; }
    catch (Throwable) { $bind = null; }
    $valid = $enabled && is_array($bind) && ctype_digit((string) ($bind['user_id'] ?? ''))
        && preg_match('/^-?[0-9]+$/D', (string) ($bind['chat_id'] ?? '')) === 1
        && User::find((int) $bind['user_id'])?->isAdmin();
    return ['owner_ready' => (bool) $valid, 'sender_ready' => app(BaleSender::class)->enabled()];
}
function bmPreview(): array
{
    $digest = app(BaleManagerDailyDigest::class);
    $snapshot = $digest->snapshot();
    $text = $digest->text($snapshot);
    $sources = [];
    foreach ($snapshot['sections'] as $topic => $section) { $sources[$topic] = ($section['available'] ?? false) === true; }
    return ['report_sha256' => hash('sha256', $text), 'report_characters' => mb_strlen($text),
        'section_available' => $sources, 'external_messages_sent' => 0, 'provider_refreshes' => 0, 'model_calls' => 0];
}

$locks = []; $baleEvent = null; $mutexHeld = false;
try {
    require BM_APP.'/vendor/autoload.php';
    $app = require BM_APP.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    foreach (['hermes:support-review', 'hermes:operations-review', 'hermes:business-review'] as $key) {
        $lock = Cache::lock($key, 2700);
        if (! $lock->get()) { throw new RuntimeException('native_worker_busy'); }
        $locks[] = $lock;
    }
    $events = app(Schedule::class)->events();
    $matches = array_values(array_filter($events, fn ($event) => preg_match('/(?:^|\s|[\x27\x22])bale:work(?:$|\s|[\x27\x22])/D', (string) ($event->command ?? '')) === 1));
    if (count($matches) !== 1 || ! $matches[0]->withoutOverlapping || $matches[0]->expiresAt !== 5
        || ! ($matches[0]->mutex instanceof CacheEventMutex)) { throw new RuntimeException('bale_worker_schedule_changed'); }
    $baleEvent = $matches[0];
    if (! $baleEvent->mutex->create($baleEvent)) { throw new RuntimeException('bale_worker_busy'); }
    $mutexHeld = true;

    if ($mode === 'deploy') {
        bmOff();
        $prior = bmPolicy();
        bmFiles('prepare');
        $audit = bmAudit();
        if ($audit === []) { $audit = ['release' => 'bale-manager-20261031', 'prior_policy' => $prior, 'actions' => []]; }
        $audit['actions'][] = ['action' => 'deploy_started', 'at' => gmdate('c')];
        bmAudit($audit); // Capture exact prior absent/off value before installation.
        bmFiles('apply'); bmFiles('verify');
        $result = ['status' => 'files_verified', 'policy_enabled' => false];
    } else {
        $audit = bmAudit();
        if ($audit === []) { throw new RuntimeException('prior_release_receipt_required'); }
        if ($mode === 'rollback') {
            Setting::put(BM_FLAG, '0'); // Keep the new behavior off even if file rollback stops.
            bmFiles('rollback');
            if ($audit['prior_policy']['exists']) { Setting::put(BM_FLAG, $audit['prior_policy']['value']); }
            else {
                Setting::query()->where('key', BM_FLAG)->delete();
                Cache::forget('settings.all'); app()->forgetInstance('settings.request-memo');
            }
            if (bmPolicy() !== $audit['prior_policy']) { throw new RuntimeException('prior_policy_restore_unverified'); }
            $result = ['status' => 'files_rolled_back', 'policy_restored' => true, 'schema_retained' => true];
        } else {
            bmFiles('verify');
            if ($mode === 'schema') {
                bmOff();
                bmProcess(['/opt/cpanel/ea-php84/root/usr/bin/php', BM_PACKAGE.'/schema.php']);
                if (! bmSchema()) { throw new RuntimeException('receipt_schema_incomplete'); }
                bmOff();
                $result = ['status' => 'schema_verified', 'evidence_columns' => count(BM_COLUMNS), 'policy_enabled' => false];
            } elseif ($mode === 'enable') {
                $owner = bmOwner();
                if (! bmSchema()) { throw new RuntimeException('receipt_schema_incomplete'); }
                if (! $owner['owner_ready'] || ! $owner['sender_ready']) { throw new RuntimeException('owner_or_sender_unavailable'); }
                $preview = bmPreview();
                if (! ($preview['section_available']['stats'] ?? false) || ! ($preview['section_available']['payments'] ?? false)) { throw new RuntimeException('essential_report_source_unavailable'); }
                $enabledHere = bmPolicy()['value'] !== '1';
                Setting::put(BM_FLAG, '1');
                if (bmPolicy()['value'] !== '1') { throw new RuntimeException('policy_write_unverified'); }
                $result = ['status' => 'enabled', 'policy_enabled' => true] + $owner + $preview;
            } elseif ($mode === 'observe-balances') {
                $rows = app(ProviderBalanceSnapshot::class)->snapshot(true);
                $statuses = [];
                foreach ($rows as $provider => $row) { $statuses[$provider] = ['status' => $row['status'] ?? 'unknown', 'reason' => $row['reason'] ?? null]; }
                $result = ['status' => 'balances_observed', 'providers' => $statuses, 'external_messages_sent' => 0];
            } else {
                $schedule = [];
                foreach ($events as $event) {
                    foreach (['bale:manager-digest', 'system:health'] as $name) {
                        if (str_contains((string) ($event->command ?? ''), $name)) { $schedule[$name][] = ['expression' => $event->expression, 'timezone' => (string) $event->timezone]; }
                    }
                }
                $result = ['status' => 'read_only_accepted', 'policy_enabled' => bmPolicy()['value'] === '1',
                    'evidence_schema_ready' => bmSchema(), 'schedule_configured' => $schedule, 'natural_schedule_run_observed' => false] + bmOwner() + bmPreview();
            }
        }
    }
    $audit['actions'][] = ['action' => $mode, 'at' => gmdate('c'), 'result' => $result];
    bmAudit($audit);
    echo json_encode(['phase' => $mode] + $result, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    if (($enabledHere ?? false) === true) { try { Setting::put(BM_FLAG, '0'); } catch (Throwable) {} }
    echo json_encode(['phase' => $mode, 'status' => 'stopped', 'exception_class' => $error::class,
        'reason' => $error instanceof RuntimeException && preg_match('/^[a-z_]+$/D', $error->getMessage())
            ? $error->getMessage() : 'inspect_private_release_status'], JSON_THROW_ON_ERROR).PHP_EOL;
    $failed = true;
} finally {
    $releaseFailed = false;
    if ($mutexHeld && $baleEvent !== null) { try { $baleEvent->mutex->forget($baleEvent); } catch (Throwable) { $releaseFailed = true; } }
    foreach (array_reverse($locks) as $lock) { try { $lock->release(); } catch (Throwable) { $releaseFailed = true; } }
    if ($releaseFailed) { echo json_encode(['phase' => $mode, 'status' => 'stopped', 'reason' => 'native_lock_release_unverified']).PHP_EOL; $failed = true; }
}
exit(isset($failed) ? 1 : 0);
