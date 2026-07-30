<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * نوارِ «جای مشتری نشسته‌اید» — راهِ برگشتِ مدیر.
 *
 * ═══ باگی که این تست نگهبانش است ═══
 *
 * نوار قبلاً فقط در panel/layout.blade.php و **داخلِ تگِ main** بود، با
 * `position:sticky;top:0;z-index:120`. هدرِ سایت اما
 * `position:fixed;top:0;z-index:200` است (site.css → .site-header-wrap)، پس:
 *
 *   ۱) نوار دقیقاً همان مستطیلِ هدر را اشغال می‌کرد و ۱۲۰ < ۲۰۰ ⇒ هدر رویش
 *      می‌افتاد، نه دیده می‌شد و نه کلیک را می‌گرفت؛
 *   ۲) در صفحاتِ سایتِ اصلی (که مستقیم از layouts/site ارث می‌برند) **هیچ**
 *      نواری رندر نمی‌شد.
 *
 * نتیجه: مدیر بعد از ورود به حسابِ مشتری هیچ راهِ خروجی نداشت جز پاک‌کردنِ
 * دستیِ نشست. یعنی یک باگِ کاربردیِ جدی، نه زیبایی‌شناسی.
 *
 * ⚠️ «کد ۲۰۰ یعنی هیچ»: اینجا وجودِ نوار در HTML هم کافی نیست — تست باید ثابت
 * کند نوار **بیرونِ** جریانِ محتوا و داخلِ هدرِ ثابت است، و قاعده‌های CSSِ
 * تکیه‌گاهش واقعاً در فایلِ سروشده هستند (کلاسِ CSSِ نبود بی‌هیچ خطایی
 * بی‌استایل رندر می‌شود).
 */
class ImpersonateBannerTest extends TestCase
{
    use RefreshDatabase;

    /** صفحهٔ پنلِ مشتری و یک صفحهٔ سایتِ اصلی — نوار باید در هر دو باشد */
    private const PAGES = ['/account', '/'];

