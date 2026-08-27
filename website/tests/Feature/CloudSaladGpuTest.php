<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\CloudNaming;
use App\Services\Cloud\SaladClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * زیرساختِ ۶ (GPU) — و سه تلهٔ پولی که هنگامِ ساختش پیدا شدند.
 *
 * ⚠️ هیچ تماسِ واقعی‌ای زده نمی‌شود؛ حسابِ آنها سندباکس ندارد و هر ساختِ گروه
 * پولِ واقعی است. همه با `Http::fake()`.
 */
class CloudSaladGpuTest extends TestCase
{
    use RefreshDatabase;

    private function configure(): void
    {
        // ⚠️ بی‌نرخ، بها عمداً صفر می‌شود (رفتارِ درست) و تستِ ترکیبِ قیمت
        // چیزی برای سنجیدن ندارد. پس هر دو نرخ دستی ست می‌شوند.
        Setting::put('pricing_rate_override', '100000');      // ۱ یورو
        Setting::put('pricing_usd_rate_override', '90000');   // ۱ دلار

        Setting::putSecret('salad_api_key', 'k-test');
        Setting::put('salad_org', 'servernet');
        Setting::put('salad_project', 'prod');
    }

    /** یک کلاسِ GPU با قیمتِ هر چهار اولویت */
    private function gpuClass(array $over = []): array
    {
        return array_merge([
            'id'          => 'gc-4090',
            'name'        => 'RTX 4090',
            'gpu_count'   => 1,
            'max_vcpu'    => 8,
            'max_ram'     => 30720,
            'max_storage' => 100_000_000_000,
            'is_high_demand' => false,
            'prices'      => [
                ['priority' => 'high', 'price' => '0.500'],
                ['priority' => 'batch', 'price' => '0.100'],
            ],
        ], $over);
    }

    private function fakeClasses(array $rows): void
    {
        Http::fake(['api.salad.com/*' => Http::response(['items' => $rows], 200)]);
    }

    public function test_the_driver_is_registered_and_resolves(): void
    {
        $d = app(CloudManager::class)->driver('salad');

        $this->assertInstanceOf(SaladClient::class, $d);
        $this->assertSame('salad', $d->slug());
    }

    /**
     * ⚠️ کلیدِ تنها کافی نیست — هر مسیرِ این API نامِ سازمان را در خودش دارد.
     * بی‌این شرط، درایور «تنظیم‌شده» اعلام می‌شد و هر تماس ۴۰۴ می‌گرفت.
     */
    public function test_a_key_without_an_organisation_is_not_configured(): void
    {
        Setting::putSecret('salad_api_key', 'k-test');

        $this->assertFalse(app(CloudManager::class)->driver('salad')->isConfigured());

        Setting::put('salad_org', 'servernet');

        $this->assertTrue(app(CloudManager::class)->driver('salad')->isConfigured());
    }

    /**
     * 🔴 گران‌ترین تلهٔ این زیرساخت: بهایِ تمام‌شده **سه تکه** است و API فقط
     * تکهٔ GPU را می‌دهد.
     *
     * بی‌افزودنِ vCPU و رم، روی پیکربندیِ بزرگ زیرِ قیمتِ خرید می‌فروشیم — روی
     * **هر ساعت**، بی‌هیچ خطایی.
     */
    public function test_the_cost_includes_cpu_and_ram_not_just_the_gpu(): void
    {
        $this->configure();
        Setting::put('salad_priority', 'high');
        $this->fakeClasses([$this->gpuClass()]);

        $plans = app(CloudManager::class)->driver('salad')->fetchCatalog()['plans'];

        $this->assertCount(1, $plans);

        // نرخِ پیش‌فرض: ۸ هسته × ۰٫۰۰۴ + ۳۰ گیگ × ۰٫۰۰۱ = ۰٫۰۶۲ دلار/ساعت
        // پس بهایِ ساعتی باید از ۰٫۵ (تنها GPU) **بیشتر** باشد.
        $gpuOnly = $this->eurCentsFor(0.500);
        $withAll = $plans[0]['cost_eur_cents'];

        $this->assertGreaterThan($gpuOnly, $withAll,
            'بهایِ تمام‌شده فقط قیمتِ GPU را گرفته — vCPU و رم جا افتاده‌اند.');
    }

