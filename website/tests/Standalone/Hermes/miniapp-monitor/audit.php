<?php
/** Isolated observer tests: existing API responses, delivery and thrown exceptions remain unchanged. */
declare(strict_types=1);
namespace Symfony\Component\HttpFoundation {
    class HeaderBag {
        private array $items = [];
        public function get(string $key, mixed $default = null): mixed { return $this->items[strtolower($key)] ?? $default; }
        public function set(string $key, mixed $value): void { $this->items[strtolower($key)] = $value; }
    }
    class Response {
        public HeaderBag $headers;
        public function __construct(private int $status = 200) { $this->headers = new HeaderBag(); }
        public function getStatusCode(): int { return $this->status; }
    }
}
namespace Illuminate\Http {
    class JsonResponse extends \Symfony\Component\HttpFoundation\Response {
        public function __construct(private mixed $data, int $status = 200) { parent::__construct($status); }
        public function getData(bool $assoc = false): mixed { return $this->data; }
    }
    class Request {
        public \Symfony\Component\HttpFoundation\HeaderBag $attributes;
        public function __construct(private string $path = 'bale/hermes/chat') { $this->attributes = new \Symfony\Component\HttpFoundation\HeaderBag(); }
        public function path(): string { return $this->path; }
        public function getContent(): never { throw new \RuntimeException('Observer must not read request content'); }
        public function input(string $key): never { throw new \RuntimeException('Observer must not read request input'); }
        public function header(string $key): never { throw new \RuntimeException('Observer must not read authentication or provider headers'); }
    }
}
namespace Illuminate\Support\Facades {
    class Cache {
        public static array $items = [];
        public static bool $fail = false;
        public static function add(string $key, mixed $value, int $seconds): bool {
            if (self::$fail) { throw new \RuntimeException('private-cache-detail'); }
            if (isset(self::$items[$key])) { return false; }
            self::$items[$key] = ['value' => $value, 'ttl' => $seconds]; return true;
        }
    }
}
namespace App\Support {
    class ErrorTracker { public static array $items = []; public static function noteOnce(string $area, string $what, int $seconds, array $ctx = []): void { self::$items[] = compact('area', 'what', 'ctx'); } }
}
namespace {
    use App\Http\Middleware\HermesMiniAppAudit;
    use App\Services\Hermes\HermesMiniAppErrors;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Cache;
    use Symfony\Component\HttpFoundation\Response;
    function check(bool $condition, string $message = 'assertion failed'): void { if (! $condition) { throw new RuntimeException($message); } }
    $stage = $argv[1] ?? dirname(__DIR__, 4);
    require $stage.'/app/Services/Hermes/HermesMiniAppErrors.php';
    require $stage.'/app/Http/Middleware/HermesMiniAppAudit.php';
    $testRoot = sys_get_temp_dir().'/hermes-audit-tests-'.bin2hex(random_bytes(8));
    $tests = [];
    $tests['error responses retain identity body headers and status; only mapped safe metadata is logged'] = static function () use ($testRoot): void {
        $errors = new HermesMiniAppErrors($testRoot.'/mapped'); $audit = new HermesMiniAppAudit($errors);
        foreach ([401 => ['session_expired', 'owner_unauthorized'], 503 => ['storage_unavailable', 'state_unavailable'], 409 => ['request_pending', 'busy'], 422 => ['private-error-secret', 'validation_failed']] as $status => [$code, $safe]) {
            $data = ['ok' => false, 'code' => $code, 'error' => 'private-response-secret']; $r = new JsonResponse($data, $status); $r->headers->set('X-Existing', 'preserved');
            $out = $audit->handle(new Request(), static fn (): Response => $r);
            check($out === $r && $out->getData(true) === $data && $out->getStatusCode() === $status && $out->headers->get('X-Existing') === 'preserved');
            check(count(array_filter($errors->recent(400), static fn (array $row): bool => $row['code'] === $safe)) >= 1);
        }
        $stored = file_get_contents($testRoot.'/mapped/errors.json');
        check(! str_contains($stored, 'private-error-secret') && ! str_contains($stored, 'private-response-secret'));
    };
    $tests['the exact downstream exception is rethrown after safe logging'] = static function () use ($testRoot): void {
        $errors = new HermesMiniAppErrors($testRoot.'/throw'); $audit = new HermesMiniAppAudit($errors);
        $exception = new class('private-thrown-secret') extends RuntimeException { public function getStatusCode(): int { return 429; } };
        try { $audit->handle(new Request(), static fn (): Response => throw $exception); check(false); }
        catch (Throwable $seen) { check($seen === $exception); }
        check($errors->recent()[0]['code'] === 'throttled');
        check(! str_contains(file_get_contents($testRoot.'/throw/errors.json'), 'private-thrown-secret'));
    };
    $tests['failed and uncertain persisted UUIDs are coalesced without reading or logging chat text'] = static function () use ($testRoot): void {
        Cache::$items = []; $errors = new HermesMiniAppErrors($testRoot.'/delivery'); $audit = new HermesMiniAppAudit($errors);
        $id = '12345678-abcd-1234-5678-123456789abc';
        $data = ['ok' => true, 'delivery' => 'uncertain', 'state' => ['messages' => [
            ['id' => $id, 'role' => 'user', 'status' => 'uncertain', 'text' => 'private-chat-text'],
            ['id' => '87654321-abcd-1234-5678-123456789abc', 'role' => 'assistant', 'status' => 'failed', 'text' => 'private-assistant-text'],
        ]]];
        $r = new JsonResponse($data);
        for ($i = 0; $i < 3; $i++) { check($audit->handle(new Request(), static fn (): Response => $r) === $r); }
        $failedData = $data; $failedData['delivery'] = 'failed'; $failedData['state']['messages'][0]['status'] = 'failed';
        $failedResponse = new JsonResponse($failedData); check($audit->handle(new Request(), static fn (): Response => $failedResponse) === $failedResponse);
        $rows = $errors->recent(); check(count($rows) === 1 && $rows[0]['code'] === 'agent_failed' && $rows[0]['count'] === 1);
        $stored = file_get_contents($testRoot.'/delivery/errors.json');
        check(! str_contains($stored, $id) && ! str_contains($stored, 'private-chat-text') && ! str_contains($stored, 'private-assistant-text'));
        check(count(Cache::$items) === 1);
        foreach (Cache::$items as $key => $value) { check(preg_match('/^hermes-miniapp-incident:[a-f0-9]{64}$/D', $key) === 1 && $value['ttl'] === 86400); }
        check($r->getData(true) === $data);
    };
    $tests['normal success pending and monitoring failures never change existing API outcomes'] = static function () use ($testRoot): void {
        $errors = new HermesMiniAppErrors($testRoot.'/normal'); $audit = new HermesMiniAppAudit($errors);
        foreach (['done', 'pending'] as $status) {
            $r = new JsonResponse(['ok' => true, 'delivery' => $status, 'state' => ['messages' => [['role' => 'user', 'status' => $status, 'text' => 'private-text']]]]);
            check($audit->handle(new Request(), static fn (): Response => $r) === $r);
        }
        check($errors->recent() === []);
        Cache::$fail = true;
        $r = new JsonResponse(['ok' => true, 'delivery' => 'uncertain']); check($audit->handle(new Request(), static fn (): Response => $r) === $r);
        Cache::$fail = false;
        mkdir($testRoot.'/blocked', 0700); file_put_contents($testRoot.'/blocked/errors.json', '{corrupt'); $broken = new HermesMiniAppAudit(new HermesMiniAppErrors($testRoot.'/blocked'));
        $r = new JsonResponse(['ok' => false, 'code' => 'storage_unavailable'], 503);
        check($broken->handle(new Request(), static fn (): Response => $r) === $r);
        $plain = new Response(503); check($audit->handle(new Request(), static fn (): Response => $plain) === $plain);
    };
    $results = [];
    foreach ($tests as $name => $test) {
        try { $test(); $results[] = ['name' => $name, 'ok' => true]; }
        catch (Throwable $error) { $results[] = ['name' => $name, 'ok' => false, 'error' => $error->getMessage()]; }
    }
    $failed = count(array_filter($results, static fn (array $r): bool => ! $r['ok']));
    echo json_encode(['tests' => count($results), 'failed' => $failed, 'results' => $results], JSON_PRETTY_PRINT).PHP_EOL;
    exit($failed > 0 ? 1 : 0);
}
