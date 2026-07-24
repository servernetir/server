<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

/**
 * دامنهٔ کوکیِ نشست — تا کاربرِ واردشده در کنسول روی سایتِ اصلی هم شناخته شود.
 *
 * پنل روی console.servernet.cloud است و سایت روی servernet.cloud؛ اگر کوکی
 * host-only بماند، هدرِ سایتِ اصلی نامِ کاربر را نشان نمی‌دهد.
 */
class SessionCookieDomainTest extends TestCase
{
    /** میزبان‌های خودمان کوکیِ مشترک می‌گیرند */
    public function test_our_hosts_get_the_shared_cookie_domain(): void
    {
        foreach (['servernet.cloud', 'console.servernet.cloud', 'CONSOLE.SERVERNET.CLOUD', 'www.servernet.cloud'] as $host) {
            $this->assertSame('.servernet.cloud', AppServiceProvider::cookieDomainFor($host), $host);
        }
    }

    /** محلی دست‌نخورده می‌ماند، وگرنه ورودِ محلی می‌شکند */
    public function test_local_hosts_are_untouched(): void
    {
        foreach (['localhost', '127.0.0.1', 'localhost:8000'] as $host) {
            $this->assertNull(AppServiceProvider::cookieDomainFor($host), $host);
        }
    }

    /**
     * دامنهٔ شبیه‌سازی‌شده نباید کوکیِ ما را بگیرد — اگر با «پایان‌یافتن با
     * servernet.cloud» (بدون نقطه) می‌سنجیدیم، evil-servernet.cloud هم جا می‌افتاد.
     */
    public function test_lookalike_domains_do_not_get_our_cookie(): void
    {
        foreach (['evil-servernet.cloud', 'notservernet.cloud', 'servernet.cloud.attacker.com', 'servernet.cloudx'] as $host) {
            $this->assertNull(AppServiceProvider::cookieDomainFor($host), $host);
        }
    }

    /**
     * حلقهٔ آخرِ زنجیره: مقدارِ config در زمانِ اجرا واقعاً دامنهٔ کوکیِ نشستِ
     * پاسخ را عوض می‌کند. (provider در boot ست می‌کند و StartSession بعد از آن
     * می‌خواند — این تست همان را ثابت می‌کند، نه فرض.)
     */
    public function test_runtime_config_actually_changes_the_emitted_cookie_domain(): void
    {
        config(['session.domain' => '.servernet.cloud']);

        $response = $this->get('/');
        $name = config('session.cookie');

        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === $name);

        $this->assertNotNull($cookie, 'کوکیِ نشست در پاسخ نبود');
        $this->assertSame('.servernet.cloud', $cookie->getDomain());
    }
}
