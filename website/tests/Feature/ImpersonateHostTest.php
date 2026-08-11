<?php

namespace Tests\Feature;

use App\Http\Middleware\ConsoleHost;
use Tests\TestCase;

/**
 * دکمهٔ «بازگشت به پنل مدیریت» باید از **هر میزبانی** به کنسول برود.
 *
 * ═══ چرا این تست هست ═══
 *
 * کارفرما گزارش داد دکمه «بعضی وقت‌ها» کار نمی‌کند و حدس زد به سوییچِ بینِ
 * `console.` و دامنهٔ اصلی مربوط است. حدسش دربارهٔ **جای** مشکل درست بود:
 *
 *   · نوارِ جای‌نشستن در `partials/header.blade.php` است — هدرِ سایتِ **اصلی**
 *   · پس مدیرِ جای‌نشسته می‌تواند روی `/vps/germany` یا `/blog` باشد و همان‌جا
 *     دکمه را ببیند
 *   · `action` نسبی بود ⇒ به `servernet.cloud/admin/impersonate/stop` پست می‌شد
 *   · و `ConsoleHost` عمداً فقط **GET** را ریدایرکت می‌کند (۳۰۱ روی POST بدنه را
 *     دور می‌ریزد)، پس آن POST روی میزبانِ بازاریابی می‌نشست
 *
 * ⚠️ **این تست ادعا نمی‌کند که علتِ گزارشِ کارفرما همین بود.** سنجیده شد که
 * نشست بینِ دو میزبان مشترک است (توکنِ CSRF روی هر دو بایت‌به‌بایت یکی بود)،
 * پس آن POST امروز احتمالاً کار می‌کند. چیزی که این‌جا بسته می‌شود یک
 * **وابستگیِ نانوشته** است: درستیِ یک کنشِ مدیریتی نباید به `SESSION_DOMAIN`
 * در `.env` گره بخورد. اگر آن تنظیم روزی برداشته شود، دکمه بی‌صدا به صفحهٔ
 * ورود می‌رود و مدیر داخلِ حسابِ مشتری گیر می‌افتد.
 *
 * همان خانواده‌ٔ خرابی یک بار رخ داده و در `partials/header.blade.php` ثبت است:
 * آن‌بار z-index هدر روی نوار می‌افتاد و کلیک را می‌بلعید. یعنی «راهِ بازگشتِ
 * مدیر» تاریخچهٔ شکستن دارد و ارزشِ تستِ صریح را دارد.
 */
class ImpersonateHostTest extends TestCase
{
    /** روی دامنهٔ اصلی، مسیرِ پنلی باید مطلق و روی کنسول شود */
    public function test_a_panel_action_rendered_on_the_marketing_host_targets_the_console(): void
    {
        $this->app['request']->headers->set('HOST', 'servernet.cloud');
        $this->app['request']->server->set('HTTP_HOST', 'servernet.cloud');

        $url = ConsoleHost::panelUrl('/admin/impersonate/stop');

        $this->assertSame('https://console.servernet.cloud/admin/impersonate/stop', $url,
            'کنشِ مدیریتی روی میزبانِ بازاریابی پست می‌شود');
    }

    /** روی خودِ کنسول، مسیرِ نسبی درست است و پرشِ اضافه نمی‌خواهد */
    public function test_on_the_console_the_path_stays_relative(): void
    {
        $this->app['request']->headers->set('HOST', 'console.servernet.cloud');
        $this->app['request']->server->set('HTTP_HOST', 'console.servernet.cloud');

        $this->assertSame('/admin/impersonate/stop',
            ConsoleHost::panelUrl('/admin/impersonate/stop'));
    }

    /**
     * 🔴 مهم‌ترین محافظ برای توسعهٔ محلی: روی localhost **هرگز** نباید به
     * کنسولِ پروداکشن پست شود.
     *
     * بی‌این، یک کلیک روی محیطِ محلی نشستِ جای‌نشستنِ **سایتِ زنده** را می‌بست.
     */
    public function test_local_development_never_posts_to_production(): void
    {
        foreach (['localhost', '127.0.0.1', 'servernet.test'] as $host) {
            $this->app['request']->headers->set('HOST', $host);
            $this->app['request']->server->set('HTTP_HOST', $host);

            $this->assertSame('/admin/impersonate/stop',
                ConsoleHost::panelUrl('/admin/impersonate/stop'),
                "روی «{$host}» نشانیِ پروداکشن ساخته شد");
        }
    }

    /** نرمال‌سازی: با یا بی‌اسلشِ ابتدایی، خروجی یکی است */
    public function test_the_leading_slash_is_normalised(): void
    {
        $this->app['request']->headers->set('HOST', 'servernet.cloud');
        $this->app['request']->server->set('HTTP_HOST', 'servernet.cloud');

        $this->assertSame(
            ConsoleHost::panelUrl('/admin/login'),
            ConsoleHost::panelUrl('admin/login'),
        );
    }

    /**
     * ⚠️ نیمهٔ دومِ ادعا: نوار واقعاً از همین تابع استفاده کند.
     *
     * بی‌این، کسی می‌توانست تابع را درست نگه دارد و در Blade دوباره مسیرِ خام
     * بنویسد — تستِ سبز، باگِ برگشته.
     */
    public function test_the_impersonation_bar_uses_the_helper_not_a_raw_path(): void
    {
        $blade = file_get_contents(resource_path('views/partials/header.blade.php'));

        $this->assertStringContainsString('ConsoleHost::panelUrl', $blade,
            'نوارِ جای‌نشستن دوباره مسیرِ خام گرفته');
        $this->assertStringNotContainsString('action="/admin/impersonate/stop"', $blade);
    }
}