    /**
     * 🔴 کارمزدِ انتقالِ ارز روی **بهایِ تمام‌شده** می‌نشیند، نه روی قیمتِ فروش.
     *
     * دلاری که واقعاً به حسابِ زیرساخت می‌رسد گران‌تر از نرخِ اسمیِ بازار تمام
     * می‌شود (کارمزدِ حواله، اسپردِ صرافی). تا امروز این تکه هیچ‌جا حساب نمی‌شد،
     * پس حاشیهٔ سودِ واقعی از آنچه فکر می‌کردیم کمتر بود.
     *
     * ⚠️ جای نشستنش تستِ جدایی می‌خواهد: اگر روی قیمتِ فروش بنشیند، کارمزد از
     * جیبِ حاشیه می‌رود و در گزارشِ مالی نامرئی می‌مانَد.
     */
    public function test_the_fx_transfer_fee_raises_the_cost_basis(): void
    {
        $this->configure();
        Setting::put('salad_priority', 'high');
        $this->fakeClasses([$this->gpuClass()]);

        $without = app(CloudManager::class)->driver('salad')->fetchCatalog()['plans'][0]['cost_eur_cents'];

        Setting::put('pricing_fx_fee_pct', '10');
        app()->forgetInstance(CloudManager::class);

        $with = app(CloudManager::class)->driver('salad')->fetchCatalog()['plans'][0]['cost_eur_cents'];

        $this->assertGreaterThan($without, $with, 'کارمزد به بهایِ تمام‌شده اضافه نشد.');

        // ۱۰٪ یعنی حدودِ ۱۰٪ — نه دو برابر، نه بی‌اثر.
        $this->assertEqualsWithDelta($without * 1.10, $with, max(2, $without * 0.005));
    }

    /**
     * ⚠️ پیش‌فرض **صفر** است، نه عددِ حدسی: کارمزدِ هر مسیر فرق دارد و حدسِ ما
     * به‌جای مدیر یعنی قیمتی که هیچ‌کس نمی‌داند از کجا آمده.
     */
    public function test_no_fee_is_assumed_when_the_setting_is_empty(): void
    {
        $this->configure();
        Setting::put('salad_priority', 'high');
        Setting::put('pricing_fx_fee_pct', null);
        $this->fakeClasses([$this->gpuClass()]);

        $plan = app(CloudManager::class)->driver('salad')->fetchCatalog()['plans'][0];

        // بی‌کارمزد، عدد باید دقیقاً همان زنجیرهٔ خام باشد
        $raw = 0.500 + (8 * SaladClient::DEFAULT_VCPU_USD_HOUR) + (30 * SaladClient::DEFAULT_RAM_GB_USD_HOUR);

        $this->assertSame($this->eurCentsFor($raw), $plan['cost_eur_cents']);
    }

    /** کمکی: همان زنجیرهٔ تبدیلِ درایور، برای ادعای نسبی */
    private function eurCentsFor(float $usdHour): int
    {
        // ⚠️ همان زنجیرهٔ درایور: **اول** نرخِ دستی، بعد زنده. اگر این‌جا مستقیم
        // نرخِ زنده خوانده شود، در تست صفر می‌دهد و ادعا بی‌معنا می‌شود — همان
        // ناهماهنگیِ فیکسچر که یک بار همین‌جا رخ داد.
        $usdToman = (int) (Setting::get('pricing_usd_rate_override', '0') ?: 0);

        if ($usdToman <= 0) {
            $usdToman = (int) (app(\App\Services\ExchangeRate::class)->toToman('USD') ?? 0);
        }
        $eurToman = (int) app(\App\Services\Cloud\CloudPricing::class)->eurToToman();

        if ($usdToman <= 0 || $eurToman <= 0) {
            return 0;
        }

        return (int) round((($usdHour * SaladClient::HOURS_PER_MONTH * $usdToman) / $eurToman) * 100);
    }

