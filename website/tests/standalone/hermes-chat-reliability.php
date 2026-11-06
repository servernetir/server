<?php
/** Composer-only isolated acceptance: no app bootstrap, live state, model or sends. */
namespace {
    if (PHP_SAPI !== 'cli') { exit(1); }
    $root = $argv[2] ?? dirname(__DIR__, 3);
    $loader = require $argv[1];
    $loader->setClassMapAuthoritative(false);
}
namespace App\Models {
    class Setting { public static function get($key) { return $key === 'hermes_operations_enabled' ? '1' : ''; } }
}
namespace App\Services\Bale\Admin {
    class AdminBaleGate {
        public function binding() { return ['chat_id' => '200']; }
        public function boundUser() { return (object) ['id' => 100]; }
        public function enabled() { return true; }
    }
}
namespace App\Services\Bale {
    class BaleSender {
        public array $sent = [];
        public function send($chat, $text) { $this->sent[] = [$chat, $text]; return true; }
    }
}
namespace App\Services\Cloud { class CloudInventory {} }
namespace App\Services\Hermes {
    class HermesOperationsStore {
        public array $state = ['tasks' => [], 'alerts' => [], 'scans' => [], 'digests' => []];
        public function read($owner) { return $this->state; }
        public function change($owner, $operation) { return $operation($this->state); }
    }
    class HermesServiceDossier {}
    class HermesReadOnlyDiagnostics {}
    class HermesSupportState {}
    class HermesBusinessReport {
        public const LABELS = ['business_fixture' => 'مرور مالی آزمایشی'];
        public static function text($state) { return 'business-fixture-preserved'; }
        public static function fingerprint($alerts) { return hash('sha256', json_encode($alerts)); }
    }
}
namespace {
    use App\Http\Middleware\ConsoleHost;
    use App\Services\Hermes\HermesAlertSummary as Summary;
    use Illuminate\Http\Request;
    use Symfony\Component\HttpFoundation\Response;
    require_once $root.'/website/app/Http/Middleware/ConsoleHost.php';
    require_once $root.'/website/app/Services/Cloud/CloudManager.php';
    require_once $root.'/website/app/Services/Hermes/HermesAlertSummary.php';
    require_once $root.'/infra/hermes/chat-reliability-20261031/HermesOperations.php';
    $container = new \Illuminate\Container\Container;
    \Illuminate\Container\Container::setInstance($container);
    $container->instance('redirect', new \Illuminate\Routing\Redirector(new \Illuminate\Routing\UrlGenerator(
        new \Illuminate\Routing\RouteCollection, Request::create('https://servernet.cloud'))));
    $checks = 0;
    $assert = static function (bool $ok, string $message) use (&$checks): void {
        if (! $ok) { throw new \RuntimeException('FAILED: '.$message); }
        $checks++;
    };
    $middleware = new ConsoleHost;
    $body = json_encode(['model' => 'fixture-only', 'messages' => [['role' => 'user', 'content' => 'سلام']]], JSON_UNESCAPED_UNICODE);
    foreach (['chat/completions', 'embeddings', 'rerank', 'inference'] as $path) {
        $request = Request::create('https://console.servernet.cloud/v1/'.$path.'?trace=fixture', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer synthetic-fixture'], $body);
        $seen = null;
        $response = $middleware->handle($request, static function ($r) use (&$seen) { $seen = $r; return new Response('continuation', 202); });
        $assert($response->getStatusCode() === 202 && ! $response->headers->has('Location'), $path.' reaches continuation without redirect');
        $assert($seen->method() === 'POST' && $seen->getContent() === $body, $path.' retains method and exact JSON bytes');
        $assert($seen->bearerToken() === 'synthetic-fixture' && $seen->query('trace') === 'fixture', $path.' retains auth and query');
    }
    foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
        $response = $middleware->handle(Request::create('https://console.servernet.cloud/v1/models', $method), fn () => new Response('next', 202));
        $assert($response->getStatusCode() === 202 && ! $response->headers->has('Location'), $method.' API is not redirected');
    }
    $response = $middleware->handle(Request::create('https://console.servernet.cloud/blog?q=fixture'), fn () => new Response);
    $assert($response->getStatusCode() === 301 && $response->headers->get('Location') === 'https://servernet.cloud/blog?q=fixture', 'marketing SEO redirect preserved');
    $response = $middleware->handle(Request::create('https://servernet.cloud/account/tickets'), fn () => new Response);
    $assert($response->getStatusCode() === 301 && $response->headers->get('Location') === 'https://console.servernet.cloud/account/tickets', 'panel redirect preserved');
    $alert = static fn ($code, $provider, $ref, $service = null, $active = true, $ack = null) => [
        'code' => $code, 'active' => $active, 'acknowledged_at' => $ack,
        'data' => ['provider' => $provider, 'ref' => $ref, 'service_id' => $service]];
    $alerts = [];
    for ($i = 1; $i <= 8; $i++) { $alerts[] = $alert('unassigned_resource', 'hetzner', 'fixture-'.$i); }
    $alerts[] = $alert('unassigned_resource', 'salad', 'gpu-fixture');
    $alerts[] = $alert('service_resource_missing', 'hetzner', 'missing-fixture', 77);
    $alerts[] = $alert('unassigned_resource', 'hetzner', 'inactive-marker', null, false);
    $alerts[] = $alert('unassigned_resource', 'hetzner', 'acknowledged-marker', null, true, time());
    $before = $alerts;
    $text = Summary::render($alerts);
    $assert(substr_count($text, '• منبع بدون سرویس متصل') === 2, 'eight same-provider rows grouped; providers remain separate');
    $assert(str_contains($text, 'Hetzner Cloud؛ 8 مورد') && str_contains($text, 'SaladCloud (GPU)؛ 1 مورد'), 'provider names and counts shown');
    $assert(str_contains($text, 'fixture-1، fixture-2، fixture-3') && str_contains($text, 'سرویس: #77'), 'bounded exact resource and service references');
    $assert(! str_contains($text, 'inactive-marker') && ! str_contains($text, 'acknowledged-marker'), 'inactive and acknowledged alerts omitted');
    $assert(str_contains($text, 'ممکن است داخلی، آزمایشی') && str_contains($text, 'مجوز حذف'), 'classification uncertainty explained');
    $assert($alerts === $before, 'presentation does not mutate source alerts');
    $assert(Summary::render([]) === '', 'empty alerts render empty');
    $text = Summary::render([$alert('detached_volume_review', 'hetzner-storage', 'vol-fixture')]);
    $assert(str_contains($text, 'Hetzner Cloud / ذخیره‌سازی') && str_contains($text, 'vol-fixture'), 'storage provider remains identifiable');
    $text = Summary::render([$alert('business_fixture', null, null, 658)], \App\Services\Hermes\HermesBusinessReport::LABELS);
    $assert(str_contains($text, 'مرور مالی آزمایشی') && str_contains($text, '#658'), 'business labels and service IDs preserved');
    $text = Summary::render([$alert('unknown', "<b>evil</b>", "Bearer private-token\n@all")]);
    $assert(! str_contains($text, 'evil') && ! str_contains($text, 'private-token') && ! str_contains($text, '@all'), 'malformed identity fields never enter message');
    $many = [];
    for ($i = 0; $i < 15; $i++) { for ($j = 0; $j < 4; $j++) { $many[] = $alert('code-'.$i, 'hetzner', str_repeat('x', 78).$j, 1000 + $j); } }
    $text = Summary::render($many);
    $assert(mb_strlen($text) <= 1800 && str_contains($text, 'گروه هشدار دیگر'), 'large digest is bounded with explicit remaining-group count');
    $store = new \App\Services\Hermes\HermesOperationsStore;
    $store->state['alerts'] = $alerts;
    $sender = new \App\Services\Bale\BaleSender;
    $operations = new \App\Services\Hermes\HermesOperations($store, new \App\Services\Bale\Admin\AdminBaleGate,
        new \App\Services\Hermes\HermesServiceDossier, new \App\Services\Hermes\HermesReadOnlyDiagnostics,
        new \App\Services\Hermes\HermesSupportState, new \App\Services\Cloud\CloudInventory,
        new \App\Services\Cloud\CloudManager, $sender);
    $assert($operations->digest(false) === 'sent' && count($sender->sent) === 1, 'real native digest calls captured sender once');
    $sent = $sender->sent[0][1];
    $assert(str_contains($sent, 'Hetzner Cloud؛ 8 مورد') && str_contains($sent, 'business-fixture-preserved'), 'real digest integrates grouping and keeps business review');
    $assert($store->state['alerts'] === $alerts, 'digest never acknowledges or suppresses alerts');
    $assert($operations->digest(false) === 'unchanged_alerts' && count($sender->sent) === 1, 'existing fingerprint send deduplication preserved');
    echo json_encode(['status' => 'passed', 'checks' => $checks, 'live_model_calls' => 0, 'external_messages_sent' => 0], JSON_THROW_ON_ERROR).PHP_EOL;
}
