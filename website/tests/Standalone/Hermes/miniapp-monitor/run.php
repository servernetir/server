<?php
/** Isolated monitor, actual installed auth and diagnostic tests; no Laravel boot or external I/O. */
declare(strict_types=1);

namespace Symfony\Component\HttpFoundation {
    class HeaderBag
    {
        private array $values = [];
        public function __construct(array $values = []) { foreach ($values as $k => $v) { $this->set($k, $v); } }
        public function get(string $key, mixed $default = null): mixed { return $this->values[strtolower($key)] ?? $default; }
        public function set(string $key, mixed $value): void { $this->values[strtolower($key)] = $value; }
    }
    class Response
    {
        public HeaderBag $headers;
        public function __construct(private int $status = 200) { $this->headers = new HeaderBag(); }
        public function getStatusCode(): int { return $this->status; }
        public function header(string $name, mixed $value): static { $this->headers->set($name, $value); return $this; }
    }
    class BinaryFileResponse extends Response
    {
        public function __construct(public string $path, array $headers) { parent::__construct(); $this->headers = new HeaderBag($headers); }
    }
}
namespace Illuminate\Http {
    class JsonResponse extends \Symfony\Component\HttpFoundation\Response
    {
        public function __construct(private mixed $data, int $status = 200) { parent::__construct($status); }
        public function getData(bool $assoc = false): mixed { return $this->data; }
        public function setData(mixed $data): self { $this->data = $data; return $this; }
    }
    class Request
    {
        public \Symfony\Component\HttpFoundation\HeaderBag $headers;
        public \Symfony\Component\HttpFoundation\HeaderBag $attributes;
        public function __construct(array $headers = [], private string $method = 'GET', private string $body = '{}', private string $requestPath = 'bale/hermes/errors')
        { $this->headers = new \Symfony\Component\HttpFoundation\HeaderBag($headers); $this->attributes = new \Symfony\Component\HttpFoundation\HeaderBag(); }
        public function header(string $key, mixed $default = null): mixed { return $this->headers->get($key, $default); }
        public function path(): string { return $this->requestPath; }
        public function isMethod(string $method): bool { return strtoupper($method) === $this->method; }
        public function isJson(): bool { return str_contains((string) $this->header('Content-Type'), 'application/json'); }
        public function getContent(): string { return $this->body; }
        public function input(string $key, mixed $default = null): mixed { $data = json_decode($this->body, true); return is_array($data) ? ($data[$key] ?? $default) : $default; }
    }
}
namespace Illuminate\Support\Facades {
    // Crypto implementation belongs to Laravel; this double permits testing the actual auth envelope checks only.
    class Crypt
    {
        public static function encryptString(string $value): string { return base64_encode($value); }
        public static function decryptString(string $value): string { $r = base64_decode($value, true); if ($r === false) { throw new \RuntimeException('invalid test token'); } return $r; }
    }
}
namespace App\Services\Bale\Admin {
    class AdminBaleGate
    {
        public ?array $value = ['chat_id' => '123', 'user_id' => 7, 'at' => 'test-binding'];
        public bool $active = true;
        public bool $user = true;
        public function binding(): ?array { return $this->value; }
        public function enabled(): bool { return $this->active; }
        public function boundUser(): mixed { return $this->user ? (object) ['id' => 7] : null; }
    }
}
namespace App\Http\Controllers { class Controller {} }
namespace App\Models {
    class Setting { public static array $values = []; public static function get(string $key): mixed { return self::$values[$key] ?? null; } }
}
namespace App\Support {
    class ErrorTracker { public static array $rows = []; public static function noteOnce(string $area, string $code, int $seconds, array $context = []): void { self::$rows[] = compact('area', 'code', 'context'); } }
}
namespace App\Services\Hermes {
    class HermesSupportState
    {
        public array $value = [];
        public bool $fail = false;
        public function status(): array { if ($this->fail) { throw new \RuntimeException('private-state-credential'); } return $this->value; }
    }
}
namespace {
    use App\Http\Controllers\HermesMiniAppMonitorController;
    use App\Http\Middleware\HermesMiniAppJson;
    use App\Models\Setting;
    use App\Services\Bale\Admin\AdminBaleGate;
    use App\Services\Bale\Admin\HermesMiniAppAuth;
    use App\Services\Hermes\HermesMiniAppErrors;
    use App\Services\Hermes\HermesSupportState;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Crypt;
    use Symfony\Component\HttpFoundation\BinaryFileResponse;
    use Symfony\Component\HttpFoundation\Response;