    /**
     * 🔴 دو کارتِ کاملاً متفاوت با vCPU/رم/دیسکِ یکسان نباید یک اسلاگ بگیرند.
     *
     * اسلاگ کلیدِ گروه‌بندیِ `offers()` است و `bestForSlug()` **ارزان‌ترین** را
     * برمی‌دارد ⇒ مشتری پولِ کارتِ گران را می‌داد و کارتِ ارزان تحویل می‌گرفت.
     * همان تلهٔ ثبت‌شدهٔ «ARM و x86 با اسلاگِ یکسان»، این‌بار روی پول.
     */
    public function test_two_different_gpus_never_collapse_into_one_offer(): void
    {
        $a = CloudNaming::planSlug(8, 30720, 100, 'global-gpu', 'shared', 'RTX 4090', 1);
        $b = CloudNaming::planSlug(8, 30720, 100, 'global-gpu', 'shared', 'H100', 1);

        $this->assertNotSame($a, $b, 'دو کارتِ متفاوت اسلاگِ یکسان گرفتند.');
    }

    /**
     * 🔴 گاردِ اسلاگ باید در **مسیرِ واقعی** اجرا شود، نه فقط وقتی تست تابع را
     * مستقیم صدا می‌زند.
     *
     * افزودنِ پارامتر به `planSlug()` کافی نبود: `CloudCatalogSync` آن را پاس
     * نمی‌داد، پس دو کارتِ متفاوت باز هم یک اسلاگ می‌گرفتند و تستِ بالا سبز
     * می‌مانْد در حالی که پروداکشن خراب بود. همان تلهٔ ثبت‌شده — تستی که خودش
     * تابع را صدا می‌زند، سیم‌کشی را نمی‌سنجد.
     *
     * پس این تست از **بیرون** می‌رود: کاتالوگ را می‌سازد و روی ردیف‌های
     * ذخیره‌شده در دیتابیس ادعا می‌کند.
     */
    public function test_the_catalogue_sync_actually_writes_the_gpu_columns(): void
    {
        $this->configure();
        Setting::put('salad_priority', 'high');

        $this->fakeClasses([
            $this->gpuClass(),
            $this->gpuClass(['id' => 'gc-h100', 'name' => 'H100', 'prices' => [
                ['priority' => 'high', 'price' => '2.500'],
            ]]),
        ]);

        app(\App\Services\Cloud\CloudCatalogSync::class)->sync('salad');

        $rows = \App\Models\CloudPlan::where('provider', 'salad')->get();

        $this->assertCount(2, $rows, 'همگام‌ساز پلن‌های GPU را ننوشت.');

        foreach ($rows as $r) {
            $this->assertNotNull($r->gpu_model, 'ستونِ gpu_model خالی ماند — `$fillable` جا افتاده؟');
            $this->assertTrue((bool) $r->is_interruptible, 'پرچمِ قطع‌شدنی ذخیره نشد.');
        }

        // 🔴 و مهم‌ترین ادعا: دو کارتِ متفاوت **در دیتابیس** دو اسلاگ دارند.
        $this->assertCount(2, $rows->pluck('slug')->unique(),
            'دو کارتِ متفاوت در مسیرِ واقعی اسلاگِ یکسان گرفتند — گارد صدا زده نشد.');
    }

    /**
     * ⚠️ و نیمهٔ دیگر: اسلاگِ پلن‌های **بی‌GPU** باید بایت‌به‌بایت دست‌نخورده
     * بماند، وگرنه هر ردیفِ موجود دوباره‌اسلاگ می‌شود و گروه‌بندیِ امروز می‌شکند.
     */
    public function test_plans_without_a_gpu_keep_their_existing_slug(): void
    {
        $this->assertSame(
            CloudNaming::planSlug(2, 4096, 40, 'de-frankfurt'),
            CloudNaming::planSlug(2, 4096, 40, 'de-frankfurt', 'shared', null, null),
        );
    }

    /**
     * 🔴 هر پلنِ این زیرساخت **قطع‌شدنی** است، حتی در بالاترین اولویت.
     *
     * مستنداتِ خودشان صریح‌اند. اگر این پرچم دروغ بگوید، همان صفحه‌ای که سرورِ
     * پایدار می‌فروشد این را هم کنارش می‌گذارد و تعهدِ `/sla` زیرش می‌رود.
     */
    public function test_every_gpu_plan_is_flagged_interruptible(): void
    {
        $this->configure();
        Setting::put('salad_priority', 'high');
        $this->fakeClasses([$this->gpuClass()]);

        foreach (app(CloudManager::class)->driver('salad')->fetchCatalog()['plans'] as $p) {
            $this->assertTrue($p['is_interruptible'], 'پلنِ GPU «پایدار» علامت خورد.');
        }
    }

