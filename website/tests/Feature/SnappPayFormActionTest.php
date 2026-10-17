<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔴 اولین پرداختِ واقعیِ اسنپ‌پی `redirected` شد ولی کاربر «هیچ اتفاقی» ندید:
 * کروم form-action را روی ۳۰۲ ِ بعد از submit هم اعمال می‌کند و صفحهٔ
 * پرداختِ اسنپ‌پی در فهرست نبود. همان تلهٔ زرین‌پال.
 */
class SnappPayFormActionTest extends TestCase
{
    use RefreshDatabase;

    private function formAction(): string
    {
        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');
        preg_match('/form-action [^;]+/', $csp, $m);

        return $m[0] ?? '';
    }

    public function test_an_enabled_gateway_allows_its_payment_page(): void
    {
        config([
            'snapppay.enabled'    => true,
            'snapppay.base_url'   => 'https://fms-gateway-staging.apps.public.teh-1.snappcloud.io',
            'snapppay.page_hosts' => ['https://*.snapppay.ir'],
        ]);

        $fa = $this->formAction();

        $this->assertStringContainsString('https://*.snapppay.ir', $fa);
        $this->assertStringContainsString('https://fms-gateway-staging.apps.public.teh-1.snappcloud.io', $fa);
        $this->assertStringContainsString('zarinpal.com', $fa, 'زرین‌پال سرِ جایش می‌مانَد');
    }

    public function test_a_disabled_gateway_widens_nothing(): void
    {
        config(['snapppay.enabled' => false, 'snapppay.base_url' => 'https://api.snapppay.test']);

        $this->assertStringNotContainsString('snapppay', $this->formAction());
    }

    /** یک خطِ بدِ .env نباید بتواند دستورِ CSP ِ تازه تزریق کند یا همه‌جا را باز کند. */
    public function test_a_malformed_host_is_dropped(): void
    {
        config([
            'snapppay.enabled'    => true,
            'snapppay.base_url'   => '',
            'snapppay.page_hosts' => ['https://ok.snapppay.ir', "https://x.ir; script-src *", '*', 'http://plain.ir'],
        ]);

        $fa = $this->formAction();

        $this->assertStringContainsString('https://ok.snapppay.ir', $fa);
        $this->assertStringNotContainsString('script-src *', $fa);
        $this->assertStringNotContainsString('plain.ir', $fa);
        $this->assertDoesNotMatchRegularExpression('/\s\*(\s|$)/', $fa);
    }
}
