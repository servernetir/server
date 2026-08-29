<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Setting;
use App\Services\Cloud\CloudCatalogSync;
use App\Services\Cloud\HetznerRobotClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * زیرساختِ ۷ (Robot) — سه ادعای پولی:
 *
 * ۱) کارمزد در بهایِ تمام‌شده می‌نشیند و قیمتِ فروش رویش حساب می‌شود (نه صفر،
 *    نه زیرِ بها — خطِ قرمز).
 * ۲) ردیفِ ناخوانا (CPUِ بی‌نگاشت، بی‌دیتاسنتر) و محصولِ دارای هزینهٔ نصب
 *    **رد و اعلام** می‌شوند — نه ذخیره با صفر، نه سکوت.
 * ۳) خریدِ خودکار وجود ندارد: createServer همیشه به صفِ دستی می‌رود.
 */
class CloudHetznerRobotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('pricing_rate_override', '100000');   // ۱€ = ۱۰۰٬۰۰۰ تومان
        Setting::put('cloud_margin_pct', '10');
        Setting::put('pricing_fx_fee_pct_hetzner', '8');
        Setting::putSecret('hetzner_robot_user', '#ws+test');
        Setting::putSecret('hetzner_robot_pass', 'secret');
    }

    private function fakeRobot(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/order/server_market/product')) {
                return Http::response([
                    // ماشینِ مزایدهٔ سالم — باید پلن شود
                    ['product' => [
                        'id' => 12345, 'name' => 'SB38', 'cpu' => 'Intel Core i7-8700',
                        'memory_size' => 64, 'hdd_size' => 512, 'hdd_count' => 2,
                        'hdd_arr' => ['2x SSD SATA 512 GB'],
                        'price' => '38.00', 'price_setup' => '0.00',
                        'datacenter' => 'FSN1-DC5', 'fixed_price' => true,
                    ]],
                    // CPUِ بی‌نگاشت — باید رد و اعلام شود
                    ['product' => [
                        'id' => 22222, 'name' => 'SB55', 'cpu' => 'Intel Core i3-540',
                        'memory_size' => 8, 'hdd_size' => 750, 'hdd_count' => 2,
                        'price' => '25.00', 'price_setup' => '0.00', 'datacenter' => 'NBG1-DC3',
                    ]],
                    // بی‌دیتاسنتر — باید رد شود، نه اینکه شهر برایش جعل شود
                    ['product' => [
                        'id' => 33333, 'name' => 'SB60', 'cpu' => 'AMD Ryzen 5 3600',
                        'memory_size' => 64, 'hdd_size' => 512, 'hdd_count' => 2,
                        'price' => '60.00', 'price_setup' => '0.00',
                    ]],
                ]);
            }

            if (str_contains($url, '/order/server_product') || str_contains($url, '/order/server/product')) {
                return Http::response([
                    // GEX131 — مشخصات از جدولِ ثابت، GPU باید بنشیند
                    ['product' => [
                        'id' => 'GEX131', 'name' => 'GEX131',
                        'description' => ['Intel Xeon Gold 5412U', '256 GB DDR5 ECC', '2 x 1.92 TB NVMe SSD'],
                        'traffic' => 'unlimited',
                        'prices' => [
                            ['location' => 'FSN1',
                                'price' => ['net' => '889.00', 'gross' => '1057.91'],
                                'price_setup' => ['net' => '0.00', 'gross' => '0.00']],
                        ],
                    ]],
                    // EX با هزینهٔ نصب — باید رد شود (فاز ۱ فقط بی‌نصب)
                    ['product' => [
                        'id' => 'EX44', 'name' => 'EX44',
                        'description' => ['Intel Core i5-13500', '64 GB DDR4', '2 x 512 GB NVMe SSD'],
                        'prices' => [
                            ['location' => 'HEL1',
                                'price' => ['net' => '44.00', 'gross' => '52.36'],
                                'price_setup' => ['net' => '39.00', 'gross' => '46.41']],
                        ],
                    ]],
                ]);
            }

            return Http::response(['error' => ['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'x']], 404);
        });
    }

    public function test_the_catalog_prices_carry_the_fee_and_reject_the_unpriceable(): void
    {
        $this->fakeRobot();

        $cat = app(HetznerRobotClient::class)->fetchCatalog();

        $this->assertTrue($cat['ok']);

        $plans = collect($cat['plans']);

        // فقط دو ردیفِ سالم: مزایدهٔ i7-8700 و GEX131
        $this->assertCount(2, $plans);

        $sb = $plans->firstWhere('provider_ref', 'market-12345');
        $this->assertNotNull($sb);
        $this->assertSame('de-falkenstein', $sb['location_code']);
        $this->assertSame(6, $sb['vcpu']);
        $this->assertSame(64 * 1024, $sb['ram_mb']);
        $this->assertSame(1024, $sb['disk_gb']);
        $this->assertSame('ssd', $sb['disk_type']);
        $this->assertSame('dedicated', $sb['cpu_kind']);
        // ۳۸€ × ۱٫۰۸ کارمزد = ۴۱٫۰۴€ → ۴۱۰۴ سنت (گردِ رو به بالا)
        $this->assertSame(4104, $sb['cost_eur_cents']);

        $gex = $plans->firstWhere('provider_ref', 'GEX131');
        $this->assertNotNull($gex);
        $this->assertSame('RTX PRO 6000 Blackwell', $gex['gpu_model']);
        $this->assertSame(98304, $gex['gpu_vram_mb']);
        $this->assertSame(24, $gex['vcpu']);
        // ۸۸۹€ × ۱٫۰۸ = ۹۶۰٫۱۲€ دقیق — خطای ممیزِ شناور نباید یک سنت اضافه کند
        $this->assertSame(96012, $gex['cost_eur_cents']);

        // ردشده‌ها ساکت نیستند — پیام علت را می‌گوید
        $this->assertStringContainsString('CPUِ ناشناخته', $cat['message']);
        $this->assertStringContainsString('هزینهٔ نصب', $cat['message']);
        $this->assertStringContainsString('بی‌دیتاسنتر', $cat['message']);

        // هیچ کدِ مکانِ legacy تولید نمی‌شود (قاعدهٔ ممیزی ۷)
        foreach ($cat['locations'] as $loc) {
            $this->assertFalse(CloudLocation::isLegacyCode($loc['code']), $loc['code']);
        }
    }

    public function test_the_sync_lands_dedicated_plans_with_a_real_sale_price(): void
    {
        $this->fakeRobot();

        app(CloudCatalogSync::class)->sync('hetzner-robot');

        $row = CloudPlan::where('provider', 'hetzner-robot')
            ->where('provider_ref', 'market-12345')->first();

        $this->assertNotNull($row, 'پلنِ مزایده باید در کاتالوگ بنشیند.');
        $this->assertSame('dedicated', $row->cpu_kind);
        $this->assertTrue($row->is_active);

        // قیمتِ فروش = بها(با کارمزد) × (۱+حاشیه) — هرگز صفر، هرگز زیرِ بها
        $this->assertGreaterThan(0, (int) $row->price_irt);
        $this->assertGreaterThanOrEqual((int) $row->cost_eur_cents, (int) $row->price_eur_cents);

        // GPU اختصاصی هم با ستون‌های GPU نشسته
        $gex = CloudPlan::where('provider', 'hetzner-robot')->where('provider_ref', 'GEX131')->first();
        $this->assertNotNull($gex);
        $this->assertSame('RTX PRO 6000 Blackwell', $gex->gpu_model);

        // و مکان‌ها با کدِ «کشور-شهر» ساخته شده‌اند
        $this->assertNotNull(CloudLocation::where('code', 'de-falkenstein')->first());
    }

    /**
     * 🔴 whitelabel: JSONِ پلنِ اختصاصی هم نامِ زیرساخت و بها را لو نمی‌دهد.
     */
    public function test_the_dedicated_plan_never_leaks_provider_or_cost(): void
    {
        $this->fakeRobot();
        app(CloudCatalogSync::class)->sync('hetzner-robot');

        $json = json_encode(CloudPlan::where('provider', 'hetzner-robot')->first());

        $this->assertStringNotContainsString('hetzner', strtolower((string) $json));
        $this->assertStringNotContainsString('cost_eur_cents', (string) $json);
    }

    public function test_ordering_never_buys_automatically(): void
    {
        $r = app(HetznerRobotClient::class)->createServer([
            'name' => 'sn-svc-1', 'plan_ref' => 'market-12345',
            'location_ref' => 'FSN1', 'image_ref' => '',
        ]);

        $this->assertFalse($r['ok']);
        $this->assertTrue($r['manual'] ?? false, 'سفارش باید به صفِ دستی برود، نه شکستِ خام.');
        Http::assertNothingSent();
    }

    public function test_the_germany_page_shows_the_synced_dedicated_plan(): void
    {
        $this->fakeRobot();
        app(CloudCatalogSync::class)->sync('hetzner-robot');

        $res = $this->get('/dedicated/germany');

        $res->assertOk();
        // نامِ سفیدبرچسبِ پلن (CVD-6-64) — یعنی جدولِ زندهٔ اختصاصی رندر شده
        $this->assertStringContainsString('BM-6-64', $res->getContent());
        // و ردیفِ برمتال برچسبِ متمایز دارد، نه «پردازندهٔ اختصاصی»
        $this->assertStringContainsString('سرور فیزیکی (برمتال)', $res->getContent());
        // و نه «تماس برای قیمت»ِ پلن‌های سخت‌کدِ config
        $this->assertStringNotContainsString('hetzner-robot', $res->getContent());
    }
}
