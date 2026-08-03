<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * انتقالِ سئوی `servernet.ir` → `servernet.cloud`.
 *
 * 🔴 خطرناک‌ترین چیزی که این تست‌ها می‌پایند، خودِ ریدایرکت نیست — **استثناها**
 * است. دامنهٔ قدیمی بازنشسته نشده و هنوز کار می‌کند:
 *   • `my.servernet.ir` هنوز WHMCSِ زندهٔ فارسی است
 *   • `‎/sms-relay.php‎` تنها راهِ فرستادنِ پیامک از سرورِ آلمان است، چون
 *     اپراتورِ ایرانی آی‌پیِ غیرایرانی را رد می‌کند
 * یک ریدایرکتِ بیش‌ازحد پهن، ورودِ همهٔ کاربران را از کار می‌انداخت.
 */
class LegacyDomainTest extends TestCase
{
    use RefreshDatabase;

    private function on(string $host, string $uri, string $method = 'GET')
    {
        return $this->call($method, "http://{$host}{$uri}");
    }

    // ═══════════ انتقالِ عادی ═══════════

    public function test_the_old_apex_redirects_to_the_new_domain(): void
    {
        $this->on('servernet.ir', '/blog')
            ->assertRedirect('https://servernet.cloud/blog');
    }

    public function test_www_of_the_old_domain_redirects_too(): void
    {
        $this->on('www.servernet.ir', '/blog')
            ->assertRedirect('https://servernet.cloud/blog');
    }

    public function test_the_root_goes_to_the_root(): void
    {
        $this->on('servernet.ir', '/')
            ->assertRedirect('https://servernet.cloud/');
    }

    /** پارامترهای کمپین باید بمانند وگرنه گزارشِ بازاریابی می‌شکند */
    public function test_the_query_string_survives(): void
    {
        $this->on('servernet.ir', '/blog?utm_source=telegram&page=2')
            ->assertRedirect('https://servernet.cloud/blog?utm_source=telegram&page=2');
    }

    public function test_an_exact_mapping_is_used(): void
    {
        config(['legacy.exact' => ['/old-hosting' => '/hosting/linux']]);

        $this->on('servernet.ir', '/old-hosting')
            ->assertRedirect('https://servernet.cloud/hosting/linux');
    }

    public function test_a_prefix_mapping_keeps_the_tail(): void
    {
        config(['legacy.prefix' => ['/weblog/' => '/blog/']]);

        $this->on('servernet.ir', '/weblog/my-post')
            ->assertRedirect('https://servernet.cloud/blog/my-post');
    }

    /**
     * مسیرِ ناشناخته → همان مسیر، نه صفحهٔ اول.
     *
     * فرستادنِ همه به «/» یعنی «۴۰۴ نرم»: گوگل ریدایرکتِ بی‌ربط می‌شمارد،
     * اعتباری منتقل نمی‌شود، و سیگنالی که می‌شد نقشه را از رویش ساخت هم از
     * بین می‌رود.
     */
    public function test_an_unmapped_path_keeps_its_path(): void
    {
        $this->on('servernet.ir', '/something/we/never/had')
            ->assertRedirect('https://servernet.cloud/something/we/never/had');
    }

    public function test_the_home_policy_can_be_switched(): void
    {
        config(['legacy.unknown' => 'home']);

        $this->on('servernet.ir', '/whatever')
            ->assertRedirect('https://servernet.cloud/');
    }

    // ═══════════ استثناهای حیاتی ═══════════

    /**
     * 🔴 زیردامنه‌ها دست‌نخورده می‌مانند.
     *
     * `my.servernet.ir` هنوز WHMCSِ زندهٔ فارسی است. یک شرطِ
     * `str_ends_with($host, 'servernet.ir')` این را هم می‌گرفت.
     */
    public function test_subdomains_are_never_redirected(): void
    {
        foreach (['my.servernet.ir', 'panel.servernet.ir', 'mail.servernet.ir'] as $host) {
            $res = $this->on($host, '/');
            $this->assertNotSame(301, $res->getStatusCode(), "$host نباید ریدایرکت شود");
        }
    }