    /**
     * ⚠️ کلاسی که قیمتِ اولویتِ انتخابی را ندارد **رد** می‌شود، نه ذخیره با صفر.
     * قیمتِ صفر یعنی «رایگان» و `scopeSellable` هم بیرونش نمی‌گذارد اگر بقیه
     * پر باشد — پس فروشِ کارتِ گران به قیمتِ هیچ.
     */
    public function test_a_class_without_a_price_for_our_priority_is_skipped(): void
    {
        $this->configure();
        Setting::put('salad_priority', 'high');

        $this->fakeClasses([
            $this->gpuClass(['id' => 'gc-x', 'name' => 'RTX 3060', 'prices' => [
                ['priority' => 'batch', 'price' => '0.047'],
            ]]),
        ]);

        $out = app(CloudManager::class)->driver('salad')->fetchCatalog();

        $this->assertFalse($out['ok'], 'کلاسِ بی‌قیمت پذیرفته شد.');
        $this->assertSame([], $out['plans']);
    }

    /**
     * 🔴 فهرستِ خالیِ **موفق** یعنی «هیچ کارتی نداریم» و همگام‌ساز همهٔ پلن‌های
     * قبلی را ناموجود می‌کند. ولی خالی معمولاً یعنی «نتوانستیم بپرسیم».
     * همان قاعدهٔ ایمنیِ `CloudInventory`.
     */
    public function test_an_empty_catalogue_is_reported_as_failure_not_as_zero_cards(): void
    {
        $this->configure();
        $this->fakeClasses([]);

        $this->assertFalse(app(CloudManager::class)->driver('salad')->fetchCatalog()['ok']);
    }

    /**
     * 🔴 بی‌ایمیج، تحویل عمداً انجام **نمی‌شود**.
     *
     * کانتینری که بالا بیاید و مشتری راهی به داخلش نداشته باشد، از
     * تحویل‌نشدن بدتر است: پول رفته و سرویس بی‌فایده است.
     */
    public function test_delivery_refuses_when_no_container_image_is_configured(): void
    {
        $this->configure();
        Setting::put('salad_image', null);

        Http::fake(['api.salad.com/*' => Http::response(['name' => 'sn-svc-1'], 200)]);

        $r = app(CloudManager::class)->driver('salad')
            ->createServer(['name' => 'sn-svc-1', 'plan_ref' => 'gc-4090']);

        $this->assertFalse($r['ok']);
        $this->assertNull($r['ref']);
        Http::assertNothingSent();
    }

    /**
     * ⚠️ ۴۰۴ روی حذف = «از قبل نبود» = موفق — همان قاعدهٔ هر درایورِ دیگر.
     * بی‌آن، سرویسِ خاتمه‌یافته تا ابد در صفِ آزادسازی می‌مانْد.
     */
    public function test_deleting_an_already_gone_group_counts_as_success(): void
    {
        $this->configure();
        Http::fake(['api.salad.com/*' => Http::response(['message' => 'not found'], 404)]);

        $this->assertTrue(app(CloudManager::class)->driver('salad')->deleteServer('sn-svc-9')['ok']);
    }

    /**
     * ⚠️ شکستِ خواندنِ فهرست **صریح** برمی‌گردد، نه فهرستِ خالیِ موفق — وگرنه
     * گزارشِ موجودی می‌گوید همهٔ سرورهای مشتریان ناپدید شده‌اند.
     */
    public function test_a_failed_listing_never_looks_like_zero_servers(): void
    {
        $this->configure();
        Http::fake(['api.salad.com/*' => Http::response(['message' => 'bad key'], 401)]);

        $r = app(CloudManager::class)->driver('salad')->listServers();

        $this->assertFalse($r['ok']);
        $this->assertSame([], $r['servers']);
    }