    function config(string $key): mixed { return $key === 'services.bale.token' ? 'offline-test-bale-token' : null; }
    function response(): object { return new class {
        public function json(mixed $data, int $status = 200): JsonResponse { return new JsonResponse($data, $status); }
        public function file(string $path, array $headers): BinaryFileResponse { return new BinaryFileResponse($path, $headers); }
    }; }
    function app(string $class): object { return $GLOBALS['supportState']; }
    function storage_path(string $path): string { return $GLOBALS['testRoot'].'/storage/'.$path; }
    function public_path(string $path): string { return $GLOBALS['testRoot'].'/app/public/'.$path; }
    function base_path(): string { return $GLOBALS['testRoot'].'/app'; }
    function check(bool $condition, string $message = 'assertion failed'): void { if (! $condition) { throw new RuntimeException($message); } }
    function signed(int $date = 0, int $userId = 123): string
    {
        $data = ['auth_date' => (string) ($date ?: time()), 'user' => json_encode(['id' => $userId], JSON_THROW_ON_ERROR)];
        ksort($data, SORT_STRING); $pairs = [];
        foreach ($data as $key => $value) { $pairs[] = $key.'='.$value; }
        $key = hash_hmac('sha256', config('services.bale.token'), 'WebAppData', true);
        $data['hash'] = hash_hmac('sha256', implode("\n", $pairs), $key);
        return http_build_query($data);
    }
    function request(?string $data = null, string $method = 'GET', string $contentType = 'application/json', string $body = '{}', array $headers = []): Request
    { return new Request($headers + ['X-Bale-Init-Data' => $data ?? signed(), 'Content-Type' => $contentType], $method, $body); }
    function jsonData(JsonResponse $response): array { return $response->getData(true); }

