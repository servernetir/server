<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صفحهٔ کشور باید **همهٔ** پلن‌های همهٔ شهرهای آن کشور را نشان دهد.
 *
 * 🔴 دو خرابی که این تست‌ها می‌بندند، هر دو بی‌خطا و با کدِ ۲۰۰ کار می‌کردند:
 *
 *  ۱) ادغامِ بین‌شهری: پلن‌های پاریس و لیون با مشخصاتِ یکسان روی هم `unique()`
 *     می‌شدند و یکی حذف. ولی مشتری دقیقاً بین شهرها انتخاب می‌کند — تأخیرِ
 *     شبکه فرق دارد. نتیجه‌اش پنهان‌ماندنِ موجودیِ واقعی بود.
 *
 *  ۲) فیلترِ نوعِ پردازنده: `/vps/*` فقط اشتراکی می‌گرفت، پس صفحهٔ کشور هرگز
 *     پلنِ پردازندهٔ اختصاصی را نشان نمی‌داد.
 */
class CountryPlanTableTest extends TestCase
{
    use RefreshDatabase;

    private function loc(string $code, string $city, string $country = 'FR'): CloudLocation
    {
        return CloudLocation::create([
            'code' => $code, 'country' => $country, 'city' => $city,
            'is_active' => true, 'sort' => 0,
        ]);
    }

    private function plan(string $loc, int $vcpu, int $ram, int $price, string $cpuKind = 'shared', array $over = []): CloudPlan
    {
        return CloudPlan::create(array_merge([
            'provider' => 'p1', 'provider_ref' => $loc.'-'.$vcpu.'-'.$ram.'-'.$cpuKind,
            'location_code' => $loc,
            'slug' => 'cv-'.$vcpu.'c-'.$ram.'g-40d-'.$loc,
            'public_name' => 'CV-'.$vcpu.'-'.$ram,
            'vcpu' => $vcpu, 'ram_mb' => $ram * 1024, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20000, 'cpu_kind' => $cpuKind, 'arch' => 'x86',
            'cost_eur_cents' => $price, 'price_eur_cents' => $price * 2,
            'price_irt' => $price * 10000,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ], $over));
    }

    private function html(): string
    {
        return $this->get('/vps/france')->assertOk()->getContent();
    }

    /**
     * دو شهر با عرضهٔ **دقیقاً برابر** ⇒ صفحه یکی را نشان می‌دهد.
     *
     * ⚠️ **این تست دو بار بازنویسی شده و هر بار ادعایش عوض شده — پس تاریخچه‌اش
     * بماند تا کسی فکر نکند نسخهٔ امروز «همیشه» درست بوده:**
     *
     *   نسخهٔ ۱: دو شهرِ هم‌مشخصات = **دو ردیف**  (صفحهٔ ایران ۱۴۶ ردیف شد)
     *   نسخهٔ ۲: یک ردیف، شهر یک انتخاب **داخلِ** ردیف
     *   نسخهٔ ۳: یک ردیف، **یک شهر** — ارزان‌ترین  ← امروز
     *
     * کارفرما: «اگر دو شهر بود قیمتش یکی بود یکجا بیوفته.»
     *
     * 🔴 چیزی که در هر سه نسخه ثابت مانده و **نباید** بشکند: عرضهٔ شهرِ دوم از
     * دیدِ مسیرِ **سفارش** پاک نشود. صفحه تبلیغش نمی‌کند؛ این با «وجود ندارد»
     * یکی نیست. نیمهٔ دومِ همین تست دقیقاً همان را می‌سنجد.
     */
    public function test_two_cities_with_an_equally_good_offer_collapse_to_one_on_the_page(): void
    {
        $this->loc('fr-paris', 'پاریس');
        $this->loc('fr-lyon', 'لیون');
        $this->plan('fr-paris', 2, 4, 500);
        $this->plan('fr-lyon', 2, 4, 500);

        $html = $this->html();

        $this->assertSame(1, substr_count($html, 'data-city='),
            'مشخصاتِ یکسان باید یک ردیف باشد، نه یک ردیف به ازای هر شهر');

        // قیمت‌ها برابرند ⇒ ترتیبِ `sort`ِ مکان تصمیم می‌گیرد ⇒ پاریس
        $this->assertStringContainsString('data-city="پاریس"', $html);
        $this->assertStringContainsString('location=fr-paris&amp;plan=cv-2c-4g-40d-fr-paris', $html);

        $this->assertStringNotContainsString('location=fr-lyon', $html,
            'هر دو شهر روی صفحه‌اند — قاعده «یکجا بیوفته» است');

        // 🔴 ولی لیون فروختنی مانده — صرفاً تبلیغ نمی‌شود
        $this->assertCount(1, \App\Models\CloudPlan::offers('fr-lyon'),
            'عرضهٔ لیون از مسیرِ سفارش هم پاک شد — این دیگر موجودیِ پنهانِ واقعی است');
    }

