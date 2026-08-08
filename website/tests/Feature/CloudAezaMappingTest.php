<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Cloud\AezaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * نگاشتِ فیلدهای محصولِ زیرساختِ ۲ — با ساختارِ **واقعیِ** آن API.
 *
 * 🔴 چرا این فایل هست: روی حسابِ واقعیِ کارفرما ۳۸۹ محصول خوانده شد و **صفر**
 * پلن ساخته شد. توکن درست بود، مسیر درست بود، ۸۰ سیستم‌عامل هم خوانده می‌شد —
 * تنها مشکل، نامِ فیلدها بود. آن نام‌ها حدسی بودند و داکیومنتِ رسمی نمونهٔ
 * کاملِ JSON ندارد، پس حدس هرگز درست نمی‌شد.
 *
 * نام‌های واقعی از SDKهای **تایپ‌شده** درآمد و نمونه‌های این تست دقیقاً همان
 * ساختار را دارند:
 *
 *  • carlsmei/go-aeza-sdk                → Product / Configuration / Price
 *  • scinfra-pro/terraform-provider-aeza → legacy.Product، group.payload، «کوچک‌ترین یکای ارز»
 *  • nikolai-in/aeza1password            → summaryConfiguration.{cpu,ram,rom}.count
 *  • AezaGroup/aeza-net-sdk (رسمی)       → id / name / payload.oslist / prices
 *
 * دو چیزی که کلِ ماجرا را توضیح می‌دهد و این تست‌ها قفلش می‌کنند:
 *  ۱) «دیسک» در این API **`rom`** است، نه `disk`.
 *  ۲) مشخصات یک **فهرست** است (`configuration[]{slug,base}`)، نه فیلدِ صاف.
 *
 * ⚠️ تلهٔ `Http::fake`: در هر تست **یک بار** ثبت می‌شود. استابها به ترتیبِ ثبت
 * بررسی می‌شوند و یک استابِ همه‌گیر، بعدی‌ها را بی‌صدا بی‌اثر می‌کند — همین
 * یک‌بار در این پروژه تستی را بی‌صدا از کار انداخت.
 */
class CloudAezaMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::putSecret('aeza_api_token', 'k');

        // مسیرها ازقبل کشف‌شده تا تست دربارهٔ کشفِ مسیر نباشد
        Setting::put('aeza_path_products', 'services/products');
        Setting::put('aeza_path_os', 'os');
        Setting::put('aeza_path_recipe', 'vm/recipe');

        // ⚠️ هیچ نرخِ ارزی ست نمی‌شود — و لازم هم نیست: پشتیبانیِ آن ارائه‌دهنده
        // کتباً گفت موجودیِ حساب فقط می‌تواند **یورو** باشد، پس قیمت‌ها یورویی‌اند
        // و مسیرِ `payment/currencies` (که ۵۰۰ می‌داد) از کد حذف شد.
    }

    // ═══════════════════ نمونه‌های واقع‌نما ═══════════════════

    /**
     * گروهِ مکانِ آن ارائه‌دهنده: مکان روی **گروه** است نه روی محصول.
     * `payload.code` کدِ دوحرفیِ کشور، `payload.label` برچسبِ مکان،
     * `payload.mode` اشتراکی/اختصاصی بودنِ هسته.
     */
    private function group(array $over = []): array
    {
        return array_merge([
            'id'      => 12,
            'order'   => 1,
            'name'    => 'Netherlands, Amsterdam',
            'type'    => 'vps',
            'role'    => null,
            'payload' => ['code' => 'nl', 'label' => 'NL-SHARED', 'mode' => 'shared'],
        ], $over);
    }

    /**
     * محصولِ سرورِ مجازی — دقیقاً ساختارِ `legacy.Product` و `Product` گو:
     * `configuration` یک **فهرست** با `slug`/`base` و دیسک با اسلاگِ `rom`.
     *
     * قیمت‌ها به **سنتِ یورو**‌اند (۵۰۰ سنت = ۵ یورو). داکیومنتِ
     * ترافورم‌پروایدرشان می‌گوید عددها «کوچک‌ترین یکای ارز» اند و پشتیبانی گفت
     * آن ارز فقط یورو می‌تواند باشد ⇒ عدد = سنت.
     */
    private function vps(array $over = []): array
    {
        return array_merge([
            'id'             => 181,
            'name'           => 'NLs-2',
            'type'           => 'vps',
            'groupId'        => 12,
            'serviceHandler' => 'vm6',
            'isPrivate'      => false,
            'installPrice'   => 0,
            'configuration'  => [
                ['slug' => 'cpu', 'base' => 2, 'max' => 8, 'type' => 'slider'],
                ['slug' => 'ram', 'base' => 4096, 'max' => 16384, 'type' => 'slider'],
                ['slug' => 'rom', 'base' => 60, 'max' => 400, 'type' => 'slider'],
                ['slug' => 'ip', 'base' => 1, 'max' => 4, 'type' => 'slider'],
            ],
            'prices'    => ['hour' => 1, 'month' => 500, 'year' => 5000],
            'rawPrices' => ['hour' => 1, 'month' => 500, 'year' => 5000],
            'payload'   => ['oslist' => [940, 941]],
            'group'     => $this->group(),
        ], $over);
    }

    /**
     * یک `Http::fake` برای همهٔ مسیرها. **فقط یک بار** ثبت می‌شود.
     */
    private function fake(array $products, array $os = []): void
    {
        Http::fake(function ($request) use ($products, $os) {
            $url = $request->url();

            if (str_contains($url, 'services/products')) {
                return Http::response(
                    ['data' => ['items' => $products, 'total' => count($products)]], 200
                );
            }

            if (str_contains($url, '/api/os')) {
                return Http::response(['data' => ['items' => $os, 'total' => count($os)]], 200);
            }

            return Http::response(['data' => ['items' => [], 'total' => 0]], 200);
        });
    }

    private function catalog(): array
    {
        return app(AezaClient::class)->fetchCatalog();
    }

    // ═══════════════════ سرورِ مجازیِ سالم ═══════════════════

    /**
     * ستونِ فقراتِ این فایل: با ساختارِ واقعی باید **یک پلنِ درست** بسازد.
     * اگر این بشکند، همان خرابیِ «۰ پلن» برگشته است.
     */
    public function test_real_world_vps_row_becomes_a_correct_plan(): void
    {
        $this->fake([$this->vps()]);

        $cat = $this->catalog();

        $this->assertTrue($cat['ok'], (string) ($cat['message'] ?? ''));
        $this->assertCount(1, $cat['plans'], 'پیامِ تشخیص: '.((string) ($cat['message'] ?? '')));

        $plan = $cat['plans'][0];

        $this->assertSame('181', $plan['provider_ref']);
        $this->assertSame(2, $plan['vcpu'], 'هسته از configuration[slug=cpu].base');
        $this->assertSame(4096, $plan['ram_mb'], 'رم از configuration[slug=ram].base');
        $this->assertSame(60, $plan['disk_gb'], '🔴 دیسک از اسلاگِ **rom** است نه disk');
        $this->assertSame('nl-amsterdam', $plan['location_code'], 'کشور از group.payload.code');
        $this->assertSame('shared', $plan['cpu_kind']);
        $this->assertTrue($plan['in_stock']);

        // ۵۰۰ سنت = ۵ یورو. هیچ ضریبِ ارزی در کار نیست.
        $this->assertSame(500, $plan['cost_eur_cents'], 'عددِ API سنتِ یورو است');

        // مکان هم باید ساخته شود، وگرنه پلن جای نشستن ندارد
        $this->assertSame('NL', $cat['locations'][0]['country']);
    }

    /** `summaryConfiguration` (نگاشتِ slug⇒{count}) هم باید خوانده شود */
    public function test_summary_configuration_shape_is_understood(): void
    {
        $row = $this->vps();
        unset($row['configuration']);

        $row['summaryConfiguration'] = [
            'cpu' => ['slug' => 'cpu', 'count' => 4, 'base' => 4],
            'ram' => ['slug' => 'ram', 'count' => 8192, 'base' => 8192],
            'rom' => ['slug' => 'rom', 'count' => 120, 'base' => 120],
            'ip'  => ['slug' => 'ip', 'count' => 1, 'base' => 1],
        ];

        $this->fake([$row]);

        $plan = $this->catalog()['plans'][0] ?? null;

        $this->assertNotNull($plan);
        $this->assertSame(4, $plan['vcpu']);
        $this->assertSame(8192, $plan['ram_mb']);
        $this->assertSame(120, $plan['disk_gb']);
    }

    /**
     * `prices.month` می‌تواند شیء باشد: `{value, suffix, defaultCurrency, slug}`
     * (ساختارِ `Price` در SDKِ گو). این شکل هم باید بخواند.
     */
    public function test_object_shaped_price_is_understood(): void
    {
        $row = $this->vps();
        unset($row['rawPrices']);
        $row['prices'] = [
            'month' => ['value' => 500, 'suffix' => '€', 'defaultCurrency' => true, 'slug' => 'eur'],
        ];

        $this->fake([$row]);

        $this->assertSame(500, $this->catalog()['plans'][0]['cost_eur_cents'] ?? null);
    }

    /**
     * قیمتِ اختصاصیِ حسابِ ما باید **برنده** شود — همان است که واقعاً می‌پردازیم.
     */
    public function test_individual_price_wins_over_list_price(): void
    {
        $this->fake([$this->vps(['individualPrices' => ['month' => 300]])]);

        // ۳۰۰ سنت = ۳ یورو
        $this->assertSame(300, $this->catalog()['plans'][0]['cost_eur_cents'] ?? null);
    }

    /**
     * واحدِ قیمت یک **تنظیم** است، نه یک حدس.
     *
     * 🔴 چرا حدس‌زدن ممکن نیست: عددِ ۵۰۰ اگر سنت باشد ۵ یورو است و اگر یورو
     * باشد ۵۰۰ یورو، و **هر دو** برای یک سرورِ مجازی ممکن‌اند (اولی VPSِ کوچک،
     * دومی پلنِ چندده‌هسته‌ای). هیچ بازه‌ای این دو را جدا نمی‌کند، پس حدس‌زدن
     * یعنی گاهی ۱۰۰ برابر ارزان فروختن — و درست همین اتفاق روی حسابِ واقعی
     * افتاد و کارفرما با چشم دیدش.
     */
    public function test_price_divisor_is_an_explicit_setting(): void
    {
        // پیش‌فرض: سنت (÷۱۰۰) — از داکیومنتِ Terraform همان ارائه‌دهنده
        $this->assertSame(100.0, \App\Services\Cloud\AezaClient::priceDivisor());

        \App\Models\Setting::put('aeza_price_divisor', '1');
        $this->assertSame(1.0, \App\Services\Cloud\AezaClient::priceDivisor());

        // مقدارِ بی‌معنا نباید بی‌صدا قیمت را خراب کند
        \App\Models\Setting::put('aeza_price_divisor', '7');
        $this->assertSame(100.0, \App\Services\Cloud\AezaClient::priceDivisor());
    }

    /** با مقسومِ ۱، همان عدد **یوروی کامل** خوانده می‌شود */
    public function test_divisor_one_reads_the_number_as_whole_euros(): void
    {
        \App\Models\Setting::put('aeza_price_divisor', '1');

        // هر دو کیف را عوض کن: rawPrices پیش از prices خوانده می‌شود
        $this->fake([$this->vps(['prices' => ['month' => 5], 'rawPrices' => ['month' => 5]])]);

        $cat = app(\App\Services\Cloud\AezaClient::class)->fetchCatalog();

        $this->assertCount(1, $cat['plans'], (string) ($cat['message'] ?? ''));
        // ۵ یورو = ۵۰۰ سنت
        $this->assertSame(500, $cat['plans'][0]['cost_eur_cents']);
    }


    /** قیمتِ بی‌معنا در هر دو تفسیر ⇒ **هیچ** قیمتی ساخته نشود */
    public function test_implausible_price_is_rejected_rather_than_guessed(): void
    {
        $this->fake([$this->vps(['prices' => ['month' => 3], 'rawPrices' => ['month' => 3]])]);

        $cat = $this->catalog();

        $this->assertSame([], $cat['plans']);
        $this->assertStringContainsString('بی‌قیمتِ ماهانه', $cat['message']);
    }

    /** پلنِ اختصاصی‌هسته (`hicpu`) سرورِ مجازی است و باید فروخته شود */
    public function test_hicpu_is_a_virtual_server_with_dedicated_cores(): void
    {
        $row = $this->vps([
            'id'    => 205,
            'name'  => 'EPs-4',
            'type'  => 'hicpu',
            'group' => $this->group(['payload' => ['code' => 'de', 'label' => 'DE-DEDICATED', 'mode' => 'dedicated'],
                'name' => 'Germany, Frankfurt']),
        ]);

        $this->fake([$row]);

        $plan = $this->catalog()['plans'][0] ?? null;

        $this->assertNotNull($plan, 'hicpu قبلاً به‌غلط رد می‌شد — بخشی از آن ۲۷۸ محصول');
        $this->assertSame('dedicated', $plan['cpu_kind']);
        $this->assertSame('de-frankfurt', $plan['location_code']);
    }

    // ═══════════════════ آنچه باید رد شود ═══════════════════

    /**
     * کارفرما: «فعلاً فقط سرور مجازی». پروکسی و WAF و دامنه و فضا و سرورِ
     * فیزیکی نباید روی سایت بنشینند — نه صفحه‌شان را ساخته‌ایم نه تحویلشان را.
     */
    public function test_non_virtual_products_are_all_rejected(): void
    {
        $this->fake([
            $this->vps(),

            // پروکسی: نوعِ صریح
            ['id' => 300, 'name' => 'SOCKS5 Proxy', 'type' => 'proxy', 'serviceHandler' => 'proxy',
                'prices' => ['month' => 10000], 'group' => $this->group()],

            // WAF: نوعِ صریح + دستگیرهٔ خودش
            ['id' => 301, 'name' => 'WAF Protection', 'type' => 'waf', 'serviceHandler' => 'waf',
                'prices' => ['month' => 30000], 'group' => $this->group()],

            // سرورِ فیزیکی: مشخصاتش شبیهِ VPS است ولی محصولِ دیگری است
            ['id' => 302, 'name' => 'AMD Ryzen 9 5950X – 10 Gbps', 'type' => 'dedicated',
                'serviceHandler' => 'manual',
                'configuration' => [
                    ['slug' => 'cpu', 'base' => 32], ['slug' => 'ram', 'base' => 131072],
                    ['slug' => 'rom', 'base' => 2000],
                ],
                'prices' => ['month' => 900000], 'group' => $this->group()],

            // دامنه
            ['id' => 303, 'name' => '.com', 'type' => 'domain', 'serviceHandler' => 'feru',
                'prices' => ['month' => 100000], 'group' => $this->group()],

            // فضای ابری
            ['id' => 304, 'name' => 'S3 Storage 500GB', 'type' => 'storage', 'serviceHandler' => 's3',
                'configuration' => [['slug' => 'rom', 'base' => 500]],
                'prices' => ['month' => 40000], 'group' => $this->group()],

            // لایسنسِ پنل
            ['id' => 305, 'name' => 'ISPmanager Lite', 'type' => 'soft', 'serviceHandler' => 'ispmgr',
                'prices' => ['month' => 20000], 'group' => $this->group()],
        ]);

        $cat = $this->catalog();

        $this->assertCount(1, $cat['plans'], 'فقط سرورِ مجازیِ سالم باید بماند');
        $this->assertSame('181', $cat['plans'][0]['provider_ref']);
    }

    /**
     * دستگیرهٔ سرویس قطعی‌ترین نشانه است: `manual` یعنی تحویلِ دستی (فیزیکی)،
     * حتی اگر فیلدِ نوع دروغ بگوید و `vps` باشد.
     */
    public function test_manual_handler_beats_a_lying_type_field(): void
    {
        $this->fake([$this->vps(['id' => 400, 'type' => 'vps', 'serviceHandler' => 'manual'])]);

        $cat = $this->catalog();

        $this->assertSame([], $cat['plans']);
        $this->assertStringContainsString('دستگیرهٔ سرویس', $cat['message']);
    }

    /** بی‌فیلدِ نوع، صافیِ نام کار می‌کند (پشتیبانِ نسخه‌های قدیمی‌ترِ API) */
    public function test_name_filter_still_works_when_type_is_absent(): void
    {
        $this->fake([
            ['id' => 500, 'name' => 'Proxy Pack 10',
                'configuration' => [['slug' => 'cpu', 'base' => 1], ['slug' => 'ram', 'base' => 512],
                    ['slug' => 'rom', 'base' => 10]],
                'prices' => ['month' => 10000], 'group' => $this->group()],
        ]);

        $cat = $this->catalog();

        $this->assertSame([], $cat['plans']);
        $this->assertStringContainsString('نامِ مشخصاً غیرِ سرورِ مجازی', $cat['message']);
    }

    /**
     * 🔴 مشخصاتِ ناخوانا باید **رد** شود، نه با صفر ذخیره. پلنِ «۰ هسته / ۰ گیگ»
     * روی سایت، از نبودِ پلن بدتر است: مشتری می‌خرد و ما تحویل نمی‌دهیم.
     */
    public function test_product_without_specs_is_rejected_not_stored_as_zero(): void
    {
        $row = $this->vps(['id' => 600, 'name' => 'Mystery Box']);
        unset($row['configuration']);

        $this->fake([$row]);

        $cat = $this->catalog();

        $this->assertSame([], $cat['plans'], 'هیچ پلنی با صفر نباید ساخته شود');
        $this->assertStringContainsString('هیچ مشخصه‌ای نداشت', $cat['message']);
    }

    /** مشخصاتِ نصفه (رم دارد، هسته و دیسک ندارد) هم رد می‌شود — و جدا شمرده */
    public function test_partial_specs_are_rejected_and_counted_separately(): void
    {
        $this->fake([$this->vps(['id' => 601, 'configuration' => [['slug' => 'ram', 'base' => 2048]]])]);

        $cat = $this->catalog();

        $this->assertSame([], $cat['plans']);
        $this->assertStringContainsString('مشخصاتِ ناقص', $cat['message']);
    }

    /** سقفِ ارتقا نباید جای مقدارِ پایه فروخته شود */
    public function test_max_is_never_sold_as_the_included_amount(): void
    {
        $this->fake([$this->vps(['configuration' => [
            ['slug' => 'cpu', 'base' => 2, 'max' => 8],
            ['slug' => 'ram', 'base' => 4096, 'max' => 32768],
            ['slug' => 'rom', 'base' => 60, 'max' => 800],
        ]])]);

        $plan = $this->catalog()['plans'][0];

        $this->assertSame(2, $plan['vcpu'], 'اگر ۸ شد، سقفِ ارتقا را پلن حساب کرده‌ایم');
        $this->assertSame(4096, $plan['ram_mb']);
        $this->assertSame(60, $plan['disk_gb']);
    }

    // ═══════════════════ موجودی ═══════════════════

    /**
     * مکانِ بسته = ناموجود. بی‌این، پلنِ تمام‌شده را می‌فروشیم و تحویل با خطا
     * شکست می‌خورد — یعنی پولِ گرفته‌شده و تحویلِ ناممکن.
     */
    public function test_disabled_location_group_marks_the_plan_out_of_stock(): void
    {
        $this->fake([$this->vps([
            'group' => $this->group(['payload' => [
                'code' => 'nl', 'label' => 'NL-SHARED', 'mode' => 'shared', 'isDisabled' => true,
            ]]),
        ])]);

        $plan = $this->catalog()['plans'][0] ?? null;

        $this->assertNotNull($plan, 'ناموجود یعنی «نفروش»، نه «حذف از کاتالوگ»');
        $this->assertFalse($plan['in_stock']);
    }

    // ═══════════════════ ساختارِ گروه‌بندی‌شده ═══════════════════

    /**
     * اگر آن API روزی محصولات را گروه‌به‌گروه بدهد (هر ردیف یک دسته با آرایهٔ
     * `products` درونش)، باید باز شود. بی‌این، **گروه‌ها** را محصول می‌فهمیدیم و
     * چون گروه هسته/رم/دیسک ندارد، همه‌شان رد می‌شدند.
     */
    public function test_grouped_response_is_flattened_and_group_fields_are_inherited(): void
    {
        $child = $this->vps();
        unset($child['group']);            // مکان فقط روی والد است

        $this->fake([[
            'id'       => 12,
            'name'     => 'Netherlands, Amsterdam',
            'type'     => 'vps',
            'payload'  => ['code' => 'nl', 'label' => 'NL-SHARED', 'mode' => 'shared'],
            'products' => [$child],
        ]]);

        $cat = $this->catalog();

        $this->assertCount(1, $cat['plans'], 'پیامِ تشخیص: '.((string) ($cat['message'] ?? '')));
        $this->assertSame('181', $cat['plans'][0]['provider_ref'], 'محصولِ فرزند، نه خودِ گروه');
        $this->assertSame('nl-amsterdam', $cat['plans'][0]['location_code'], 'مکان از والد ارث می‌رسد');
    }

    // ═══════════════════ پشتیبانِ چندمسیره ═══════════════════

    /**
     * نام‌های حدسیِ قبلی حذف نشده‌اند. این API یک‌بار عوض شده
     * (core.aeza.net ← my.aeza.net) و باز هم عوض می‌شود؛ پشتیبان ارزشش را دارد.
     */
    public function test_legacy_flat_field_names_still_work(): void
    {
        $this->fake([[
            'id' => 77, 'name' => 'EPs-1', 'type' => 'vm',
            'cpu' => 2, 'ram' => 4096, 'disk' => 60,
            'location' => ['country' => 'DE', 'city' => 'Frankfurt', 'id' => 'de-1'],
            // به سنتِ یورو، مثلِ بقیه — ۵۰۰ سنت = ۵ یورو
            'prices' => ['month' => 500.0],
        ]]);

        $plan = $this->catalog()['plans'][0] ?? null;

        $this->assertNotNull($plan);
        $this->assertSame(2, $plan['vcpu']);
        $this->assertSame(4096, $plan['ram_mb']);
        $this->assertSame(60, $plan['disk_gb']);
        $this->assertSame('de-frankfurt', $plan['location_code']);
        $this->assertSame(500, $plan['cost_eur_cents']);
    }

    /** رمِ نیم‌گیگ نباید ۵۱۲ گیگابایت فهمیده شود */
    public function test_half_gigabyte_ram_is_not_read_as_512_gigabytes(): void
    {
        $this->fake([$this->vps(['configuration' => [
            ['slug' => 'cpu', 'base' => 1],
            ['slug' => 'ram', 'base' => 512],
            ['slug' => 'rom', 'base' => 10],
        ]])]);

        $this->assertSame(512, $this->catalog()['plans'][0]['ram_mb'] ?? null);
    }

    /** رم به گیگابایت هم پذیرفته می‌شود (واحد در هیچ منبعی صریح نبود) */
    public function test_gigabyte_ram_is_normalized_to_megabytes(): void
    {
        $this->fake([$this->vps(['configuration' => [
            ['slug' => 'cpu', 'base' => 2],
            ['slug' => 'ram', 'base' => 4],
            ['slug' => 'rom', 'base' => 60],
        ]])]);

        $this->assertSame(4096, $this->catalog()['plans'][0]['ram_mb'] ?? null);
    }

    // ═══════════════════ سفیدبرچسبی ═══════════════════

    /**
     * واژگانِ بومیِ ارائه‌دهنده نباید به لایهٔ بالا سرریز کند.
     *
     * توجه: `name` و `provider_ref` عمداً نامِ خامِ ارائه‌دهنده‌اند — لایهٔ مدل
     * (`CloudPlan::$hidden`) آنها را از هر JSONای بیرون می‌گذارد و `public_name`
     * جایشان را می‌گیرد. آنچه اینجا مهم است این است که **واژگانِ اختصاصیِ آن
     * API** (نوعِ محصول، دستگیرهٔ سرویس، ساختارِ گروه) به کاتالوگ راه پیدا نکند.
     */
    public function test_catalog_rows_carry_no_provider_vocabulary(): void
    {
        $this->fake([$this->vps()]);

        $plan = $this->catalog()['plans'][0];

        foreach (['type', 'serviceHandler', 'group', 'configuration', 'prices', 'rawPrices', 'payload'] as $native) {
            $this->assertArrayNotHasKey($native, $plan, "کلیدِ بومیِ «{$native}» نباید در ردیفِ کاتالوگ باشد");
        }

        $json = (string) json_encode($plan, JSON_UNESCAPED_UNICODE);

        foreach (['aeza', 'Aeza', 'vm6', 'hicpu'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, "«{$secret}» نباید در ردیفِ کاتالوگ باشد");
        }
    }

    // ═══════════════════ صفحهٔ عیب‌یابی ═══════════════════

    /**
     * اگر بازهم چیزی نخواند، `rawProbe()` باید **بیشترین** کمک را بکند: اسلاگ‌های
     * واقعیِ مشخصات، دوره‌های قیمت، کلیدهای گروه، و نتیجهٔ نگاشت روی همان ردیف.
     * بی‌این، گزارشِ «۰ پلن» عیب‌یابی را به حدس‌وگمان تبدیل می‌کند.
     */
    public function test_raw_probe_reports_the_actual_field_shape(): void
    {
        $this->fake([$this->vps()], [['id' => 940, 'name' => 'Ubuntu 24.04']]);

        $probe = app(AezaClient::class)->rawProbe();

        $shape = $probe['products']['tried']['services/products']['shape'] ?? [];

        $this->assertContains('configuration', $shape['keys'] ?? []);
        $this->assertSame('vps', $shape['type'] ?? null);
        $this->assertSame('vm6', $shape['service_handler'] ?? null);

        // 🔴 همان چیزی که نبودش ساعت‌ها وقت برد: اسلاگ‌های واقعیِ مشخصات
        $this->assertSame(['cpu', 'ram', 'rom', 'ip'], $shape['config_slugs']['configuration'] ?? []);
        $this->assertSame(['hour', 'month', 'year'], $shape['price_terms']['prices'] ?? []);
        $this->assertSame(['code', 'label', 'mode'], array_keys($shape['group_payload'] ?? []));

        // و این‌که نگاشتِ فعلی از همین ردیف چه فهمید
        $this->assertSame('ok', $shape['parsed']['vps_verdict'] ?? null);
        $this->assertSame(60, $shape['parsed']['specs']['disk_gb'] ?? null);

        // 🔴 عددِ **خام** کنارِ عددِ **تفسیرشده** و مقسومی که به کار رفته: تنها
        // راهی که مدیر بتواند با فاکتورِ واقعیِ خودش بسنجد کدام واحد درست است.
        $this->assertSame(500.0, $shape['parsed']['monthly_raw'] ?? null);
        $this->assertSame(100.0, $shape['parsed']['price_divisor'] ?? null);
        $this->assertSame(500, $shape['parsed']['monthly_eur_cents'] ?? null);
    }

    /** ردیفی که هیچ‌چیزش نخواند هم باید کلیدهایش را لو بدهد */
    public function test_raw_probe_lists_first_level_keys_of_an_unknown_row(): void
    {
        $this->fake([['ident' => 1, 'title' => 'Whatever', 'hardware' => ['c' => 2]]]);

        $probe = app(AezaClient::class)->rawProbe();
        $shape = $probe['products']['tried']['services/products']['shape'] ?? [];

        $this->assertSame(['ident', 'title', 'hardware'], $shape['keys'] ?? null);
        $this->assertSame(0, $shape['parsed']['specs']['vcpu'] ?? null, 'و صریح بگوید که چیزی نخواند');
    }

    // ═══════════════════ پلنِ تشویقی (PROMO) ═══════════════════

    /**
     * 🔴 کارفرما دید: «قیمتِ پکیجِ PROMO سوئد خیلی ارزون افتاده».
     *
     * قیمتش واقعاً پایین است — ولی **موقت**. چون ما قیمتِ مشتری را سرِ سفارش
     * قفل می‌کنیم و سرویس خودکار تمدید می‌شود، از دورهٔ دوم هر تمدید ضررِ خالص
     * است و ماه‌به‌ماه بی‌صدا تکرار می‌شود. پس پیش‌فرض کنار می‌رود.
     */
    public function test_promo_named_plan_is_skipped_by_default(): void
    {
        $this->fake([
            $this->vps(),
            $this->vps(['id' => 900, 'name' => 'SEs-1 PROMO', 'prices' => ['month' => 90], 'rawPrices' => ['month' => 90]]),
        ]);

        $cat = $this->catalog();

        $refs = array_column($cat['plans'], 'provider_ref');

        $this->assertContains('181', $refs, 'پلنِ عادی باید بماند');
        $this->assertNotContains('900', $refs, 'پلنِ تشویقی نباید فروخته شود');
    }

    /**
     * نشانهٔ قطعی‌تر از نام: خودِ ارائه‌دهنده می‌گوید نرخِ دورهٔ اول پایین‌تر است.
     * حتی بی‌کلمهٔ PROMO در نام، این یعنی قیمت موقتی است.
     */
    public function test_plan_with_a_cheaper_first_period_is_treated_as_promo(): void
    {
        $this->fake([
            $this->vps([
                'id' => 901, 'name' => 'NLs-9',
                'prices'      => ['month' => 500],
                'rawPrices'   => ['month' => 500],
                'firstPrices' => ['month' => 90],     // دورهٔ اول ارزان‌تر
            ]),
        ]);

        $cat = $this->catalog();

        $this->assertSame([], $cat['plans'], 'قیمتِ دورهٔ اولِ ارزان‌تر = نرخِ موقت');
        $this->assertStringContainsString('تشویقی', (string) ($cat['message'] ?? ''),
            'گزارش باید دلیلش را بگوید، نه فقط «۰ پلن»');
    }

    /** نرخِ برابرِ دورهٔ اول و عادی، تشویقی نیست */
    public function test_equal_first_and_normal_price_is_not_promo(): void
    {
        $this->fake([
            $this->vps([
                'prices'      => ['month' => 500],
                'rawPrices'   => ['month' => 500],
                'firstPrices' => ['month' => 500],
            ]),
        ]);

        $this->assertCount(1, $this->catalog()['plans']);
    }

    /** مدیر می‌تواند آگاهانه برشان گرداند */
    public function test_admin_can_opt_into_promo_plans(): void
    {
        \App\Models\Setting::put('aeza_include_promo', '1');

        $this->fake([
            $this->vps(['id' => 900, 'name' => 'SEs-1 PROMO', 'prices' => ['month' => 90], 'rawPrices' => ['month' => 90]]),
        ]);

        $this->assertCount(1, $this->catalog()['plans'], 'با تنظیمِ صریح باید بیاید');
    }
}
