<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Cloud\ArvanClient;
use App\Services\Cloud\CloudNaming;
use App\Services\Cloud\ProxmoxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 خطِ VPSِ ایران روی سختِ‌افزارِ خودمان.
 *
 * ═══ چرا این خط لازم شد (۱۱ شهریور ۱۴۰۵) ═══
 *
 * مشتری SN-978603 «سرور ایران ۱ گیگ رم ۱ هسته» خرید و پولش را داد. ماشین روی
 * Proxmox ساخته شد، ولی وصل‌کردنش به پرتالِ مشتری ناممکن بود: فرمِ «اتصالِ
 * سرورِ موجود» یک `cloud_plan_id` می‌خواهد و **هیچ پلنِ ایرانی برای این
 * زیرساخت وجود نداشت**.
 *
 * و پلن از هیچ صفحه‌ای دستی ساخته نمی‌شود — تنها منبعِ ردیف‌های `cloud_plans`
 * همین `fetchCatalog()` است. یعنی خرابی «کمبودِ داده» نبود، **کمبودِ مسیر** بود:
 * محصولی که فروخته می‌شد در کاتالوگِ تحویل وجود نداشت.
 *
 * ⚠️ درسِ ثبت‌شده: هر چیزی که می‌فروشیم باید در کاتالوگِ تحویل نمایندهٔ خودش را
 * داشته باشد، وگرنه فروش و تحویل دو دنیای جدا می‌شوند.
 */
class CloudProxmoxIranLineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::putSecret('proxmox_token_secret', 'test-secret-uuid');
        Http::fake();
    }

    private function catalog(): array
    {
        return app(ProxmoxClient::class)->fetchCatalog();
    }

    /** @return array<int, array<string, mixed>> */
    private function iranPlans(array $catalog): array
    {
        return array_values(array_filter(
            $catalog['plans'],
            fn ($p) => str_starts_with((string) $p['location_code'], 'ir-')
        ));
    }

    // ═══════════ مکان ═══════════

    /**
     * 🔴 مکانِ ایران باید **دقیقاً همان کدی** باشد که آروان می‌سازد.
     *
     * کلِ لایهٔ سفیدبرچسب روی این بنا شده: یک مکان، چند زیرساخت، تحویل از
     * ارزان‌ترینِ در دسترس. اگر این‌جا کدِ دیگری بسازیم، مشتری دو کارتِ
     * «ایران — تهران» می‌بیند و هیچ‌کدام جایگزینِ دیگری نمی‌شود — یعنی وقتی
     * آروان قرنطینه شود، تهران به‌جای افتادن روی سختِ‌افزارِ خودمان، غیب می‌شود.
     */
    public function test_the_iran_location_code_is_the_same_one_arvan_builds_for_tehran(): void
    {
        $loc = collect($this->catalog()['locations'])->firstWhere('country', 'IR');

        $this->assertNotNull($loc, 'خطِ ایران در کاتالوگ نیست');
        $this->assertSame('ir-tehran', $loc['code']);

        // و همان تابعی که آروان استفاده می‌کند همین را می‌دهد — پس اگر روزی
        // قاعدهٔ نام‌گذاری عوض شود، هر دو با هم عوض می‌شوند.
        $this->assertSame(CloudNaming::locationCode('IR', 'tehran', 'ir'), $loc['code']);
    }

    /** خطِ اکسیت دست‌نخورده می‌مانَد — این تغییر چیزی را جابه‌جا نکرده. */
    public function test_the_exit_line_is_untouched(): void
    {
        $codes = collect($this->catalog()['locations'])->pluck('code')->all();

        foreach (['exit-de', 'exit-nl', 'exit-fi'] as $c) {
            $this->assertContains($c, $codes);
        }
    }

    // ═══════════ اندازه‌ها ═══════════

    /** اندازه‌ها از تنظیمات می‌آیند، نه سخت‌کد — افزودنِ بعدی نباید دیپلوی بخواهد. */
    public function test_sizes_come_from_settings(): void
    {
        Setting::put('proxmox_ir_plans', '1-1-25,8-16-200');

        $plans = $this->iranPlans($this->catalog());

        $this->assertCount(2, $plans);
        $this->assertSame([1, 1024, 25], [$plans[0]['vcpu'], $plans[0]['ram_mb'], $plans[0]['disk_gb']]);
        $this->assertSame([8, 16384, 200], [$plans[1]['vcpu'], $plans[1]['ram_mb'], $plans[1]['disk_gb']]);
    }

    /**
     * ⚠️ یک تایپو در تنظیمات نباید کلِ همگام‌سازیِ زیرساخت را بخواباند — ولی
     * نباید بی‌صدا هم پلنِ بی‌معنا بسازد.
     */
    public function test_a_malformed_size_is_skipped_not_fatal(): void
    {
        Setting::put('proxmox_ir_plans', '1-1-25,ابله,2-2,0-4-40,4-4-40');

        $plans = $this->iranPlans($this->catalog());

        $this->assertCount(2, $plans, 'ردیفِ خراب باید رد شود، نه اینکه پلن بسازد');
        $this->assertSame(1, $plans[0]['vcpu']);
        $this->assertSame(4, $plans[1]['vcpu']);
    }

    /** اگر هیچ ردیفِ سالمی نماند، خط بی‌صدا غیب نمی‌شود. */
    public function test_an_entirely_broken_setting_falls_back_to_the_default_line(): void
    {
        Setting::put('proxmox_ir_plans', 'x,y,z');

        $this->assertNotEmpty($this->iranPlans($this->catalog()));
    }

    /** ردیفِ تکراری یک پلن می‌سازد، نه دو تا. */
    public function test_duplicate_sizes_collapse(): void
    {
        Setting::put('proxmox_ir_plans', '1-1-25,1-1-25');

        $this->assertCount(1, $this->iranPlans($this->catalog()));
    }

    /** رشتهٔ خالیِ شهر = «این خط را نساز» — راهِ خاموش‌کردن بی‌دیپلوی. */
    public function test_an_empty_city_switches_the_whole_line_off(): void
    {
        Setting::put('proxmox_ir_city', '');

        $catalog = $this->catalog();

        $this->assertEmpty($this->iranPlans($catalog));
        $this->assertNull(collect($catalog['locations'])->firstWhere('country', 'IR'));
        // ولی اکسیت سرِ جایش
        $this->assertNotEmpty($catalog['plans']);
    }

    // ═══════════ بها ═══════════

    /**
     * 🔴 دو محصولِ هم‌اندازه روی **یک سختِ‌افزار** نباید دو بهای متفاوت بگیرند.
     *
     * وگرنه گزارشِ سود بی‌معنا می‌شود: همان ماشین، بسته به اینکه از کدام خط
     * فروخته شده، حاشیهٔ متفاوتی نشان می‌دهد.
     */
    public function test_an_iran_plan_costs_the_same_as_the_identical_exit_plan(): void
    {
        Setting::put('proxmox_ir_plans', '2-2-30');

        $catalog = $this->catalog();
        $exit = collect($catalog['plans'])->firstWhere('location_code', 'exit-de');
        $iran = $this->iranPlans($catalog)[0];

        $this->assertSame(2, $exit['vcpu']);
        $this->assertSame(30, $exit['disk_gb']);
        $this->assertSame($exit['cost_eur_cents'], $iran['cost_eur_cents'],
            'بهایِ اسمیِ دو پلنِ هم‌اندازه روی یک سختِ‌افزار باید یکی باشد');
    }

    /** بها با اندازه بالا می‌رود — پلنِ بزرگ‌تر نباید ارزان‌تر در بیاید. */
    public function test_cost_grows_with_size(): void
    {
        Setting::put('proxmox_ir_plans', '1-1-25,2-2-40,4-8-80');

        $costs = array_column($this->iranPlans($this->catalog()), 'cost_eur_cents');
        $sorted = $costs;
        sort($sorted);

        $this->assertSame($sorted, $costs);
        $this->assertSame(count($costs), count(array_unique($costs)));
    }

    // ═══════════ شکلِ خروجی ═══════════

    /**
     * ⚠️ هر کلیدی که `CloudCatalogSync` می‌خوانَد باید باشد. کلیدِ جاافتاده
     * ردیفِ ناقص می‌سازد که تازه سرِ فروش یا تحویل خودش را نشان می‌دهد.
     */
    public function test_the_iran_plan_carries_every_key_the_sync_reads(): void
    {
        $plan = $this->iranPlans($this->catalog())[0];

        foreach (['provider_ref', 'provider_location', 'location_code', 'vcpu', 'ram_mb',
            'disk_gb', 'disk_type', 'traffic_gb', 'cpu_kind', 'arch', 'cost_eur_cents',
            'in_stock', 'name'] as $key) {
            $this->assertArrayHasKey($key, $plan, 'کلیدِ «'.$key.'» در پلنِ ایران نیست');
        }

        // شناسهٔ زیرساخت باید یکتا و قابلِ‌خواندن باشد؛ سینک رویش upsert می‌کند
        $refs = array_column($this->iranPlans($this->catalog()), 'provider_ref');
        $this->assertSame($refs, array_unique($refs));
    }

    /** ایمیجِ اوبونتو برای این خط هم همان قالبِ cloud-init است. */
    public function test_the_iran_line_shares_the_ubuntu_template_image(): void
    {
        $images = $this->catalog()['images'];

        $this->assertCount(1, $images);
        $this->assertSame('ubuntu-24.04', $images[0]['key']);
    }
}
