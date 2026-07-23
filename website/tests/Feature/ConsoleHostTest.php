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