    /**
     * ⚠️ آنچه این زیرساخت **ندارد** باید صریح false باشد تا رابطِ کاربری
     * دکمهٔ بی‌فایده نسازد و فروشگاه چیزی نفروشد که تحویلش ممکن نیست.
     */
    public function test_capabilities_admit_what_this_provider_cannot_do(): void
    {
        $caps = app(CloudManager::class)->driver('salad')->capabilities();

        foreach (['rebuild', 'reset_password', 'resize', 'console', 'extra_ip', 'ssh_key'] as $k) {
            $this->assertFalse($caps[$k], "توانایی «{$k}» را دارد ادعا می‌کند در حالی که ندارد.");
        }
    }

    /**
     * ⚠️ پیامِ خطا هرگز نباید خودِ کلید را حمل کند — این متن تا لاگ و پنل
     * می‌رود. استثنای شبکه فقط با **نامِ کلاس** گزارش می‌شود.
     */
    public function test_a_transport_failure_never_leaks_the_api_key(): void
    {
        $this->configure();
        Http::fake(fn () => throw new \RuntimeException('connect failed for key k-test'));

        $msg = app(CloudManager::class)->driver('salad')->listServers()['message'];

        $this->assertStringNotContainsString('k-test', $msg);
    }
    /**
     * 🔴 نامِ **پروژه** باید در آزمونِ اتصال سنجیده شود، نه سرِ اولین سفارش.
     *
     * تماسِ `gpu-classes` فقط کلید و **سازمان** را می‌سنجد؛ نامِ پروژه تنها در
     * مسیرِ ساختِ کانتینر ظاهر می‌شود. اگر آزمون آن را نبیند، یک غلطِ تایپی در
     * فرمِ تنظیمات تا لحظه‌ای پنهان می‌مانَد که پولِ مشتری گرفته شده و تحویل
     * شکست می‌خورد — همان الگوی «محافظی که در بدترین لحظه امتحان می‌شود».
     */
    public function test_a_wrong_project_name_fails_the_connection_test(): void
    {
        $this->configure();

        Http::fake([
            // سازمان درست است
            'api.salad.com/api/public/organizations/servernet/gpu-classes'
                => Http::response(['items' => [$this->gpuClass()]], 200),
            // ولی پروژه وجود ندارد
            'api.salad.com/api/public/organizations/servernet/projects/*'
                => Http::response(['message' => 'not found'], 404),
        ]);

        $r = app(SaladClient::class)->testConnection();

        $this->assertFalse($r['ok'], 'پروژهٔ ناموجود «اتصالِ برقرار» گزارش شد.');
        $this->assertStringContainsString('پروژه', $r['message'],
            'پیام نمی‌گوید مشکل از پروژه است — مدیر دنبالِ کلید و سازمان می‌گردد.');
    }

    /** وقتی هر دو درست‌اند، آزمون سبز است و هر دو تماس زده شده */
    public function test_the_connection_test_checks_both_org_and_project(): void
    {
        $this->configure();

        Http::fake([
            'api.salad.com/api/public/organizations/servernet/gpu-classes'
                => Http::response(['items' => [$this->gpuClass()]], 200),
            'api.salad.com/api/public/organizations/servernet/projects/prod/containers'
                => Http::response(['items' => []], 200),
        ]);

        $r = app(SaladClient::class)->testConnection();

        $this->assertTrue($r['ok'], $r['message']);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/gpu-classes'));
        Http::assertSent(fn ($req) => str_contains($req->url(), '/projects/prod/containers'));
    }

    /** پلنی که createServer لازم دارد (درایور مشخصات را از کاتالوگ می‌خوانَد) */
    private function seedPlan(): void
    {
        \App\Models\CloudLocation::firstOrCreate(
            ['code' => 'global-gpu'],
            ['country' => 'XX', 'is_active' => true, 'sort' => 1],
        );
        \App\Models\CloudPlan::create([
            'provider' => 'salad', 'provider_ref' => 'gc-4090',
            'provider_location' => 'global', 'location_code' => 'global-gpu',
            'public_name' => 'RTX 4090', 'slug' => 'cv-8c-30g-100d-global-gpu-rtx-4090',
            'vcpu' => 8, 'ram_mb' => 30720, 'disk_gb' => 100, 'disk_type' => 'ssd',
            'traffic_gb' => 0, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 400, 'price_eur_cents' => 600, 'price_irt' => 720000,
            'is_active' => true, 'in_stock' => true,
            'gpu_model' => 'RTX 4090', 'gpu_count' => 1, 'is_interruptible' => true,
        ]);
    }

