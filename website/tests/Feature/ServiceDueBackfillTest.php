<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Service;
use App\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * سرویسِ بی‌سررسید — سرویسِ رایگانِ ابدی.
 *
 * `services:renew-due` شرطِ `whereNotNull('next_due_at')` دارد، پس ردیفِ
 * بی‌سررسید از دیدِ کلِ صورت‌حساب **غایب** است: نه فاکتور، نه یادآوری، نه
 * تعلیق — و هیچ خطایی هم تولید نمی‌کند.
 */
class ServiceDueBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function service(array $over = []): Service
    {
        $c = Customer::create([
            'email' => 'sv'.random_int(1, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);

        return Service::create(array_merge([
            'customer_id'  => $c->id,
            'name'         => 'خدمات',
            'plan'         => 'custom',
            'cycle'        => 'monthly',
            'price'        => 2_000_000,
            'currency_code' => 'IRT',
            'status'       => 'active',
            'activated_at' => now()->subMonths(7),
            'next_due_at'  => null,
        ], $over));
    }

    /**
     * 🔴 سررسیدِ پرشده باید در **آینده** باشد.
     *
     * ═══ چرا این مهم‌ترین ادعای این فایل است ═══
     *
     * زنجیرهٔ کرون بی‌رحم است:
     *     ۰۷:۰۰ services:renew-due → فاکتورِ تمدید برای سرویسِ سررسیدگذشته
     *     ۰۷:۳۰ services:lifecycle → همان فاکتورِ پرداخت‌نشده → تعلیقِ واقعی
     *
     * یعنی یک backfillِ ساده‌لوحانه که `activated_at + یک ماه` بنویسد، فردا صبح
     * سرویسِ سالمِ مشتری را قطع می‌کند و پیامکِ «سرویس شما غیرفعال شد»
     * می‌فرستد. همان تلهٔ ثبت‌شدهٔ `/admin/cloud/attach`.
     */
    public function test_the_backfilled_date_is_always_in_the_future(): void
    {
        $s = $this->service(['activated_at' => now()->subMonths(9)]);

        $this->artisan('services:backfill-due')->assertSuccessful();

        $due = $s->fresh()?->next_due_at;

        $this->assertNotNull($due, 'سررسید پر نشد.');
        $this->assertTrue($due->isFuture(),
            'سررسید در گذشته نوشته شد — فردا صبح سرویسِ سالم تعلیق می‌شود.');
    }

    /** سررسید روی همان **روزِ** لنگر می‌نشیند، فقط دوره‌ها جلو رفته‌اند */
    public function test_the_billing_day_of_month_is_preserved(): void
    {
        $anchor = now()->subMonths(6)->startOfDay();
        $s = $this->service(['activated_at' => $anchor]);

        $this->artisan('services:backfill-due')->assertSuccessful();

        $this->assertSame((int) $anchor->day, (int) $s->fresh()?->next_due_at?->day,
            'روزِ صورت‌حساب جابه‌جا شد.');
    }

    /** لنگرِ صریح (`--from`) بر `activated_at` می‌چربد */
    public function test_an_explicit_anchor_wins(): void
    {
        $s = $this->service(['activated_at' => now()->subYears(3)]);

        $this->artisan('services:backfill-due', ['--from' => now()->subMonths(2)->toDateString()])
            ->assertSuccessful();

        $this->assertSame((int) now()->subMonths(2)->day, (int) $s->fresh()?->next_due_at?->day);
    }

    /** سرویسِ مرده سررسید نمی‌خواهد */
    public function test_a_dead_service_is_left_alone(): void
    {
        $s = $this->service(['status' => 'cancelled']);

        $this->artisan('services:backfill-due')->assertSuccessful();

        $this->assertNull($s->fresh()?->next_due_at);
    }

    /** سرویسی که از قبل سررسید دارد دست نمی‌خورد */
    public function test_an_existing_due_date_is_never_overwritten(): void
    {
        $when = now()->addDays(9)->startOfDay();
        $s = $this->service(['next_due_at' => $when]);

        $this->artisan('services:backfill-due')->assertSuccessful();

        $this->assertSame($when->toDateString(), $s->fresh()?->next_due_at?->toDateString());
    }

    /** `--dry` هیچ عارضه‌ای ندارد */
    public function test_dry_run_changes_nothing(): void
    {
        $s = $this->service();

        $this->artisan('services:backfill-due --dry')->assertSuccessful();

        $this->assertNull($s->fresh()?->next_due_at);
    }

    /**
     * 🔴 و مهم‌تر از رفعِ امروز: موردِ **بعدی** باید دیده شود.
     *
     * اگر فقط ردیف‌های امروز را درست کنیم، اولین مسیرِ فروشی که فردا این ستون
     * را پر نکند، همان سرویسِ رایگانِ ابدی را دوباره می‌سازد — و باز هم تنها
     * راهِ کشفش تصادف است.
     */
    public function test_system_health_reports_a_service_without_a_due_date(): void
    {
        $this->service();

        $checks = collect(app(SystemHealth::class)->checks())->keyBy('key');

        $this->assertArrayHasKey('unbilled', $checks->all(),
            'چکِ سررسیدِ سرویس‌ها در لایهٔ سلامت نیست.');
        $this->assertSame('warn', $checks['unbilled']['level'],
            'سرویسِ بی‌سررسید هشدار تولید نکرد — یعنی بی‌صدا رایگان می‌مانَد.');
    }

    public function test_system_health_is_green_when_every_service_is_billed(): void
    {
        $this->service(['next_due_at' => now()->addDays(10)]);

        $checks = collect(app(SystemHealth::class)->checks())->keyBy('key');

        $this->assertSame('ok', $checks['unbilled']['level']);
    }
}