    $stage = $argv[1] ?? dirname(__DIR__, 4);
    $authFile = $argv[2] ?? $stage.'/app/Services/Bale/Admin/HermesMiniAppAuth.php';
    require $authFile;
    require $stage.'/app/Services/Hermes/HermesMiniAppErrors.php';
    require $stage.'/app/Http/Middleware/HermesMiniAppJson.php';
    require $stage.'/app/Http/Controllers/HermesMiniAppMonitorController.php';
    $testRoot = sys_get_temp_dir().'/hermes-monitor-tests-'.bin2hex(random_bytes(8));
    $gate = new AdminBaleGate(); $auth = new HermesMiniAppAuth($gate);
    $errors = new HermesMiniAppErrors($testRoot.'/errors');
    $middleware = new HermesMiniAppJson($auth, $errors);
    $controller = new HermesMiniAppMonitorController(); $supportState = new HermesSupportState();
    $tests = [];
    $tests['actual signed owner is required by middleware and controller; spoofed attributes fail'] = static function () use ($auth, $middleware, $controller, $errors): void {
        check($auth->owner(request())['chat_id'] === '123');
        $ran = false;
        $r = $middleware->handle(request(''), static function () use (&$ran): JsonResponse { $ran = true; return new JsonResponse(['ok' => true]); });
        check(! $ran && $r->getStatusCode() === 403 && jsonData($r)['code'] === 'owner_unauthorized');
        $req = request(''); $req->attributes->set('hermes_miniapp_owner', ['chat_id' => '123']);
        foreach (['errors', 'automation', 'clientError'] as $method) { check($controller->$method($req, $auth, $errors)->getStatusCode() === 403); }
    };
    $tests['expired future forged and wrong-user initial signatures remain rejected'] = static function () use ($auth): void {
        check($auth->owner(request(signed(time() - 601))) === null);
        check($auth->owner(request(signed(time() + 31))) === null);
        check($auth->owner(request(signed(userId: 456))) === null);
        $raw = preg_replace('/hash=[a-f0-9]+/', 'hash='.str_repeat('0', 64), signed());
        check($auth->owner(request($raw)) === null);
    };
    $tests['current absolute session accepts matching binding and rejects expiry wrong audience and excessive lifetime'] = static function () use ($auth, $gate): void {
        $issued = $auth->issue($gate->value); $token = $issued['token'];
        check($auth->owner(request('', headers: ['X-Hermes-Session' => $token]))['user_id'] === 7);
        $session = json_decode(Crypt::decryptString($token), true);
        foreach ([['exp' => time() - 1], ['aud' => 'other'], ['iat' => time() + 30], ['exp' => time() + HermesMiniAppAuth::SESSION_SECONDS + 100]] as $changes) {
            $bad = Crypt::encryptString(json_encode(array_replace($session, $changes), JSON_THROW_ON_ERROR));
            check($auth->owner(request('', headers: ['X-Hermes-Session' => $bad])) === null);
        }
        $gate->value['at'] = 'changed-binding';
        check($auth->owner(request('', headers: ['X-Hermes-Session' => $token])) === null);
        $gate->value['at'] = 'test-binding';
    };
    $tests['owner revocation is checked every request including an existing session'] = static function () use ($gate, $auth): void {
        $token = $auth->issue($gate->value)['token']; $req = request('', headers: ['X-Hermes-Session' => $token]);
        $gate->active = false; check($auth->owner($req) === null); $gate->active = true;
        $gate->user = false; check($auth->owner($req) === null); $gate->user = true;
    };
    $tests['non-JSON malformed non-object and excessive diagnostic bodies cannot reach handler'] = static function () use ($middleware): void {
        $never = static fn (): Response => throw new RuntimeException('Handler must not run');
        foreach ([['text/html', '{}'], ['application/json', '{'], ['application/json', '[]'], ['application/json', 'true'], ['application/json', str_repeat('x', 32769)]] as [$type, $body]) {
            check($middleware->handle(request(method: 'POST', contentType: $type, body: $body), $never)->getStatusCode() === 422);
        }
    };
    $tests['HTML and pipeline-rendered JSON errors normalize without raw errors'] = static function () use ($middleware): void {
        $r = $middleware->handle(request(), static fn (): Response => new Response(200));
        check($r instanceof JsonResponse && $r->getStatusCode() === 503 && jsonData($r)['ok'] === false);
        foreach ([429 => 'throttled', 422 => 'validation_failed', 404 => 'request_not_found', 403 => 'owner_unauthorized'] as $status => $code) {
            $r = $middleware->handle(request(), static fn (): JsonResponse => new JsonResponse(['message' => 'private raw credential'], $status));
            check($r->getStatusCode() === $status && jsonData($r)['code'] === $code);
            check(! str_contains(json_encode(jsonData($r)), 'private raw'));
        }
        $r = $middleware->handle(request(), static fn (): JsonResponse => throw new RuntimeException('private raw credential'));
        check($r->getStatusCode() === 503 && ! str_contains(json_encode(jsonData($r)), 'private raw'));
    };
    $tests['success has server correlation and private no-store headers'] = static function () use ($middleware): void {
        $req = request();
        $r = $middleware->handle($req, static function (Request $request): JsonResponse {
            check($request->header('Accept') === 'application/json');
            return new JsonResponse(['ok' => true, 'request_id' => 'untrusted-id']);
        });
        check(preg_match('/^[a-f0-9]{32}$/D', jsonData($r)['request_id']) === 1);
        check($r->headers->get('X-Request-Id') === jsonData($r)['request_id']);
        check($r->headers->get('Cache-Control') === 'private, no-store' && $r->headers->get('X-Robots-Tag') === 'noindex, nofollow');
    };
    $tests['client diagnostics retain only safe grouped metadata'] = static function () use ($controller, $auth, $errors, $testRoot): void {
        $payload = ['code' => 'non_json_response', 'operation' => '/bale/hermes/errors?secret=private-raw-token', 'status' => 502,
            'content_type' => 'text/html; credential=private-raw-token', 'request_id' => 'private-raw-token',
            'html' => '<html>private-raw-token</html>', 'text' => 'private-raw-token'];
        $r = $controller->clientError(request(method: 'POST', body: json_encode($payload)), $auth, $errors);
        check($r->getStatusCode() === 202 && jsonData($r)['ok'] === true);
        $stored = file_get_contents($testRoot.'/errors/errors.json');
        check(! str_contains($stored, 'private-raw-token') && ! str_contains($stored, '<html>'));
        $rows = $errors->recent(400); $found = array_values(array_filter($rows, static fn (array $row): bool => $row['code'] === 'client_non_json'));
        check(count($found) === 1 && $found[0]['operation'] === 'errors' && $found[0]['content_type'] === 'text/html');
    };
    $tests['private logs are grouped bounded to400 and have restrictive modes'] = static function () use ($testRoot): void {
        $log = new HermesMiniAppErrors($testRoot.'/bounded');
        $id = str_repeat('a', 32);
        $log->record('client_http', 'errors', 502, 'application/json', $id);
        $log->record('client_http', 'errors', 502, 'application/json', $id);
        check($log->recent()[0]['count'] === 2);
        for ($i = 0; $i < 405; $i++) { $log->record('client_http', 'errors', 502, 'application/x-test-'.$i, $id); }
        check(count($log->recent(400)) === 400);
        check((fileperms($testRoot.'/bounded') & 0777) === 0700 && (fileperms($testRoot.'/bounded/errors.json') & 0777) === 0600);
        $stored = file_get_contents($testRoot.'/bounded/errors.json');
        file_put_contents($testRoot.'/bounded/errors.json', '{broken');
        try { $log->recent(); check(false, 'corrupt state must not pass silently'); } catch (JsonException) {}
        $log->record('client_http', 'errors', 502, 'application/json', $id);
        check(file_get_contents($testRoot.'/bounded/errors.json') === '{broken');
    };
    $tests['owner errors combine safe summaries only and never support context or draft'] = static function () use ($controller, $auth, $errors, $supportState): void {
        $supportState->value = ['errors' => [['code' => 'worker_interrupted', 'last_at' => time(), 'count' => 3,
            'request_id' => str_repeat('b', 24), 'context' => ['secret' => 'private-support-text'], 'draft' => 'private-support-text']]];
        $r = $controller->errors(request(), $auth, $errors); $data = jsonData($r);
        check($r->getStatusCode() === 200 && $data['ok'] === true && count($data['errors']) >= 1);
        check(! str_contains(json_encode($data), 'private-support-text'));
        foreach ($data['errors'] as $row) { check(count(array_diff(array_keys($row), ['utc', 'code', 'message', 'count', 'request_id'])) === 0); }
        check(count(array_filter($data['errors'], static fn (array $row): bool => $row['code'] === 'support_worker_interrupted')) === 1);
    };
    $tests['automation emits only aggregate counters and fixed schedule metadata'] = static function () use ($controller, $auth, $errors, $supportState): void {
        Setting::$values = ['hermes_support_enabled' => '1', 'hermes_support_auto_send' => '0'];
        $supportState->value = ['last_run_at' => time() - 10, 'last_success_at' => time() - 20, 'last_error_review_at' => time() - 10,
            'counts' => ['sent' => 2, 'sent_notification_error' => 1, 'retry' => 3, 'private-support-text' => 4],
            'recent_runs' => [['scanned' => 5, 'draft' => 'private-support-text']], 'items' => ['private-support-text']];
        $r = $controller->automation(request(), $auth, $errors); $data = jsonData($r); $a = $data['automation'];
        check($a['enabled'] && ! $a['auto_send'] && $a['interval_minutes'] === 60 && $a['checked_tickets'] === 5 && $a['sent_replies'] === 3);
        check($a['next_run_at'] > time() && $a['next_run_at'] % 3600 === 0);
        check(! str_contains(json_encode($data), 'private-support-text'));
        Setting::$values = []; check(jsonData($controller->automation(request(), $auth, $errors))['automation']['next_run_at'] === null);
    };
    $tests['unavailable support state becomes safe503'] = static function () use ($controller, $auth, $errors, $supportState): void {
        $supportState->fail = true;
        foreach (['automation', 'errors'] as $method) {
            $r = $controller->$method(request(), $auth, $errors);
            check($r->getStatusCode() === 503 && jsonData($r)['code'] === 'state_unavailable');
            check(! str_contains(json_encode(jsonData($r)), 'private-state-credential'));
        }
        $supportState->fail = false;
    };
    $tests['font whitelist serves existing regular medium bold without arbitrary paths'] = static function () use ($controller, $testRoot): void {
        $path = $testRoot.'/app/public/assets/font/woff2'; mkdir($path, 0700, true);
        foreach (['regular' => 'IRANSans-web.woff2', 'medium' => 'IRANSans-Medium-web.woff2', 'bold' => 'IRANSans-Bold-web.woff2'] as $weight => $name) {
            file_put_contents($path.'/'.$name, 'test-font-'.$weight);
            $r = $controller->font(request(''), 'IRANSans-'.$weight.'.woff2');
            check($r instanceof BinaryFileResponse && $r->path === $path.'/'.$name && $r->headers->get('Content-Type') === 'font/woff2');
        }
        foreach (['../../.env', 'IRANSans-web.woff2', 'other.woff2'] as $name) { check($controller->font(request(''), $name)->getStatusCode() === 404); }
    };
    $tests['font fallback uses sibling public_html without creating a bale directory'] = static function () use ($controller, $testRoot): void {
        unlink($testRoot.'/app/public/assets/font/woff2/IRANSans-web.woff2');
        $path = $testRoot.'/public_html/assets/font/woff2'; mkdir($path, 0700, true); file_put_contents($path.'/IRANSans-web.woff2', 'test-font-fallback');
        $r = $controller->font(request(''), 'IRANSans-regular.woff2');
        check($r instanceof BinaryFileResponse && $r->path === $path.'/IRANSans-web.woff2');
        check(! is_dir($testRoot.'/public_html/bale'));
    };
    $tests['additive routes declare only font diagnostics errors automation and no catchall'] = static function () use ($stage): void {
        $source = file_get_contents($stage.'/routes/hermes-monitor.php');
        check(substr_count($source, 'Route::get(') === 3 && substr_count($source, 'Route::post(') === 1);
        foreach (["'/chat'", "'/session'", "'/state'", "'/memory'", "Route::any", "'/{path}'"] as $excluded) { check(! str_contains($source, $excluded)); }
        check(str_contains($source, 'HermesMiniAppJson::class') && str_contains($source, 'withoutMiddleware([PreventRequestForgery::class])'));
    };
    $results = [];
    foreach ($tests as $name => $test) {
        try { $test(); $results[] = ['name' => $name, 'ok' => true]; }
        catch (Throwable $error) { $results[] = ['name' => $name, 'ok' => false, 'error' => $error->getMessage()]; }
    }
    $failed = count(array_filter($results, static fn (array $r): bool => ! $r['ok']));
    echo json_encode(['tests' => count($results), 'failed' => $failed, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
    exit($failed > 0 ? 1 : 0);
}
