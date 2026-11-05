<?php
declare(strict_types=1);

// Real ArvanClient createServer() with synthetic HTTP/cache/claim boundaries.
// No Composer, Laravel bootstrap, database, provider or guest calls.
namespace App\Models {
    final class Setting {
        public static function getSecret(string $key): string { return 'Apikey SYNTHETIC_PROVIDER_SECRET'; }
        public static function get(string $key, mixed $default = null): mixed { return $default; }
    }
    final class CloudImage {
        public static string $label = 'Ubuntu 24.04';
        public static function query(): self { return new self; }
        public function where(...$args): self { return $this; }
        public function value(string $key): string { return self::$label; }
    }
}
namespace Illuminate\Support {
    final class Arr {
        public static function flatten(array $input): array { $out = []; array_walk_recursive($input, function ($v) use (&$out) { $out[] = $v; }); return $out; }
    }
}
namespace Illuminate\Support\Facades {
    final class Cache {
        public static array $values = [];
        public static function remember(string $key, int $ttl, callable $callback): mixed { return self::$values[$key] ??= $callback(); }
    }
    final class Log { public static function warning(...$args): void {} }
    final class DB {
        public static array $claims = [];
        public static int $claimCalls = 0;
        public static function table(string $table): self { if ($table !== 'arvan_create_attempts') throw new \RuntimeException('unexpected_database_table'); return new self; }
        public function insertOrIgnore(array $row): int {
            self::$claimCalls++; if (isset(self::$claims[$row['operation_key']])) return 0;
            self::$claims[$row['operation_key']] = $row; return 1;
        }
    }
    final class Http {
        public static array $requests = [];
        public static array $images = [];
        public static array $servers = [];
        public static array $sizes = [];
        public static int $postStatus = 200;
        public static bool $timeout = false;
        public static function withHeaders(array $headers): self { return new self; }
        public function acceptJson(): self { return $this; }
        public function timeout(int $seconds): self { return $this; }
        public function connectTimeout(int $seconds): self { return $this; }
        public function get(string $url, array $query): object { return self::respond('GET', $url, $query); }
        public function post(string $url, array $payload): object { return self::respond('POST', $url, $payload); }
        private static function respond(string $method, string $url, array $data): object {
            self::$requests[] = ['method' => $method, 'url' => $url, 'data' => $data];
            if (!str_starts_with($url, 'https://napi.arvancloud.ir/')) throw new \RuntimeException('unexpected_origin');
            if ($method === 'POST') {
                if (self::$timeout) throw new \RuntimeException('SYNTHETIC_TRANSPORT_FAILURE');
                $body = self::$postStatus === 200 ? ['data' => ['id' => '11111111-2222-3333-4444-555555555555', 'status' => 'BUILD', 'password' => 'SYNTHETIC_VM_PASSWORD']]
                    : ['message' => 'You have reached RAM size limit', 'errors' => []];
                return new FakeResponse($body, self::$postStatus);
            }
            if (str_ends_with($url, '/networks')) return new FakeResponse(['data' => [['id' => 'network-public', 'name' => 'public', 'enable_gateway' => true]]]);
            if (str_contains($url, '/firewall/')) return new FakeResponse(['data' => [['id' => 'firewall-default', 'default' => true]]]);
            if (str_ends_with($url, '/images')) return new FakeResponse(['data' => self::$images]);
            if (str_ends_with($url, '/servers')) return new FakeResponse(['data' => self::$servers]);
            if (str_ends_with($url, '/sizes')) return new FakeResponse(['data' => self::$sizes]);
            throw new \RuntimeException('unexpected_http_request');
        }
    }
    final class FakeResponse {
        public function __construct(private array $value, private int $code = 200) {}
        public function body(): string { return json_encode($this->value, JSON_THROW_ON_ERROR); }
        public function json(): array { return $this->value; }
        public function status(): int { return $this->code; }
        public function successful(): bool { return $this->code >= 200 && $this->code < 300; }
    }
}
namespace {
    use App\Models\CloudImage;
    use App\Services\Cloud\ArvanClient;
    use App\Services\Cloud\ArvanLinuxBootstrap;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Http;

