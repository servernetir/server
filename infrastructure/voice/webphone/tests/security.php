<?php
declare(strict_types=1);

/* Isolated fake FusionPBX fixture. Uses no application config, live data or network. */
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "FAIL: pdo_sqlite is required for the isolated SQL authorization fixture.\n");
    exit(1);
}

$source = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/sn-voice-fixture-' . bin2hex(random_bytes(8));
$app = $fixture . '/app/servernet_voice';
mkdir($app, 0700, true);
mkdir($fixture . '/resources', 0700, true);

$native = <<<'PHP'
<?php
declare(strict_types=1);

const FIXTURE_DOMAIN = '11111111-1111-4111-8111-111111111111';
const FIXTURE_OTHER_DOMAIN = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
const FIXTURE_USER = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const FIXTURE_OTHER_USER = '77777777-7777-4777-8777-777777777777';
const FIXTURE_EXT = '99999999-9999-4999-8999-999999999999';
const FIXTURE_OTHER_EXT = '22222222-2222-4222-8222-222222222222';
const FIXTURE_FOREIGN_EXT = '33333333-3333-4333-8333-333333333333';
const FIXTURE_SECRET = 'fictional-owner-fixture-secret';

final class FixtureDatabase {
    public static int $selects = 0;
    public static int $secretSelects = 0;
    private PDO $pdo;

