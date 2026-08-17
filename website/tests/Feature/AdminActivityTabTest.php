<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تبِ تاریخچهٔ فعالیتِ مشتری — جدول، فیلتر، صفحه‌بندی، تاریخِ شمسی.
 *
 * ⚠️ ادعاها روی **ردیف‌هایی که واقعاً برمی‌گردند** است، نه بر رندر شدنِ صفحه.
 * فیلتری که همیشه همه‌چیز را نشان دهد هم ۲۰۰ می‌دهد و هم بی‌فایده است.
 */
class AdminActivityTabTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 'ac'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'ac'.random_int(1, 99999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    /**
     * ⚠️ `created_at` در `$fillable` **نیست** (لاگ تغییرناپذیر است)، پس
     * `create(['created_at' => …])` آن را بی‌صدا نادیده می‌گیرد و ردیف روی
     * «الان» می‌نشیند.
     *
     * نسخهٔ اولِ همین فیکسچر همین اشتباه را داشت: تستِ بازهٔ تاریخ به‌درستی قرمز
     * شد و تستِ تاریخِ شمسی **به دلیلِ غلط سبز** بود — که بدتر است. پس زمان
     * صریح و بعد از ساخت نوشته می‌شود.
     */
    private function log(Customer $c, array $over = []): ActivityLog
    {
        $when = $over['created_at'] ?? now()->subDay();
        unset($over['created_at']);

        $row = ActivityLog::create(array_merge([
            'customer_id' => $c->id,
            'actor'       => 'customer',
            'action'      => 'login',
            'description' => 'ورود از مرورگر',
            'ip'          => '1.2.3.4',
        ], $over));

        $row->forceFill(['created_at' => $when])->save();

        return $row->refresh();
    }

    public function test_the_activity_tab_renders_as_a_filterable_table(): void
    {
        $c = $this->customer();
        $this->log($c);

        $html = (string) $this->actingAs($this->staff(), 'web')
            ->get("/admin/customers/{$c->id}")->assertOk()->getContent();

        $this->assertStringContainsString('data-tab="activity"', $html, 'تبِ فعالیت ساخته نشد');
        $this->assertStringContainsString('data-pane="activity"', $html, 'محتوای تبِ فعالیت نیست');
        $this->assertStringContainsString('class="act-filter"', $html, 'فرمِ فیلتر نیست');
        $this->assertStringContainsString('ورود از مرورگر', $html, 'ردیفِ فعالیت رندر نشد');
    }

    /**
     * 🔴 فیلتر باید واقعاً **کم** کند.
     *
     * فیلتری که همه‌چیز را برگرداند هم ۲۰۰ می‌دهد و شبیهِ کارکردن است — پس
     * ادعا روی نبودِ ردیفِ فیلترشده است، نه بر وجودِ فرم.
     */
    public function test_filters_actually_narrow_the_result(): void
    {
        $c = $this->customer();
        $this->log($c, ['action' => 'login', 'description' => 'ورود از مرورگر']);
        $this->log($c, ['action' => 'payment', 'description' => 'پرداختِ فاکتور', 'actor' => 'system']);

        $staff = $this->staff();

        $only = (string) $this->actingAs($staff, 'web')
            ->get("/admin/customers/{$c->id}?act=payment")->assertOk()->getContent();

        $this->assertStringContainsString('پرداختِ فاکتور', $only);
        $this->assertStringNotContainsString('ورود از مرورگر', $only,
            'فیلترِ رویداد چیزی را کم نکرد.');

        // فیلترِ نقشِ انجام‌دهنده
        $sys = (string) $this->actingAs($staff, 'web')
            ->get("/admin/customers/{$c->id}?who=system")->assertOk()->getContent();

        $this->assertStringContainsString('پرداختِ فاکتور', $sys);
        $this->assertStringNotContainsString('ورود از مرورگر', $sys);

        // جستجوی متنی روی شرح
        $q = (string) $this->actingAs($staff, 'web')
            ->get("/admin/customers/{$c->id}?q=".urlencode('مرورگر'))->assertOk()->getContent();

        $this->assertStringContainsString('ورود از مرورگر', $q);
        $this->assertStringNotContainsString('پرداختِ فاکتور', $q);
    }

    /**
     * ⚠️ بازهٔ تاریخ روی **مرزِ** بازه سنجیده می‌شود، نه وسطش.
     *
     * همان باگِ ثبت‌شدهٔ تقویم: مقایسهٔ رشته‌ای با ستونِ datetime، آخرین روزِ
     * بازه را می‌انداخت و هیچ تستی نمی‌گرفتش چون همه رویداد را وسطِ بازه
     * می‌گذاشتند.
     */
    public function test_the_date_range_includes_its_own_boundary_days(): void
    {
        $c = $this->customer();
        $edge = now()->subDays(5);

        $this->log($c, ['description' => 'رویدادِ لبه', 'created_at' => $edge->copy()->setTime(21, 30)]);

        $html = (string) $this->actingAs($this->staff(), 'web')
            ->get("/admin/customers/{$c->id}?from={$edge->toDateString()}&to={$edge->toDateString()}")
            ->assertOk()->getContent();

        $this->assertStringContainsString('رویدادِ لبه', $html,
            'رویدادِ همان‌روزِ مرزِ بازه از فیلتر افتاد.');
    }

    /**
     * 🔴 صفحهٔ دوم نباید فیلترها را گم کند.
     *
     * بی‌`withQueryString()` صفحهٔ ۲ کلِ تاریخچه را نشان می‌دهد — خرابیِ خاموشی
     * که مدیر آن را «فیلتر کار نمی‌کند» می‌بیند.
     */
    public function test_pagination_keeps_the_filters(): void
    {
        $c = $this->customer();

        for ($i = 0; $i < 30; $i++) {
            $this->log($c, ['action' => 'payment', 'description' => 'پرداخت شمارهٔ '.$i]);
        }

        $this->log($c, ['action' => 'login', 'description' => 'ورود از مرورگر']);

        $html = (string) $this->actingAs($this->staff(), 'web')
            ->get("/admin/customers/{$c->id}?act=payment")->assertOk()->getContent();

        $this->assertStringContainsString('act=payment', $html,
            'لینکِ صفحهٔ بعد فیلتر را نگه نداشت.');
    }

    /** تاریخ باید شمسی باشد، نه میلادی */
    public function test_dates_are_shown_in_the_jalali_calendar(): void
    {
        $c = $this->customer();
        $this->log($c, ['created_at' => \Illuminate\Support\Carbon::parse('2026-08-07 10:00:00')]);

        $html = (string) $this->actingAs($this->staff(), 'web')
            ->get("/admin/customers/{$c->id}")->assertOk()->getContent();

        // ۲۰۲۶-۰۸-۰۷ = ۱۶ مرداد ۱۴۰۵
        $this->assertStringContainsString(fa_num('1405'), $html, 'سالِ شمسی در جدول نیست');
        $this->assertStringNotContainsString('2026-08-07', $html, 'تاریخِ میلادیِ خام نشان داده شد');
    }

    /**
     * ⚠️ ورودیِ نامعتبر نباید صفحهٔ پروندهٔ مشتری را بخواباند.
     *
     * این یک روتِ نمایشی است؛ یک تاریخِ بی‌معنی در URL (بوکمارکِ خراب، لینکِ
     * دست‌کاری‌شده) باید نادیده گرفته شود نه اینکه ۵۰۰ بدهد.
     */
    public function test_a_broken_date_filter_does_not_break_the_page(): void
    {
        $c = $this->customer();
        $this->log($c);

        $this->actingAs($this->staff(), 'web')
            ->get("/admin/customers/{$c->id}?from=banana&to=%%%")
            ->assertOk();
    }
}
