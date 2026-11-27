<?php
declare(strict_types=1);

/* Installed as /app/servernet_voice inside an existing FusionPBX 5.6 tree. */
const SN_VOICE_TOKEN_KEY = '/app/servernet_voice/api.php';
const SN_VOICE_ORIGIN = 'https://pbx.servernet.cloud';

function sn_voice_headers(): void {
    header('Cache-Control: no-store, private, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: microphone=(self), camera=(), display-capture=()');
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self' wss://pbx.servernet.cloud; media-src 'self' blob:; img-src 'self' data:; base-uri 'none'; object-src 'none'; frame-ancestors 'self'; form-action 'self'");
}

function sn_voice_json(int $status, array $payload): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function sn_voice_uuid(mixed $value): bool {
    return is_string($value) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value) === 1;
}

function sn_voice_bootstrap(bool $api = false): array {
    global $database;
    sn_voice_headers();
    require_once dirname(__DIR__, 2) . '/resources/require.php';
    if ($api && empty($_SESSION['authorized'])) {
        sn_voice_json(401, ['ok' => false, 'error' => 'authentication_required']);
    }
    require_once dirname(__DIR__, 2) . '/resources/check_auth.php';
    if (empty($_SESSION['authorized']) || !permission_exists('extension_view') || !permission_exists('extension_password')) {
        if ($api) sn_voice_json(403, ['ok' => false, 'error' => 'access_denied']);
        http_response_code(403);
        exit('Access denied');
    }
    $domainUuid = $_SESSION['domain_uuid'] ?? null;
    $userUuid = $_SESSION['user_uuid'] ?? null;
    $domain = $_SESSION['domain_name'] ?? null;
    if (!sn_voice_uuid($domainUuid) || !sn_voice_uuid($userUuid) || !is_string($domain)
        || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/iD', $domain) !== 1) {
        if ($api) sn_voice_json(403, ['ok' => false, 'error' => 'access_denied']);
        http_response_code(403);
        exit('Access denied');
    }
    return ['domain_uuid' => $domainUuid, 'user_uuid' => $userUuid, 'domain_name' => $domain];
}

/* Both listing and Connect enforce current database state, without an administrator bypass. */
function sn_voice_owned_extensions(array $context, ?string $extensionUuid = null): array {
    global $database;
    $columns = 'e.extension_uuid, e.extension, e.effective_caller_id_name';
    if ($extensionUuid !== null) $columns .= ', e.password';
    $sql = "SELECT DISTINCT $columns FROM v_extensions AS e
        JOIN v_extension_users AS eu ON eu.extension_uuid = e.extension_uuid AND eu.domain_uuid = e.domain_uuid
        JOIN v_users AS u ON u.user_uuid = eu.user_uuid AND u.domain_uuid = e.domain_uuid
        JOIN v_domains AS d ON d.domain_uuid = e.domain_uuid
        WHERE e.domain_uuid = :domain_uuid AND eu.user_uuid = :user_uuid
        AND d.domain_name = :domain_name AND d.domain_enabled = true
        AND u.user_enabled = true AND e.enabled = true
        AND (e.extension_type IS NULL OR e.extension_type <> 'virtual')
        AND e.extension ~ '^[0-9]{2,16}$' AND e.user_context = :domain_name";
    $parameters = $context;
    if ($extensionUuid !== null) {
        $sql .= ' AND e.extension_uuid = :extension_uuid';
        $parameters['extension_uuid'] = $extensionUuid;
    }
    $sql .= ' ORDER BY e.extension';
    return $database->select($sql, $parameters, 'all') ?: [];
}