    /**
     * همان مشخصات با قیمتِ بالاتر در شهرِ دیگر: **هر دو** می‌مانند، ارزان‌تر
     * سرصفحه با نشانِ «شروع از».
     *
     * ⚠️ **این تست هم عمداً بازنویسی شد.** نسخهٔ قبلی
     * `assertStringNotContainsString('data-city="لیون"')` بود و رفتارِ آن‌روز را
     * قفل می‌کرد: `CloudDominance` روی مجموعهٔ چندشهری می‌دوید و چون مکان یک
     * بُعدِ مقایسه نیست، شهرِ گران‌تر با مشخصاتِ **یکسان** پیش از رسیدن به ویو
     * پاک می‌شد.
     *
     * چرا آن غلط بود: خواستهٔ کارفرما («از یک هسته و رم و فضا چند قیمت نگذار»)
     * دربارهٔ **دو ردیفِ هم‌مشخصات با دو قیمت** بود، نه دربارهٔ حذفِ یک شهر. یک
     * ردیف با یک قیمتِ سرصفحه‌ای، همان خواسته را برآورده می‌کند — و شهرِ دوم را
     * هم قابلِ خرید نگه می‌دارد. حذفش دقیقاً همان «موجودیِ پنهان با کدِ ۲۰۰» بود
     * که کلِ این پرونده دربارهٔ آن است.
     *
     * ⚠️ و «شروع از» حالا باید **نباشد** — دقیقاً برعکسِ نسخهٔ قبلی. آن روز
     * عدد کفِ یک بازه بود؛ امروز قیمتِ قطعیِ تنها شهری است که تبلیغ می‌شود، و
     * «از» به مشتری می‌گوید منتظرِ گران‌تر شدن باشد.
     */
    public function test_the_same_spec_at_a_higher_price_shows_only_the_cheaper_city(): void
    {
        $this->loc('fr-paris', 'پاریس');
        $this->loc('fr-lyon', 'لیون');
        $this->plan('fr-paris', 2, 4, 500);
        $this->plan('fr-lyon', 2, 4, 900);

        $html = $this->html();

        $this->assertSame(1, substr_count($html, 'data-city='));
        $this->assertStringContainsString('data-city="پاریس"', $html);
        $this->assertStringContainsString('data-price="5000000"', $html);

        $this->assertStringNotContainsString('pt-from', $html,
            'نشانِ «شروع از» مانده در حالی که ردیف فقط یک شهر و یک قیمت دارد');

        $this->assertStringNotContainsString('location=fr-lyon', $html,
            'شهرِ گران‌تر هنوز روی صفحه لینک دارد');
        $this->assertStringNotContainsString(fa_num(number_format(9000000)), $html,
            'قیمتِ شهرِ گران‌تر هنوز روی صفحه است');

        // 🔴 باز هم: فروختنی مانده، فقط تبلیغ نمی‌شود
        $this->assertCount(1, \App\Models\CloudPlan::offers('fr-lyon'));
    }

    /** قیمتِ یکنواخت ⇒ نشانِ «از» نباید بیاید (وگرنه نشان بی‌معنا می‌شود) */
    public function test_no_from_marker_when_every_city_costs_the_same(): void
    {
        $this->loc('fr-paris', 'پاریس');
        $this->loc('fr-lyon', 'لیون');
        $this->plan('fr-paris', 2, 4, 500);
        $this->plan('fr-lyon', 2, 4, 500);

        $this->assertStringNotContainsString('pt-from', $this->html(),
            'قیمت‌ها یکسان‌اند؛ «شروع از» این‌جا فقط شک می‌سازد');
    }

