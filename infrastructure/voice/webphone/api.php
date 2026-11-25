<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/bootstrap.php';

try {
    $context = sn_voice_bootstrap(true);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        sn_voice_json(405, ['ok' => false, 'error' => 'post_required']);
    }
    // This endpoint is used by same-origin browser fetch, never by cross-site clients.
    if (($_SERVER['HTTP_ORIGIN'] ?? '') !== SN_VOICE_ORIGIN
        || (isset($_SERVER['HTTP_SEC_FETCH_SITE']) && $_SERVER['HTTP_SEC_FETCH_SITE'] !== 'same-origin')) {
        sn_voice_json(403, ['ok' => false, 'error' => 'request_denied']);
    }
    if (($_POST['action'] ?? null) !== 'connect' || !sn_voice_uuid($_POST['extension_uuid'] ?? null)) {
        sn_voice_json(400, ['ok' => false, 'error' => 'invalid_request']);
    }
    $token = new token;
    $postedToken = null;
    foreach ($_SESSION['tokens'][SN_VOICE_TOKEN_KEY] ?? [] as $candidate) {
        $name = $candidate['name'] ?? null;
        if (is_string($name) && is_string($_POST[$name] ?? null)
            && is_string($candidate['hash'] ?? null) && hash_equals($candidate['hash'], $_POST[$name])) {
            $postedToken = $_POST[$name];
            break;
        }
    }
    if ($postedToken === null || !$token->validate(SN_VOICE_TOKEN_KEY, $postedToken)) {
        sn_voice_json(403, ['ok' => false, 'error' => 'request_denied']);
    }
    // The password is selected only here, after native authorization and CSRF validation.
    $rows = sn_voice_owned_extensions($context, $_POST['extension_uuid']);
    if (count($rows) !== 1) sn_voice_json(403, ['ok' => false, 'error' => 'extension_unavailable']);
    $row = $rows[0];
    if (!is_string($row['password'] ?? null) || $row['password'] === '') {
        sn_voice_json(409, ['ok' => false, 'error' => 'extension_unavailable']);
    }
    $nextToken = $token->create(SN_VOICE_TOKEN_KEY);
    // Allowlisted identifiers only: no SIP secret, SIP response or request body.
    error_log('servernet_voice_connect ' . json_encode([
        'user_uuid' => $context['user_uuid'],
        'domain_uuid' => $context['domain_uuid'],
        'extension_uuid' => $_POST['extension_uuid'],
    ], JSON_THROW_ON_ERROR));
    $payload = [
        'ok' => true,
        'extension' => $row['extension'],
        'sip' => [
            'uri' => 'sip:' . $row['extension'] . '@' . $context['domain_name'],
            'authorization_user' => $row['extension'],
            'password' => $row['password'],
            'display_name' => $row['effective_caller_id_name'] ?: $row['extension'],
            'domain' => $context['domain_name'],
        ],
        'csrf' => ['name' => $nextToken['name'], 'hash' => $nextToken['hash']],
    ];
    unset($rows, $row);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    sn_voice_json(200, $payload);
} catch (Throwable $error) {
    // No exception details, database output or credentials enter the response or logs.
    sn_voice_json(503, ['ok' => false, 'error' => 'temporarily_unavailable']);
}
