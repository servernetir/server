<?php
declare(strict_types=1);
// Generated deployment entry: temporary, POST-only, header-authenticated, expiring.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || time() > __EXPIRES_UNIX__
    || !hash_equals('__TOKEN_SHA256__', hash('sha256', (string) ($_SERVER['HTTP_X_SERVERNET_RELEASE'] ?? '')))) {
    http_response_code(404);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
$stage = '__PRIVATE_STAGE__';
$runner = $stage . '/live-runner.php';
if (is_link($runner) || !is_file($runner) || hash_file('sha256', $runner) !== '__RUNNER_SHA256__') {
    http_response_code(409);
    echo '{"ok":false,"code":"runner_mismatch"}';
    exit;
}
try {
    $body = file_get_contents('php://input', false, null, 0, 32769);
    if (strlen($body) > 32768) {
        throw new RuntimeException('request_size');
    }
    $input = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        throw new RuntimeException('request_shape');
    }
    token_get_all(file_get_contents($runner), TOKEN_PARSE);
    require $runner;
} catch (Throwable $error) {
    http_response_code(409);
    // No request, exception text, bank identifiers or configuration in responses/logs.
    $safeCodes = ['action', 'private_stage', 'lock_symlink', 'release_busy', 'lock_changed',
        'scope_hash', 'scope', 'source_path', 'destination', 'target_symlink', 'app_key_missing',
        'database_driver', 'receipt_symlink', 'receipt_write', 'receipt_activate'];
    $safeCodes = array_merge($safeCodes, json_decode('__SAFE_OPERATION_CODES_JSON__', true, 32, JSON_THROW_ON_ERROR));
    $code = in_array($error->getMessage(), $safeCodes, true) ? $error->getMessage() : 'release_step_failed';
    $safeSteps = ['private_stage', 'locks', 'scope', 'bootstrap', 'schema', 'accounts', 'admins', 'receipt', 'install', 'activate', 'verify', 'register'];
    $step = isset($releaseStep) && in_array($releaseStep, $safeSteps, true) ? $releaseStep : 'entry';
    echo json_encode(['ok' => false, 'code' => $code, 'step' => $step, 'error_class' => get_class($error)]);
}
