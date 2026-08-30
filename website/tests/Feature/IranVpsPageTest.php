<?php

namespace Tests\Feature;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * صفحهٔ `/vps/iran` — «مشکل داره نشون نمیده».
 *
 * 🔴 سه خرابیِ جدا که همگی با کدِ ۲۰۰ و بی‌هیچ خطایی رخ می‌دادند و هر سه از
 * چشمِ بازدیدکننده یک چیز بودند: «این صفحه چیزی نشان نمی‌دهد».
 *
 *  ۱) **سکوت در حالتِ بی‌موجودی.** `scopeSellable` هر پلنی را که
 *     `price_irt = 0` باشد بیرون می‌گذارد، و `price_irt` وقتی صفر می‌شود که
 *     نرخِ روزِ یورو در دسترس نباشد. یعنی یک قطعیِ چنددقیقه‌ایِ نرخ، کلِ
 *     کاتالوگِ یک کشور را از صفحه برمی‌داشت و صفحه بی‌هیچ توضیحی به کارت‌های
 *     config با «استعلام از واحد فروش» برمی‌گشت. نه بازدیدکننده می‌فهمید چرا
 *     قیمت نیست، نه ما می‌فهمیدیم صفحه سالم است و **داده** نیست.
 *
 *  ۲) **بخشِ واقعاً خالی.** اگر محصولی پلنِ config هم نداشت (یا کلیدِ `plans`
 *     نداشت)، ویو یک `<div class="plans">`ِ خالی می‌ساخت — یا ۵۰۰ می‌داد.
 *
 *  ۳) **تکثیرِ پرس‌وجو به ازای هر ردیف.** `CloudPlan::trafficLabel()` برای هر
 *     ردیف `Setting::get()` می‌زد و آن هم هر بار یک `Schema::hasTable()` و یک
 *     خواندنِ کش. صفحهٔ ایران ۱۵۱ ردیف دارد (پنج شهر × ~۳۰ مشخصات) و هر ردیف
 *     دو بار خوانده می‌شد ⇒ ~۳۰۰ رفت‌وبرگشتِ اضافه. سنجشِ زنده: ۳٫۴ تا ۷٫۵
 *     ثانیه برای `/vps/iran` در برابر ۰٫۷ ثانیه برای `/vps/germany`.
 *     ⚠️ و چون کش و نشست هر دو روی همان دیتابیس‌اند، هر یک از آن ۳۰۰ خواندن یک
 *     فرصتِ ۵۰۰ (صفحهٔ سفید) است. سنگین‌ترین صفحهٔ سایت نباید شکننده‌ترینش باشد.
 *
 * ⚠️ هیچ‌کدام از این تست‌ها به کدِ ۲۰۰ قانع نیست — همه **محتوای واقعی** را
 * می‌سنجند (نامِ شهر، عددِ قیمت، لینکِ خرید، متنِ توضیح).
 */
class IranVpsPageTest extends TestCase
{
    use RefreshDatabase;

    private function tehran(): CloudLocation
    {
        return CloudLocation::create([
            'code' => 'ir-tehran', 'country' => 'IR', 'city' => 'Tehran',
            'is_active' => true, 'sort' => 0,
        ]);
    }

    /**
     * پلنِ فروختنی. مشخصات و قیمت **هم‌جهت** بالا می‌روند تا `CloudDominance`
     * هیچ‌کدام را مغلوب نشمارد؛ وگرنه تست چیزی را می‌سنجید که روی صفحه نیست.
     */
    private function plan(int $n, string $loc = 'ir-tehran', array $over = []): CloudPlan
    {
        return CloudPlan::create(array_merge([
            'provider'      => 'p1',
            'provider_ref'  => $loc.'-'.$n,
            'location_code' => $loc,
            'slug'          => 'cv-'.$n.'c-'.$n.'g-'.(20 * $n).'d-'.$loc,
            'public_name'   => 'CV-'.$n.'-'.$n,
            'vcpu'          => $n,
            'ram_mb'        => $n * 1024,
            'disk_gb'       => 20 * $n,
            'disk_type'     => 'nvme',
            'traffic_gb'    => 1000 * $n,
            'cpu_kind'      => 'shared',
            'arch'          => 'x86',
            'cost_eur_cents'  => 100 * $n,
            'price_eur_cents' => 200 * $n,
            'price_irt'       => 500000 * $n,
            'is_active'     => true, 'in_stock' => true, 'admin_disabled' => false,
        ], $over));
    }

