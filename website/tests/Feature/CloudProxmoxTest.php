<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Setting;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\ProxmoxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * درایورِ Proxmox VE — زیرساختِ ۵، میزبانِ **خودمان** در ایران (Exit VPS).
 *
 * برخلافِ هتزنر/آیزا که سرور را می‌خریم، این‌جا با clone از یک قالبِ cloud-init
 * روی سخت‌افزارِ خودمان VM می‌سازیم. تست‌ها جریانِ واقعی را با `Http::fake`
 * می‌سنجند — یک fake در هر تست (قاعدهٔ پروژه: استابِ `'*'` بعدی‌ها را می‌بلعد).
 */
class CloudProxmoxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // فقط توکنِ سرّی لازم است؛ بقیه (آدرس/نود/قالب/…) پیش‌فرض دارند.
        Setting::putSecret('proxmox_token_secret', 'test-secret-uuid');
    }

    // ═══════════════ ثبت در سیستم ═══════════════

    public function test_proxmox_is_a_registered_driver(): void
    {
        $this->assertArrayHasKey('proxmox', CloudManager::DRIVERS);
        $this->assertInstanceOf(ProxmoxClient::class, app(CloudManager::class)->driver('proxmox'));
    }

    // ═══════════════ کاتالوگ ═══════════════

    public function test_catalog_returns_one_exit_vps_plan_per_country(): void
    {
        // پیش‌فرضِ فهرستِ کشورها: de,nl,fi ⇒ سه مکان و سه پلنِ هم‌مشخصات.
        $cat = app(ProxmoxClient::class)->fetchCatalog();

        $this->assertTrue($cat['ok'], (string) ($cat['message'] ?? ''));

        $this->assertCount(3, $cat['locations']);
        $this->assertCount(3, $cat['plans']);

        // اولین کشورِ پیش‌فرض = آلمان → مکانِ exit-de
        $this->assertSame('exit-de', $cat['locations'][0]['code']);
        $this->assertSame('DE', $cat['locations'][0]['country']);

        $this->assertSame('exit-vps-de', $cat['plans'][0]['provider_ref']);
        $this->assertSame('exit-de', $cat['plans'][0]['location_code']);
        $this->assertSame(2048, $cat['plans'][0]['ram_mb']);
        $this->assertSame(400, $cat['plans'][0]['cost_eur_cents']);

        // ایمیج = VMIDِ قالب (پیش‌فرض ۹۰۰۲)، کلیدِ یکسان‌شده — یکی برای همه
        $this->assertCount(1, $cat['images']);
        $this->assertSame('9002', $cat['images'][0]['provider_ref']);
        $this->assertSame('ubuntu-24.04', $cat['images'][0]['key']);
    }

    // ═══════════════ ساخت ═══════════════

    /** clone + config + start؛ ref/ipv4/root_password برمی‌گردد */
    public function test_create_server_clones_configures_and_starts(): void
    {
        $seen = [];

        Http::fake(function ($request) use (&$seen) {
            $method = $request->method();
            $path = parse_url($request->url(), PHP_URL_PATH) ?? '';
            $seen[] = $method.' '.$path;

            if ($method === 'GET' && str_contains($path, '/tasks/')) {
                return Http::response(['data' => ['status' => 'stopped']], 200);   // تسک تمام
            }
            if ($method === 'GET' && str_ends_with($path, '/cluster/nextid')) {
                return Http::response(['data' => '123'], 200);
            }
            if ($method === 'GET' && str_ends_with($path, '/qemu')) {
                return Http::response(['data' => []], 200);          // فهرستِ خالی
            }
            if ($method === 'POST' && str_contains($path, '/clone')) {
                return Http::response(['data' => 'UPID:ir:clone:done'], 200);
            }
            if ($method === 'PUT' && str_ends_with($path, '/config')) {
                return Http::response(['data' => null], 200);
            }
            if ($method === 'PUT' && str_ends_with($path, '/resize')) {
                return Http::response(['data' => null], 200);
            }
            if ($method === 'POST' && str_ends_with($path, '/status/start')) {
                return Http::response(['data' => 'UPID:ir:start'], 200);
            }

            return Http::response(['data' => []], 200);
        });

        $r = app(ProxmoxClient::class)->createServer([
            'name' => 'sn-svc-42', 'plan_ref' => 'exit-vps-1', 'location_ref' => 'ir',
            'image_ref' => '9002', 'disk_gb' => 30, 'ssh_keys' => [],
            'labels' => ['snet-service' => '42', 'exit_country' => 'DE'],
        ]);

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('123', $r['ref']);
        // اولین IPِ آزاد از ip_start (۱۰.۱۰.۱۰.۶۰)
        $this->assertSame('10.10.10.60', $r['ipv4']);
        $this->assertNotEmpty($r['root_password']);
        $this->assertSame('building', $r['status']);

        $joined = implode("\n", $seen);
        $this->assertStringContainsString('/clone', $joined);
        $this->assertStringContainsString('/config', $joined);
        $this->assertStringContainsString('/status/start', $joined);
    }

    /** idempotency: نامِ تکراری → همان VMِ موجود، بی‌clone دوم */
    public function test_existing_vm_is_returned_without_a_second_clone(): void
    {
        $clones = 0;

        Http::fake(function ($request) use (&$clones) {
            $method = $request->method();
            $path = parse_url($request->url(), PHP_URL_PATH) ?? '';

            if ($method === 'GET' && str_ends_with($path, '/qemu')) {
                return Http::response(['data' => [
                    ['vmid' => 555, 'name' => 'sn-svc-7', 'status' => 'running'],
                ]], 200);
            }
            // IPِ VMِ موجود از ipconfig0ِ کانفیگش
            if ($method === 'GET' && str_contains($path, '/qemu/555/config')) {
                return Http::response(['data' => [
                    'name' => 'sn-svc-7', 'ipconfig0' => 'ip=10.10.10.66/24,gw=10.10.10.1',
                ]], 200);
            }
            if ($method === 'POST' && str_contains($path, '/clone')) {
                $clones++;

                return Http::response(['data' => 'UPID:x'], 200);
            }

            return Http::response(['data' => []], 200);
        });

        $r = app(ProxmoxClient::class)->createServer([
            'name' => 'sn-svc-7', 'plan_ref' => 'exit-vps-1', 'location_ref' => 'ir',
            'image_ref' => '9002', 'disk_gb' => 30, 'ssh_keys' => [],
        ]);

        $this->assertSame(0, $clones, 'نباید سرورِ دوم بسازد');
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('555', $r['ref']);
        $this->assertSame('10.10.10.66', $r['ipv4']);
        $this->assertSame('running', $r['status']);
    }

    // ═══════════════ وضعیت و حذف ═══════════════

    /** mapStatus: qmpstatus running→running، stopped→off */
    public function test_status_maps_running_and_off(): void
    {
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH) ?? '';

            if (str_contains($path, '/qemu/101/status/current')) {
                return Http::response(['data' => ['status' => 'running', 'qmpstatus' => 'running']], 200);
            }
            if (str_contains($path, '/qemu/102/status/current')) {
                return Http::response(['data' => ['status' => 'stopped', 'qmpstatus' => 'stopped']], 200);
            }

            return Http::response(['data' => []], 200);
        });

        $c = app(ProxmoxClient::class);
        $this->assertSame('running', $c->serverStatus('101')['status']);
        $this->assertSame('off', $c->serverStatus('102')['status']);
    }

    /** حذفِ سرورِ ازقبل‌نبود (۴۰۴) = «موفق» برای خاتمه */
    public function test_delete_treats_404_as_success(): void
    {
        Http::fake(fn () => Http::response(['data' => null], 404));

        $this->assertTrue(app(ProxmoxClient::class)->deleteServer('900')['ok']);
    }

    // ═══════════════ سفیدبرچسبی ═══════════════

    /**
     * نامِ زیرساخت و شناسهٔ بومی نباید در JSONِ پلن/نمونه لو بروند.
     * از مدلِ حافظه‌ای استفاده می‌شود (بی‌ذخیره) تا وابسته به کلیدِ خارجی نباشد.
     */
    public function test_provider_identifiers_never_leak_in_serialization(): void
    {
        CloudLocation::firstOrCreate(['code' => 'ir-tehran'],
            ['country' => 'IR', 'city' => 'tehran', 'is_active' => true]);

        $plan = new CloudPlan([
            'provider' => 'proxmox', 'provider_ref' => 'exit-vps-1', 'provider_location' => 'ir',
            'location_code' => 'ir-tehran', 'public_name' => 'Exit VPS 2GB',
            'slug' => 'cv-2c-2g-30d-ir-tehran',
            'vcpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 30, 'disk_type' => 'ssd',
            'traffic_gb' => 1000, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 400, 'price_eur_cents' => 600, 'price_irt' => 600000,
            'is_active' => true, 'in_stock' => true,
        ]);

        $instance = new CloudInstance([
            'service_id' => 1, 'provider' => 'proxmox', 'provider_ref' => '90210',
            'location_code' => 'ir-tehran', 'status' => 'running', 'ipv4' => '10.10.10.60',
        ]);
        $instance->setPassword('SuperSecret123');

        $planJson = $plan->toJson();
        $instJson = $instance->toJson();

        foreach ([$planJson, $instJson] as $json) {
            $this->assertStringNotContainsStringIgnoringCase('proxmox', $json);
        }

        // شناسهٔ بومیِ پلن و نمونه نباید دیده شود
        $this->assertStringNotContainsString('exit-vps-1', $planJson);
        $this->assertStringNotContainsString('90210', $instJson);
        $this->assertStringNotContainsString('SuperSecret123', $instJson);

        // ولی داده‌های عمومی باید باشند
        $this->assertStringContainsString('ir-tehran', $planJson);
        $this->assertStringContainsString('10.10.10.60', $instJson);
    }
}
