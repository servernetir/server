<?php
declare(strict_types=1);

// Temporary HTTP-denied primary. Only the reviewed same-account FastCGI caller may invoke it.
const AF_RELEASE = 'arvan-delivery-20261102-v2q4m7dz';
const AF_HOME = '/home/servernetcloud';
const AF_APP = AF_HOME.'/servernet_app';
const AF_STAGE = AF_APP.'/storage/app/private/'.AF_RELEASE;
const AF_PRIMARY = AF_HOME.'/public_html/_arvan_delivery_20261102_v2q4m7dz/activate.php';
const AF_HELPER_SHA = '0b9c796cda3f05e6e6092c445198ae49c25b805742f502786569f7b7ce92c75d';

function afSameCompiled(array $row, int $owner, string $expected): array {
    $now = checkedRead($row['path'], $owner);
    if ($now['sha256'] !== $expected || $now['mode'] !== $row['mode']
        || $now['uid'] !== $row['uid'] || $now['gid'] !== $row['gid']) {
        throw new RuntimeException('compiled_view_changed');
    }
    return $now;
}
function afReplaceCompiled(array $row, int $owner, string $expected, string $bytes, string $stage, ?callable $beforeRename = null): array {
    $temp = $stage.'/compiled-install-'.bin2hex(random_bytes(12)).'.php';
    writeNew($temp, $bytes, $row['mode']);
    if (filegroup($temp) !== $row['gid'] && !chgrp($temp, $row['gid'])) { throw new RuntimeException('compiled_group_preservation_failed'); }
    if (stat($temp)['dev'] !== stat(dirname($row['path']))['dev']) { throw new RuntimeException('compiled_atomic_rename_crosses_filesystem'); }
    afSameCompiled($row, $owner, $expected);
    if ($beforeRename !== null) { $beforeRename(); }
    if (!rename($temp, $row['path'])) { throw new RuntimeException('compiled_view_replace_failed'); }
    return afSameCompiled($row, $owner, sha($bytes));
}
function afRollbackCompiled(array $owned, int $owner, string $stage, callable $invalidate): array {
    $errors = [];
    foreach (array_reverse($owned) as $row) {
        try {
            $now = checkedRead($row['path'], $owner);
            if ($now['sha256'] === $row['sha256']) { afSameCompiled($row, $owner, $row['sha256']); continue; }
            afSameCompiled($row, $owner, $row['after']);
            $backup = checkedRead($row['backup'], $owner);
            if ($backup['sha256'] !== $row['sha256'] || $backup['mode'] !== 0600) { throw new RuntimeException('compiled_backup_changed'); }
            afReplaceCompiled($row, $owner, $row['after'], $backup['bytes'], $stage);
            $invalidate($row['path']);
        } catch (Throwable $error) { $errors[] = basename($row['path']); }
    }
    return $errors;
}
function afVerifyCompiledReceipt(array $previous, array $compiled, int $owner): void {
    $expected = $previous['compiled_verified'] ?? null;
    if (!is_array($expected) || array_column($expected, 'path') !== array_column($compiled, 'path')) {
        throw new RuntimeException('historical_compiled_inventory_changed');
    }
    foreach ($expected as $row) { afSameCompiled($row, $owner, $row['sha256']); }
}

