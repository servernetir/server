<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Notify\AdminNotifier;
use App\Support\ErrorTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `domains:check-contracts` — قراردادِ امضانشده را **پیش از** فروش پیدا کن.
 *
 * ═══ چرا این فرمان هست ═══
 *
 * مشتری `partolastik.com` را خرید، پول رفت، و ثبت شکست خورد چون قراردادِ
 * رجیستریِ `.com` در حسابِ ما امضا نشده بود. تنها راهِ فهمیدن، همان شکست بود.
 * `TldGate` جلوی مشتریِ **دوم** را می‌گیرد؛ این فرمان مشتریِ **اول** را هم
 * حذف می‌کند.
 *
 * ═══ سه ادعایی که این فایل قفل می‌کند ═══
 *
 *   ۱) قراردادِ امضانشده به مدیر خبر می‌دهد، با نامِ خودِ قرارداد
 *   ۲) اجرای دومِ **بی‌تغییر** ساکت است — وگرنه از هفتهٔ دوم خوانده نمی‌شود
 *   ۳) 🔴 شکستِ خواندن **هرگز** «همه‌چیز امضا شده» تفسیر نمی‌شود
 *
 * ادعای سوم گران‌ترینشان است: یک توکنِ منقضی هم آرایهٔ خالی می‌دهد. اگر آن را
 * «هیچ قراردادِ امضانشده‌ای نیست» بخوانیم، امضای وضعیت پاک می‌شود و دفعهٔ بعد
 * که واقعاً چیزی امضا نشده باشد «تغییری رخ نداد» و هیچ خبری نمی‌رود — همان
 * تلهٔ `CloudInventory` که فهرستِ خالیِ زیرساخت را «همهٔ سرورها ناپدید شدند»
 * می‌خواند.
 */
class DomainContractMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openprovider.username', 'test@example.com');
        config()->set('services.openprovider.password', 'secret');
    }

    /**
     * اعلان‌های مدیر را می‌گیرد بی‌آنکه بله/SMTP واقعی صدا شود.
     *
     * ⚠️ `ArrayObject` نه آرایه: آرایهٔ PHP با مقدار پاس می‌شود و جاسوس در
     * نسخهٔ خودش می‌نوشت، پس تست همیشه صفر می‌دید — سبزی که هیچ‌چیز نمی‌سنجد.
     */
    private function spyOnAdminNotices(): \ArrayObject
    {
        $seen = new \ArrayObject;

        $this->app->instance(AdminNotifier::class, new class($seen) extends AdminNotifier
        {
            public function __construct(private \ArrayObject $box)
            {
                // عمداً parent::__construct صدا نمی‌شود: وابستگیِ بله لازم نیست
            }

            /* ⚠️ امضا باید دقیقاً با والد بخواند؛ `$buttons` وقتی اضافه شد که
               اعلان‌ها دکمهٔ شیشه‌ای گرفتند. */
            public function event(string $title, array $rows = [], ?string $url = null, string $emoji = '🔔', array $buttons = [], ?string $key = null): void
            {
                $this->box[] = ['title' => $title, 'rows' => $rows, 'url' => $url];
            }
        });

        return $seen;
    }

    /**
     * ⚠️ کارخانهٔ نو: یک `Http::fake()`ِ همه‌گیرِ قبلی هر استابِ بعدی را بی‌اثر
     * می‌کند (اولین تطبیق برنده است).
     *
     * @param  array<int,array<string,mixed>>  $contracts
     */
    private function fakeContracts(array $contracts, int $code = 0, string $desc = ''): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            '*/auth/login*' => Http::response(['code' => 0, 'data' => ['token' => 't']]),
            '*/resellers/settings*' => Http::response(
                ['code' => $code, 'desc' => $desc, 'data' => ['signed_contracts' => $contracts]],
                $code === 0 ? 200 : 500,
            ),
        ]);
    }

    // ═══════════════ ۱) خبر دادن ═══════════════

    /** 🔴 هستهٔ ادعا: قراردادِ امضانشده به مدیر می‌رسد و نامش در پیام هست */
    public function test_an_unsigned_contract_notifies_the_admin(): void
    {
        $box = $this->spyOnAdminNotices();

        $this->fakeContracts([
            ['title' => '.com registry contract', 'is_signed' => false],
            ['title' => '.ir registry contract', 'is_signed' => true],
        ]);

        $this->artisan('domains:check-contracts')->assertSuccessful();

        $this->assertCount(1, (array) $box, 'قراردادِ امضانشده هیچ اعلانی نساخت');

        $flat = json_encode($box[0], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('.com registry contract', $flat,
            'نامِ قرارداد در اعلان نیست — مدیر نمی‌داند کدام را امضا کند');
        $this->assertStringNotContainsString('.ir registry contract', $flat,
            'قراردادِ امضاشده هم گزارش شد');
        $this->assertStringContainsString('openprovider', strtolower((string) $box[0]['url']),
            'نشانیِ صفحهٔ امضا در اعلان نیست');
    }

    /**
     * `is_signed` ممکن است بولین بیاید یا ۰/۱ یا رشتهٔ `"false"`.
     *
     * ⚠️ ساختارِ دقیقِ پاسخِ واقعی را ندیده‌ایم؛ اگر فقط `=== false` می‌سنجیدیم،
     * یک `0`ِ عددی «امضاشده» خوانده می‌شد و این پایشگر بی‌صدا کور می‌ماند.
     */
    public function test_the_signed_flag_is_read_loosely_enough(): void
    {
        $box = $this->spyOnAdminNotices();

        $this->fakeContracts([
            ['title' => 'A', 'is_signed' => 0],
            ['title' => 'B', 'is_signed' => '0'],
            ['title' => 'C', 'is_signed' => 1],
            ['title' => 'D', 'is_signed' => 'true'],
        ]);

        $this->artisan('domains:check-contracts')->assertSuccessful();

        $flat = json_encode($box[0], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('A', $flat);
        $this->assertStringContainsString('B', $flat);
        $this->assertSame(2, (int) $box[0]['rows']['تعداد'],
            'شمارش با مقادیرِ ۰/۱ نخواند');
    }

    // ═══════════════ ۲) سکوت وقتی چیزی عوض نشده ═══════════════

    /**
     * 🔴 روزی یک پیامِ تکراری یعنی از هفتهٔ دوم خوانده نمی‌شود — همان قاعدهٔ
     * ثبت‌شدهٔ `SystemHealth` (۹۶ پیامِ روزانه بدتر از نداشتنِ هشدار است، چون
     * توهمِ پایش می‌سازد).
     */
    public function test_a_second_identical_run_stays_silent(): void
    {
        $box = $this->spyOnAdminNotices();

        $this->fakeContracts([['title' => '.com registry contract', 'is_signed' => false]]);

        $this->artisan('domains:check-contracts')->assertSuccessful();
        $this->artisan('domains:check-contracts')->assertSuccessful();

        $this->assertCount(1, (array) $box, 'اجرای دومِ بی‌تغییر هم پیام فرستاد');
    }

    /** ولی اگر قراردادِ تازه‌ای اضافه شود، همان روز خبر می‌رود */
    public function test_a_newly_unsigned_contract_breaks_the_silence(): void
    {
        $box = $this->spyOnAdminNotices();

        $this->fakeContracts([['title' => '.com', 'is_signed' => false]]);
        $this->artisan('domains:check-contracts')->assertSuccessful();

        $this->fakeContracts([
            ['title' => '.com', 'is_signed' => false],
            ['title' => '.shop', 'is_signed' => false],
        ]);
        $this->artisan('domains:check-contracts')->assertSuccessful();

        $this->assertCount(2, (array) $box, 'قراردادِ امضانشدهٔ تازه هیچ خبری نساخت');
    }

    /**
     * ترتیبِ ردیف‌های API نباید اعلانِ کاذب بسازد.
     *
     * ⚠️ رجیسترار هیچ تضمینی دربارهٔ ترتیب نداده؛ بی‌مرتب‌سازی، یک جابه‌جاییِ
     * ساده امضای وضعیت را عوض می‌کرد و هر روز یک «تغییر» جعلی می‌ساخت.
     */
    public function test_reordering_the_same_contracts_is_not_a_change(): void
    {
        $box = $this->spyOnAdminNotices();

        $this->fakeContracts([
            ['title' => '.com', 'is_signed' => false],
            ['title' => '.shop', 'is_signed' => false],
        ]);
        $this->artisan('domains:check-contracts')->assertSuccessful();

        $this->fakeContracts([
            ['title' => '.shop', 'is_signed' => false],
            ['title' => '.com', 'is_signed' => false],
        ]);
        $this->artisan('domains:check-contracts')->assertSuccessful();

        $this->assertCount(1, (array) $box, 'فقط ترتیب عوض شد و اعلانِ کاذب رفت');
    }

    /** خبرِ خوب هم خبر است — ولی فقط یک بار، در لحظهٔ تغییر */
    public function test_everything_signed_reports_once_then_goes_quiet(): void
    {
        $box = $this->spyOnAdminNotices();

        $this->fakeContracts([['title' => '.com', 'is_signed' => false]]);
        $this->artisan('domains:check-contracts')->assertSuccessful();

        $this->fakeContracts([['title' => '.com', 'is_signed' => true]]);
        $this->artisan('domains:check-contracts')->assertSuccessful();
        $this->artisan('domains:check-contracts')->assertSuccessful();

        $this->assertCount(2, (array) $box);
        $this->assertStringContainsString('امضا', $box[1]['title']);
    }

    // ═══════════════ ۳) شکستِ خواندن ≠ «همه امضا شده» ═══════════════

    /**
     * 🔴 گران‌ترین ادعای این فایل.
     *
     * توکنِ منقضی، قطعیِ گذرا، یا تغییرِ مسیرِ API همگی «هیچ قراردادی» می‌دهند.
     * اگر آن را موفقیت بخوانیم، امضای وضعیت پاک می‌شود و پایشگر برای همیشه
     * ساکت می‌مانَد — بدتر از نداشتنش، چون مدیر فکر می‌کند دارد پایش می‌شود.
     */
    public function test_a_failed_read_is_never_read_as_all_signed(): void
    {
        $box = $this->spyOnAdminNotices();

        // اول یک وضعیتِ واقعی ثبت شود
        $this->fakeContracts([['title' => '.com', 'is_signed' => false]]);
        $this->artisan('domains:check-contracts')->assertSuccessful();

        $before = Setting::get('domain_contracts_unsigned');
        $this->assertNotNull($before);

        // حالا خواندن شکست بخورد (اوپن‌پروایدر روی خطا هم HTTP 500 می‌دهد)
        $this->fakeContracts([], 196, 'Authentication/Authorization Failed');

        $this->artisan('domains:check-contracts')->assertFailed();

        $this->assertSame($before, Setting::get('domain_contracts_unsigned'),
            'امضای وضعیت با یک شکستِ خواندن پاک شد — دفعهٔ بعد هیچ خبری نمی‌رود');

        $this->assertCount(1, (array) $box,
            'شکستِ خواندن به مدیر گفت «همهٔ قراردادها امضا شده‌اند»');
    }

    /** و شکست بی‌صدا هم نمی‌مانَد: ردیابِ خطا می‌بیندش */
    public function test_a_failed_read_lands_in_the_error_tracker(): void
    {
        $this->spyOnAdminNotices();

        ErrorTracker::clear();

        $this->fakeContracts([], 196, 'Authentication/Authorization Failed');

        $this->artisan('domains:check-contracts')->assertFailed();

        $flat = json_encode(ErrorTracker::recent(20), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('قرارداد', $flat,
            'شکستِ خواندن هیچ ردی نگذاشت — عیب‌یابیِ بعدی مرجعی ندارد');
    }

    // ═══════════════ پیکربندی نبود ═══════════════

    /**
     * نبودِ اعتبارنامه خطا نیست، فقط کارِ امروز نیست.
     *
     * ⚠️ اگر `FAILURE` می‌داد، هر نصبِ بی‌رجیسترار هر روز یک کرونِ قرمز داشت و
     * قرمزِ همیشگی یعنی قرمز دیده نمی‌شود.
     */
    public function test_no_registrar_credentials_means_skip_not_fail(): void
    {
        $box = $this->spyOnAdminNotices();

        config()->set('services.openprovider.username', null);
        config()->set('services.openprovider.password', null);

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake();

        $this->artisan('domains:check-contracts')->assertSuccessful();

        $this->assertSame([], (array) $box);
        Http::assertNothingSent();
    }

    // ═══════════════ زمان‌بندی ═══════════════

    /**
     * 🔴 فرمانِ ثبت‌نشده اجرا نمی‌شود.
     *
     * این پروژه دقیقاً همین را یک بار خورد: `domains:provision` نوشته شده بود،
     * `PaymentService` پرچمش را می‌زد، و آن کرون هرگز در `routes/console.php`
     * ثبت نشده بود — دامنه‌های پرداخت‌شده تا ابد در صف ماندند، بی‌خطا و با
     * کدِ ۲۰۰.
     */
    public function test_the_command_is_actually_scheduled_daily(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'domains:check-contracts'));

        $this->assertCount(1, $events, 'در routes/console.php ثبت نشده — هرگز اجرا نمی‌شود');

        // روزانه: پنج‌بخشیِ کرون با دقیقه و ساعتِ ثابت و بقیه ستاره
        $this->assertMatchesRegularExpression('/^\d+ \d+ \* \* \*$/', $events->first()->expression,
            'بسامدش روزانه نیست — تماسِ بیشتر با حسابی که یک بار علامت خورده');
    }
}
