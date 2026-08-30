<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * مسیریابی کنسول: همهٔ پنل روی console.servernet.cloud.
 *
 * دو جهت:
 *   • دامنهٔ اصلی + مسیر پنلی → ۳۰۱ به کنسول
 *   • کنسول + مسیر غیرپنلی → ۳۰۱ به دامنهٔ اصلی
 */
class ConsoleHostTest extends TestCase
{
    private function on(string $host, string $uri, string $method = 'GET')
    {
        return $this->call($method, "http://{$host}{$uri}");
    }

    // ── www به دامنهٔ اصلی ──

    /**
     * 🔴 هر دو میزبان همان صفحه را سرو می‌کردند و canonical از میزبانِ درخواست
     * ساخته می‌شود، پس هر صفحه دو نسخهٔ ایندکس‌شدنیِ خودcanonical داشت.
     */
    public function test_www_redirects_to_the_apex(): void
    {
        $this->on('www.servernet.cloud', '/blog')
            ->assertRedirect('https://servernet.cloud/blog');
    }

    public function test_www_keeps_the_query_string(): void
    {
        $this->on('www.servernet.cloud', '/blog?page=3')
            ->assertRedirect('https://servernet.cloud/blog?page=3');
    }

    /** زبان‌ها هم باید حفظ شوند، نه اینکه همه به صفحهٔ اول بروند */
    public function test_www_keeps_the_locale_prefix(): void
    {
        $this->on('www.servernet.cloud', '/en/blog')
            ->assertRedirect('https://servernet.cloud/en/blog');
    }

    /** ⚠️ POST نباید ۳۰۱ شود: تغییرِ متد بدنه را دور می‌ریزد و فرم خالی می‌رسد */
    public function test_post_to_www_is_not_redirected(): void
    {
        $res = $this->on('www.servernet.cloud', '/blog', 'POST');

        $this->assertNotSame(301, $res->getStatusCode());
    }

    /**
     * 🔴 HEAD هم باید ۳۰۱ شود.
     *
     * `Route::get()` در لاراول HEAD را هم می‌گیرد. بی‌این، لینک‌سنج‌ها و
     * مانیتورهای آپ‌تایم و پروکسی‌ها `www` را منبعی زندهٔ ۲۰۰ ثبت می‌کردند
     * درحالی‌که مرورگر ۳۰۱ می‌گرفت — یعنی همان دوگانگیِ سئویی برای خزنده‌ها
     * دست‌نخورده می‌ماند.
     */
    public function test_head_to_www_is_redirected_like_get(): void
    {
        $this->on('www.servernet.cloud', '/blog', 'HEAD')
            ->assertRedirect('https://servernet.cloud/blog');
    }
    /** دامنهٔ اصلی خودش نباید حلقهٔ ریدایرکت بسازد */
    public function test_the_apex_itself_is_not_redirected(): void
    {
        $this->on('servernet.cloud', '/blog')->assertOk();
    }
    // ── دامنهٔ اصلی: مسیر پنلی به کنسول می‌رود ──

    public function test_main_host_redirects_login_to_console(): void
    {
        $this->on('servernet.cloud', '/login')
            ->assertRedirect('https://console.servernet.cloud/login');
    }

    public function test_main_host_redirects_register_to_console(): void
    {
        $this->on('servernet.cloud', '/register')
            ->assertRedirect('https://console.servernet.cloud/register');
    }

    public function test_main_host_redirects_account_to_console(): void
    {
        $this->on('servernet.cloud', '/account/tickets')
            ->assertRedirect('https://console.servernet.cloud/account/tickets');
    }

    public function test_main_host_redirects_admin_to_console(): void
    {
        $this->on('servernet.cloud', '/admin/finance')
            ->assertRedirect('https://console.servernet.cloud/admin/finance');
    }

    public function test_main_host_keeps_marketing_pages(): void
    {
        // بلاگ و ابزار روی دامنهٔ اصلی می‌مانند
        $this->on('servernet.cloud', '/blog')->assertOk();
    }

    public function test_a_post_to_main_login_is_not_redirected(): void
    {
        // POST نباید ۳۰۱ شود (بدنه گم می‌شود)؛ فرم‌ها روی کنسول رندر می‌شوند
        $r = $this->on('servernet.cloud', '/login', 'POST');
        $this->assertNotSame(301, $r->getStatusCode());
    }

    // ── کنسول: مسیر غیرپنلی به دامنهٔ اصلی می‌رود ──

    public function test_console_redirects_blog_to_main(): void
    {
        $this->on('console.servernet.cloud', '/blog')
            ->assertRedirect('https://servernet.cloud/blog');
    }

    public function test_console_root_redirects_to_login_for_guests(): void
    {
        $this->on('console.servernet.cloud', '/')->assertRedirect();
    }

    public function test_console_allows_login(): void
    {
        // ورود روی کنسول باید مستقیم کار کند، نه ریدایرکت
        $this->on('console.servernet.cloud', '/login')->assertOk();
    }

    public function test_console_allows_admin_login(): void
    {
        $this->on('console.servernet.cloud', '/admin/login')->assertOk();
    }

    public function test_console_pages_are_noindex(): void
    {
        $this->on('console.servernet.cloud', '/login')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