    // ───────────────── ۱) حالتِ سالم: محتوای واقعی، نه فقط ۲۰۰ ─────────────────

    /** با موجودیِ زنده، جدول باید شهر و قیمت و لینکِ خریدِ همان پلن را بدهد */
    public function test_the_iran_page_shows_real_rows_when_stock_is_sellable(): void
    {
        $this->tehran();
        $this->plan(2);

        $html = $this->get('/vps/iran')->assertOk()->getContent();

        $this->assertStringContainsString('data-city="تهران"', $html,
            'ردیفِ پلن روی صفحه نیست — همان «نشون نمیده»');
        $this->assertStringContainsString('data-price="1000000"', $html);
        $this->assertStringContainsString('location=ir-tehran', $html);
        $this->assertStringContainsString('plan=cv-2c-2g-40d-ir-tehran', $html);

        // قیمت باید با رقمِ فارسی چاپ شود، نه خام
        $this->assertStringContainsString(fa_num(number_format(1000000)), $html);

        // در حالتِ سالم توضیحِ بی‌موجودی نباید باشد
        $this->assertStringNotContainsString(__('ui.hp_stock_out'), $html);
    }

    // ───────── ۲) حالتِ بی‌موجودی: صفحه باید حرف بزند، نه ساکت بماند ─────────

    /**
     * 🔴 قلبِ گزارشِ کارفرما.
     *
     * نرخِ یورو نیامده ⇒ `price_irt = 0` ⇒ `scopeSellable` همه را رد می‌کند.
     * صفحه باید صریح بگوید چه شده و چه‌کار باید کرد — و **قیمتی از خودش
     * نسازد**.
     */
    public function test_a_country_with_no_sellable_stock_says_so_instead_of_going_silent(): void
    {
        $this->tehran();
        $this->plan(2, 'ir-tehran', ['price_irt' => 0]);   // نرخِ ارز نیامده

        $html = $this->get('/vps/iran')->assertOk()->getContent();

        $this->assertStringContainsString(__('ui.hp_stock_out'), $html,
            'کشورِ بی‌موجودی هیچ توضیحی نمی‌دهد — بازدیدکننده صفحه را خالی می‌بیند');

        // مشخصات برای مقایسه می‌مانَد
        $this->assertStringContainsString('استعلام از واحد فروش', $html);
        $this->assertStringContainsString('IR-1', $html);

        // ⚠️ و هیچ قیمتِ سخت‌کدِ config نباید بیرون بزند
        $this->assertStringNotContainsString('data-price=', $html);
        $this->assertStringNotContainsString(fa_num(number_format(490000)), $html,
            'قیمتِ سخت‌کدِ config روی صفحه آمده — همان چیزی که مشتری را سرِ پرداخت شوکه می‌کرد');
    }

    /** همان توضیح باید در هر سه زبان واقعاً ترجمه‌شده باشد */
    public function test_the_no_stock_notice_is_localised_in_all_three_languages(): void
    {
        $this->tehran();
        $this->plan(2, 'ir-tehran', ['price_irt' => 0]);

        foreach (['' => 'fa', '/en' => 'en', '/tr' => 'tr'] as $prefix => $locale) {
            $html = $this->get($prefix.'/vps/iran')->assertOk()->getContent();

            $expected = trans('ui.hp_stock_out', [], $locale);

            $this->assertNotSame('ui.hp_stock_out', $expected,
                "کلیدِ hp_stock_out در زبانِ {$locale} وجود ندارد — کاربر رشتهٔ خام می‌بیند");

            // ⚠️ با `e()` مقایسه می‌شود، نه با متنِ خام: آپاستروفِ انگلیسی در
            // Blade به `&#039;` تبدیل می‌شود و تستِ خام بی‌دلیل قرمز می‌شد.
            $this->assertStringContainsString(e($expected), $html,
                "توضیحِ بی‌موجودی در زبانِ {$locale} روی صفحه نیست");
        }
    }

