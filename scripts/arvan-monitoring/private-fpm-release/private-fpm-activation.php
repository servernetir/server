<?php
declare(strict_types=1);

// Private-stage primary. Only the reviewed same-account FastCGI caller may invoke it.
const AF_RELEASE = 'arvan-delivery-20261103-v3q4m7dz';
const AF_HOME = '/home/servernetcloud';
const AF_APP = AF_HOME.'/servernet_app';
const AF_STAGE = AF_APP.'/storage/app/private/'.AF_RELEASE;
const AF_PRIMARY = AF_STAGE.'/private-fpm-activation.php';
const AF_TRANSPORT = 'private-stage-fcgi-v1';
const AF_SCRIPT_NAME = '/__servernet_private_arvan_release_20261103_v3q4m7dz.php';
const AF_DOCUMENT_ROOT = AF_HOME.'/public_html';
const AF_HELPER_SHA = '02c952a7ba3ac370e88081898c43be2b2b91ff200e894ce927c5e8bc7da8c93e';

function afSameCompiled(array $row, int $owner, string $expected): array {
    $now = checkedRead($row['path'], $owner);
    if ($now['sha256'] !== $expected || $now['mode'] !== $row['mode']
        || $now['uid'] !== $row['uid'] || $now['gid'] !== $row['gid']) {
        throw new RuntimeException('compiled_view_changed');
    }
    return $now;
}
function afPrepareCompiled(array $row, int $owner, string $bytes, string $stage): string {
    $temp = $stage.'/compiled-install-'.bin2hex(random_bytes(12)).'.php';
    writeNew($temp, $bytes, $row['mode']);
    if (filegroup($temp) !== $row['gid'] && !chgrp($temp, $row['gid'])) { throw new RuntimeException('compiled_group_preservation_failed'); }
    $ready = checkedRead($temp, $owner);
    if ($ready['sha256'] !== sha($bytes) || $ready['mode'] !== $row['mode']
        || $ready['uid'] !== $row['uid'] || $ready['gid'] !== $row['gid']
        || $ready['dev'] !== stat(dirname($row['path']))['dev']) { throw new RuntimeException('compiled_prepared_file_invalid'); }
    return $temp;
}
function afReplacePreparedCompiled(array $row, int $owner, string $expected, string $target, string $temp, ?callable $beforeRename = null, $lock = null): array {
    if (is_resource($lock)) { checkReleaseLock($lock, $owner); }
    $ready = checkedRead($temp, $owner);
    if ($ready['sha256'] !== $target || $ready['mode'] !== $row['mode']
        || $ready['uid'] !== $row['uid'] || $ready['gid'] !== $row['gid']
        || $ready['dev'] !== stat(dirname($row['path']))['dev']) { throw new RuntimeException('compiled_prepared_file_invalid'); }
    afSameCompiled($row, $owner, $expected);
    if (is_resource($lock)) { checkReleaseLock($lock, $owner); }
    if ($beforeRename !== null) { $beforeRename(); }
    if (!rename($temp, $row['path'])) { throw new RuntimeException('compiled_view_replace_failed'); }
    if (is_resource($lock)) { checkReleaseLock($lock, $owner); }
    return afSameCompiled($row, $owner, $target);
}
function afReplaceCompiled(array $row, int $owner, string $expected, string $bytes, string $stage, ?callable $beforeRename = null): array {
    return afReplacePreparedCompiled($row, $owner, $expected, sha($bytes), afPrepareCompiled($row, $owner, $bytes, $stage), $beforeRename);
}
function afRollbackCompiled(array $owned, int $owner, string $stage, callable $invalidate, $lock = null): array {
    $errors = [];
    foreach (array_reverse($owned) as $row) {
        try {
            if (is_resource($lock)) { checkReleaseLock($lock, $owner); }
            $now = checkedRead($row['path'], $owner);
            if ($now['sha256'] === $row['sha256']) { afSameCompiled($row, $owner, $row['sha256']); continue; }
            afSameCompiled($row, $owner, $row['after']);
            if (!is_string($row['rollback'] ?? null)) { throw new RuntimeException('compiled_prepared_rollback_missing'); }
            // No new source-sized write is needed after a disk/receipt publication failure.
            afReplacePreparedCompiled($row, $owner, $row['after'], $row['sha256'], $row['rollback'], null, $lock);
            $invalidate($row['path']);
        } catch (Throwable $error) { $errors[] = basename($row['path']); }
    }
    return $errors;
}
function afReadPrivate(string $path, int $owner, array $links = [1]): array {
    clearstatcache(true, $path);
    if (is_link($path) || !is_file($path) || realpath($path) !== $path) { throw new RuntimeException('activation_private_file_invalid'); }
    $before = lstat($path);
    if (!$before || $before['uid'] !== $owner || ($before['mode'] & 0777) !== 0600
        || !in_array($before['nlink'], $links, true)) { throw new RuntimeException('activation_private_metadata_invalid'); }
    $bytes = file_get_contents($path); clearstatcache(true, $path); $after = lstat($path);
    if (!is_string($bytes) || !$after) { throw new RuntimeException('activation_private_read_failed'); }
    foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime', 'ctime'] as $key) {
        if ($before[$key] !== $after[$key]) { throw new RuntimeException('activation_private_file_changed'); }
    }
    return ['sha256' => sha($bytes), 'bytes' => $bytes, 'dev' => $before['dev'], 'ino' => $before['ino']];
}
function afWriteReceiptTemp(string $path, string $bytes): void {
    if (realpath(dirname($path)) !== dirname($path) || is_link($path)) { throw new RuntimeException('activation_receipt_temp_invalid'); }
    $handle = fopen($path, 'xb');
    if (!$handle) { throw new RuntimeException('activation_receipt_write_failed'); }
    try {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($handle, substr($bytes, $offset));
            if ($written === false || $written === 0) { throw new RuntimeException('activation_receipt_short_write'); }
            $offset += $written;
        }
        if (!chmod($path, 0600) || !fflush($handle) || !fsync($handle)) { throw new RuntimeException('activation_receipt_flush_failed'); }
    } finally { fclose($handle); }
    if (hash_file('sha256', $path) !== sha($bytes)) { throw new RuntimeException('activation_receipt_temp_hash_mismatch'); }
}
function afVerifyPublishedReceipt(string $path, array $publication, int $owner): void {
    $saved = afReadPrivate($path, $owner);
    if ($saved['sha256'] !== $publication['sha256'] || $saved['dev'] !== $publication['dev']
        || $saved['ino'] !== $publication['ino']) { throw new RuntimeException('activation_receipt_publication_mismatch'); }
}
function afPublishReceipt(string $path, array $receipt, int $owner, array &$publication, $lock = null): void {
    $bytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    $temp = dirname($path).'/activation-receipt-'.bin2hex(random_bytes(12)).'.json';
    afWriteReceiptTemp($temp, $bytes);
    $ready = afReadPrivate($temp, $owner);
    if ($ready['sha256'] !== sha($bytes)) { throw new RuntimeException('activation_receipt_candidate_changed'); }
    if (is_resource($lock)) { checkReleaseLock($lock, $owner); }
    // Atomic no-overwrite: even a receipt created after preflight is preserved.
    if (!@link($temp, $path)) { throw new RuntimeException('activation_receipt_publication_failed'); }
    $publication = ['sha256' => $ready['sha256'], 'dev' => $ready['dev'], 'ino' => $ready['ino'], 'temp' => $temp];
    if (is_resource($lock)) { checkReleaseLock($lock, $owner); }
    if (!unlink($temp)) { throw new RuntimeException('activation_receipt_temp_unlink_failed'); }
    afVerifyPublishedReceipt($path, $publication, $owner);
}
function afWithdrawOwnReceipt(string $path, array $publication, int $owner, $lock = null): bool {
    if (is_resource($lock)) { checkReleaseLock($lock, $owner); }
    clearstatcache(true, $path);
    if ($publication === []) { return !file_exists($path) && !is_link($path); }
    if (!file_exists($path) && !is_link($path)) { return true; }
    try {
        // A temp unlink failure can leave two names for our exact inode.
        $current = afReadPrivate($path, $owner, [1, 2]);
        if ($current['sha256'] !== $publication['sha256'] || $current['dev'] !== $publication['dev']
            || $current['ino'] !== $publication['ino']) { return false; }
        if (is_resource($lock)) { checkReleaseLock($lock, $owner); }
        if (!unlink($path)) { return false; }
    } catch (Throwable $error) { return false; }
    // A leftover private temp is not the active receipt; never delete a foreign replacement.
    $temp = $publication['temp'];
    try {
        if (file_exists($temp) || is_link($temp)) {
            $private = afReadPrivate($temp, $owner);
            if ($private['sha256'] === $publication['sha256'] && $private['dev'] === $publication['dev']
                && $private['ino'] === $publication['ino']) {
                if (is_resource($lock)) { checkReleaseLock($lock, $owner); }
                unlink($temp);
            }
        }
    } catch (Throwable $error) { /* Preserve unexpected private temp state for inspection. */ }
    return true;
}
function afVerifyCompiledReceipt(array $previous, array $compiled, int $owner): void {
    $expected = $previous['compiled_verified'] ?? null;
    if (!is_array($expected) || array_column($expected, 'path') !== array_column($compiled, 'path')) {
        throw new RuntimeException('historical_compiled_inventory_changed');
    }
    foreach ($expected as $row) { afSameCompiled($row, $owner, $row['sha256']); }
}

