<?php
declare(strict_types=1);

// Exactly one PHP source. No Laravel bootstrap, database, provider, wallet or cache operations.
final class MeterReleaseRefusal extends RuntimeException {}
const METER_ROOT = '/home/servernetcloud/servernet_app';
const METER_PHP = '/opt/cpanel/ea-php84/root/usr/bin/php';
const METER_TARGET = 'app/Services/Finance/ArvanTrafficMeter.php';
const METER_BEFORE = '464b2db7f0bda854d6473b06e46da630cdc325c82bb22894088af8bcfaba4f3f';
const METER_AFTER = '56c3972ba0896e6bc14c4d443246c1eadaef6bccb63245e0cd3b274e292d2ca8';
const METER_COMMIT = 'b0ff56359c3ab8786ec80220a25279c69740efe9';

function deny(string $code): never { throw new MeterReleaseRefusal($code); }
function digest(string $bytes): string { return hash('sha256', $bytes); }
function readOwned(string $path, int $owner, ?int $mode = null): array {
    clearstatcache(true, $path);
    if (is_link($path) || !is_file($path) || realpath($path) !== $path) { deny('file_missing_or_symlink'); }
    $a = lstat($path);
    if (!$a || $a['uid'] !== $owner || ($a['mode'] & 0022) || $a['nlink'] !== 1
        || ($mode !== null && ($a['mode'] & 0777) !== $mode)) { deny('file_owner_or_mode_invalid'); }
    $bytes = file_get_contents($path);
    clearstatcache(true, $path); $b = lstat($path);
    if (!is_string($bytes) || !$b) { deny('file_read_failed'); }
    foreach (['dev','ino','mode','uid','gid','nlink','size','mtime','ctime'] as $k) {
        if ($a[$k] !== $b[$k]) { deny('file_changed_during_read'); }
    }
    return ['sha256' => digest($bytes), 'bytes' => $bytes, 'mode' => $a['mode'] & 0777,
        'uid' => $a['uid'], 'gid' => $a['gid'], 'dev' => $a['dev'], 'ino' => $a['ino']];
}
function jsonOwned(string $path, int $owner): array {
    $d = json_decode(readOwned($path, $owner, 0600)['bytes'], true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($d) || array_is_list($d)) { deny('json_shape_invalid'); }
    return $d;
}
function writeFresh(string $path, string $bytes, int $mode = 0600): void {
    if (realpath(dirname($path)) !== dirname($path) || is_link($path)) { deny('unsafe_private_path'); }
    $h = fopen($path, 'xb');
    if (!$h) { deny('private_file_exists_or_unwritable'); }
    try {
        $n = 0;
        while ($n < strlen($bytes)) {
            $w = fwrite($h, substr($bytes, $n));
            if ($w === false || $w === 0) { deny('private_short_write'); }
            $n += $w;
        }
        if (!chmod($path, $mode) || !fflush($h) || !fsync($h)) { deny('private_file_flush_failed'); }
    } finally { fclose($h); }
    if (hash_file('sha256', $path) !== digest($bytes)) { deny('private_file_hash_mismatch'); }
}
function emit(array $value): void { echo json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n"; }
function publishReceipt(array $receipt, int $owner, array &$publication): void {
    $path = __DIR__.'/receipt.json';
    $bytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    $tmp = __DIR__.'/receipt-'.bin2hex(random_bytes(8)).'.tmp';
    writeFresh($tmp, $bytes);
    $ready = readOwned($tmp, $owner, 0600);
    // Atomic no-overwrite publication: link fails if any receipt already exists.
    if (!@link($tmp, $path)) { deny('receipt_publication_failed'); }
    $publication = ['sha256' => $ready['sha256'], 'dev' => $ready['dev'], 'ino' => $ready['ino'], 'tmp' => $tmp];
    if (!unlink($tmp)) { deny('receipt_temp_unlink_failed'); }
    // A verification failure is handled inside the source rollback scope.
    if (readOwned($path, $owner, 0600)['sha256'] !== digest($bytes)) { deny('published_receipt_changed'); }
}
function withdrawOwnReceipt(array $publication, int $owner): bool {
    $path = __DIR__.'/receipt.json';
    if ($publication === []) { return !file_exists($path) && !is_link($path); }
    clearstatcache(true, $path); $st = @lstat($path);
    if (!$st && !file_exists($path) && !is_link($path)) { return true; }
    if (!$st || is_link($path) || $st['uid'] !== $owner || ($st['mode'] & 0777) !== 0600
        || $st['dev'] !== $publication['dev'] || $st['ino'] !== $publication['ino']) { return false; }
    $bytes = file_get_contents($path);
    if (!is_string($bytes) || digest($bytes) !== $publication['sha256']) { return false; }
    // Only this invocation's exact success receipt can be withdrawn before rollback.
    if (!unlink($path)) { return false; }
    $tmp = $publication['tmp'];
    if (is_file($tmp) && !is_link($tmp) && fileinode($tmp) === $publication['ino']) { unlink($tmp); }
    return true;
}
function lintOnly(string $path): void {
    if (!function_exists('proc_open')) { deny('native_lint_unavailable'); }
    $p = proc_open([METER_PHP, '-l', $path], [0 => ['file','/dev/null','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($p)) { deny('native_lint_failed'); }
    stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($p) !== 0) { deny('native_lint_failed'); }
}
function checkLock($lock, int $owner): void {
    $path = METER_ROOT.'/storage/app/private/arvan-traffic-meter/run.lock';
    clearstatcache(true, $path); $a = lstat($path); $b = fstat($lock);
    if (!$a || !$b || is_link($path) || realpath($path) !== $path || $a['uid'] !== $owner
        || ($a['mode'] & 0022) || $a['nlink'] !== 1 || ($a['mode'] & 0170000) !== 0100000
        || $a['dev'] !== $b['dev'] || $a['ino'] !== $b['ino']) { deny('meter_lock_identity_changed'); }
}
function checkReleaseLock($lock, int $owner): void {
    $path = METER_ROOT.'/storage/framework/arvan-meter-source-release.lock';
    clearstatcache(true, $path); $a = lstat($path); $b = fstat($lock);
    if (!$a || !$b || is_link($path) || realpath($path) !== $path || $a['uid'] !== $owner
        || ($a['mode'] & 0777) !== 0600 || $a['nlink'] !== 1 || ($a['mode'] & 0170000) !== 0100000
        || $a['dev'] !== $b['dev'] || $a['ino'] !== $b['ino']) { deny('release_lock_identity_changed'); }
}
function planScope(array $plan): void {
    if (($plan['schema'] ?? null) !== 1 || ($plan['path'] ?? null) !== METER_TARGET
        || ($plan['before'] ?? null) !== METER_BEFORE || ($plan['after'] ?? null) !== METER_AFTER
        || ($plan['commit'] ?? null) !== METER_COMMIT || !is_int($plan['mode'] ?? null)
        || !is_int($plan['uid'] ?? null) || !is_int($plan['gid'] ?? null)) { deny('plan_scope_changed'); }
}
function sameSource(array $plan, int $owner, string $which): array {
    $r = readOwned(METER_ROOT.'/'.METER_TARGET, $owner);
    if ($r['sha256'] !== $plan[$which] || $r['mode'] !== $plan['mode']
        || $r['uid'] !== $plan['uid'] || $r['gid'] !== $plan['gid']) { deny('source_precondition_changed'); }
    return $r;
}
function prepareRename(string $staged, array $plan, int $owner, string $to): string {
    $s = readOwned($staged, $owner, 0600);
    if ($s['sha256'] !== $to) { deny('candidate_or_backup_changed'); }
    $tmp = __DIR__.'/rename-'.bin2hex(random_bytes(8));
    writeFresh($tmp, $s['bytes'], $plan['mode']);
    if (filegroup($tmp) !== $plan['gid'] && !chgrp($tmp, $plan['gid'])) { deny('group_preservation_failed'); }
    if (stat($tmp)['dev'] !== stat(dirname(METER_ROOT.'/'.METER_TARGET))['dev']) { deny('atomic_rename_crosses_filesystem'); }
    return $tmp;
}
function replacePrepared(string $tmp, array $plan, int $owner, string $from, string $to, $lock, $releaseLock, ?callable $beforeRename = null): void {
    checkLock($lock, $owner); checkReleaseLock($releaseLock, $owner);
    $r = readOwned(METER_ROOT.'/'.METER_TARGET, $owner);
    if ($r['sha256'] !== $from || $r['mode'] !== $plan['mode'] || $r['gid'] !== $plan['gid']) { deny('source_precondition_changed'); }
    $ready = readOwned($tmp, $owner, $plan['mode']);
    if ($ready['sha256'] !== $to || $ready['gid'] !== $plan['gid']) { deny('rename_candidate_changed'); }
    if ($beforeRename !== null) { $beforeRename(); }
    if (!rename($tmp, METER_ROOT.'/'.METER_TARGET)) { deny('atomic_rename_failed'); }
    $r = readOwned(METER_ROOT.'/'.METER_TARGET, $owner);
    if ($r['sha256'] !== $to || $r['mode'] !== $plan['mode'] || $r['gid'] !== $plan['gid']) { deny('installed_hash_or_metadata_mismatch'); }
}
function runRelease(array $args): int {
    umask(0077);
    if (PHP_SAPI !== 'cli' || realpath(getcwd()) !== METER_ROOT || version_compare(PHP_VERSION, '8.4.0', '<')
        || realpath(PHP_BINARY) !== realpath(METER_PHP)) { deny('wrong_runtime_or_application_root'); }
    $owner = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    $dir = __DIR__;
    if (!preg_match('~^'.preg_quote(METER_ROOT, '~').'/storage/app/private/arvan-meter-release-[A-Za-z0-9_-]{6,80}$~D', $dir)
        || realpath($dir) !== $dir || is_link($dir) || fileowner($dir) !== $owner
        || (fileperms($dir) & 0777) !== 0700) { deny('unsafe_private_release_directory'); }
    readOwned(__FILE__, $owner, 0600);
    $candidate = readOwned($dir.'/candidate.php', $owner, 0600);
    if ($candidate['sha256'] !== METER_AFTER) { deny('candidate_changed'); }
    $mode = $args[1] ?? '--inspect';
    if (!in_array($mode, ['--inspect','--plan','--apply'], true) || count($args) > ($mode === '--apply' ? 3 : 2)) { deny('invalid_arguments'); }
    // Fixed source-release mutex is shared across all private package directories.
    $releasePath = METER_ROOT.'/storage/framework/arvan-meter-source-release.lock';
    if (is_link($releasePath) || (file_exists($releasePath) && (!is_file($releasePath)
        || fileowner($releasePath) !== $owner || (fileperms($releasePath) & 0777) !== 0600))) { deny('unsafe_release_lock'); }
    $releaseLock = fopen($releasePath, 'c');
    if (!$releaseLock) { deny('release_lock_unavailable'); }
    try {
    checkReleaseLock($releaseLock, $owner);
    if (!flock($releaseLock, LOCK_EX | LOCK_NB)) { deny('release_busy'); }
    checkReleaseLock($releaseLock, $owner);
    // Open only the existing meter lock. Never create/replace it or start the meter.
    $lockPath = METER_ROOT.'/storage/app/private/arvan-traffic-meter/run.lock';
    if (!is_file($lockPath) || is_link($lockPath) || realpath($lockPath) !== $lockPath) { deny('existing_meter_lock_required'); }
    $lock = fopen($lockPath, 'r+');
    if (!$lock) { deny('meter_lock_unavailable'); }
    try {
        checkLock($lock, $owner); checkReleaseLock($releaseLock, $owner);
        if (!flock($lock, LOCK_EX | LOCK_NB)) { deny('meter_busy'); }
        checkLock($lock, $owner); checkReleaseLock($releaseLock, $owner);
        if ($mode === '--inspect') {
            $r = readOwned(METER_ROOT.'/'.METER_TARGET, $owner);
            emit(['status' => 'read_only_source_inventory', 'utc' => gmdate('c'), 'path' => METER_TARGET,
                'sha256' => $r['sha256'], 'mode' => decoct($r['mode']), 'uid' => $r['uid'], 'gid' => $r['gid'],
                'comparison' => $r['sha256'] === METER_BEFORE ? 'expected_before' : ($r['sha256'] === METER_AFTER ? 'expected_after' : 'drift_requires_review'),
                'candidate_sha256' => METER_AFTER, 'commit' => METER_COMMIT, 'meter_lock_held' => true, 'release_lock_held' => true]);
            return 0;
        }
        if (is_file($dir.'/receipt.json')) {
            $receipt = jsonOwned($dir.'/receipt.json', $owner);
            if (($receipt['path'] ?? '') !== METER_TARGET || ($receipt['commit'] ?? '') !== METER_COMMIT
                || ($receipt['status'] ?? '') !== 'source_installed_activation_pending') { deny('historical_failure_requires_operator'); }
            $plan = jsonOwned($dir.'/plan.json', $owner); planScope($plan);
            $hash = hash_file('sha256', $dir.'/plan.json');
            if (($receipt['plan_sha256'] ?? '') !== $hash || ($mode === '--apply' && ($args[2] ?? '') !== $hash)) { deny('receipt_plan_hash_changed'); }
            sameSource($plan, $owner, 'after');
            emit(['status' => 'receipt_already_exists', 'changed' => false, 'live_after_hash_reverified' => true, 'plan_sha256' => $hash]);
            return 0;
        }
        if ($mode === '--plan') {
            if (is_file($dir.'/plan.json')) {
                $plan = jsonOwned($dir.'/plan.json', $owner); planScope($plan); sameSource($plan, $owner, 'before');
                if (readOwned($dir.'/before.php', $owner, 0600)['sha256'] !== METER_BEFORE) { deny('backup_changed'); }
                emit(['status' => 'existing_plan_revalidated', 'changed' => false, 'plan_sha256' => hash_file('sha256', $dir.'/plan.json')]);
                return 0;
            }
            $r = readOwned(METER_ROOT.'/'.METER_TARGET, $owner);
            if ($r['sha256'] !== METER_BEFORE) { deny('expected_before_hash_required'); }
            lintOnly($dir.'/candidate.php');
            writeFresh($dir.'/before.php', $r['bytes']); lintOnly($dir.'/before.php');
            $plan = ['schema' => 1, 'utc' => gmdate('c'), 'path' => METER_TARGET, 'before' => METER_BEFORE,
                'after' => METER_AFTER, 'commit' => METER_COMMIT, 'mode' => $r['mode'], 'uid' => $r['uid'], 'gid' => $r['gid'],
                'activation' => 'not_performed', 'meter_lock' => 'existing_run_lock_held'];
            sameSource($plan, $owner, 'before'); checkLock($lock, $owner); checkReleaseLock($releaseLock, $owner);
            writeFresh($dir.'/plan.json', json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            emit(['status' => 'prepared_not_installed', 'changed' => false, 'plan_sha256' => hash_file('sha256', $dir.'/plan.json'), 'plan' => $plan]);
            return 0;
        }
        $hash = $args[2] ?? '';
        if (!preg_match('/\A[a-f0-9]{64}\z/', $hash)) { deny('reviewed_plan_hash_required'); }
        $plan = jsonOwned($dir.'/plan.json', $owner); planScope($plan);
        if (hash_file('sha256', $dir.'/plan.json') !== $hash) { deny('reviewed_plan_hash_required'); }
        $receipt = ['schema' => 1, 'utc' => gmdate('c'), 'path' => METER_TARGET, 'commit' => METER_COMMIT,
            'plan_sha256' => $hash, 'activation' => 'not_performed', 'attempted_write' => false, 'rolled_back' => false];
        $publication = [];
        $rollbackPrepared = null;
        try {
            sameSource($plan, $owner, 'before');
            if (readOwned($dir.'/before.php', $owner, 0600)['sha256'] !== METER_BEFORE) { deny('backup_changed'); }
            lintOnly($dir.'/candidate.php'); checkLock($lock, $owner);
            // Allocate/flush both rename files before mutation. Reversal needs no new source-sized write.
            $rollbackPrepared = prepareRename($dir.'/before.php', $plan, $owner, METER_BEFORE);
            $installPrepared = prepareRename($dir.'/candidate.php', $plan, $owner, METER_AFTER);
            replacePrepared($installPrepared, $plan, $owner, METER_BEFORE, METER_AFTER, $lock, $releaseLock,
                function () use (&$receipt): void { $receipt['attempted_write'] = true; });
            // Post-replacement verification is deliberately inside rollback scope.
            sameSource($plan, $owner, 'after'); checkLock($lock, $owner); checkReleaseLock($releaseLock, $owner);
            $receipt['status'] = 'source_installed_activation_pending';
            $receipt['completed_utc'] = gmdate('c');
            publishReceipt($receipt, $owner, $publication);
            // Success requires both the published receipt and the exact live source.
            sameSource($plan, $owner, 'after'); checkLock($lock, $owner); checkReleaseLock($releaseLock, $owner);
        } catch (Throwable $e) {
            $receipt['error_code'] = $e instanceof MeterReleaseRefusal ? $e->getMessage() : get_class($e);
            $receipt['status'] = 'failed_no_source_change';
            $receiptWithdrawn = withdrawOwnReceipt($publication, $owner);
            if (!$receiptWithdrawn) {
                // Preserve an unexpected receipt edit and the installed source for reconciliation.
                $receipt['status'] = 'failed_receipt_reconciliation_requires_operator';
            } elseif ($receipt['attempted_write']) {
                try {
                    $current = readOwned(METER_ROOT.'/'.METER_TARGET, $owner);
                    if ($current['sha256'] !== METER_BEFORE) {
                        if ($current['sha256'] !== METER_AFTER) { deny('concurrent_update_preserved'); }
                        if (!is_string($rollbackPrepared)) { deny('prepared_rollback_missing'); }
                        replacePrepared($rollbackPrepared, $plan, $owner, METER_AFTER, METER_BEFORE, $lock, $releaseLock);
                        $receipt['rolled_back'] = true;
                    }
                    sameSource($plan, $owner, 'before');
                    $receipt['status'] = 'failed_source_rollback_complete';
                } catch (Throwable $rollback) {
                    $receipt['status'] = 'failed_rollback_requires_operator';
                    $receipt['rollback_error'] = $rollback instanceof MeterReleaseRefusal ? $rollback->getMessage() : get_class($rollback);
                }
            }
            $receipt['completed_utc'] = gmdate('c');
            if ($receiptWithdrawn) {
                try { $failurePublication = []; publishReceipt($receipt, $owner, $failurePublication); }
                catch (Throwable $recordingFailure) {
                    $receipt['receipt_recording_error'] = $recordingFailure instanceof MeterReleaseRefusal ? $recordingFailure->getMessage() : get_class($recordingFailure);
                }
            }
        }
        emit($receipt);
        return $receipt['status'] === 'source_installed_activation_pending' ? 0 : 1;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
    } finally { flock($releaseLock, LOCK_UN); fclose($releaseLock); }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { exit(runRelease($argv)); }
    catch (Throwable $e) { emit(['status' => 'refused', 'error_code' => $e instanceof MeterReleaseRefusal ? $e->getMessage() : get_class($e), 'source_state' => 'inspect_required']); exit(1); }
}