    /**
     * 🔴 انتخابِ فروشگاه (image_ref) باید بر تنظیمِ سراسری بچربد.
     *
     * پیش از این درایور فقط تنظیمِ مدیر را می‌خواند و انتخابِ مشتری بی‌صدا
     * دور ریخته می‌شد — مشتری Jupyter می‌خرید و Ollama تحویل می‌گرفت.
     */
    public function test_the_customer_chosen_image_beats_the_global_setting(): void
    {
        $this->configure();
        $this->seedPlan();
        Setting::put('salad_image', 'admin/global:latest');

        Http::fake(['api.salad.com/*' => Http::response(['name' => 'sn-svc-9'], 200)]);

        $chosen = SaladClient::APPS['gpu-ollama']['ref'];

        $r = app(CloudManager::class)->driver('salad')->createServer([
            'name' => 'sn-svc-9', 'plan_ref' => 'gc-4090', 'image_ref' => $chosen,
        ]);

        $this->assertTrue($r['ok'], $r['message']);
        Http::assertSent(fn ($req) => data_get($req->data(), 'container.image') === $chosen);
    }

    /**
     * 🔴 دروازه باید با **پورتِ همان برنامه** روشن شود، وگرنه کانتینر بالا
     * می‌آید، GPU مصرف می‌کند و مشتری هیچ نشانی‌ای ندارد — تحویلِ «موفق».
     *
     * auth هم باید false باشد: authِ زیرساخت فقط کلیدِ کلِ حسابِ ما را
     * می‌پذیرد و دادنی به مشتری نیست.
     */
    public function test_the_gateway_opens_on_the_apps_own_port(): void
    {
        $this->configure();
        $this->seedPlan();

        Http::fake(['api.salad.com/*' => Http::response(['name' => 'sn-svc-9'], 200)]);

        app(CloudManager::class)->driver('salad')->createServer([
            'name' => 'sn-svc-9', 'plan_ref' => 'gc-4090',
            'image_ref' => SaladClient::APPS['gpu-ollama']['ref'],
        ]);

        Http::assertSent(function ($req) {
            $n = data_get($req->data(), 'networking');

            return is_array($n) && $n['port'] === 11434
                && $n['protocol'] === 'http' && $n['auth'] === false;
        });
    }

    /**
     * ⚠️ ایمیجِ ناشناخته (دلخواهِ مدیر) دروازه **نمی‌گیرد** — پورتش را
     * نمی‌دانیم و پورتِ حدسی همان خرابیِ خاموش است از درِ دیگر.
     */
    public function test_an_unknown_image_gets_no_gateway(): void
    {
        $this->configure();
        $this->seedPlan();
        Setting::put('salad_image', 'customer/own-image:v1');

        Http::fake(['api.salad.com/*' => Http::response(['name' => 'sn-svc-9'], 200)]);

        app(CloudManager::class)->driver('salad')
            ->createServer(['name' => 'sn-svc-9', 'plan_ref' => 'gc-4090']);

        Http::assertSent(fn ($req) => ! array_key_exists('networking', $req->data()));
    }

    /**
     * 🔴 برنامه‌ای که توکن می‌فهمد (Jupyter) باید توکنِ **اختصاصی** بگیرد و
     * همان توکن به‌عنوانِ root_password برگردد تا پنل یک بار نشانش دهد.
     * بی‌این، نشانیِ عمومیِ بی‌قفل یعنی GPUِ پولیِ مشتری در دستِ هر رهگذر.
     */
    public function test_jupyter_gets_a_per_customer_token(): void
    {
        $this->configure();
        $this->seedPlan();

        Http::fake(['api.salad.com/*' => Http::response(['name' => 'sn-svc-9'], 200)]);

        $r = app(CloudManager::class)->driver('salad')->createServer([
            'name' => 'sn-svc-9', 'plan_ref' => 'gc-4090',
            'image_ref' => SaladClient::APPS['gpu-jupyter']['ref'],
        ]);

        $this->assertTrue($r['ok'], $r['message']);
        $this->assertNotNull($r['root_password'], 'توکن به پنل برنگشت — مشتری هرگز نمی‌بیندش.');

        Http::assertSent(function ($req) use ($r) {
            $env = data_get($req->data(), 'container.environment_variables', []);

            return ($env['JUPYTER_TOKEN'] ?? null) === $r['root_password']
                && ($env['NOTEBOOK_ARGS'] ?? '') !== '';
        });
    }

