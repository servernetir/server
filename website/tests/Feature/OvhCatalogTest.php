<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Cloud\OvhClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * کاتالوگِ فروشِ زیرساختِ ۴ (OVHcloud).
 *
 * ═══ فیکسچرِ این فایل از پاسخِ **واقعیِ** حسابِ خودمان آمده ═══
 *
 * (`/admin/cloud/probe?provider=ovh`، ۱۵ شهریور ۱۴۰۵ — همان شکل، همان
 * کلیدها، همان اعداد.) این عمدی است و درسِ همین هفته پشتش است: شکلِ
 * `locations[]`ِ هتزنر **حدس** زده شد، تست با همان حدس نوشته شد، هر دو سبز
 * شدند، و رفع روی پروداکشن هیچ ردیفی را فیلتر نکرد.
 *
 * سه چیزی که فقط از دیدنِ پاسخِ واقعی معلوم شد و هیچ‌کدام حدس‌زدنی نبود:
 *
 * ۱) از ۲۴۳ ردیفِ کاتالوگ، بیشترشان **افزونه**‌اند. تنها تفاوتِ ساختاریِ
 *    قابلِ اتکا این است که سرورِ واقعی پیکربندیِ `vps_datacenter` دارد.
 * ۲) قیمت در واحدِ ۱۰⁻⁸ است (`850000000` ⇒ ۸٫۵۰) و خودِ پاسخ با
 *    `formattedPrice: "$8.50 USD"` تأییدش می‌کند.
 * ۳) 🔴 ارزِ حساب **دلار** است، نه یورو — و کلِ زنجیرهٔ قیمتِ این پروژه
 *    یورویی است.
 */
class OvhCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const AK = 'appkey123';
    private const AS = 'appsecret456';
    private const CK = 'consumer789';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::putSecret('ovh_app_key', self::AK);
        Setting::putSecret('ovh_app_secret', self::AS);
        Setting::putSecret('ovh_consumer_key', self::CK);

        // نرخ‌ها صریح، تا ادعای بها عددِ **قطعی** باشد نه تقریبی
        Setting::put('pricing_rate_override', '2000000');      // ۱ یورو
        Setting::put('pricing_usd_rate_override', '1000000');  // ۱ دلار ⇒ ضریب ۰٫۵
        Setting::put('pricing_fx_fee_pct', '0');
    }

    /** یک پلنِ VPSِ واقعی، عیناً به شکلِ پاسخِ زنده */
    private function vpsPlan(array $over = []): array
    {
        return array_merge([
            'planCode' => 'vps-2025-model1.LZ',
            'invoiceName' => 'VPS-1 LZ 2026',
            'product' => 'vps-2025-model1-lz',
            'pricings' => [
                // نصب و ارتقا — نباید به‌عنوان قیمتِ ماهانه خوانده شوند
                ['phase' => 0, 'capacities' => ['installation'], 'commitment' => 0,
                    'intervalUnit' => 'none', 'price' => 0],
                ['phase' => 1, 'capacities' => ['renew'], 'commitment' => 0,
                    'intervalUnit' => 'month', 'price' => 850000000,
                    'formattedPrice' => '$8.50 USD'],
                // 🔴 نرخِ تعهدی — ارزان‌تر، ولی ما ماهانه می‌خریم
                ['phase' => 1, 'capacities' => ['renew'], 'commitment' => 12,
                    'intervalUnit' => 'month', 'price' => 723000000,
                    'formattedPrice' => '$7.23 USD'],
                ['phase' => 1, 'capacities' => ['renew'], 'commitment' => 6,
                    'intervalUnit' => 'month', 'price' => 808000000,
                    'formattedPrice' => '$8.08 USD'],
            ],
            'configurations' => [
                ['name' => 'region', 'values' => ['united_states']],
                ['name' => 'vps_datacenter', 'values' => ['US-EAST-LZ-NYC', 'US-WEST-LZ-LAX']],
            ],
            'family' => 'vps-2025-lz',
            'blobs' => ['commercial' => ['features' => [['name' => 'Anti-DDoS', 'value' => 'Yes']]]],
        ], $over);
    }

    /** یک افزونه — بی‌`vps_datacenter`، دقیقاً مثلِ ردیفِ اولِ پاسخِ واقعی */
    private function addonRow(): array
    {
        return [
            'planCode' => 'option-storage-remote-2027-ca',
            'invoiceName' => 'Remote Storage 2027',
            'product' => 'vps-option-storage-remote-resell',
            'pricings' => [['phase' => 1, 'capacities' => ['renew'], 'commitment' => 0,
                'intervalUnit' => 'month', 'price' => 0]],
            'configurations' => [['name' => 'region', 'values' => ['canada']]],
            'blobs' => null,
        ];
    }

    private function fake(array $plans, ?array $technical = null, string $currency = 'USD'): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);

        $technical ??= [[
            'planCode' => 'vps-2025-model1.LZ',
            'blobs' => ['technical' => [
                'cpu' => ['cores' => 2],
                'memory' => ['size' => 2048],
                'storage' => ['disks' => [['capacity' => 40]]],
            ]],
        ]];

        Http::fake([
            '*/1.0/auth/time' => Http::response((string) time()),
            '*/1.0/me' => Http::response([
                'nichandle' => 'x', 'ovhSubsidiary' => 'US',
                'currency' => ['code' => $currency, 'symbol' => '$'],
            ]),
            '*/order/catalog/formatted/vps*' => Http::response(['plans' => $technical]),
            '*/order/catalog/public/vps*' => Http::response(['plans' => $plans]),
        ]);
    }

    private function client(): OvhClient
    {
        return app(OvhClient::class);
    }

    // ───────────────────────── ادعاها ─────────────────────────

    /**
     * 🔴 ادعای اصلی: هر دیتاسنتر یک ردیف، با بهایی که واقعاً از **دلار** تبدیل
     * شده.
     *
     * ۸٫۵۰ دلار × ضریبِ ۰٫۵ = ۴٫۲۵ یورو = ۴۲۵ سنت. اگر کسی روزی تبدیل را
     * بردارد، این عدد ۸۵۰ می‌شود و تست همان‌جا می‌شکند.
     */
    public function test_a_real_vps_plan_becomes_one_row_per_datacentre(): void
    {
        $this->fake([$this->addonRow(), $this->vpsPlan()]);

        $c = $this->client()->fetchCatalog();

        $this->assertTrue($c['ok'], $c['message']);
        $this->assertCount(2, $c['plans']);

        foreach ($c['plans'] as $p) {
            $this->assertSame('vps-2025-model1.LZ', $p['provider_ref']);
            $this->assertSame(2, $p['vcpu']);
            $this->assertSame(2048, $p['ram_mb']);
            $this->assertSame(40, $p['disk_gb']);
            $this->assertSame(425, $p['cost_eur_cents'], 'بها باید از دلار به یورو تبدیل شده باشد');
        }

        $this->assertSame(
            ['US-EAST-LZ-NYC', 'US-WEST-LZ-LAX'],
            array_column($c['plans'], 'provider_location')
        );
    }

    /**
     * 🔴 افزونه ردیف نمی‌گیرد.
     *
     * از ۲۴۳ ردیفِ کاتالوگِ واقعی بیشترشان افزونه‌اند؛ بی‌این فیلتر، فروشگاه
     * پر می‌شد از «cPanel 100» و «Remote Storage» به‌عنوان سرورِ مجازی.
     */
    public function test_an_addon_row_never_becomes_a_plan(): void
    {
        $this->fake([$this->addonRow()]);

        $c = $this->client()->fetchCatalog();

        $this->assertFalse($c['ok']);
        $this->assertSame([], $c['plans']);
    }

    /**
     * 🔴 نرخِ **تعهدی** بهای ما نیست.
     *
     * همان پلن ۷٫۲۳ دلارِ دوازده‌ماهه هم دارد. برداشتنِ ارزان‌ترین یعنی بهایی
     * ثبت کنیم که فقط با پیش‌پرداختِ یک‌ساله واقعی است — و ما ماهانه می‌خریم،
     * پس روی هر سرور ضرر می‌کردیم بی‌آنکه هیچ خطایی ثبت شود.
     */
    public function test_a_committed_price_is_never_used_as_the_cost(): void
    {
        $this->fake([$this->vpsPlan()]);

        $c = $this->client()->fetchCatalog();

        // ۷٫۲۳ ⇒ ۳۶۲ سنت · ۸٫۰۸ ⇒ ۴۰۴ سنت — هیچ‌کدام نباید انتخاب شوند
        $this->assertSame(425, $c['plans'][0]['cost_eur_cents']);
    }

    /**
     * 🔴 ردیفِ بی‌مشخصات ذخیره نمی‌شود، **شمرده و گزارش** می‌شود.
     *
     * ذخیره با صفر یعنی فروشِ پلنی که مشتری نمی‌داند چه می‌خرد — و سکوت یعنی
     * هیچ‌کس نمی‌فهمد چرا کاتالوگ ناقص است.
     */
    public function test_a_plan_without_technical_specs_is_rejected_and_reported(): void
    {
        $this->fake([$this->vpsPlan()], technical: []);

        $c = $this->client()->fetchCatalog();

        $this->assertFalse($c['ok']);
        $this->assertStringContainsString('بی‌مشخصات', $c['message']);
    }

    /** مشخصاتِ بی‌معنا هم رد می‌شود — «۰ هسته» بدتر از نبودن است */
    public function test_out_of_range_specs_are_rejected(): void
    {
        $this->fake([$this->vpsPlan()], technical: [[
            'planCode' => 'vps-2025-model1.LZ',
            'blobs' => ['technical' => [
                'cpu' => ['cores' => 0], 'memory' => ['size' => 2048],
                'storage' => ['disks' => [['capacity' => 40]]],
            ]],
        ]]);

        $this->assertFalse($this->client()->fetchCatalog()['ok']);
    }

    /**
     * 🔴 دیتاسنترِ ناشناخته ردیف نمی‌گیرد و **نامش گزارش می‌شود**.
     *
     * حدس‌زدنِ شهر از روی کد یعنی مشتری «نیویورک» بخرد و سرورش جای دیگری بالا
     * بیاید؛ و چون تحویل دستی است، تا شکایتِ خودش معلوم نمی‌شود.
     */
    public function test_an_unknown_datacentre_is_skipped_and_named(): void
    {
        $plan = $this->vpsPlan([
            'configurations' => [
                ['name' => 'vps_datacenter', 'values' => ['US-EAST-LZ-NYC', 'XX-NOWHERE-9']],
            ],
        ]);

        $this->fake([$plan]);
        $c = $this->client()->fetchCatalog();

        $this->assertTrue($c['ok']);
        $this->assertCount(1, $c['plans']);
        $this->assertStringContainsString('XX-NOWHERE-9', $c['message']);
    }

    /**
     * 🔴 بی‌نرخِ تبدیل، کاتالوگ ساخته **نمی‌شود**.
     *
     * ضریبِ پیش‌فرضِ ۱ یعنی دلار را یورو بخوانیم و بهایِ تمام‌شده ~۸٪ کمتر ثبت
     * شود — خطایی که تحویل را خراب نمی‌کند و فقط ماه‌ها بعد در صورت‌حساب پیدا
     * می‌شود. همان الگوی «سربارِ ارزیِ جاافتاده».
     */
    public function test_without_a_conversion_rate_nothing_is_built(): void
    {
        Setting::put('pricing_usd_rate_override', '0');
        Setting::put('pricing_rate_override', '0');

        $this->fake([$this->vpsPlan()]);

        $c = $this->client()->fetchCatalog();

        $this->assertFalse($c['ok']);
        $this->assertSame([], $c['plans']);
    }

    /** حسابِ یورویی تبدیل نمی‌خواهد و بها همان عددِ خودش است */
    public function test_a_euro_account_needs_no_conversion(): void
    {
        $this->fake([$this->vpsPlan()], currency: 'EUR');

        $c = $this->client()->fetchCatalog();

        $this->assertTrue($c['ok'], $c['message']);
        $this->assertSame(850, $c['plans'][0]['cost_eur_cents']);
    }

    /** خریدِ خودکار همچنان خاموش است — این متد فقط قیمت می‌سازد */
    public function test_ordering_is_still_manual(): void
    {
        $r = $this->client()->createServer(['x' => 1]);

        $this->assertFalse($r['ok']);
        $this->assertTrue($r['manual']);
    }
}
