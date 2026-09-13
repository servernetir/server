<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «چیزی پیدا کردم» شکستِ فرمان نیست.
 *
 * ═══ رخدادی که این فایل از آن آمد (۱۵ شهریور ۱۴۰۵) ═══
 *
 * `/admin/errors` سه روزِ پیاپی ردیفِ **۵۰۰** داشت:
 *
 *     Scheduled command [... cloud:hourly-audit --notify] failed with exit code [1]
 *     Scheduled command [... links:site] failed with exit code [1]
 *     Scheduled command [... links:content] failed with exit code [1]
 *
 * هیچ‌کدام خراب نبودند. هر سه **درست دویده بودند** و کدِ ۱ داده بودند چون
 * چیزی پیدا کرده بودند: سرویسِ ساعتیِ زیرِ بها، و لینکِ داخلیِ شکسته. زمان‌بند
 * هر کدِ غیرِصفر را استثنا گزارش می‌کند، پس یافته به‌شکلِ **خطای سرور** در
 * فهرست می‌نشست.
 *
 * 🔴 بهایش بیشتر از شلوغی است. در فهرستی که هر روز یک قرمزِ بی‌معنا دارد،
 * قرمزِ **واقعی** هم دیده نمی‌شود — همان درسِ ثبت‌شدهٔ پروژه دربارهٔ تستِ
 * فلیکی: «قرمزِ تصادفی یاد می‌دهد که قرمز را نادیده بگیرند، یعنی روزی که
 * قرمزِ واقعی بیاید هم کسی نگاهش نمی‌کند.»
 *
 * ⚠️ و یافته هیچ‌جا گم نمی‌شود: هر سه فرمان پیش از `return` یک
 * `ErrorTracker::noteOnce` می‌زنند و ممیزیِ ساعتی با `--notify` به بله و
 * ایمیلِ مدیر هم می‌رود. کدِ خروجی **کانالِ گزارش نیست**؛ فقط می‌گوید
 * «دویدم یا نه».
 */
class ScheduledFindingIsNotAFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        // بی‌نرخِ یورو، ممیزی هر سرویس را رد می‌کند و تست بی‌صدا هیچ‌چیز
        // نمی‌سنجد — همان تلهٔ «فیکسچری که حالتِ مسئله را نمی‌سازد».
        \App\Models\Setting::put('pricing_rate_override', '1000000');
    }

    /** یک سرویسِ ساعتی که نرخِ قفل‌شده‌اش زیرِ بهای تمام‌شده است */
    private function underwaterService(): Service
    {
        $customer = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'uw'.random_int(1, 99999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('secret-pass-123'), 'status' => 'active', 'locale' => 'fa',
        ]);

        CloudLocation::firstOrCreate(['code' => 'de-falkenstein'],
            ['country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true]);

        $plan = CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cx22-'.random_int(1, 999999),
            'provider_location' => 'fsn1', 'location_code' => 'de-falkenstein',
            'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de-falkenstein',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            // بهایِ ساعتیِ گران، در برابرِ نرخِ قفل‌شدهٔ ارزانِ پایین
            'cost_hour_eur_micro' => 90000,
            'is_active' => true, 'in_stock' => true,
        ]);

        return Service::create([
            'customer_id' => $customer->id, 'name' => 'سرورِ ابری CV-2-4',
            'currency_code' => 'IRT', 'price' => 570000, 'tax_percent' => 0,
            'cycle' => 'monthly', 'billing_mode' => 'hourly',
            'hourly_rate_irt' => 1,          // عمداً مضحک‌پایین ⇒ حتماً زیرِ بها
            'status' => 'active', 'provision_status' => 'done',
            'cloud_plan_id' => $plan->id, 'cloud_image_key' => 'ubuntu-24.04',
            'activated_at' => now(), 'next_due_at' => now()->addMonth(),
        ]);
    }

    // ───────────────────────── ممیزیِ ساعتی ─────────────────────────

    /**
     * 🔴 ادعای اصلی: اجرای **زمان‌بندی‌شده** (`--notify`) با یافته هم موفق است.
     *
     * آن‌جا اعلان رفته و `noteOnce` خورده — یعنی گزارش **رسیده**. کدِ خروجی
     * دیگر لازم نیست حرف بزند، و اگر بزند فقط ردیفِ ۵۰۰ می‌سازد.
     */
    public function test_the_scheduled_hourly_audit_succeeds_even_when_it_finds_a_loss(): void
    {
        $this->underwaterService();

        $this->artisan('cloud:hourly-audit --notify')->assertExitCode(0);
    }

    /**
     * ⚠️ ولی اجرای **دستی** رفتارِ قبلی را نگه می‌دارد.
     *
     * بدونِ `--notify` هیچ اعلانی نمی‌رود، پس کدِ خروجی تنها راهِ فهمیدنِ
     * نتیجه است — چه برای آدم، چه برای CI.
     */
    public function test_a_manual_hourly_audit_still_fails_when_it_finds_a_loss(): void
    {
        $this->underwaterService();

        $this->artisan('cloud:hourly-audit')->assertExitCode(1);
    }

    /** بی‌یافته، هر دو حالت موفق‌اند */
    public function test_a_clean_audit_succeeds_either_way(): void
    {
        $this->artisan('cloud:hourly-audit')->assertExitCode(0);
        $this->artisan('cloud:hourly-audit --notify')->assertExitCode(0);
    }

    // ───────────────────────── خزندهٔ لینک ─────────────────────────

    /**
     * 🔴 پرچم باید **در زمان‌بند هم پاس داده شود**، نه فقط تعریف شده باشد.
     *
     * گزینه‌ای که تعریف شود و صدا زده نشود، یک رفعِ کاملاً بی‌اثر است که
     * تستِ «گزینه وجود دارد» سبز نشانش می‌دهد. پس خودِ `routes/console.php`
     * خوانده می‌شود — همان الگوی `ContentPipelineTest`.
     */
    public function test_the_schedule_actually_passes_the_scheduled_flag(): void
    {
        $console = file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString("Schedule::command('links:site --scheduled')", $console);
        $this->assertStringContainsString("Schedule::command('links:content --scheduled')", $console);
    }

    /** و خودِ فرمان‌ها باید آن پرچم را بشناسند، وگرنه اجرا می‌ترکد */
    public function test_both_crawlers_define_the_scheduled_option(): void
    {
        foreach (['links:site', 'links:content'] as $name) {
            $definition = $this->app[\Illuminate\Contracts\Console\Kernel::class]
                ->all()[$name]->getDefinition();

            $this->assertTrue($definition->hasOption('scheduled'),
                "{$name} گزینهٔ --scheduled را ندارد");
        }
    }
}