    /**
     * ⚠️ ایمیجِ خامِ Ollama پشتِ دروازهٔ IPv6 بی‌صدا جواب نمی‌دهد؛ درایور
     * باید OLLAMA_HOST='::' را خودش تزریق کند (کاری که entrypointِ دستورِ
     * رسمیِ زیرساخت می‌کند).
     */
    public function test_ollama_gets_the_ipv6_binding_it_needs(): void
    {
        $this->configure();
        $this->seedPlan();

        Http::fake(['api.salad.com/*' => Http::response(['name' => 'sn-svc-9'], 200)]);

        app(CloudManager::class)->driver('salad')->createServer([
            'name' => 'sn-svc-9', 'plan_ref' => 'gc-4090',
            'image_ref' => SaladClient::APPS['gpu-ollama']['ref'],
        ]);

        Http::assertSent(fn ($req) => data_get($req->data(),
            'container.environment_variables.OLLAMA_HOST') === '::');
    }

    /** کاتالوگ برنامه‌های آماده را به‌عنوانِ ایمیجِ kind=app برمی‌گرداند */
    public function test_the_catalogue_ships_the_app_images(): void
    {
        $this->configure();
        $this->fakeClasses([$this->gpuClass()]);

        $r = app(CloudManager::class)->driver('salad')->fetchCatalog();

        $this->assertTrue($r['ok']);
        $keys = array_column($r['images'], 'key');
        $this->assertContains('gpu-ollama', $keys);
        $this->assertContains('gpu-jupyter', $keys);

        foreach ($r['images'] as $img) {
            $this->assertSame('app', $img['kind']);
        }
    }

    /**
     * 🔴 هر رشتهٔ کاتالوگ باید در ستونش **جا شود**.
     *
     * ستونِ country دو حرفیِ ISO است؛ درایور یک بار متنِ فارسی در آن گذاشت.
     * SQLiteِ محلی طول را چک نمی‌کند و MariaDBِ پروداکشن سینک را کشت
     * (Data too long for column). این تست طول‌ها را روی خودِ خروجیِ درایور
     * می‌سنجد تا شکاف SQLite/MariaDB دیگر پنهانش نکند.
     */
    public function test_catalogue_strings_fit_their_database_columns(): void
    {
        $this->configure();
        $this->fakeClasses([$this->gpuClass()]);

        $r = app(CloudManager::class)->driver('salad')->fetchCatalog();

        $this->assertTrue($r['ok']);

        foreach ($r['locations'] as $loc) {
            $this->assertLessThanOrEqual(2, mb_strlen((string) $loc['country']),
                'country باید کدِ ISOِ دوحرفی باشد، نه متن: '.$loc['country']);
            $this->assertLessThanOrEqual(32, mb_strlen((string) $loc['code']));
        }

        foreach ($r['images'] as $img) {
            $this->assertLessThanOrEqual(96, mb_strlen((string) $img['provider_ref']));
            $this->assertLessThanOrEqual(64, mb_strlen((string) $img['key']));
            $this->assertLessThanOrEqual(96, mb_strlen((string) $img['label']));
        }

        foreach ($r['plans'] as $plan) {
            // slug و public_name را سینک می‌سازد؛ درایور name و ref می‌دهد
            $this->assertLessThanOrEqual(64, mb_strlen((string) $plan['name']));
            $this->assertLessThanOrEqual(96, mb_strlen((string) $plan['provider_ref']));
        }
    }

    /** برچسبِ «شبکهٔ توزیع‌شده» از نقشهٔ کشورها می‌آید، نه از ستونِ country */
    public function test_the_distributed_network_label_still_renders(): void
    {
        $loc = new \App\Models\CloudLocation(['code' => 'global-gpu', 'country' => 'XX']);

        $this->assertStringContainsString('توزیع', $loc->label('fa'));
        $this->assertSame('🌐', $loc->flagEmoji());
    }

}
