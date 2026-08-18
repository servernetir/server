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

        // ⚠️ بیش از یک صفحه با کوچک‌ترین اندازهٔ مجاز (۵۰)
        for ($i = 0; $i < 55; $i++) {
            $this->log($c, ['action' => 'payment', 'description' => 'پرداخت شمارهٔ '.$i]);
        }

        $this->log($c, ['action' => 'login', 'description' => 'ورود از مرورگر']);

        $html = (string) $this->actingAs($this->staff(), 'web')
            ->get("/admin/customers/{$c->id}?act=payment&per=50")->assertOk()->getContent();

        $this->assertStringContainsString('act=payment', $html,
            'لینکِ صفحهٔ بعد فیلتر را نگه نداشت.');
        $this->assertStringContainsString('per=50', $html,
            'لینکِ صفحهٔ بعد اندازهٔ صفحه را نگه نداشت.');
    }

    /** پیش‌فرض ۱۰۰ ردیف است، نه ۲۵ */
    public function test_the_default_page_size_is_one_hundred(): void
    {
        $c = $this->customer();

        for ($i = 0; $i < 105; $i++) {
            $this->log($c, ['description' => 'رویداد '.$i]);
        }

        $html = (string) $this->actingAs($this->staff(), 'web')
            ->get("/admin/customers/{$c->id}")->assertOk()->getContent();

        // ۱۰۰ ردیفِ اول رندر شده باشد و ردیفِ ۱۰۱ام نه
        $this->assertSame(100, substr_count($html, '<td>رویداد '),
            'اندازهٔ پیش‌فرضِ صفحه ۱۰۰ نیست.');
    }

    /**
     * 🔴 اندازهٔ صفحه از فهرستِ سفید می‌آید، نه از URL.
     *
     * `?per=100000` یعنی کلِ تاریخچهٔ یک مشتریِ قدیمی در یک درخواست از دیتابیس
     * بیرون بیاید و حافظهٔ PHP را پر کند — یک DoSِ رایگان از راهِ یک پارامترِ
     * صرفاً نمایشی.
     */
    public function test_an_absurd_page_size_is_ignored(): void
    {
        $c = $this->customer();

        for ($i = 0; $i < 105; $i++) {
            $this->log($c, ['description' => 'رویداد '.$i]);
        }

        $html = (string) $this->actingAs($this->staff(), 'web')
            ->get("/admin/customers/{$c->id}?per=100000")->assertOk()->getContent();

        $this->assertSame(100, substr_count($html, '<td>رویداد '),
            'اندازهٔ دلبخواهِ URL پذیرفته شد.');
    }

    /**
     * بجِ کنارِ تب باید **کلِ** تاریخچه را بگوید، نه نتیجهٔ فیلتر.
     *
     * وگرنه مدیر فیلتر می‌زند و عددِ تب هم عوض می‌شود — «۱ رویداد» برای مشتری‌ای
     * که ۳ تا دارد.
     */
    public function test_the_tab_badge_shows_the_unfiltered_total(): void
    {
        $c = $this->customer();
        $this->log($c, ['action' => 'login']);
        $this->log($c, ['action' => 'payment']);
        $this->log($c, ['action' => 'payment']);

        $html = (string) $this->actingAs($this->staff(), 'web')
            ->get("/admin/customers/{$c->id}?act=login")->assertOk()->getContent();

        // بجِ تب: ۳ (کل) — و سرصفحهٔ پنل: «۱ از ۳»
        $this->assertMatchesRegularExpression('~data-tab="activity".*?<i class="ct-n">'.fa_num('3').'</i>~s', $html,
            'بجِ تب با فیلتر عوض شد.');
        $this->assertStringContainsString(fa_num('1').' از '.fa_num('3'), $html,
            'سرصفحه «چند از چند» را نمی‌گوید.');
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