    /** 🔴 هر دو نوعِ پردازنده در یک صفحه، در دو جدولِ جدا */
    public function test_both_cpu_kinds_appear_in_separate_tables(): void
    {
        $this->loc('fr-paris', 'پاریس');
        $this->plan('fr-paris', 2, 4, 500, 'shared');
        $this->plan('fr-paris', 4, 8, 900, 'dedicated');

        $html = $this->html();

        $this->assertStringContainsString('data-group="std"', $html);
        $this->assertStringContainsString('data-group="ded"', $html);
        $this->assertStringContainsString(__('ui.pt_g_std'), $html);
        $this->assertStringContainsString(__('ui.pt_g_ded'), $html);
    }

    /**
     * ⚠️ ادغامِ **درون‌شهری** باید بماند: دو زیرساخت با مشخصاتِ یکسان در یک
     * شهر = یک ردیف، ارزان‌ترین. این همان قاعده‌ای است که کارفرما خواست و
     * سفیدبرچسبی را هم حفظ می‌کند.
     */
    public function test_two_providers_with_the_same_spec_in_one_city_collapse_to_the_cheaper(): void
    {
        $this->loc('fr-paris', 'پاریس');
        $this->plan('fr-paris', 4, 4, 900, 'shared', ['provider' => 'expensive', 'provider_ref' => 'x1']);
        $this->plan('fr-paris', 4, 4, 400, 'shared', ['provider' => 'cheap', 'provider_ref' => 'x2']);

        $html = $this->html();

        $this->assertSame(1, substr_count($html, 'data-city='), 'گران‌تر نباید نشان داده شود');
        $this->assertStringContainsString('data-price="4000000"', $html);
        $this->assertStringNotContainsString('data-price="9000000"', $html);
    }

    /** پیش‌فرضِ چیدمان: ارزان به گران */
    public function test_rows_are_ordered_cheapest_first(): void
    {
        $this->loc('fr-paris', 'پاریس');
        $this->plan('fr-paris', 8, 16, 900);
        $this->plan('fr-paris', 1, 2, 200);
        $this->plan('fr-paris', 4, 8, 500);

        $html = $this->html();

        preg_match_all('~data-price="(\d+)"~', $html, $m);
        $prices = array_map('intval', $m[1]);

        $sorted = $prices;
        sort($sorted);
        $this->assertSame($sorted, $prices, 'جدول باید از ارزان به گران باشد');
    }

    /** ابزارِ فیلتر باید فقط شهرهایی را بدهد که واقعاً ردیف دارند */
    public function test_the_city_filter_only_lists_cities_that_have_rows(): void
    {
        $this->loc('fr-paris', 'پاریس');
        $this->loc('fr-lyon', 'لیون');
        $this->plan('fr-paris', 2, 4, 500);
        // لیون عمداً پلنی ندارد

        $html = $this->html();

        // فیلترها داخلِ هدرِ جدول‌اند و دکمه‌اند، نه `<select>`
        $this->assertStringContainsString('data-f="city" data-v="پاریس"', $html);
        $this->assertStringNotContainsString('data-v="لیون"', $html,
            'فیلترِ بی‌نتیجه بدترین تجربه است');
    }

    /**
     * هر ردیف باید مستقیم به تسویهٔ همان پلن در همان مکان برود.
     *
     * ⚠️ دو شهر عمداً مشخصاتِ **متفاوت** دارند، وگرنه گران‌تر مغلوب می‌شود و
     * حذف — و آن‌وقت این تست چیزی را می‌سنجید که اصلاً روی صفحه نیست.
     */
    public function test_every_row_links_to_checkout_for_its_own_plan_and_city(): void
    {
        $this->loc('fr-paris', 'پاریس');
        $this->loc('fr-lyon', 'لیون');
        $this->plan('fr-paris', 2, 4, 500);
        $this->plan('fr-lyon', 8, 16, 900);

        $html = $this->html();

        $this->assertStringContainsString('location=fr-paris', $html);
        $this->assertStringContainsString('location=fr-lyon', $html);
    }

    /** نامِ زیرساخت هرگز نباید به HTML برسد */
    public function test_the_provider_name_never_reaches_the_page(): void
    {
        $this->loc('fr-paris', 'پاریس');
        $this->plan('fr-paris', 2, 4, 500, 'shared', ['provider' => 'hetzner']);

        $this->assertStringNotContainsString('hetzner', strtolower($this->html()));
    }

    /** کشورِ بی‌پلن نباید ۵۰۰ بدهد — به متنِ config برمی‌گردد */
    public function test_a_country_with_no_live_plan_still_renders(): void
    {
        $this->get('/vps/france')->assertOk();
    }
}