    function blank(mixed $value): bool { return $value === null || $value === '' || $value === []; }
    function filled(mixed $value): bool { return !blank($value); }
    function now(): string { return '2026-11-03 00:00:00'; }
    function data_get(mixed $target, string $key): mixed { foreach (explode('.', $key) as $part) { if (!is_array($target) || !array_key_exists($part, $target)) return null; $target = $target[$part]; } return $target; }
    if (!function_exists('mb_strtolower')) { function mb_strtolower(string $value): string { return strtolower($value); } }
    if (!function_exists('mb_substr')) { function mb_substr(string $value, int $start, ?int $length = null): string { return substr($value, $start, $length); } }
    require __DIR__.'/../../website/app/Services/Cloud/CloudProvider.php';
    // An isolated copied builder path is used only by the pin-failure fixture.
    require $argv[1] ?? __DIR__.'/../../website/app/Services/Cloud/ArvanLinuxBootstrap.php';
    require __DIR__.'/../../website/app/Services/Cloud/ArvanClient.php';
    $tests = 0; $assertions = 0;
    function check(bool $ok, string $message): void { global $assertions; $assertions++; if (!$ok) throw new RuntimeException($message); }
    function test(string $label, Closure $run): void { global $tests; resetFake(); $run(); $tests++; echo 'ok '.$tests.' - '.$label."\n"; }
    function resetFake(): void {
        Cache::$values = []; DB::$claims = []; DB::$claimCalls = 0; Http::$requests = []; Http::$servers = []; Http::$sizes = [];
        Http::$postStatus = 200; Http::$timeout = false; CloudImage::$label = 'Ubuntu 24.04';
        Http::$images = [['name' => 'Ubuntu', 'images' => [['id' => 'image-selected', 'name' => '24.04', 'distribution_name' => 'ubuntu']]]];
    }
    function spec(array $overrides = []): array { return array_replace(['name' => 'snet-fixture-17', 'location_ref' => 'ir-thr-c2', 'plan_ref' => 'flavor-exact', 'image_ref' => 'image-selected', 'disk_gb' => 25], $overrides); }
    function posts(): array { return array_values(array_filter(Http::$requests, fn ($r) => $r['method'] === 'POST')); }
    function created(array $overrides = []): array { return (new ArvanClient)->createServer(spec($overrides)); }
    function requestedMetadata(array $out): void {
        $meta = $out['raw']['guest_bootstrap'];
        check($meta['state'] === 'requested' && $meta['execution'] === 'unknown', 'request is not execution proof');
        check($meta['central_enrollment'] === 'not_performed' && $meta['billing'] === 'not_performed', 'no central/billing claim');
        check($meta['installer_sha256'] === ArvanLinuxBootstrap::INSTALLER_SHA256, 'installer pin');
    }