    /**
     * 🔴 رلهٔ پیامک هرگز ریدایرکت نمی‌شود.
     *
     * ۳۰۱ روی POST متد را به GET عوض می‌کند و بدنه را دور می‌ریزد؛ یعنی هر
     * کدِ ورود بی‌صدا گم می‌شود و هیچ‌کس نمی‌تواند وارد شود.
     */
    public function test_the_sms_relay_is_never_redirected(): void
    {
        $res = $this->on('servernet.ir', '/sms-relay.php');
        $this->assertNotSame(301, $res->getStatusCode());

        $post = $this->on('servernet.ir', '/sms-relay.php', 'POST');
        $this->assertNotSame(301, $post->getStatusCode());
    }

    /** تمدیدِ گواهی SSL نباید ریدایرکت شود، وگرنه گواهی تازه نمی‌شود */
    public function test_acme_challenge_is_never_redirected(): void
    {
        $res = $this->on('servernet.ir', '/.well-known/acme-challenge/xyz');
        $this->assertNotSame(301, $res->getStatusCode());
    }

    /** ⚠️ POST نباید ۳۰۱ شود — تغییرِ متد بدنه را دور می‌ریزد */
    public function test_post_is_not_redirected(): void
    {
        $res = $this->on('servernet.ir', '/contact', 'POST');
        $this->assertNotSame(301, $res->getStatusCode());
    }

    /** HEAD باید مثلِ GET رفتار کند، وگرنه خزنده‌ها صفحهٔ قدیمی را زنده می‌بینند */
    public function test_head_is_redirected_like_get(): void
    {
        $this->on('servernet.ir', '/blog', 'HEAD')
            ->assertRedirect('https://servernet.cloud/blog');
    }

    // ═══════════ بی‌اثری روی دامنهٔ اصلی ═══════════

    public function test_the_new_domain_is_untouched(): void
    {
        $this->on('servernet.cloud', '/blog')->assertOk();
    }

    public function test_local_development_is_untouched(): void
    {
        $this->on('localhost', '/blog')->assertOk();
    }

    /**
     * 🔴 HSTS روی دامنهٔ قدیمی نباید `includeSubDomains` داشته باشد.
     *
     * آن پین در مرورگرِ کاربر می‌نشیند، دو سال می‌مانَد و از سمتِ ما قابلِ
     * برگرداندن نیست — و کلِ `*.servernet.ir` را می‌گیرد، از جمله WHMCSِ زنده.
     *
     * ⚠️ نسخهٔ قبلیِ این تست ادعایش را داخل `if ($hsts !== '')` گذاشته بود، پس
     * وقتی هدر اصلاً نبود سبز می‌شد و هیچ‌چیز را نمی‌سنجید. حالا **وجودِ** هدر
     * هم ادعا می‌شود.
     */
    public function test_hsts_does_not_pin_the_old_subdomains(): void
    {
        $res = $this->call('GET', 'https://servernet.ir/sms-relay.php');
        $hsts = (string) $res->headers->get('Strict-Transport-Security');

        $this->assertNotSame('', $hsts, 'هدرِ HSTS باید روی این مسیر فرستاده شود');
        $this->assertStringNotContainsString('includeSubDomains', $hsts);

        // دامنهٔ اصلی همچنان کاملش را می‌گیرد
        $main = $this->call('GET', 'https://servernet.cloud/blog');
        $this->assertStringContainsString('includeSubDomains',
            (string) $main->headers->get('Strict-Transport-Security'));
    }

