<?php
declare(strict_types=1);

// Six web sources only. No application bootstrap, DB, provider, queue or cache operations.
final class ReleaseRefusal extends RuntimeException {}
const RELEASE_ROOT = '/home/servernetcloud/servernet_app';
const RELEASE_PHP = '/opt/cpanel/ea-php84/root/usr/bin/php';
const PATCHDATA_SHA256 = '59d21b9b2b3d131d7cbcc51d1d20b16798ccfabb1c7459a807a3ee40ff5053ed';
const RELEASE_PATHS = [
    'app/Services/Cloud/ArvanConsoleBroker.php', 'app/Models/CloudInstance.php',
    'lang/fa/ui.php', 'lang/en/ui.php', 'lang/tr/ui.php',
    'resources/views/account/cloud-server.blade.php',
];

function refuse(string $code): never { throw new ReleaseRefusal($code); }
function sha(string $bytes): string { return hash('sha256', $bytes); }
function readJson(string $file): array {
    $value = json_decode((string) file_get_contents($file), true, 128, JSON_THROW_ON_ERROR);
    if (!is_array($value)) { refuse('json_shape_invalid'); }
    return $value;
}
function checkedRead(string $file, int $owner): array {
    clearstatcache(true, $file);
    if (is_link($file) || !is_file($file) || realpath($file) !== $file) { refuse('source_missing_or_symlink'); }
    $before = lstat($file);
    if (!$before || $before['uid'] !== $owner || ($before['mode'] & 0002)) { refuse('source_owner_or_mode_invalid'); }
    $bytes = file_get_contents($file);
    clearstatcache(true, $file);
    $after = lstat($file);
    if (!is_string($bytes) || !$after) { refuse('source_changed_during_read'); }
    foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) {
        if ($before[$key] !== $after[$key]) { refuse('source_changed_during_read'); }
    }
    return ['bytes' => $bytes, 'sha256' => sha($bytes), 'mode' => $before['mode'] & 0777,
        'uid' => $before['uid'], 'gid' => $before['gid'], 'dev' => $before['dev']];
}
function patchSource(string $source, array $hunks): array {
    $crlf = str_contains($source, "\r\n");
    if ($crlf && str_contains(str_replace("\r\n", '', $source), "\n")) { refuse('mixed_newlines_require_review'); }
    $states = [];
    foreach ($hunks as $hunk) {
        if (!is_int($hunk['id'] ?? null) || !is_string($hunk['old'] ?? null) || !is_string($hunk['new'] ?? null)
            || $hunk['old'] === '' || $hunk['new'] === '') { refuse('invalid_patch_hunk'); }
        $old = $crlf ? str_replace("\n", "\r\n", $hunk['old']) : $hunk['old'];
        $new = $crlf ? str_replace("\n", "\r\n", $hunk['new']) : $hunk['new'];
        $oldCount = substr_count($source, $old); $newCount = substr_count($source, $new);
        if ($oldCount === 0 && $newCount === 1) { $states[$hunk['id']] = 'already_applied'; continue; }
        if ($oldCount !== 1 || $newCount !== 0) { refuse('patch_context_not_unique_or_drifted_hunk_'.$hunk['id']); }
        $source = str_replace($old, $new, $source);
        $states[$hunk['id']] = 'pending';
    }
    return ['bytes' => $source, 'hunks' => $states];
}
function anchors(string $source): array {
    $out = [];
    foreach (['configuredSocketPath', 'arvanWindowsConsoleAvailable', 'readyForNotice'] as $name) {
        $out['method_'.$name] = preg_match_all('/\bfunction\s+'.preg_quote($name, '/').'\s*\(/', $source);
    }
    $out['onboarding_markup'] = substr_count($source, 'data-windows-console-setup');
    foreach (['h', 'password', 'rdp', 'note'] as $suffix) {
        $out['translation_'.$suffix] = substr_count($source, "'cs_windows_console_setup_".$suffix."' =>");
    }
    return $out;
}
function writeNew(string $path, string $bytes, int $mode = 0600): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) { refuse('private_stage_directory_failed'); }
    if (realpath($dir) !== $dir || is_link($path)) { refuse('private_stage_symlink'); }
    $h = fopen($path, 'xb');
    if (!$h) { refuse('private_stage_exists_or_unwritable'); }
    try {
        $n = 0;
        while ($n < strlen($bytes)) {
            $w = fwrite($h, substr($bytes, $n));
            if ($w === false || $w === 0) { refuse('private_stage_short_write'); }
            $n += $w;
        }
        if (!fflush($h) || !chmod($path, $mode)) { refuse('private_stage_flush_or_mode_failed'); }
    } finally { fclose($h); }
    if (hash_file('sha256', $path) !== sha($bytes)) { refuse('private_stage_hash_mismatch'); }
}
function lintCandidate(string $file): void {
    if (!function_exists('proc_open')) { refuse('proc_open_unavailable'); }
    $p = proc_open([RELEASE_PHP, '-l', $file], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { refuse('php_lint_start_failed'); }
    stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($p) !== 0) { refuse('php_lint_failed'); }
}
function sameLive(array $file, int $owner, string $which): void {
    $now = checkedRead(RELEASE_ROOT.'/'.$file['path'], $owner);
    if ($now['sha256'] !== $file[$which] || $now['mode'] !== $file['mode']
        || $now['uid'] !== $file['uid'] || $now['gid'] !== $file['gid']) { refuse('live_precondition_changed'); }
}
function replaceFromStage(string $stage, array $file, int $owner, string $expected, string $target, string $privateDir, ?callable $beforeRename = null): void {
    $dst = RELEASE_ROOT.'/'.$file['path'];
    if (hash_file('sha256', $stage) !== $target) { refuse('candidate_or_backup_changed'); }
    $tmp = $privateDir.'/rename-'.bin2hex(random_bytes(8));
    writeNew($tmp, (string) file_get_contents($stage), $file['mode']);
    if (filegroup($tmp) !== $file['gid'] && !chgrp($tmp, $file['gid'])) { refuse('group_preservation_failed'); }
    if (stat($tmp)['dev'] !== stat(dirname($dst))['dev']) { refuse('atomic_rename_crosses_filesystem'); }
    $now = checkedRead($dst, $owner);
    if ($now['sha256'] !== $expected || $now['mode'] !== $file['mode'] || $now['gid'] !== $file['gid']) { refuse('live_precondition_changed'); }
    if ($beforeRename !== null) { $beforeRename(); }
    if (!rename($tmp, $dst)) { refuse('atomic_rename_failed'); }
    if (hash_file('sha256', $dst) !== $target) { refuse('installed_hash_mismatch'); }
}
function main(array $args): int {
    umask(0077);
    if (PHP_SAPI !== 'cli' || realpath(getcwd()) !== RELEASE_ROOT || version_compare(PHP_VERSION, '8.4.0', '<')
        || realpath(PHP_BINARY) !== realpath(RELEASE_PHP)) { refuse('wrong_runtime_or_application_root'); }
    $dir = __DIR__;
    $owner = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    if (!preg_match('~^'.preg_quote(RELEASE_ROOT, '~').'/storage/app/private/arvan-delivery-[A-Za-z0-9_-]{6,80}$~D', $dir)
        || realpath($dir) !== $dir || is_link($dir) || (fileperms($dir) & 0777) !== 0700 || fileowner($dir) !== $owner) { refuse('unsafe_private_release_directory'); }
    $mode = $args[1] ?? '--inspect';
    if (!in_array($mode, ['--inspect', '--plan', '--apply'], true)) { refuse('invalid_mode'); }
    if ($mode === '--inspect') {
        $packagePath = $dir.'/patchdata.json';
        checkedRead($packagePath, $owner);
        if (hash_file('sha256', $packagePath) !== PATCHDATA_SHA256) { refuse('patch_package_changed'); }
        $package = readJson($packagePath);
        if (array_column($package['files'] ?? [], 'path') !== RELEASE_PATHS) { refuse('patch_scope_invalid'); }
        $out = ['mode' => 'read_only', 'utc' => gmdate('c'), 'files' => []];
        if (is_file($dir.'/receipt.json')) {
            try { $out['historical_receipt_status'] = readJson($dir.'/receipt.json')['status'] ?? 'unknown'; }
            catch (Throwable) { $out['historical_receipt_status'] = 'unreadable'; }
        }
        foreach ($package['files'] as $patch) {
            $path = $patch['path'];
            $r = checkedRead(RELEASE_ROOT.'/'.$path, $owner);
            $out['files'][$path] = ['sha256' => $r['sha256'], 'mode' => decoct($r['mode']), 'anchors' => anchors($r['bytes'])];
            try {
                $patched = patchSource($r['bytes'], $patch['hunks']);
                $out['files'][$path]['hunks'] = $patched['hunks'];
                $out['files'][$path]['proposed_sha256'] = sha($patched['bytes']);
            } catch (ReleaseRefusal $e) {
                $out['files'][$path]['patch_requires_review'] = $e->getMessage();
            }
        }
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
        return 0;
    }
    $lockPath = RELEASE_ROOT.'/storage/framework/arvan-delivery-release.lock';
    if (is_link($lockPath) || (file_exists($lockPath) && fileowner($lockPath) !== $owner)) { refuse('unsafe_release_lock'); }
    $lock = fopen($lockPath, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { refuse('release_busy'); }
    try {
        $packagePath = $dir.'/patchdata.json';
        checkedRead($packagePath, $owner);
        if (hash_file('sha256', $packagePath) !== PATCHDATA_SHA256) { refuse('patch_package_changed'); }
        $package = readJson($packagePath);
        if (($package['schema'] ?? null) !== 1 || array_column($package['files'] ?? [], 'path') !== RELEASE_PATHS) { refuse('patch_scope_invalid'); }
        if (is_file($dir.'/receipt.json')) {
            checkedRead($dir.'/receipt.json', $owner);
            $receipt = readJson($dir.'/receipt.json');
            if (($receipt['status'] ?? null) !== 'source_installed_activation_pending') {
                echo json_encode(['status' => 'historical_failure_requires_operator', 'recorded_status' => $receipt['status'] ?? null,
                    'changed' => false], JSON_THROW_ON_ERROR), PHP_EOL;
                return 1;
            }
            checkedRead($dir.'/plan.json', $owner);
            $planHash = hash_file('sha256', $dir.'/plan.json');
            if (($receipt['plan_sha256'] ?? null) !== $planHash
                || ($mode === '--apply' && ($args[2] ?? '') !== $planHash)) { refuse('historical_receipt_plan_hash_changed'); }
            $plan = readJson($dir.'/plan.json');
            if (($plan['patchdata_sha256'] ?? null) !== PATCHDATA_SHA256
                || array_column($plan['files'] ?? [], 'path') !== RELEASE_PATHS) { refuse('plan_scope_changed'); }
            try { foreach ($plan['files'] as $file) { sameLive($file, $owner, 'after'); } }
            catch (Throwable) { refuse('historical_receipt_live_state_changed'); }
            echo json_encode(['status' => 'receipt_already_exists', 'recorded_status' => $receipt['status'],
                'live_after_hashes_reverified' => true, 'changed' => false,
                'receipt_sha256' => hash_file('sha256', $dir.'/receipt.json')], JSON_THROW_ON_ERROR), PHP_EOL;
            return 0;
        }
        if ($mode === '--plan') {
            if (is_file($dir.'/plan.json')) {
                $plan = readJson($dir.'/plan.json');
                foreach ($plan['files'] as $file) { sameLive($file, $owner, 'before'); }
                echo json_encode(['status' => 'existing_plan_revalidated', 'plan_sha256' => hash_file('sha256', $dir.'/plan.json'), 'changed' => false], JSON_THROW_ON_ERROR), PHP_EOL;
                return 0;
            }
            $plan = ['schema' => 1, 'utc' => gmdate('c'), 'patchdata_sha256' => PATCHDATA_SHA256,
                'base_commit' => $package['base_commit'], 'candidate_commit' => $package['candidate_commit'],
                'scope' => 'six_web_source_files_only', 'activation' => 'not_performed', 'files' => []];
            $snapshots = [];
            foreach ($package['files'] as $patch) {
                $read = checkedRead(RELEASE_ROOT.'/'.$patch['path'], $owner);
                try { $patched = patchSource($read['bytes'], $patch['hunks']); }
                catch (ReleaseRefusal $e) { refuse($patch['path'].':'.$e->getMessage()); }
                $file = ['path' => $patch['path'], 'before' => $read['sha256'], 'after' => sha($patched['bytes']),
                    'mode' => $read['mode'], 'uid' => $read['uid'], 'gid' => $read['gid'], 'hunks' => $patched['hunks'],
                    'base_exact' => $read['sha256'] === $patch['base_sha256'],
                    'before_anchors' => anchors($read['bytes']), 'after_anchors' => anchors($patched['bytes'])];
                $plan['files'][] = $file;
                $snapshots[$file['path']] = ['before' => $read['bytes'], 'after' => $patched['bytes']];
            }
            foreach ($plan['files'] as $file) {
                writeNew($dir.'/backup/'.$file['path'], $snapshots[$file['path']]['before']);
                writeNew($dir.'/candidate/'.$file['path'], $snapshots[$file['path']]['after']);
                lintCandidate($dir.'/candidate/'.$file['path']);
            }
            foreach ($plan['files'] as $file) { sameLive($file, $owner, 'before'); }
            writeNew($dir.'/plan.json', json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            echo json_encode(['status' => 'prepared_not_installed', 'plan_sha256' => hash_file('sha256', $dir.'/plan.json'),
                'files' => $plan['files'], 'changed' => false], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
            return 0;
        }
        $expected = $args[2] ?? '';
        if (!preg_match('/\A[a-f0-9]{64}\z/', $expected) || !is_file($dir.'/plan.json')
            || hash_file('sha256', $dir.'/plan.json') !== $expected) { refuse('reviewed_plan_hash_required'); }
        $plan = readJson($dir.'/plan.json');
        if (($plan['patchdata_sha256'] ?? null) !== PATCHDATA_SHA256 || array_column($plan['files'] ?? [], 'path') !== RELEASE_PATHS) { refuse('plan_scope_changed'); }
        $receipt = ['schema' => 1, 'utc' => gmdate('c'), 'status' => 'precondition_failed',
            'plan_sha256' => $expected, 'activation' => 'not_performed', 'attempted_writes' => [], 'installed' => [], 'rolled_back' => []];
        try {
            foreach ($plan['files'] as $file) {
                sameLive($file, $owner, 'before');
                if (hash_file('sha256', $dir.'/backup/'.$file['path']) !== $file['before']
                    || hash_file('sha256', $dir.'/candidate/'.$file['path']) !== $file['after']) { refuse('candidate_or_backup_changed'); }
            }
            foreach ($plan['files'] as $file) {
                if ($file['before'] === $file['after']) { continue; }
                replaceFromStage($dir.'/candidate/'.$file['path'], $file, $owner, $file['before'], $file['after'], $dir,
                    function () use (&$receipt, $file): void { $receipt['attempted_writes'][] = $file['path']; });
                $receipt['installed'][] = $file['path'];
            }
            foreach ($plan['files'] as $file) { sameLive($file, $owner, 'after'); }
            $receipt['status'] = 'source_installed_activation_pending';
        } catch (Throwable $failure) {
            $receipt['error_code'] = $failure instanceof ReleaseRefusal ? $failure->getMessage() : get_class($failure);
            $receipt['status'] = 'failed_source_rollback_complete';
            $receipt['rollback_errors'] = [];
            foreach (array_reverse($plan['files']) as $file) {
                if (!in_array($file['path'], $receipt['attempted_writes'], true)) { continue; }
                try {
                    // Only our attempted renames qualify for rollback. A later
                    // unrelated edit must not prevent restoration of other paths.
                    $now = checkedRead(RELEASE_ROOT.'/'.$file['path'], $owner);
                    if ($now['sha256'] === $file['before']) { continue; }
                    if ($now['sha256'] !== $file['after']) { refuse('concurrent_update_requires_operator'); }
                    replaceFromStage($dir.'/backup/'.$file['path'], $file, $owner, $file['after'], $file['before'], $dir);
                    $receipt['rolled_back'][] = $file['path'];
                } catch (Throwable $rollbackFailure) {
                    $receipt['rollback_errors'][$file['path']] = $rollbackFailure instanceof ReleaseRefusal
                        ? $rollbackFailure->getMessage() : get_class($rollbackFailure);
                }
            }
            if ($receipt['rollback_errors'] !== []) {
                $receipt['status'] = 'failed_rollback_requires_operator';
            }
        }
        $receipt['completed_utc'] = gmdate('c');
        writeNew($dir.'/receipt.json', json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        echo json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
        return $receipt['status'] === 'source_installed_activation_pending' ? 0 : 1;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { exit(main($argv)); }
    catch (Throwable $e) {
        echo json_encode(['status' => 'refused', 'error_code' => $e instanceof ReleaseRefusal ? $e->getMessage() : get_class($e),
            'success' => false], JSON_THROW_ON_ERROR), PHP_EOL;
        exit(1);
    }
}
