<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Cloud\ArvanClient;
use App\Services\Cloud\CloudCatalogSync;
use App\Services\Cloud\CloudManager;
use App\Models\CloudPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * درایورِ ابرآروان — زیرساختِ ایرانی (زیرساختِ ۳).
 *
 * fixtureها **دقیقاً** ساختارِ Terraform-providerِ رسمیِ آروان‌اند:
 * قیمت `price_per_month` (تومان، اعشاری)، مشخصاتِ تخت (`cpu_count`, `memory`,
 * `disk`)، پاسخ در `{"data": …}`، منطقه با `create`/`visible`.
 */
class CloudArvanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::putSecret('arvan_api_token', 'Apikey test-key');
        Setting::put('pricing_rate_override', '900000');   // ۱ یورو = ۹۰۰٬۰۰۰ تومان
        Setting::put('cloud_margin_pct', '50');
    }

    /** پاسخِ آروان: مناطق + sizes + images، همه در {"data": …} */
    private function fakeArvan(array $over = []): void
    {
        $regions = $over['regions'] ?? [[
            'code' => 'ir-thr-c2', 'country' => 'IR', 'dc' => 'Tehran',
            'city_code' => 'thr', 'create' => true, 'visible' => true, 'soon' => false,
        ]];

        $sizes = $over['sizes'] ?? [[
            'id' => 'g2-2-4-20', 'name' => 'G2-2-4', 'cpu_count' => 2, 'memory' => 4096,
            'disk' => 20, 'price_per_month' => 900000.0, 'cpu_share' => 'general', 'generation' => 'g2',
        ]];

        $images = $over['images'] ?? [[
            'name' => 'Ubuntu',
            'images' => [
                ['id' => 'img-ubuntu-2204', 'name' => 'Ubuntu 22.04', 'distribution_name' => 'ubuntu', 'disk' => 10],
            ],
        ]];

        Http::fake(function ($request) use ($regions, $sizes, $images) {
            $url = $request->url();

            if (str_contains($url, '/regions/details')) {
                return Http::response(['data' => $regions], 200);
            }
            if (str_contains($url, '/sizes')) {
                return Http::response(['data' => $sizes], 200);
            }
            if (str_contains($url, '/images')) {
                return Http::response(['data' => $images], 200);
            }
            if (str_contains($url, '/networks')) {
                return Http::response(['data' => [['network_id' => 'net-pub-1', 'name' => 'Public', 'enable_gateway' => true]]], 200);
            }

            return Http::response(['data' => []], 200);
        });
    }

    // ═══════════════ اتصال و کاتالوگ ═══════════════

    public function test_test_connection_counts_creatable_regions(): void
    {
        $this->fakeArvan();

        $r = app(ArvanClient::class)->testConnection();

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(1, $r['meta']['regions']);
    }

    public function test_catalog_maps_toman_price_to_euro_cents(): void
    {
        $this->fakeArvan();

        $cat = app(ArvanClient::class)->fetchCatalog();

        $this->assertTrue($cat['ok'], (string) ($cat['message'] ?? ''));
        $this->assertCount(1, $cat['plans']);

        $plan = $cat['plans'][0];

        // ۹۰۰٬۰۰۰ تومان ÷ ۹۰۰٬۰۰۰ (تومانِ هر یورو) = ۱ یورو = ۱۰۰ سنت
        $this->assertSame(100, $plan['cost_eur_cents']);
        $this->assertSame(2, $plan['vcpu']);
        $this->assertSame(4096, $plan['ram_mb']);
        $this->assertSame(20, $plan['disk_gb']);
        $this->assertSame('ir-thr-c2', $plan['provider_location']);

        // مکان: کدِ ما «ir-tehran»، نه شناسهٔ آروان
        $this->assertSame('ir-tehran', $plan['location_code']);
    }

    public function test_dedicated_cpu_share_is_detected(): void
    {
        $this->fakeArvan(['sizes' => [[
            'id' => 'd2-4-8-40', 'name' => 'D2-4-8', 'cpu_count' => 4, 'memory' => 8192,
            'disk' => 40, 'price_per_month' => 1800000.0, 'cpu_share' => 'dedicated',
        ]]]);

        $cat = app(ArvanClient::class)->fetchCatalog();

        $this->assertSame('dedicated', $cat['plans'][0]['cpu_kind']);
    }

    public function test_images_are_mapped_to_unified_keys(): void
    {
        $this->fakeArvan();

        $cat = app(ArvanClient::class)->fetchCatalog();

        $this->assertNotEmpty($cat['images']);
        $img = $cat['images'][0];

        // کلیدِ یکسان‌شده، نه شناسهٔ آروان
        $this->assertSame('ubuntu-22.04', $img['key']);
        $this->assertSame('img-ubuntu-2204', $img['provider_ref']);
    }

    /** مشخصاتِ ناقص رد شود، نه ذخیره با صفر */
    public function test_incomplete_size_is_skipped(): void
    {
        $this->fakeArvan(['sizes' => [
            ['id' => 'bad', 'name' => 'X', 'cpu_count' => 0, 'memory' => 0, 'disk' => 0, 'price_per_month' => 0],
        ]]);

        $cat = app(ArvanClient::class)->fetchCatalog();

        $this->assertSame([], $cat['plans']);
    }

    /** پلنِ تخفیف‌دارِ موقت (off) مثلِ PROMO کنار برود */
    public function test_promo_size_with_off_is_skipped_by_default(): void
    {
        $this->fakeArvan(['sizes' => [[
            'id' => 'promo-1', 'name' => 'PROMO', 'cpu_count' => 2, 'memory' => 4096, 'disk' => 20,
            'price_per_month' => 450000.0, 'off' => 'true', 'off_percent' => '50',
        ]]]);

        $cat = app(ArvanClient::class)->fetchCatalog();

        $this->assertSame([], $cat['plans'], 'پلنِ تخفیف‌دارِ موقت نباید بیاید');
    }

    /** منطقهٔ «به‌زودی» (create=false) نباید بیاید */
    public function test_non_creatable_region_is_excluded(): void
    {
        $this->fakeArvan(['regions' => [
            ['code' => 'ir-thr-c2', 'country' => 'IR', 'dc' => 'Tehran', 'create' => true, 'visible' => true],
            ['code' => 'ir-tbz-c1', 'country' => 'IR', 'dc' => 'Tabriz', 'create' => false, 'visible' => true, 'soon' => true],
        ]]);

        $cat = app(ArvanClient::class)->fetchCatalog();

        $codes = array_column($cat['locations'], 'provider_location');
        $this->assertContains('ir-thr-c2', $codes);
        $this->assertNotContains('ir-tbz-c1', $codes, 'منطقهٔ «به‌زودی» نباید بیاید');
    }

    // ═══════════════ ساخت و مدیریت ═══════════════

    public function test_create_server_encodes_region_in_ref(): void
    {
        Setting::putSecret('arvan_api_token', 'Apikey k');

        $body = null;
        Http::fake(function ($request) use (&$body) {
            $url = $request->url();

            if (str_contains($url, '/networks')) {
                return Http::response(['data' => [['network_id' => 'net-1', 'enable_gateway' => true]]], 200);
            }
            if (str_contains($url, '/servers') && $request->method() === 'POST') {
                $body = $request->data();

                return Http::response(['data' => [
                    'id' => 'srv-9', 'status' => 'building', 'password' => 'RootPw123',
                    'addresses' => ['185.55.1.9'],
                ]], 200);
            }
            if (str_contains($url, '/servers') && $request->method() === 'GET') {
                return Http::response(['data' => []], 200);  // findByName → خالی
            }

            return Http::response(['data' => []], 200);
        });

        $r = app(ArvanClient::class)->createServer([
            'name' => 'sn-svc-1', 'plan_ref' => 'g2-2-4-20',
            'location_ref' => 'ir-thr-c2', 'image_ref' => 'img-ubuntu-2204',
            'disk_gb' => 20, 'ssh_keys' => [],
        ]);

        $this->assertTrue($r['ok'], $r['message']);
        // ⚠️ ref باید region:id باشد تا عملیاتِ بعدی منطقه را داشته باشد
        $this->assertSame('ir-thr-c2:srv-9', $r['ref']);
        $this->assertSame('185.55.1.9', $r['ipv4']);
        $this->assertSame('RootPw123', $r['root_password']);
        $this->assertSame('building', $r['status']);

        // بدنه: فیلدهای واقعیِ آروان
        $this->assertSame('g2-2-4-20', $body['flavor_id']);
        $this->assertSame('img-ubuntu-2204', $body['image_id']);
        $this->assertSame(['net-1'], $body['network_ids']);
    }

    /** نامِ تکراری → همان سرورِ موجود (idempotency)، نه سرورِ دوم */
    public function test_duplicate_name_returns_existing_server(): void
    {
        Setting::putSecret('arvan_api_token', 'Apikey k');

        $created = 0;
        Http::fake(function ($request) use (&$created) {
            $url = $request->url();

            if (str_contains($url, '/networks')) {
                return Http::response(['data' => [['network_id' => 'net-1', 'enable_gateway' => true]]], 200);
            }
            if (str_contains($url, '/servers') && $request->method() === 'GET') {
                return Http::response(['data' => [
                    ['id' => 'srv-existing', 'name' => 'sn-svc-1', 'status' => 'active', 'addresses' => ['185.55.1.5']],
                ]], 200);
            }
            if (str_contains($url, '/servers') && $request->method() === 'POST') {
                $created++;

                return Http::response(['data' => ['id' => 'srv-NEW']], 200);
            }

            return Http::response(['data' => []], 200);
        });

        $r = app(ArvanClient::class)->createServer([
            'name' => 'sn-svc-1', 'plan_ref' => 'g2-2-4-20',
            'location_ref' => 'ir-thr-c2', 'image_ref' => 'img-1', 'disk_gb' => 20, 'ssh_keys' => [],
        ]);

        $this->assertSame(0, $created, 'نباید سرورِ دوم بسازد');
        $this->assertSame('ir-thr-c2:srv-existing', $r['ref']);
    }

    public function test_power_off_uses_region_path(): void
    {
        Setting::putSecret('arvan_api_token', 'Apikey k');

        $hit = null;
        Http::fake(function ($request) use (&$hit) {
            $hit = $request->url();

            return Http::response(['data' => []], 200);
        });

        $r = app(ArvanClient::class)->power('ir-thr-c2:srv-9', 'off');

        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('/regions/ir-thr-c2/servers/srv-9/power-off', $hit);
    }

    public function test_delete_treats_404_as_success(): void
    {
        Setting::putSecret('arvan_api_token', 'Apikey k');
        Http::fake(fn () => Http::response(['message' => 'not found'], 404));

        $this->assertTrue(app(ArvanClient::class)->deleteServer('ir-thr-c2:srv-9')['ok']);
    }

    // ═══════════════ ادغام با سیستمِ کاتالوگ ═══════════════

    public function test_arvan_is_a_registered_driver(): void
    {
        $this->assertArrayHasKey('arvan', CloudManager::DRIVERS);
        $this->assertInstanceOf(ArvanClient::class, app(CloudManager::class)->driver('arvan'));
    }

    public function test_full_sync_creates_iranian_plans(): void
    {
        $this->fakeArvan();

        $report = app(CloudCatalogSync::class)->sync('arvan');

        $this->assertTrue($report['ok'], json_encode($report, JSON_UNESCAPED_UNICODE));
        $this->assertGreaterThan(0, CloudPlan::where('provider', 'arvan')->count());

        // پلنِ ایرانی باید قابلِ فروش باشد (قیمتِ تومانی ساخته شده)
        $plan = CloudPlan::where('provider', 'arvan')->first();
        $this->assertGreaterThan(0, $plan->price_irt);
        $this->assertSame('ir-tehran', $plan->location_code);
    }

    /** نامِ برندِ آروان نباید در JSONِ پلن لو برود (سفیدبرچسبی) */
    public function test_arvan_brand_never_leaks(): void
    {
        $this->fakeArvan();
        app(CloudCatalogSync::class)->sync('arvan');

        $json = CloudPlan::where('provider', 'arvan')->first()->toJson();

        foreach (['arvan', 'ابرآروان', 'g2-2-4-20', 'ir-thr-c2'] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, $json);
        }
    }
}