// Guarded FPM entry point; functions above are independently exercised on synthetic files.
if (PHP_SAPI !== 'fpm-fcgi' || version_compare(PHP_VERSION, '8.4.0', '<') || realpath(__FILE__) !== AF_PRIMARY
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
try {
    $owner = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    foreach ([[dirname(AF_PRIMARY), 0700], [AF_PRIMARY, 0600], [dirname(AF_PRIMARY).'/.htaccess', 0600], [AF_STAGE, 0700]] as [$path, $mode]) {
        if (is_link($path) || realpath($path) !== $path || fileowner($path) !== $owner || (fileperms($path) & 0777) !== $mode) {
            throw new RuntimeException('private_context_invalid');
        }
    }
    if (file_get_contents(dirname(AF_PRIMARY).'/.htaccess') !== "Require all denied\n"
        || is_link(AF_STAGE.'/deploy.php') || hash_file('sha256', AF_STAGE.'/deploy.php') !== AF_HELPER_SHA) {
        throw new RuntimeException('reviewed_helper_or_http_denial_changed');
    }
    // Function declarations only: deploy.php's main guard does not run when included here.
    require AF_STAGE.'/deploy.php';
    $lockPath = AF_APP.'/storage/framework/arvan-delivery-release.lock';
    checkedRead($lockPath, $owner);
    $lock = fopen($lockPath, 'r+b');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('release_busy'); }
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
        checkedRead($activationPath, $owner); $previous = readJson($activationPath);
        if (($previous['plan_sha256'] ?? null) !== $planHash || ($previous['status'] ?? null) !== 'ok') {
            throw new RuntimeException('historical_activation_changed');
        }
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
            checkedRead($peerPath, $owner); $peer = readJson($peerPath);
            if (($peer['plan_sha256'] ?? null) !== $planHash || ($peer['status'] ?? null) !== 'ok') { throw new RuntimeException('peer_activation_changed'); }
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
        foreach ($compiled as $row) {
            if (!$compiledAlreadyPrepared) {
                $before = afSameCompiled($row, $owner, $row['sha256']);
                $backup = AF_STAGE.'/compiled-before-'.bin2hex(random_bytes(12)).'.php';
                writeNew($backup, $before['bytes']);
                $owned = $row + ['backup' => $backup, 'after' => sha($compiledBytes)];
                $actual = afReplaceCompiled($row, $owner, $row['sha256'], $compiledBytes, AF_STAGE,
                    static function () use (&$compiledOwned, $owned): void { $compiledOwned[] = $owned; });
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
    $compiledVerified = [];
    foreach ($compiled as $row) {
        $afterHash = ($action === 'activate' && !$compiledAlreadyPrepared) ? sha($compiledBytes) : $row['sha256'];
        $now = afSameCompiled($row, $owner, $afterHash);
        $compiledVerified[] = array_intersect_key($row, array_flip(['path', 'mode', 'uid', 'gid'])) + ['sha256' => $now['sha256']];
    }
    $result = ['release' => AF_RELEASE, 'action' => $action, 'status' => 'ok', 'utc' => gmdate('c'),
        'plan_sha256' => $planHash, 'installed' => $installed, 'php' => PHP_VERSION, 'sapi' => PHP_SAPI,
        'host' => $_SERVER['HTTP_HOST'], 'opcache' => ['enabled' => $enabled, 'api_access_verified' => $opcacheApiAvailable,
            'validate_timestamps' => ini_get('opcache.validate_timestamps'), 'revalidate_freq' => ini_get('opcache.revalidate_freq'),
            'file_update_protection' => ini_get('opcache.file_update_protection')],
        'targets' => $targets, 'compiled_matches' => count($compiled), 'compiled_replaced' => $cleared, 'compiled_verified' => $compiledVerified,
        'activation_already_recorded' => $activationAlreadyRecorded, 'compiled_prepared_by_prior_pool' => $compiledAlreadyPrepared, 'definition_checks' => $checks,
        'application_bootstrapped' => false, 'provider_calls' => 0, 'database_calls' => 0, 'global_cache_flush' => false];
    if ($action === 'activate' && !$activationAlreadyRecorded) {
        $receiptBytes = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $receiptTemp = AF_STAGE.'/activation-receipt-'.bin2hex(random_bytes(12)).'.json';
        writeNew($receiptTemp, $receiptBytes);
        if (file_exists($activationPath) || is_link($activationPath) || !rename($receiptTemp, $activationPath)) { throw new RuntimeException('activation_receipt_publication_failed'); }
        $savedReceipt = checkedRead($activationPath, $owner);
        if ($savedReceipt['sha256'] !== sha($receiptBytes) || $savedReceipt['mode'] !== 0600) { throw new RuntimeException('activation_receipt_publication_mismatch'); }
        $compiledCommitted = true;
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    if (!$compiledCommitted && $compiledOwned !== []) {
        $rollbackErrors = afRollbackCompiled($compiledOwned, $owner, AF_STAGE, static function (string $path) use ($enabled): void {
            if ($enabled && opcache_is_script_cached($path) && !opcache_invalidate($path, true)) { throw new RuntimeException('compiled_rollback_invalidation_failed'); }
        });
    }
    http_response_code(503);
    echo json_encode(['release' => AF_RELEASE, 'action' => $action, 'status' => 'stopped',
        'compiled_rollback_errors' => $rollbackErrors, 'reason' => $error instanceof RuntimeException && preg_match('/\A[a-z_]+\z/', $error->getMessage())
            ? $error->getMessage() : 'inspect_private_activation_context'], JSON_THROW_ON_ERROR), PHP_EOL;
} finally { if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); } }
