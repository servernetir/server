<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * فهرستِ کلِ سرویس‌ها — `/admin/services`.
 *
 * مقصدِ لینکِ «همه»ی پنلِ «تازه‌ترین سرویس‌ها» در داشبورد. تا امروز چنین روتی
 * وجود نداشت و آن لینک ۴۰۴ می‌داد؛ `AdminDashboardLinksResolveTest` نگهبانِ
 * خودِ لینک است و این تست نگهبانِ **رفتارِ** صفحه.
 */
class AdminServiceIndexTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $handle = 'kharidar'): Customer
    {
        return Customer::create([
            'code'     => 'SN-'.random_int(100000, 999999),
            'email'    => $handle.'@example.com',
            'password' => bcrypt('secret-pass-123'),
            'status'   => 'active',
        ]);
    }

    private function service(Customer $c, string $name, array $extra = []): Service
    {
        return Service::create(array_merge([
            'customer_id' => $c->id, 'name' => $name,
            'currency_code' => 'IRT', 'price' => 1200000, 'cycle' => 'monthly',
            'status' => 'active', 'next_due_at' => now()->addMonth(),
        ], $extra));
    }

    private function asAdmin(string $url = '/admin/services')
    {
        return $this->actingAs(User::factory()->create(['role' => 'admin']))->get($url);
    }

    /** 🔴 پایه: صفحه باز می‌شود و سرویس‌ها را با مشتری‌شان نشان می‌دهد. */
    public function test_it_lists_services_with_their_customer(): void
    {
        $c = $this->customer('alefba');
        $this->service($c, 'هاست لینوکس طلایی');

        $html = $this->asAdmin()->assertOk()->getContent();

        $this->assertStringContainsString('هاست لینوکس طلایی', $html);
        $this->assertStringContainsString($c->code, $html);
    }

    /**
     * 🔴 پشتیبان راه ندارد.
     *
     * مبلغِ فروشِ همهٔ مشتریان در یک صفحه است — همان دلیلی که پنلِ داشبورد هم
     * برای پشتیبان رندر نمی‌شود. ولی پنهان‌بودنِ لینک هیچ دری را نمی‌بندد، پس
     * گاردِ سمتِ سرور جداگانه سنجیده می‌شود.
     */
    public function test_support_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'support']))
            ->get('/admin/services')->assertForbidden();
    }

    /**
     * زبانه‌ها واقعاً فیلتر می‌کنند — و «بسته» شاملِ خاتمه‌یافته هم هست.
     *
     * ⚠️ تعریفِ «بسته» از `Service::DEAD_STATUSES` می‌آید، نه رشتهٔ دست‌نویس؛
     * این تست همان یگانگی را قفل می‌کند.
     */
    public function test_tabs_filter_the_list(): void
    {
        $c = $this->customer();
        $this->service($c, 'سرویسِ فعالِ من');
        $this->service($c, 'سرویسِ لغوشدهٔ من', ['status' => 'cancelled', 'next_due_at' => null]);
        $this->service($c, 'سرویسِ خاتمه‌یافتهٔ من', ['status' => 'terminated', 'next_due_at' => null]);

        $active = $this->asAdmin('/admin/services?tab=active')->assertOk()->getContent();
        $this->assertStringContainsString('سرویسِ فعالِ من', $active);
        $this->assertStringNotContainsString('سرویسِ لغوشدهٔ من', $active);

        $dead = $this->asAdmin('/admin/services?tab=dead')->assertOk()->getContent();
        $this->assertStringContainsString('سرویسِ لغوشدهٔ من', $dead);
        $this->assertStringContainsString('سرویسِ خاتمه‌یافتهٔ من', $dead);
        $this->assertStringNotContainsString('سرویسِ فعالِ من', $dead);
    }

    /** جستجو هم روی خودِ سرویس کار می‌کند هم روی مشتری‌اش. */
    public function test_search_matches_the_service_and_its_customer(): void
    {
        $one = $this->customer('yekomi');
        $two = $this->customer('dovomi');
        $this->service($one, 'سرویسِ یکم', ['domain' => 'sitea.example']);
        $this->service($two, 'سرویسِ دوم', ['domain' => 'siteb.example']);

        $byDomain = $this->asAdmin('/admin/services?q=sitea')->assertOk()->getContent();
        $this->assertStringContainsString('سرویسِ یکم', $byDomain);
        $this->assertStringNotContainsString('سرویسِ دوم', $byDomain);

        $byCustomer = $this->asAdmin('/admin/services?q='.$two->code)->assertOk()->getContent();
        $this->assertStringContainsString('سرویسِ دوم', $byCustomer);
        $this->assertStringNotContainsString('سرویسِ یکم', $byCustomer);
    }

    /**
     * 🔴 سرویسی که مشتری‌اش حذف شده نباید از فهرست بیفتد.
     *
     * با `join` به‌جای `whereHas` بی‌صدا ناپدید می‌شد — دقیقاً ردیفی که مدیر
     * بیشتر از همه باید ببیند، چون شاید هنوز روی سرور زنده است و هزینه‌اش پای
     * ماست.
     */
    public function test_a_service_whose_customer_is_gone_still_shows_up(): void
    {
        $c = $this->customer('rafteh');
        $this->service($c, 'سرویسِ یتیم');
        $c->delete();

        $html = $this->asAdmin()->assertOk()->getContent();

        $this->assertStringContainsString('سرویسِ یتیم', $html);
        $this->assertStringContainsString('مشتری حذف شده', $html);
    }

    /**
     * دو فیلترِ صورت‌حساب مکملِ هم‌اند و روی هم کلِ فهرست را می‌پوشانند.
     *
     * ⚠️ سرویسی که `billing_mode` را صریح ست نکرده (پیش‌فرضِ ستون) باید زیرِ
     * «دوره‌ای» بیاید. با `where('billing_mode', 'cycle')` هم امروز کار می‌کرد،
     * ولی هر مقدارِ سومی که فردا اضافه شود از **هر دو** فیلتر بیرون می‌افتاد و
     * بی‌صدا نامرئی می‌شد.
     */
    public function test_the_two_billing_filters_cover_the_whole_list(): void
    {
        $c = $this->customer();
        $this->service($c, 'سرویسِ دوره‌ایِ معمولی');
        $this->service($c, 'سرورِ ساعتی', ['billing_mode' => 'hourly', 'hourly_rate_irt' => 5000]);

        $cycle = $this->asAdmin('/admin/services?billing=cycle')->assertOk()->getContent();
        $this->assertStringContainsString('سرویسِ دوره‌ایِ معمولی', $cycle);
        $this->assertStringNotContainsString('سرورِ ساعتی', $cycle);

        $hourly = $this->asAdmin('/admin/services?billing=hourly')->assertOk()->getContent();
        $this->assertStringContainsString('سرورِ ساعتی', $hourly);
        $this->assertStringNotContainsString('سرویسِ دوره‌ایِ معمولی', $hourly);
    }

    /**
     * ⚠️ مرتب‌سازی بر اساس سررسید نباید ردیفِ بی‌سررسید را بالا بیاورد.
     *
     * NULL در `orderBy` تلهٔ ثبت‌شدهٔ فهرستِ ناوگان است: «نزدیک‌ترین سررسید» پر
     * می‌شود از سرویس‌هایی که اصلاً سررسید ندارند.
     */
    public function test_services_with_no_due_date_sort_last(): void
    {
        $c = $this->customer();
        $this->service($c, 'بی‌سررسید', ['next_due_at' => null]);
        $this->service($c, 'سررسیدِ نزدیک', ['next_due_at' => now()->addDay()]);

        $html = $this->asAdmin('/admin/services?sort=due')->assertOk()->getContent();

        $this->assertLessThan(mb_strpos($html, 'بی‌سررسید'), mb_strpos($html, 'سررسیدِ نزدیک'),
            'سرویسِ بی‌سررسید بالای «نزدیک‌ترین سررسید» نشست');
    }

    /**
     * ⚠️ عددِ زبانه باید با تعدادِ ردیف‌های همان زبانه بخواند.
     *
     * شمارنده و فیلتر هر دو از `applyTab` می‌خوانند؛ این تست همان یگانگی را
     * قفل می‌کند تا کسی روزی یکی را جدا بازنویسی نکند.
     */
    public function test_the_tab_counter_agrees_with_the_rows_it_shows(): void
    {
        $c = $this->customer();
        $this->service($c, 'الف');
        $this->service($c, 'ب');
        $this->service($c, 'ج', ['status' => 'cancelled', 'next_due_at' => null]);

        $html = $this->asAdmin()->assertOk()->getContent();

        $this->assertStringContainsString('فعال ('.fa_num(2).')', $html);
        $this->assertStringContainsString('بسته ('.fa_num(1).')', $html);
        $this->assertStringContainsString('همه ('.fa_num(3).')', $html);
    }
}
