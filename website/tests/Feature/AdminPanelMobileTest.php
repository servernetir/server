<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * پنلِ مدیریت روی موبایل — کشوی کناری، نه دیوارِ لینک.
 *
 * ═══ نقصی که این را لازم کرد ═══
 *
 * 🔴 در ≤۸۲۰px سایدبار `position:static` و افقی می‌شد: ~۳۵ لینک به‌صورتِ
 * flex-wrap **بالای هر صفحه** — محتوا یک صفحه پایین می‌رفت، جدول‌ها از قاب
 * بیرون می‌زدند و عملاً چیزی قابلِ مدیریت نبود. گزارشِ کارفرما: «همه چی بهم
 * خورده بیرون زده و منو مناسب نیست».
 *
 * ⚠️ CSS تست‌پذیرِ رفتاری نیست؛ این تست **قرارداد** را قفل می‌کند: اجزای
 * کشو در HTML باشند، قواعدِ کلیدی در CSS باشند، و الگوی شکسته برنگردد.
 */
class AdminPanelMobileTest extends TestCase
{
    use RefreshDatabase;

    private function html(): string
    {
        return $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/tickets')->assertOk()->getContent();
    }

    /** اجزای کشو: همبرگر، رویه، و شناسهٔ سایدبار — همه در DOM. */
    public function test_the_drawer_machinery_is_in_the_dom(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('id="ad-burger"', $html, 'دکمهٔ همبرگر نیست');
        $this->assertStringContainsString('id="ad-scrim"', $html, 'رویهٔ تیره نیست');
        $this->assertStringContainsString('id="ad-side"', $html, 'سایدبار شناسه ندارد — اسکریپت کور است');
        $this->assertStringContainsString("classList.toggle('nav-open'", $html, 'اسکریپتِ باز/بسته روی صفحه نیست');
        // Escape هم می‌بندد — صفحه‌کلیدی‌ها گیر نکنند
        $this->assertStringContainsString("e.key === 'Escape'", $html);
    }

    /** قواعدِ CSS کشو + گاردِ [hidden] برای رویه. */
    public function test_the_css_has_the_drawer_and_the_hidden_guard(): void
    {
        $css = file_get_contents(public_path('assets/css/admin.css'));

        $this->assertStringContainsString('body.nav-open .ad-side{transform:none}', $css, 'قاعدهٔ بازشدنِ کشو نیست');
        /*
        | 🔴 تلهٔ ثبت‌شدهٔ [hidden]: قاعدهٔ نویسنده display پیش‌فرضِ مرورگر را
        | می‌بلعد — بدونِ این خط، رویهٔ تیره از لحظهٔ لود روی کلِ پنل می‌نشست
        | و **هیچ کلیکی به صفحه نمی‌رسید**.
        */
        $this->assertStringContainsString('.ad-scrim[hidden]{display:none}', $css, 'گاردِ [hidden] رویه نیست');
        $this->assertStringContainsString('.ad-mob{display:none}', $css, 'نوارِ موبایل باید در دسکتاپ پنهان باشد');
    }

    /** 🔴 الگوی شکستهٔ قبلی برنگردد: سایدبارِ static/افقی در موبایل. */
    public function test_the_old_broken_horizontal_sidebar_is_gone(): void
    {
        $css = file_get_contents(public_path('assets/css/admin.css'));

        $this->assertStringNotContainsString(
            '.ad-side{position:static;height:auto;flex-direction:row',
            $css,
            'دیوارِ افقیِ لینک‌ها برگشته است'
        );
    }

    /** جدول‌های پهن داخلِ پنلِ خودشان اسکرول شوند، نه کلِ صفحه. */
    public function test_wide_tables_scroll_inside_their_panel(): void
    {
        $css = file_get_contents(public_path('assets/css/admin.css'));

        $this->assertMatchesRegularExpression(
            '~\.ad-panel\{overflow-x:auto~',
            $css,
            'پنل‌ها سرریزِ افقی را مهار نمی‌کنند — جدول از قاب بیرون می‌زند'
        );
    }
}