    /**
     * 🔴 نقطهٔ پایانیِ میزبان نباید هیچ‌کدام از دو محافظ را دور بزند.
     *
     * `servernet.ir.` میزبانِ کاملاً معتبری است و `getHost()` نقطه را نگه
     * می‌دارد. با مقایسهٔ خام، هم کلِ سایت روی .ir سرو می‌شد و هم HSTSِ
     * `includeSubDomains` می‌رفت — یعنی همان فاجعهٔ برگشت‌ناپذیر.
     */
    public function test_a_trailing_dot_host_is_still_the_legacy_domain(): void
    {
        $this->call('GET', 'http://servernet.ir./blog')
            ->assertRedirect('https://servernet.cloud/blog');

        $res = $this->call('GET', 'https://servernet.ir./sms-relay.php');
        $this->assertStringNotContainsString('includeSubDomains',
            (string) $res->headers->get('Strict-Transport-Security'));
    }

    public function test_an_uppercase_host_is_still_the_legacy_domain(): void
    {
        $this->call('GET', 'http://ServerNet.IR/blog')
            ->assertRedirect('https://servernet.cloud/blog');
    }

    /**
     * 🔴 مسیرهای ماشین‌به‌ماشین نباید ۳۰۱ شوند.
     *
     * یک ۳۰۱ِ بین‌دامنه‌ای باعث می‌شود curl و بیشترِ کلاینت‌های HTTP هدرِ
     * `Authorization` را دور بریزند، پس تماسِ توکن‌دار بی‌صدا ۴۰۱ می‌گیرد. این
     * میدل‌ور **زودتر** از `ConsoleHost` اجرا می‌شود، پس اگر همان استثناها را
     * تکرار نکند، بی‌اثرشان می‌کند.
     */
    public function test_machine_paths_are_never_redirected(): void
    {
        foreach (['/api/v1/me', '/system/health', '/up', '/bale/webhook/x', '/payment/callback'] as $p) {
            $res = $this->on('servernet.ir', $p);
            $this->assertNotSame(301, $res->getStatusCode(), "$p نباید ۳۰۱ شود");
        }
    }

    /** فهرستِ `never` نباید به شکلِ نوشتن حساس باشد */
    public function test_the_never_list_is_not_form_sensitive(): void
    {
        foreach (['/SMS-relay.php', '/./sms-relay.php', '//sms-relay.php', '/sms-relay%2Ephp'] as $p) {
            $res = $this->on('servernet.ir', $p);
            $this->assertNotSame(301, $res->getStatusCode(), "$p نباید ۳۰۱ شود");
        }
    }

    /**
     * 🔴 نگاشتِ بی‌اسلش باید همه را روی یک صفحه جمع کند، نه دُم بچسبانَد.
     *
     * `'/category/' => '/blog'` عمداً یعنی «همه به فهرستِ بلاگ». اگر کد بی‌قید
     * دُم را بچسبانَد، `/blog/{term}` می‌سازد که مسیرِ «یک نوشته» است ⇒ ۴۰۴.
     */
    public function test_a_prefix_without_a_trailing_slash_collapses(): void
    {
        config(['legacy.prefix' => ['/category/' => '/blog']]);

        $this->on('servernet.ir', '/category/hosting')
            ->assertRedirect('https://servernet.cloud/blog');
    }

    /** و با اسلش، دُم حفظ می‌شود */
    public function test_a_prefix_with_a_trailing_slash_keeps_the_tail(): void
    {
        config(['legacy.prefix' => ['/weblog/' => '/blog/']]);

        $this->on('servernet.ir', '/weblog/my-post')
            ->assertRedirect('https://servernet.cloud/blog/my-post');
    }

    /**
     * ⚠️ نگاشتِ پیش‌فرض عمداً **خالی** است.
     *
     * نگاشتِ حدسی بدتر از نبودِ نگاشت است: مقصدِ اشتباه هم اعتبار را منتقل
     * نمی‌کند و هم سیگنالِ ۴۰۴ی را که باید نقشه از رویش ساخته شود پاک می‌کند.
     */
    public function test_no_guessed_mappings_ship_by_default(): void
    {
        $this->assertSame([], config('legacy.prefix'),
            'نگاشتِ پیشوندی باید از دادهٔ واقعیِ /admin/errors پر شود، نه از حدس');
    }
}