function afSamePrimary(array $expected, int $owner): void {
    $now = afReadPrivate(AF_PRIMARY, $owner);
    foreach (['sha256', 'dev', 'ino'] as $key) {
        if ($now[$key] !== $expected[$key]) { throw new RuntimeException('private_primary_changed'); }
    }
}
function afVerifyTransportReceipt(array $receipt, string $primarySha, string $host): void {
    if (($receipt['release'] ?? null) !== AF_RELEASE || ($receipt['transport_variant'] ?? null) !== AF_TRANSPORT
        || ($receipt['primary_path'] ?? null) !== AF_PRIMARY || ($receipt['host'] ?? null) !== $host
        || !is_string($receipt['primary_sha256'] ?? null) || !hash_equals($primarySha, $receipt['primary_sha256'])) {
        throw new RuntimeException('activation_receipt_transport_changed');
    }
}

// Guarded FPM entry point; functions above are independently exercised on synthetic files.
if (PHP_SAPI !== 'fpm-fcgi' || version_compare(PHP_VERSION, '8.4.0', '<') || realpath(__FILE__) !== AF_PRIMARY
    || ($_SERVER['SCRIPT_FILENAME'] ?? '') !== AF_PRIMARY || ($_SERVER['SCRIPT_NAME'] ?? '') !== AF_SCRIPT_NAME
    || realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) !== AF_DOCUMENT_ROOT
    || ($_SERVER['SERVERNET_LOCAL_RELEASE'] ?? '') !== AF_RELEASE
    || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || !in_array($_SERVER['SERVERNET_RELEASE_ACTION'] ?? '', ['observe', 'activate', 'verify'], true)
    || !preg_match('/\A[a-f0-9]{64}\z/', (string) ($_SERVER['SERVERNET_EXPECTED_PLAN_SHA256'] ?? ''))
    || !in_array($_SERVER['HTTP_HOST'] ?? '', ['servernet.cloud', 'console.servernet.cloud'], true)) {
    http_response_code(404); exit;
}
umask(0077);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
$action = $_SERVER['SERVERNET_RELEASE_ACTION']; $lock = null;
$compiledOwned = []; $compiledCommitted = false; $rollbackErrors = [];
$receiptPublication = []; $receiptAttempted = false; $activationPath = null;
$receiptWithdrawn = null; $receiptReconciliationRequired = false;
try {
    if (!function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) { throw new RuntimeException('native_account_required'); }
    $owner = posix_geteuid();
    if ((posix_getpwuid($owner)['name'] ?? null) !== 'servernetcloud') { throw new RuntimeException('native_account_required'); }
    foreach ([[AF_STAGE, 0700, 0040000], [AF_PRIMARY, 0600, 0100000]] as [$path, $mode, $type]) {
        clearstatcache(true, $path); $context = @lstat($path);
        if (!$context || is_link($path) || realpath($path) !== $path || $context['uid'] !== $owner
            || ($context['mode'] & 0777) !== $mode || ($context['mode'] & 0170000) !== $type
            || ($type === 0100000 && $context['nlink'] !== 1)) { throw new RuntimeException('private_context_invalid'); }
    }
    if (!is_dir(AF_DOCUMENT_ROOT) || realpath(AF_DOCUMENT_ROOT) !== AF_DOCUMENT_ROOT
        || str_starts_with(AF_STAGE.'/', AF_DOCUMENT_ROOT.'/') || str_starts_with(AF_PRIMARY, AF_DOCUMENT_ROOT.'/')) {
        throw new RuntimeException('private_primary_inside_document_root');
    }
    $helperPath = AF_STAGE.'/deploy.php'; clearstatcache(true, $helperPath); $helperStat = @lstat($helperPath);
    if (!$helperStat || is_link($helperPath) || realpath($helperPath) !== $helperPath || $helperStat['uid'] !== $owner
        || ($helperStat['mode'] & 0777) !== 0600 || ($helperStat['mode'] & 0170000) !== 0100000
        || $helperStat['nlink'] !== 1 || hash_file('sha256', $helperPath) !== AF_HELPER_SHA) {
        throw new RuntimeException('reviewed_private_helper_changed');
    }
    // Function declarations only: deploy.php's main guard does not run when included here.
    require AF_STAGE.'/deploy.php';
    $helper = afReadPrivate($helperPath, $owner);
    if ($helper['sha256'] !== AF_HELPER_SHA || $helper['dev'] !== $helperStat['dev'] || $helper['ino'] !== $helperStat['ino']) {
        throw new RuntimeException('reviewed_private_helper_changed');
    }
    $primary = afReadPrivate(AF_PRIMARY, $owner);
    $lockPath = AF_APP.'/storage/framework/arvan-delivery-release.lock';
    checkedRead($lockPath, $owner);
    $lock = fopen($lockPath, 'r+b');
    if (!$lock) { throw new RuntimeException('release_busy'); }
    checkReleaseLock($lock, $owner);
    if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('release_busy'); }
    checkReleaseLock($lock, $owner);
    checkedRead(AF_STAGE.'/plan.json', $owner);
    $planHash = hash_file('sha256', AF_STAGE.'/plan.json');
    if (!hash_equals($_SERVER['SERVERNET_EXPECTED_PLAN_SHA256'], $planHash)) { throw new RuntimeException('plan_hash_changed'); }
    $plan = readJson(AF_STAGE.'/plan.json');
    if (($plan['schema'] ?? null) !== 1 || array_column($plan['files'] ?? [], 'path') !== RELEASE_PATHS) {
        throw new RuntimeException('plan_scope_changed');
    }
    $installed = false;
    if (is_file(AF_STAGE.'/receipt.json')) {
        checkedRead(AF_STAGE.'/receipt.json', $owner);
        $receipt = readJson(AF_STAGE.'/receipt.json');
        if (($receipt['status'] ?? null) !== 'source_installed_activation_pending' || ($receipt['plan_sha256'] ?? null) !== $planHash) {
            throw new RuntimeException('installed_receipt_invalid');
        }
        $installed = true;
    }
    if ($action !== 'observe' && !$installed) { throw new RuntimeException('source_not_installed'); }
    foreach ($plan['files'] as $file) { sameLive($file, $owner, $installed ? 'after' : 'before'); }
    $source = AF_APP.'/resources/views/account/cloud-server.blade.php';
    $compiled = [];
    $cacheDir = AF_APP.'/storage/framework/views';
    if (is_dir($cacheDir) && realpath($cacheDir) === $cacheDir) {
        foreach (glob($cacheDir.'/*.php') ?: [] as $path) {
            if (is_link($path) || !is_file($path) || realpath($path) !== $path) { continue; }
            $handle = fopen($path, 'rb');
            if (!$handle) { throw new RuntimeException('compiled_view_read_failed'); }
            fseek($handle, -min(filesize($path), 2048), SEEK_END); $tail = stream_get_contents($handle); fclose($handle);
            if (!str_contains($tail, '/**PATH '.$source.' ENDPATH**/')) { continue; }
            $row = checkedRead($path, $owner);
            $compiled[] = ['path' => $path, 'sha256' => $row['sha256'], 'mode' => $row['mode'], 'uid' => $row['uid'], 'gid' => $row['gid']];
        }
    }
    if (count($compiled) > 8) { throw new RuntimeException('ambiguous_compiled_view_inventory'); }
    $enabled = filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);
    if ($enabled && (!function_exists('opcache_invalidate') || !function_exists('opcache_is_script_cached') || !function_exists('opcache_get_status'))) {
        throw new RuntimeException('opcache_functions_unavailable');
    }
    // restrict_api can make cache probes and invalidation both return false. Prove API access first.
    $opcacheApiAvailable = !$enabled;
    if ($enabled) {
        $status = @opcache_get_status(false);
        $opcacheApiAvailable = is_array($status) && ($status['opcache_enabled'] ?? false) === true;
        unset($status); // Never expose the shared-cache inventory or counters.
        if (!$opcacheApiAvailable) { throw new RuntimeException('opcache_api_access_unavailable'); }
    }
    $targets = []; $cleared = []; $activationAlreadyRecorded = false;
    $activationPath = AF_STAGE.'/activation-'.$_SERVER['HTTP_HOST'].'.json';
    if ($action !== 'observe' && is_file($activationPath)) {
        $previous = json_decode(afReadPrivate($activationPath, $owner)['bytes'], true, 128, JSON_THROW_ON_ERROR);
        if (($previous['plan_sha256'] ?? null) !== $planHash || ($previous['status'] ?? null) !== 'ok') {
            throw new RuntimeException('historical_activation_changed');
        }
        afSamePrimary($primary, $owner);
        afVerifyTransportReceipt($previous, $primary['sha256'], $_SERVER['HTTP_HOST']);
        afVerifyCompiledReceipt($previous, $compiled, $owner);
        $activationAlreadyRecorded = true;
        $compiledCommitted = true;
    }
    if ($action === 'verify' && !$activationAlreadyRecorded) { throw new RuntimeException('activation_receipt_missing'); }
    $compiledAlreadyPrepared = $activationAlreadyRecorded;
    if ($action === 'activate' && !$activationAlreadyRecorded) {
        $peerHost = $_SERVER['HTTP_HOST'] === 'servernet.cloud' ? 'console.servernet.cloud' : 'servernet.cloud';
        $peerPath = AF_STAGE.'/activation-'.$peerHost.'.json';
        if (is_file($peerPath)) {
            $peer = json_decode(afReadPrivate($peerPath, $owner)['bytes'], true, 128, JSON_THROW_ON_ERROR);
            if (($peer['plan_sha256'] ?? null) !== $planHash || ($peer['status'] ?? null) !== 'ok') { throw new RuntimeException('peer_activation_changed'); }
            afSamePrimary($primary, $owner);
            afVerifyTransportReceipt($peer, $primary['sha256'], $peerHost);
            afVerifyCompiledReceipt($peer, $compiled, $owner);
            $compiledAlreadyPrepared = true;
        }
    }
    $compiledBytes = null;
    if ($action === 'activate' && !$compiledAlreadyPrepared && $compiled !== []) {
        // Only compile the fixed source; never render it or bootstrap Laravel.
        require_once AF_APP.'/vendor/autoload.php';
        $compiler = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem, $cacheDir);
        $compiler->setPath($source);
        $compiledBytes = $compiler->compileString((string) file_get_contents($source));
        $last = null;
        foreach (token_get_all($compiledBytes) as $token) {
            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG], true)) { $last = $token[0]; }
        }
        if ($last !== null && $last !== T_CLOSE_TAG) { $compiledBytes .= ' ?>'; }
        $compiledBytes .= '<?php /**PATH '.$source.' ENDPATH**/ ?>';
        token_get_all($compiledBytes, TOKEN_PARSE);
        if (in_array(AF_APP.'/bootstrap/app.php', get_included_files(), true)) { throw new RuntimeException('unexpected_application_bootstrap'); }
    }
    $compiledPrepared = [];
    if ($action === 'activate' && !$compiledAlreadyPrepared) {
        // Prepare every install and reversal before the first compiled rename.
        foreach ($compiled as $row) {
            $before = afSameCompiled($row, $owner, $row['sha256']);
            $backup = AF_STAGE.'/compiled-before-'.bin2hex(random_bytes(12)).'.php';
            writeNew($backup, $before['bytes']);
            $compiledPrepared[] = $row + ['backup' => $backup, 'after' => sha($compiledBytes),
                'rollback' => afPrepareCompiled($row, $owner, $before['bytes'], AF_STAGE),
                'install' => afPrepareCompiled($row, $owner, $compiledBytes, AF_STAGE)];
        }
    }
    foreach (RELEASE_PATHS as $relative) {
        $path = AF_APP.'/'.$relative;
        $cached = $enabled && opcache_is_script_cached($path); $invalidated = false;
        if ($action === 'activate' && !$activationAlreadyRecorded && $enabled) {
            $invalidated = opcache_invalidate($path, true);
            if ($cached && !$invalidated) { throw new RuntimeException('target_invalidation_failed'); }
        }
        $targets[$relative] = ['was_cached' => $cached, 'invalidated' => $invalidated];
    }
    if ($action === 'activate' && !$activationAlreadyRecorded) {
        // The other observed pool may already have prepared the shared compiled artifact.
        // Only exact PATH-matched artifacts are replaced; retain their permissions/group.
        foreach ($compiled as $index => $row) {
            if (!$compiledAlreadyPrepared) {
                $owned = $compiledPrepared[$index];
                $actual = afReplacePreparedCompiled($row, $owner, $row['sha256'], $owned['after'], $owned['install'],
                    static function () use (&$compiledOwned, $owned): void { $compiledOwned[] = $owned; }, $lock);
                $cleared[] = ['basename' => basename($row['path']), 'before_sha256' => $row['sha256'], 'after_sha256' => $actual['sha256'], 'preserved_mode' => $row['mode']];
            }
            if ($enabled) {
                $cached = opcache_is_script_cached($row['path']);
                if (!opcache_invalidate($row['path'], true) && $cached) { throw new RuntimeException('compiled_invalidation_failed'); }
            }
        }
    }

    $checks = null;
    if ($action === 'verify') {
        require_once AF_APP.'/vendor/autoload.php';
        $model = new ReflectionClass(App\Models\CloudInstance::class);
        $broker = new ReflectionClass(App\Services\Cloud\ArvanConsoleBroker::class);
        $checks = ['model_new_method' => $model->hasMethod('arvanWindowsConsoleAvailable'),
            'broker_new_method' => $broker->hasMethod('configuredSocketPath'),
            'model_source_path' => $model->getFileName() === AF_APP.'/app/Models/CloudInstance.php',
            'broker_source_path' => $broker->getFileName() === AF_APP.'/app/Services/Cloud/ArvanConsoleBroker.php',
            'no_application_bootstrap' => !in_array(AF_APP.'/bootstrap/app.php', get_included_files(), true)];
        foreach (['fa', 'en', 'tr'] as $locale) {
            $translations = require AF_APP.'/lang/'.$locale.'/ui.php';
            $checks['translation_'.$locale] = is_array($translations);
            foreach (['h', 'password', 'rdp', 'note'] as $suffix) {
                $checks['translation_'.$locale] = $checks['translation_'.$locale]
                    && is_string($translations['cs_windows_console_setup_'.$suffix] ?? null)
                    && $translations['cs_windows_console_setup_'.$suffix] !== '';
            }
        }
        if (in_array(false, $checks, true)) { throw new RuntimeException('runtime_definition_verification_failed'); }
    }
    foreach ($plan['files'] as $file) { sameLive($file, $owner, $installed ? 'after' : 'before'); }
    checkReleaseLock($lock, $owner);
    $compiledVerified = [];
    foreach ($compiled as $row) {
        $afterHash = ($action === 'activate' && !$compiledAlreadyPrepared) ? sha($compiledBytes) : $row['sha256'];
        $now = afSameCompiled($row, $owner, $afterHash);
        $compiledVerified[] = array_intersect_key($row, array_flip(['path', 'mode', 'uid', 'gid'])) + ['sha256' => $now['sha256']];
    }
    afSamePrimary($primary, $owner);
    $result = ['release' => AF_RELEASE, 'transport_variant' => AF_TRANSPORT, 'primary_path' => AF_PRIMARY,
        'primary_sha256' => $primary['sha256'], 'action' => $action, 'status' => 'ok', 'utc' => gmdate('c'),
        'plan_sha256' => $planHash, 'installed' => $installed, 'php' => PHP_VERSION, 'sapi' => PHP_SAPI,
        'host' => $_SERVER['HTTP_HOST'], 'opcache' => ['enabled' => $enabled, 'api_access_verified' => $opcacheApiAvailable,
            'validate_timestamps' => ini_get('opcache.validate_timestamps'), 'revalidate_freq' => ini_get('opcache.revalidate_freq'),
            'file_update_protection' => ini_get('opcache.file_update_protection')],
        'targets' => $targets, 'compiled_matches' => count($compiled), 'compiled_replaced' => $cleared, 'compiled_verified' => $compiledVerified,
        'activation_already_recorded' => $activationAlreadyRecorded, 'compiled_prepared_by_prior_pool' => $compiledAlreadyPrepared, 'definition_checks' => $checks,
        'application_bootstrapped' => false, 'provider_calls' => 0, 'database_calls' => 0, 'global_cache_flush' => false];
    if ($action === 'activate' && !$activationAlreadyRecorded) {
        $receiptAttempted = true;
        checkReleaseLock($lock, $owner);
        afSamePrimary($primary, $owner);
        afVerifyTransportReceipt($result, $primary['sha256'], $_SERVER['HTTP_HOST']);
        afPublishReceipt($activationPath, $result, $owner, $receiptPublication, $lock);
        checkReleaseLock($lock, $owner);
        // Commit only when the durable receipt and every source/artifact still agree.
        foreach ($plan['files'] as $file) { sameLive($file, $owner, 'after'); }
        afVerifyCompiledReceipt($result, $compiledVerified, $owner);
        afVerifyPublishedReceipt($activationPath, $receiptPublication, $owner);
        afSamePrimary($primary, $owner);
        checkReleaseLock($lock, $owner);
        $compiledCommitted = true;
    }
    checkReleaseLock($lock, $owner);
    afSamePrimary($primary, $owner);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    if (!$compiledCommitted && ($receiptAttempted || $compiledOwned !== [])) {
        try {
            checkReleaseLock($lock, $owner);
            $receiptWithdrawn = afWithdrawOwnReceipt($activationPath, $receiptPublication, $owner, $lock);
        } catch (Throwable $recoveryError) {
            // Never alter a receipt or compiled artifact under a replaced mutex.
            $receiptWithdrawn = false;
        }
        if (!$receiptWithdrawn) {
            // A foreign receipt may describe the installed artifacts. Preserve both for review.
            $receiptReconciliationRequired = true;
        } elseif ($compiledOwned !== []) {
            $rollbackErrors = afRollbackCompiled($compiledOwned, $owner, AF_STAGE, static function (string $path) use ($enabled): void {
                if ($enabled && opcache_is_script_cached($path) && !opcache_invalidate($path, true)) { throw new RuntimeException('compiled_rollback_invalidation_failed'); }
            }, $lock);
        }
    }
    http_response_code(503);
    echo json_encode(['release' => AF_RELEASE, 'action' => $action, 'status' => 'stopped',
        'compiled_rollback_errors' => $rollbackErrors, 'receipt_withdrawn_or_absent' => $receiptWithdrawn,
        'receipt_reconciliation_required' => $receiptReconciliationRequired, 'reason' => $error instanceof RuntimeException && preg_match('/\A[a-z_]+\z/', $error->getMessage())
            ? $error->getMessage() : 'inspect_private_activation_context'], JSON_THROW_ON_ERROR), PHP_EOL;
} finally { if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); } }