    if (isset($argv[1])) {
        resetFake(); $out = created();
        echo json_encode(['ok' => $out['ok'], 'transient' => $out['transient'] ?? false,
            'post_count' => count(posts()), 'claim_count' => DB::$claimCalls], JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }

    test('actual Ubuntu create attaches fixed raw init_script once', function (): void {
        $out = created(); check($out['ok'] === true, 'create successful'); requestedMetadata($out);
        $post = posts(); check(count($post) === 1 && DB::$claimCalls === 1, 'one intent and POST');
        $payload = $post[0]['data'];
        check(str_starts_with($payload['init_script'], "#!/bin/sh\n"), 'plain shell, not guessed user_data/base64 field');
        check(strlen($payload['init_script']) <= 32768, 'bounded owned source');
        check(hash('sha256', $payload['init_script']) === $out['raw']['guest_bootstrap']['source_sha256'], 'request source digest');
        check($payload['flavor_id'] === 'flavor-exact' && $payload['network_ids'] === ['network-public'], 'plan/network preserved');
        check($payload['security_groups'] === [['name' => 'firewall-default']] && $payload['disk_size'] === 25, 'security/disk preserved');
        check($payload['count'] === 1 && $payload['ha_enabled'] === false, 'single server preserved');
        check(!isset($payload['user_data']) && !isset($payload['ssh_key']) && !isset($payload['key_name']), 'no invented/credential fields');
    });
    test('Debian gets the same pinned bootstrap automatically', function (): void {
        Http::$images = [['name' => 'Debian', 'images' => [['id' => 'image-selected', 'name' => '12', 'distribution_name' => 'debian']]]];
        $out = created(); requestedMetadata($out); check($out['raw']['guest_bootstrap']['family'] === 'debian', 'Debian selected');
        check(isset(posts()[0]['data']['init_script']), 'automatic Debian payload');
    });
    test('Windows receives no Linux or PowerShell bootstrap', function (): void {
        Http::$images = [['name' => 'Windows', 'images' => [['id' => 'image-selected', 'name' => 'Windows Server 2022']]]];
        $out = created(); check($out['ok'] === true, 'Windows create preserved');
        check(!isset(posts()[0]['data']['init_script']) && !isset(posts()[0]['data']['user_data']), 'no Windows bootstrap assumption');
        check($out['raw']['guest_bootstrap']['state'] === 'not_requested', 'truthful unsupported metadata');
    });
    test('customer script and family hints cannot alter owned source', function (): void {
        $out = created(['init_script' => 'SYNTHETIC_CUSTOMER_PAYLOAD', 'user_data' => 'SYNTHETIC_CUSTOMER_PAYLOAD', 'os_family' => 'windows']);
        requestedMetadata($out); check(!str_contains(posts()[0]['data']['init_script'], 'SYNTHETIC_CUSTOMER_PAYLOAD'), 'customer source ignored');
        check($out['raw']['guest_bootstrap']['family'] === 'ubuntu', 'actual regional family wins');
    });
    test('unknown other OS and contradictory image metadata never authorize a script', function (): void {
        foreach ([['Other', 'Unknown'], ['CentOS', 'CentOS 9'], ['Ubuntu', 'Windows Server 2022'], ['Ubuntu', 'Debian 12']] as [$group, $label]) {
            resetFake(); Http::$images = [['name' => $group, 'images' => [['id' => 'image-selected', 'name' => $label]]]];
            check(created()['ok'] === true, 'existing unsupported create behavior'); check(!isset(posts()[0]['data']['init_script']), 'unsupported no script');
        }
    });
    test('conflicting duplicate regional IDs cannot authorize Linux source', function (): void {
        Http::$images[] = ['name' => 'Windows', 'images' => [['id' => 'image-selected', 'name' => '2022']]];
        Http::$images[] = ['name' => 'Ubuntu', 'images' => [['id' => 'image-selected', 'name' => '24.04']]];
        check(created()['ok'] === true && !isset(posts()[0]['data']['init_script']), 'conflict remains unknown');
    });
    test('regional image replacement is classified by actual resolved ID', function (): void {
        Http::$images = [['name' => 'Ubuntu', 'images' => [['id' => 'new-region-image', 'name' => 'Ubuntu 24.04']]]];
        $out = created(['image_ref' => 'old-region-image']); requestedMetadata($out);
        check(posts()[0]['data']['image_id'] === 'new-region-image', 'replacement preserved');
    });
    test('existing deterministic-name instance is adopted without bootstrap or paid POST', function (): void {
        Http::$servers = [['id' => 'existing-instance', 'name' => 'snet-fixture-17', 'status' => 'ACTIVE']];
        $out = created(); check($out['ref'] === 'ir-thr-c2:existing-instance', 'same instance adopted');
        check(posts() === [] && DB::$claimCalls === 0, 'no second purchase or bootstrap');
    });
    test('timeout preserves create claim and retry never posts a second server', function (): void {
        Http::$timeout = true; $first = created(); $second = created();
        check(!$first['ok'] && $first['transient'], 'timeout remains transient');
        check(!$second['ok'] && $second['transient'] && count(posts()) === 1, 'ambiguous result reconciles, never repeats POST');
    });
    test('RAM quota error and single-request intent are preserved', function (): void {
        Http::$postStatus = 400; $out = created();
        check(!$out['ok'] && str_contains($out['message'], 'You have reached RAM size limit'), 'quota message preserved');
        check(count(posts()) === 1 && count(DB::$claims) === 1, 'one failed attempt retained');
    });
    test('unmatched flavor refuses before bootstrap or create intent', function (): void {
        $out = created(['plan_ref' => 'missing', 'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 25]);
        check(!$out['ok'] && posts() === [] && DB::$claimCalls === 0, 'no guessed paid plan');
    });
    test('family parser requires positive supported provider evidence', function (): void {
        check(ArvanLinuxBootstrap::family(['name' => 'Ubuntu'], ['name' => '24.04 LTS']) === 'ubuntu', 'Ubuntu child version');
        check(ArvanLinuxBootstrap::family([], ['distribution_name' => 'debian', 'name' => '12']) === 'debian', 'flat Debian');
        check(ArvanLinuxBootstrap::family([], ['name' => '2022']) === null, 'version alone unknown');
        check(ArvanLinuxBootstrap::family(['name' => 'Ubuntu'], ['distribution_name' => 'debian']) === null, 'contradiction rejected');
    });
    echo $tests.' tests, '.$assertions." assertions passed\n";
}