    public function __construct() {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->sqliteCreateFunction('regexp', static fn($pattern, $value) => preg_match('~' . str_replace('~', '\\~', (string)$pattern) . '~D', (string)$value) === 1, 2);
        $this->pdo->exec('CREATE TABLE v_domains (domain_uuid TEXT, domain_name TEXT, domain_enabled BOOLEAN);
            CREATE TABLE v_users (user_uuid TEXT, domain_uuid TEXT, user_enabled BOOLEAN);
            CREATE TABLE v_extensions (extension_uuid TEXT, domain_uuid TEXT, extension TEXT, effective_caller_id_name TEXT, password TEXT, enabled BOOLEAN, extension_type TEXT, user_context TEXT);
            CREATE TABLE v_extension_users (extension_uuid TEXT, domain_uuid TEXT, user_uuid TEXT);');
        $domains = $this->pdo->prepare('INSERT INTO v_domains VALUES (?, ?, ?)');
        $domains->execute([FIXTURE_DOMAIN, 'pbx.servernet.cloud', 1]);
        $domains->execute([FIXTURE_OTHER_DOMAIN, 'other.example.test', 1]);
        $users = $this->pdo->prepare('INSERT INTO v_users VALUES (?, ?, ?)');
        $users->execute([FIXTURE_USER, FIXTURE_DOMAIN, 1]);
        $users->execute([FIXTURE_OTHER_USER, FIXTURE_DOMAIN, 1]);
        $extensions = $this->pdo->prepare('INSERT INTO v_extensions VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $extensions->execute([FIXTURE_EXT, FIXTURE_DOMAIN, '1001', 'Owner <script>fixture</script>', FIXTURE_SECRET, 1, 'default', 'pbx.servernet.cloud']);
        $extensions->execute([FIXTURE_OTHER_EXT, FIXTURE_DOMAIN, '2001', 'Other user', 'fictional-other-fixture-secret', 1, 'default', 'pbx.servernet.cloud']);
        $extensions->execute([FIXTURE_FOREIGN_EXT, FIXTURE_OTHER_DOMAIN, '3001', 'Other tenant', 'fictional-foreign-fixture-secret', 1, 'default', 'other.example.test']);
        $links = $this->pdo->prepare('INSERT INTO v_extension_users VALUES (?, ?, ?)');
        $links->execute([FIXTURE_EXT, FIXTURE_DOMAIN, FIXTURE_USER]);
        $links->execute([FIXTURE_OTHER_EXT, FIXTURE_DOMAIN, FIXTURE_OTHER_USER]);
        $links->execute([FIXTURE_FOREIGN_EXT, FIXTURE_OTHER_DOMAIN, FIXTURE_USER]);
        $case = getenv('SN_VOICE_CASE');
        if ($case === 'disabled_domain') $this->pdo->exec('UPDATE v_domains SET domain_enabled = 0');
        if ($case === 'disabled_user') $this->pdo->exec('UPDATE v_users SET user_enabled = 0');
        if ($case === 'disabled_extension') $this->pdo->exec('UPDATE v_extensions SET enabled = 0');
        if ($case === 'virtual_extension') $this->pdo->exec("UPDATE v_extensions SET extension_type = 'virtual'");
        if ($case === 'wrong_context') $this->pdo->exec("UPDATE v_extensions SET user_context = 'other.example.test'");
        if ($case === 'nonnumeric_extension') $this->pdo->exec("UPDATE v_extensions SET extension = '1001@other.example.test'");
        if ($case === 'mismatched_link_tenant') $this->pdo->exec("UPDATE v_extension_users SET domain_uuid = '" . FIXTURE_OTHER_DOMAIN . "' WHERE extension_uuid = '" . FIXTURE_EXT . "'");
        if ($case === 'empty_secret') $this->pdo->exec("UPDATE v_extensions SET password = ''");
    }

    public function select(string $sql, array $parameters = [], string $type = 'all'): mixed {
        self::$selects++;
        if (preg_match('/\be\.password\b/i', $sql)) self::$secretSelects++;
        if (getenv('SN_VOICE_CASE') === 'sql_exception') throw new RuntimeException('fictional-secret-exception-marker');
        // PostgreSQL's regex operator is the only dialect adaptation. The real WHERE/JOIN SQL executes.
        $sql = str_replace(' ~ ', ' REGEXP ', $sql);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return match ($type) {
            'row' => $rows[0] ?? [],
            'column' => isset($rows[0]) ? reset($rows[0]) : null,
            default => $rows,
        };
    }
}

function permission_exists(string $permission): bool {
    return !((getenv('SN_VOICE_CASE') === 'no_view' && $permission === 'extension_view')
        || (getenv('SN_VOICE_CASE') === 'no_password_permission' && $permission === 'extension_password'));
}
function escape(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
final class token {
    public function create(string $key): array {
        $token = ['name' => 'fixture_token', 'hash' => str_repeat('c', 64), 'validated' => false];
        $_SESSION['tokens'][$key][] = $token;
        return $token;
    }
    public function validate(string $key, string $value = ''): bool {
        return $key === '/app/servernet_voice/api.php'
            && hash_equals(str_repeat('c', 64), (string)($_REQUEST['fixture_token'] ?? ''));
    }
}
$_SESSION = [
    'authorized' => getenv('SN_VOICE_CASE') !== 'anonymous',
    'domain_uuid' => FIXTURE_DOMAIN, 'domain_name' => 'pbx.servernet.cloud',
    'user_uuid' => FIXTURE_USER, 'username' => 'admin', 'language' => ['code' => 'fa-ir'],
    'tokens' => ['/app/servernet_voice/api.php' => [['name' => 'fixture_token', 'hash' => str_repeat('c', 64), 'validated' => false]]],
];
if (getenv('SN_VOICE_CASE') === 'malformed_domain') $_SESSION['domain_name'] = 'pbx.servernet.cloud@evil.example';
$database = new FixtureDatabase;
PHP;

$request = <<<'PHP'
<?php
declare(strict_types=1);
$case = getenv('SN_VOICE_CASE');
$root = __DIR__;
ini_set('error_log', $root . '/audit.log');
$_SERVER = [
    'REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'https://pbx.servernet.cloud',
    'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_HOST' => 'pbx.servernet.cloud',
    'SERVER_NAME' => 'pbx.servernet.cloud', 'SERVER_PORT' => '443', 'HTTPS' => 'on',
    'PHP_SELF' => '/app/servernet_voice/api.php', 'SCRIPT_NAME' => '/app/servernet_voice/api.php',
    'REMOTE_ADDR' => '127.0.0.1',
];
$_GET = [];
$_POST = ['action' => 'connect', 'extension_uuid' => '99999999-9999-4999-8999-999999999999', 'fixture_token' => str_repeat('c', 64)];
if ($case === 'get_api' || str_starts_with($case, 'index_')) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['action' => 'connect', 'extension_uuid' => $_POST['extension_uuid']];
    $_POST = [];
}
if (str_starts_with($case, 'index_')) {
    $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'] = '/app/servernet_voice/index.php';
    $_GET['lang'] = substr($case, 6);
}
if ($case === 'wrong_origin') $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
if ($case === 'origin_suffix') $_SERVER['HTTP_ORIGIN'] = 'https://pbx.servernet.cloud.evil.example';
if ($case === 'missing_origin') unset($_SERVER['HTTP_ORIGIN']);
if ($case === 'cross_site') $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
if ($case === 'bad_token') $_POST['fixture_token'] = str_repeat('d', 64);
if ($case === 'missing_token') unset($_POST['fixture_token']);
if ($case === 'query_only_token') {
    $_GET['fixture_token'] = $_POST['fixture_token'];
    unset($_POST['fixture_token']);
}
if ($case === 'bad_uuid') $_POST['extension_uuid'] = "' OR 1=1 --";
if ($case === 'array_uuid') $_POST['extension_uuid'] = ['99999999-9999-4999-8999-999999999999'];
if ($case === 'other_user') $_POST['extension_uuid'] = '22222222-2222-4222-8222-222222222222';
if ($case === 'other_tenant') $_POST['extension_uuid'] = '33333333-3333-4333-8333-333333333333';
$_REQUEST = array_merge($_GET, $_POST);
register_shutdown_function(static function () use ($root): void {
    file_put_contents($root . '/trace.json', json_encode([
        'status' => http_response_code() ?: 200,
        'selects' => class_exists('FixtureDatabase', false) ? FixtureDatabase::$selects : 0,
        'secret_selects' => class_exists('FixtureDatabase', false) ? FixtureDatabase::$secretSelects : 0,
    ], JSON_THROW_ON_ERROR));
});
require $root . '/app/servernet_voice/' . (str_starts_with($case, 'index_') ? 'index.php' : 'api.php');
PHP;

function remove_fixture(string $path): void {
    if (!is_dir($path)) return;
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        $entry->isDir() && !$entry->isLink() ? remove_fixture($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

$cases = [
    'happy' => [200, 1], 'anonymous' => [401, 0], 'native_revoked' => [403, 0],
    'no_view' => [403, 0], 'no_password_permission' => [403, 0], 'get_api' => [405, 0],
    'wrong_origin' => [403, 0], 'origin_suffix' => [403, 0], 'missing_origin' => [403, 0],
    'cross_site' => [403, 0], 'bad_token' => [403, 0], 'missing_token' => [403, 0],
    'query_only_token' => [403, 0],
    'bad_uuid' => [400, 0], 'array_uuid' => [400, 0], 'malformed_domain' => [403, 0],
    'other_user' => [403, 1], 'other_tenant' => [403, 1], 'disabled_user' => [403, 1],
    'disabled_domain' => [403, 1], 'disabled_extension' => [403, 1],
    'virtual_extension' => [403, 1], 'wrong_context' => [403, 1],
    'nonnumeric_extension' => [403, 1], 'mismatched_link_tenant' => [403, 1],
    'empty_secret' => [409, 1], 'sql_exception' => [503, 1],
    'index_fa' => [200, 0], 'index_en' => [200, 0], 'index_tr' => [200, 0],
];

$failed = 0;
try {
    foreach (['bootstrap.php', 'api.php', 'index.php'] as $file) {
        if (!is_file($source . '/' . $file)) throw new RuntimeException('Application source is incomplete');
        copy($source . '/' . $file, $app . '/' . $file);
    }
    file_put_contents($fixture . '/resources/require.php', $native);
    file_put_contents($fixture . '/resources/check_auth.php', '<?php if (getenv("SN_VOICE_CASE") === "native_revoked") $_SESSION["authorized"] = false;');
    file_put_contents($fixture . '/request.php', $request);
    foreach ($cases as $case => [$expectedStatus, $expectedSecretSelects]) {
        @unlink($fixture . '/trace.json');
        @unlink($fixture . '/audit.log');
        $pipes = [];
        $process = proc_open([PHP_BINARY, $fixture . '/request.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $fixture, ['PATH' => '/usr/bin:/bin', 'SN_VOICE_CASE' => $case]);
        if (!is_resource($process)) throw new RuntimeException('Unable to start isolated PHP request');
        fclose($pipes[0]);
        $body = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        $trace = is_file($fixture . '/trace.json') ? json_decode(file_get_contents($fixture . '/trace.json'), true, 512, JSON_THROW_ON_ERROR) : [];
        $audit = is_file($fixture . '/audit.log') ? file_get_contents($fixture . '/audit.log') : '';
        $ok = $exit === 0 && $stderr === '' && ($trace['status'] ?? null) === $expectedStatus && ($trace['secret_selects'] ?? null) === $expectedSecretSelects;
        $ok = $ok && !str_contains($audit, 'fictional-owner-fixture-secret')
            && !str_contains($audit, 'fictional-other-fixture-secret')
            && !str_contains($audit, 'fictional-foreign-fixture-secret')
            && !str_contains($audit, 'fictional-secret-exception-marker');
        if ($case === 'happy') {
            $json = json_decode($body, true);
            $ok = $ok && ($json['ok'] ?? false) === true
                && ($json['sip']['password'] ?? null) === 'fictional-owner-fixture-secret'
                && ($json['sip']['uri'] ?? null) === 'sip:1001@pbx.servernet.cloud';
            $auditMatch = [];
            $ok = $ok && preg_match('/servernet_voice_connect (\{[^\r\n]+\})/', $audit, $auditMatch) === 1;
            $auditPayload = isset($auditMatch[1]) ? json_decode($auditMatch[1], true) : null;
            $ok = $ok && $auditPayload === [
                'user_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'domain_uuid' => '11111111-1111-4111-8111-111111111111',
                'extension_uuid' => '99999999-9999-4999-8999-999999999999',
            ];
        } else {
            $ok = $ok && $audit === '';
            $ok = $ok && !str_contains($body, 'fictional-owner-fixture-secret')
                && !str_contains($body, 'fictional-other-fixture-secret')
                && !str_contains($body, 'fictional-foreign-fixture-secret')
                && !str_contains($body, 'fictional-secret-exception-marker');
        }
        if (str_starts_with($case, 'index_')) {
            $ok = $ok && str_contains($body, '1001') && !str_contains($body, '>2001<') && !str_contains($body, '>3001<')
                && !str_contains($body, 'Owner <script>fixture</script>');
        }
        if ($case === 'get_api') $ok = $ok && ($trace['selects'] ?? null) === 0;
        echo ($ok ? 'PASS ' : 'FAIL ') . $case . "\n";
        if (!$ok) {
            $failed++;
            // Deliberately omit request/response/stderr bodies: only safe test metadata leaves the fixture.
            echo '  exit=' . $exit . ' status=' . ($trace['status'] ?? 'missing') . ' secret_selects=' . ($trace['secret_selects'] ?? 'missing') . ' stderr_present=' . ($stderr !== '' ? 'yes' : 'no') . "\n";
        }
    }
} catch (Throwable $error) {
    $failed++;
    fwrite(STDERR, "FAIL: isolated fixture could not complete. No application response or credentials are printed.\n");
} finally {
    remove_fixture($fixture);
}
echo count($cases) . ' cases, ' . $failed . " failures\n";
exit($failed === 0 ? 0 : 1);