    private function admin(): User
    {
        return User::create([
            'name' => 'مدیر', 'email' => 'a'.random_int(1, 99999).'@x.com',
            'password' => bcrypt('secret1234'), 'role' => 'admin',
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'c'.random_int(1, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'secret1234', 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    /** واردِ حسابِ مشتری می‌شود و همان مشتری را برمی‌گرداند */
    private function impersonate(): Customer
    {
        $customer = $this->customer();

        $this->actingAs($this->admin(), 'web')
            ->post("/admin/customers/{$customer->id}/impersonate")
            ->assertRedirect();

        return $customer;
    }

    private function html(string $uri): string
    {
        $res = $this->get($uri);
        $this->assertSame(200, $res->getStatusCode(), "{$uri} کد ".$res->getStatusCode().' داد');

        return $res->getContent();
    }

    /**
     * ادعای اصلی: نوار و لینکِ خروج در **هر دو** حالت در HTML هست.
     */
    public function test_bar_and_exit_control_render_on_panel_and_on_main_site(): void
    {
        $customer = $this->impersonate();

        foreach (self::PAGES as $uri) {
            $html = $this->html($uri);

            // خودِ نوار — نامِ کلاسی که درستش کرده
            $this->assertStringContainsString('class="imp-bar"', $html,
                "{$uri}: نوارِ impersonation رندر نشد");

            // راهِ برگشت: فرمِ POST به مسیرِ توقف + دکمه
            $this->assertStringContainsString('action="/admin/impersonate/stop"', $html,
                "{$uri}: فرمِ خروج از حسابِ مشتری نیست");
            $this->assertStringContainsString('class="imp-out"', $html,
                "{$uri}: دکمهٔ خروج نیست");
            $this->assertStringContainsString('بازگشت به پنل مدیریت', $html,
                "{$uri}: متنِ دکمهٔ بازگشت نیست");

            // مدیر باید بداند جای **کدام** مشتری نشسته
            $this->assertStringContainsString($customer->displayName(), $html,
                "{$uri}: نامِ مشتری در نوار نیست");
            $this->assertStringContainsString($customer->code, $html,
                "{$uri}: شناسهٔ مشتری در نوار نیست");
        }
    }

    /**
     * علتِ ریشه‌ای: نوار باید **بیرونِ** تگِ main و داخلِ .site-header-wrap
     * باشد. اگر کسی روزی برش گرداند داخلِ محتوا، همان باگ برمی‌گردد و این
     * ادعا می‌شکند.
     */
    public function test_bar_sits_inside_the_fixed_header_wrap_not_inside_the_content(): void
    {
        $this->impersonate();

        foreach (self::PAGES as $uri) {
            $html = $this->html($uri);

            $wrap = strpos($html, 'class="site-header-wrap"');
            $bar  = strpos($html, 'class="imp-bar"');
            $top  = strpos($html, 'class="topbar"');
            $main = strpos($html, '<main id="main">');

            $this->assertNotFalse($wrap, "{$uri}: .site-header-wrap پیدا نشد");
            $this->assertNotFalse($bar, "{$uri}: .imp-bar پیدا نشد");
            $this->assertNotFalse($main, "{$uri}: تگِ main پیدا نشد");

            $this->assertGreaterThan($wrap, $bar,
                "{$uri}: نوار بیرونِ هدرِ ثابت است — دوباره زیرِ هدر می‌رود");
            $this->assertLessThan($top, $bar,
                "{$uri}: نوار باید اولین فرزندِ هدر باشد، بالای نوارِ اعتماد");
            $this->assertLessThan($main, $bar,
                "{$uri}: نوار داخلِ محتوا برگشته — همان باگِ اولیه");
        }
    }

    /**
     * بدنه باید کلاسِ imp-on بگیرد؛ padding-topِ همان کلاس است که جلوی
     * پوشاندنِ محتوا توسطِ نوار را می‌گیرد.
     */
    public function test_body_gets_the_spacing_class_so_the_bar_covers_nothing(): void
    {
        $this->impersonate();

        foreach (self::PAGES as $uri) {
            $this->assertMatchesRegularExpression('/<body[^>]*class="[^"]*\bimp-on\b/',
                $this->html($uri), "{$uri}: کلاسِ imp-on روی body نیست");
        }
    }

    /**
     * ⚠️ کلاسِ CSSِ نبود بی‌هیچ خطایی بی‌استایل رندر می‌شود. پس ثابت کن
     * قاعده‌ها واقعاً در فایلِ سروشده هستند — و همان z-indexِ شکستهٔ قبلی نه.
     */
    public function test_the_css_that_makes_it_clickable_actually_ships(): void
    {
        $site = file_get_contents(public_path('assets/css/site.css'));

        $this->assertStringContainsString('.imp-bar{', $site, 'قاعدهٔ .imp-bar در site.css نیست');
        $this->assertStringContainsString('.imp-out{', $site, 'قاعدهٔ .imp-out در site.css نیست');
        $this->assertStringContainsString('body.imp-on{padding-top:var(--imp-h)}', $site,
            'بدونِ این، نوار روی محتوا می‌افتد');
        $this->assertMatchesRegularExpression('/--imp-h:\s*\d+px/', $site,
            'متغیرِ ارتفاعِ نوار تعریف نشده');

        // نوار داخلِ .site-header-wrap (z-index:200) است، پس خودش باید بالاتر
        // از .topbar (z-index:201) در همان بستر بنشیند
        $this->assertMatchesRegularExpression('/\.imp-bar\{[^}]*z-index:\s*202/', $site,
            'z-indexِ نوار داخلِ هدر درست نیست');

        // اسکرول‌بار و سایدبارهای چسبیده باید جابه‌جا شده باشند
        $this->assertStringContainsString('body.imp-on #progress', $site);
        $this->assertStringContainsString('body.imp-on .drawer', $site);

        $panel = file_get_contents(public_path('assets/css/panel.css'));
        $this->assertStringContainsString('body.imp-on .pnl-side', $panel,
            'سایدبارِ پنل با هدرِ بلندشده جابه‌جا نشده');
    }

    /**
     * استایلِ شکستهٔ قبلی (نوارِ درون‌محتوا با z-index:120) نباید برگردد —
     * نه در Blade، نه در CSS.
     */
    public function test_the_old_broken_inline_style_is_gone(): void
    {
        $panelLayout = file_get_contents(resource_path('views/panel/layout.blade.php'));

        $this->assertStringNotContainsString('position:sticky;top:0;z-index:120', $panelLayout);
        $this->assertStringNotContainsString('<div class="imp-bar">', $panelLayout,
            'نوار باید فقط در partials/header.blade.php باشد، نه دو جا');
        $this->assertStringNotContainsString('.imp-bar{', $panelLayout,
            'استایلِ درون‌خطیِ نوار برگشته — جایش انتهای site.css است');

        $header = file_get_contents(resource_path('views/partials/header.blade.php'));
        $this->assertStringContainsString('class="imp-bar"', $header);

        // در HTML رندرشده هم نباید نوار دو بار بیاید
        $this->impersonate();
        foreach (self::PAGES as $uri) {
            $this->assertSame(1, substr_count($this->html($uri), 'class="imp-bar"'),
                "{$uri}: نوار بیش از یک بار رندر شد");
        }
    }

    /** بدونِ جای‌نشستن، هیچ نواری و هیچ کلاسِ فاصله‌ای نباید باشد */
    public function test_nothing_leaks_when_not_impersonating(): void
    {
        $customer = $this->customer();

        foreach (self::PAGES as $uri) {
            $html = $this->actingAs($customer, 'customer')->get($uri)->getContent();

            $this->assertStringNotContainsString('imp-bar', $html, "{$uri}: نوار بی‌دلیل رندر شد");
            $this->assertStringNotContainsString('imp-on', $html, "{$uri}: کلاسِ فاصله بی‌دلیل آمد");
            $this->assertStringNotContainsString('/admin/impersonate/stop', $html);
        }
    }

    /**
     * دکمهٔ نوار واقعاً کار می‌کند — نوار بی‌عملِ کلیک‌پذیر هم بی‌فایده است.
     */
    public function test_clicking_the_bar_button_really_returns_the_admin(): void
    {
        $this->impersonate();

        $this->assertStringContainsString('class="imp-bar"', $this->html('/account'));

        $this->post('/admin/impersonate/stop')->assertRedirect();

        // حالا نه نوار می‌ماند نه دسترسیِ مشتری
        $this->assertFalse(\Illuminate\Support\Facades\Auth::guard('customer')->check());
        $this->assertStringNotContainsString('imp-bar', $this->html('/'));
    }
}
