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

    /** کمکی: همان زنجیرهٔ تبدیلِ درایور، برای ادعای نسبی */
    private function eurCentsFor(float $usdHour): int
    {
        $usdToman = (int) (app(\App\Services\ExchangeRate::class)->toToman('USD') ?? 0);
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
}