    /** بخشِ پلن‌ها هرگز نباید **خالی** باشد، حتی وقتی config هم پلنی ندارد */
    public function test_the_plans_section_is_never_left_empty(): void
    {
        config(['catalog.vps.iran.plans' => []]);

        $html = $this->get('/vps/iran')->assertOk()->getContent();

        $this->assertStringContainsString(__('ui.hp_no_plans'), $html,
            'نه پلنی هست نه توضیحی — دقیقاً همان بخشِ خالیِ گزارش‌شده');
        $this->assertStringNotContainsString('<div class="plans  " id="plans">', $html);
    }

    // ───────── ۳) تکثیرِ پرس‌وجو: کندی‌ای که به صفحهٔ سفید ختم می‌شود ─────────

    /**
     * 🔴 تعدادِ پرس‌وجو نباید با تعدادِ ردیف‌ها رشد کند.
     *
     * پیش از رفع، هر ردیف دو بار `Setting::get()` می‌خورد و هر بار یک
     * `Schema::hasTable('settings')` می‌زد؛ یعنی ۳۶ ردیفِ بیشتر ⇒ ~۷۲
     * پرس‌وجوی بیشتر. سنجشِ محلیِ واقعی روی همین صفحه: ۱۴۵ پرس‌وجو برای ۳۰
     * ردیف، در برابر ۱۵ بعد از رفع.
     *
     * ⚠️ این تست عمداً **اختلاف** را می‌سنجد نه عددِ مطلق را: عددِ مطلق با هر
     * قابلیتِ تازه‌ای عوض می‌شود و تست را شکننده می‌کند، ولی «به ازای هر ردیف
     * یک پرس‌وجو» یک باگ است در هر عددی.
     */
    public function test_the_page_does_not_run_a_query_per_plan_row(): void
    {
        $this->tehran();

        for ($i = 1; $i <= 4; $i++) {
            $this->plan($i);
        }

        $small = $this->countQueriesForIranPage(4);

        for ($i = 5; $i <= 40; $i++) {
            $this->plan($i);
        }

        $big = $this->countQueriesForIranPage(40);

        $this->assertLessThanOrEqual(5, $big - $small,
            "پرس‌وجوها با ردیف‌ها رشد می‌کنند: {$small} برای ۴ ردیف و {$big} برای ۴۰ ردیف. "
            .'یعنی صفحهٔ ایران با ۱۵۱ ردیف صدها رفت‌وبرگشتِ اضافه می‌زند.');
    }

    private function countQueriesForIranPage(int $expectedRows): int
    {
        // یک بازدیدِ گرم‌کننده تا کشِ درون‌درخواستیِ کانتینر مقصر شمرده نشود
        $this->get('/vps/iran')->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $html = $this->get('/vps/iran')->assertOk()->getContent();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($expectedRows, substr_count($html, 'data-city='),
            'تعدادِ ردیف‌ها آن چیزی نیست که تست فرض کرده — سنجش بی‌معنا می‌شود');

        return $count;
    }

    // ───────── محافظِ خودِ لایهٔ تنظیمات ─────────

    /**
     * ⚠️ `Setting` از `Eloquent\Model` ارث می‌برد و آن‌جا `all($columns = ['*'])`
     * از قبل هست. اگر کسی حافظهٔ درون‌درخواستی را دوباره `all()` بنامد، PHP یک
     * خطای **زمانِ کامپایل** می‌دهد: کلاس اصلاً بار نمی‌شود و کلِ پردازه — نه
     * فقط این متد — می‌میرد. همین یک فراخوانی برای گرفتنش کافی است.
     */
    public function test_the_settings_memo_does_not_clash_with_eloquent_all(): void
    {
        // خودِ همین فراخوانی محافظِ اصلی است: اگر نامِ متد با متدِ ایستای
        // Eloquent تصادم کند، کلاس اصلاً بار نمی‌شود و تست «Premature end of
        // PHP process» می‌دهد، نه شکستِ ادعا.
        $this->assertIsArray(Setting::cached());
        $this->assertArrayNotHasKey('cloud_traffic_unlimited', Setting::cached());

        Setting::put('cloud_traffic_unlimited', '1');

        $this->assertSame('1', Setting::get('cloud_traffic_unlimited'),
            'حافظهٔ درون‌درخواستی بعد از نوشتن پاک نشده — مقدارِ کهنه برمی‌گردد');

        Setting::put('cloud_traffic_unlimited', null);

        $this->assertNull(Setting::get('cloud_traffic_unlimited'));
    }
}
