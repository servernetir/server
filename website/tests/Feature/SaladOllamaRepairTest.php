<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\SaladClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 تعمیرِ درجای سرورهای Ollamaای که بی‌`OLLAMA_MODEL_NAME` ساخته شدند (مهر ۱۴۰۵).
 *
 * ادعاها روی **درخواستی است که واقعاً به زیرساخت می‌رود**: متد، نوعِ بدنه، و
 * اینکه کلِ env فرستاده شود — چون PATCHِ آن‌جا نقشه را جایگزین می‌کند، نه ادغام.
 */
class SaladOllamaRepairTest extends TestCase
{
    use RefreshDatabase;

    private const REF = 'sn-svc-501';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::putSecret('salad_api_key', 'k-test');
        Setting::put('salad_org', 'servernet');
        Setting::put('salad_project', 'prod');
    }

    private function group(string $image, array $env): array
    {
        return ['name' => self::REF, 'container' => ['image' => $image, 'environment_variables' => $env],
            'current_state' => ['status' => 'running']];
    }

    private function fakeGroup(array $group): void
    {
        Http::fake(function ($req) use ($group) {
            return $req->method() === 'GET'
                ? Http::response($group, 200)
                : Http::response($group, 202);
        });
    }

    private function driver(): SaladClient
    {
        return app(CloudManager::class)->driver('salad');
    }

    private function patches(): array
    {
        return collect(Http::recorded())->filter(fn ($p) => $p[0]->method() === 'PATCH')->values()->all();
    }

    public function test_a_broken_ollama_group_gets_the_full_env_back_by_merge_patch(): void
    {
        $this->fakeGroup($this->group('saladtechnologies/ollama:0.24.0', ['OLLAMA_HOST' => '::']));

        $r = $this->driver()->ensureOllamaModelEnv(self::REF);

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertTrue($r['changed']);

        $patch = $this->patches();
        $this->assertCount(1, $patch);
        [$req] = $patch[0];

        $this->assertStringEndsWith('/organizations/servernet/projects/prod/containers/'.self::REF, $req->url());
        $this->assertSame('application/merge-patch+json', $req->header('Content-Type')[0] ?? null,
            'این PATCH فقط merge-patch می‌پذیرد.');

        $env = data_get(json_decode($req->body(), true), 'container.environment_variables');
        $this->assertSame(SaladClient::OLLAMA_PRELOAD_MODEL, $env['OLLAMA_MODEL_NAME'] ?? null);
        $this->assertSame('::', $env['OLLAMA_HOST'] ?? null,
            '🔴 PATCH نقشه را جایگزین می‌کند؛ بی‌OLLAMA_HOST سرور پشتِ دروازهٔ IPv6 بی‌صدا جواب نمی‌دهد.');
    }

    /** اگر GET env را برنگرداند، پیش‌فرض‌های برنامه نباید گم شوند */
    public function test_missing_env_in_the_response_still_sends_the_app_defaults(): void
    {
        $this->fakeGroup(['name' => self::REF, 'container' => ['image' => 'saladtechnologies/ollama:0.24.0']]);

        $this->driver()->ensureOllamaModelEnv(self::REF);

        [$req] = $this->patches()[0];
        $env = data_get(json_decode($req->body(), true), 'container.environment_variables');
        $this->assertSame('::', $env['OLLAMA_HOST'] ?? null);
        $this->assertSame(SaladClient::OLLAMA_PRELOAD_MODEL, $env['OLLAMA_MODEL_NAME'] ?? null);
    }

    public function test_extra_env_on_the_group_is_kept(): void
    {
        $this->fakeGroup($this->group('saladtechnologies/ollama:0.24.0', ['OLLAMA_HOST' => '::', 'KEEP_ME' => '1']));

        $this->driver()->ensureOllamaModelEnv(self::REF);

        [$req] = $this->patches()[0];
        $this->assertSame('1', data_get(json_decode($req->body(), true), 'container.environment_variables.KEEP_ME'));
    }

    public function test_a_healthy_ollama_group_is_not_touched(): void
    {
        $this->fakeGroup($this->group('saladtechnologies/ollama:0.24.0',
            ['OLLAMA_HOST' => '::', 'OLLAMA_MODEL_NAME' => 'qwen3']));

        $r = $this->driver()->ensureOllamaModelEnv(self::REF);

        $this->assertFalse($r['changed']);
        $this->assertSame([], $this->patches());
    }

    public function test_other_apps_are_never_patched(): void
    {
        $this->fakeGroup($this->group(SaladClient::APPS['gpu-jupyter']['ref'], ['NOTEBOOK_ARGS' => '--ip=*']));

        $r = $this->driver()->ensureOllamaModelEnv(self::REF);

        $this->assertTrue($r['ok']);
        $this->assertFalse($r['changed']);
        $this->assertSame([], $this->patches());
    }

    public function test_dry_run_never_patches(): void
    {
        $this->fakeGroup($this->group('saladtechnologies/ollama:0.24.0', ['OLLAMA_HOST' => '::']));
        $this->assertFalse($this->driver()->ensureOllamaModelEnv(self::REF, true)['changed']);
        $this->assertSame([], $this->patches());
    }

    /** ⚠️ آزمونِ جدا: fakeِ دوم در همان تست بی‌اثر است (اولین تطبیق می‌برد — CLAUDE.md §۸) */
    public function test_a_failed_read_never_patches(): void
    {
        Http::fake(['*' => Http::response(['title' => 'nope'], 500)]);
        $r = $this->driver()->ensureOllamaModelEnv(self::REF);
        $this->assertFalse($r['ok']);
        $this->assertSame([], $this->patches());
    }

    /** فرمان فقط سرورهای Ollamaِ زنده را می‌پرسد — نه سرویسِ لغوشده، نه برنامهٔ دیگر */
    public function test_the_command_only_touches_live_ollama_instances(): void
    {
        $c = Customer::create(['email' => 'r@x.com', 'phone' => '09120000501', 'password' => bcrypt('x'),
            'status' => 'active', 'locale' => 'fa']);

        $mk = function (string $image, string $status, string $ref) use ($c) {
            $s = Service::create(['customer_id' => $c->id, 'name' => 'gpu '.$ref, 'currency_code' => 'IRT',
                'price' => 0, 'tax_percent' => 0, 'cycle' => 'monthly', 'status' => $status,
                'provision_status' => 'done', 'activated_at' => now()]);
            CloudInstance::create(['service_id' => $s->id, 'provider' => 'salad', 'provider_ref' => $ref,
                'location_code' => 'global-gpu', 'image_key' => $image, 'hostname' => $ref.'.salad.cloud',
                'status' => 'building']);
        };

        $mk('gpu-ollama', 'active', 'live-ollama');
        $mk('gpu-ollama', 'cancelled', 'dead-ollama');
        $mk('gpu-jupyter', 'active', 'live-jupyter');

        Http::fake(function ($req) {
            return Http::response($this->group('saladtechnologies/ollama:0.24.0', ['OLLAMA_HOST' => '::']),
                $req->method() === 'GET' ? 200 : 202);
        });

        $this->artisan('cloud:ollama-repair')->assertSuccessful();

        $asked = collect(Http::recorded())->map(fn ($p) => $p[0]->url())->implode(' ');
        $this->assertStringContainsString('/containers/live-ollama', $asked);
        $this->assertStringNotContainsString('dead-ollama', $asked, 'سرویسِ لغوشده نباید دست بخورد.');
        $this->assertStringNotContainsString('live-jupyter', $asked);
        $this->assertCount(1, $this->patches());
    }
}
