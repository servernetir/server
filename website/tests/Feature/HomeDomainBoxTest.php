<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * جعبهٔ جستجوی دامنهٔ **صفحهٔ اول** باید از رسیلریِ خودمان بپرسد.
 *
 * 🔴 تا امروز روی WHMCSِ بیرونی بود: قیمت از `GetTLDPricing` و دکمهٔ خرید به
 * `cart.php`. یعنی پرکاربردترین ورودیِ فروشِ دامنه مشتری را از سامانهٔ ما بیرون
 * می‌بُرد، و قیمتی نشان می‌داد که با صفحهٔ `/domains` نمی‌خوانْد — دو قیمت برای
 * یک دامنه در یک سایت.
 */
class HomeDomainBoxTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠️ `Http::swap(new Factory)` و نه `Http::fake()`ِ دوم.
     *
     * استاب‌ها به ترتیبِ ثبت سنجیده می‌شوند و **اولین تطبیق برنده است**؛ یک
     * استابِ `'*'`ِ همه‌گیر در فیکسچرِ دیگری، هر fakeِ بعدی را بی‌اثر می‌کند و
     * تست بی‌صدا هیچ‌چیز نمی‌سنجد.
     */
    private function fakeRegistrar(array $rows): void
    {
        Http::swap(new Factory);

        Http::fake([
            '*/auth/login' => Http::response(['code' => 0, 'data' => ['token' => 'T']], 200),
            '*/domains/check*' => Http::response(['code' => 0, 'data' => ['results' => $rows]], 200),
            '*' => Http::response(['code' => 0, 'data' => []], 200),
        ]);

        config([
            'services.openprovider.username' => 'u',
            'services.openprovider.password' => 'p',
            'services.openprovider.base_url' => 'https://api.example.test/v1beta',
        ]);

        /*
        | ⚠️ نرخِ یورو حتماً ست می‌شود.
        |
        | بی‌آن، `DomainSearch` عمداً `price_toman = 0` می‌دهد (نرخِ نامعلوم ⇒
        | قیمتِ حدسی نزن) و برچسبِ قیمت `null` می‌شود. آن رفتار **درست** است، پس
        | تست باید نرخ بدهد نه اینکه ادعا را ضعیف کند — وگرنه تستی داشتیم که
        | «قیمت نشان داده می‌شود» را هرگز واقعاً نمی‌سنجید.
        */
        \App\Models\Setting::put('pricing_rate_override', '100000');
    }

    private function check(string $domain): array
    {
        return $this->postJson(route('domain.check'), ['domain' => $domain])->json();
    }

    // ═══════════════ منبعِ استعلام ═══════════════

    /** 🔴 هیچ تماسی با WHMCS نباید بماند */
    public function test_it_no_longer_calls_whmcs(): void
    {
        $this->fakeRegistrar([
            ['domain' => 'example.com', 'status' => 'free', 'price' => ['reseller' => ['price' => 10.0, 'currency' => 'EUR']]],
        ]);

        $this->check('example.com');

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'includes/api.php')
            || str_contains(strtolower($r->url()), 'whmcs'));
    }

    /** استعلام باید واقعاً به رسیلری برود */
    public function test_it_asks_the_registrar(): void
    {
        $this->fakeRegistrar([
            ['domain' => 'example.com', 'status' => 'free', 'price' => ['reseller' => ['price' => 10.0, 'currency' => 'EUR']]],
        ]);

        $this->check('example.com');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/domains/check'));
    }

    // ═══════════════ شکلِ پاسخ (قراردادِ جاوااسکریپت) ═══════════════

    /**
     * ⚠️ `site.js` دقیقاً `result` / `suggestions` / `more_url` را می‌خوانَد.
     * شکستنِ این قرارداد یعنی جعبهٔ صفحهٔ اول بی‌صدا می‌میرد، با کدِ ۲۰۰.
     */
    public function test_the_json_contract_the_homepage_script_reads_is_intact(): void
    {
        $this->fakeRegistrar([
            ['domain' => 'example.com', 'status' => 'free', 'price' => ['reseller' => ['price' => 10.0, 'currency' => 'EUR']]],
        ]);

        $json = $this->check('example.com');

        $this->assertArrayHasKey('result', $json);
        $this->assertArrayHasKey('suggestions', $json);
        $this->assertArrayHasKey('more_url', $json);

        foreach (['domain', 'available', 'price', 'cart_url'] as $k) {
            $this->assertArrayHasKey($k, $json['result'], "کلیدِ result.$k که جاوااسکریپت می‌خواند نیست");
        }
    }

    /** 🔴 دکمهٔ خرید باید به کنسولِ خودمان برود، نه سبدِ WHMCS */
    public function test_the_buy_button_stays_inside_our_own_console(): void
    {
        $this->fakeRegistrar([
            ['domain' => 'example.com', 'status' => 'free', 'price' => ['reseller' => ['price' => 10.0, 'currency' => 'EUR']]],
        ]);

        $json = $this->check('example.com');

        $this->assertStringContainsString('/domains', $json['result']['cart_url']);
        $this->assertStringNotContainsString('cart.php', $json['result']['cart_url']);
        $this->assertStringNotContainsString('domainchecker.php', $json['more_url']);
    }

    // ═══════════════ درستیِ نتیجه ═══════════════

    public function test_a_free_domain_is_reported_free_with_a_price(): void
    {
        $this->fakeRegistrar([
            ['domain' => 'example.com', 'status' => 'free', 'price' => ['reseller' => ['price' => 10.0, 'currency' => 'EUR']]],
        ]);

        $json = $this->check('example.com');

        $this->assertTrue($json['result']['available']);
        $this->assertNotNull($json['result']['price']);
    }

    public function test_a_taken_domain_gets_free_alternatives(): void
    {
        $this->fakeRegistrar([
            ['domain' => 'example.com', 'status' => 'active'],
            ['domain' => 'example.net', 'status' => 'free', 'price' => ['reseller' => ['price' => 9.0, 'currency' => 'EUR']]],
            ['domain' => 'example.org', 'status' => 'free', 'price' => ['reseller' => ['price' => 8.0, 'currency' => 'EUR']]],
        ]);

        $json = $this->check('example.com');

        $this->assertFalse($json['result']['available']);
        $this->assertNotEmpty($json['suggestions']);

        foreach ($json['suggestions'] as $s) {
            $this->assertStringContainsString('/domains', $s['cart_url']);
        }
    }

    /**
     * 🔴 رسیلری که جواب ندهد، نباید به «آزاد است» تعبیر شود.
     *
     * نسخهٔ قبلی وقتی WHMCS در دسترس نبود به DNS برمی‌گشت، و DNS «رکورد ندارد»
     * را با «ثبت‌نشده» یکی می‌گیرد — یعنی به مشتری می‌گفتیم دامنه آزاد است و
     * سرِ پرداخت رجیسترار ردش می‌کرد.
     */
    public function test_a_silent_registrar_never_claims_the_domain_is_free(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([], 500)]);

        $json = $this->check('example.com');

        $this->assertFalse($json['result']['available']);
        $this->assertNull($json['result']['price']);
    }

    // ═══════════════ قیمت از کش ═══════════════

    /**
     * 🔴 قیمتِ پیشنهادها از دفترچهٔ کش‌شده می‌آید، نه از پاسخِ زنده.
     *
     * دفترچه با یک نامِ **بلندِ قطعاً آزاد** (`sn7price9check4base`) هر ۶ ساعت
     * قیمتِ پایه را می‌گیرد. این‌جا عمداً به آن نام قیمتی متفاوت می‌دهیم تا
     * ثابت شود عددی که روی پیشنهاد می‌نشیند واقعاً از دفترچه آمده.
     */
    public function test_suggestion_prices_come_from_the_cached_price_book(): void
    {
        Http::swap(new Factory);
        Http::fake([
            '*/auth/login' => Http::response(['code' => 0, 'data' => ['token' => 'T']], 200),
            '*/domains/check*' => Http::sequence()
                // ۱) استعلامِ خودِ کاربر — قیمتِ زنده عمداً گران
                ->push(['code' => 0, 'data' => ['results' => [
                    ['domain' => 'example.com', 'status' => 'active'],
                    ['domain' => 'example.net', 'status' => 'free',
                        'price' => ['reseller' => ['price' => 99.0, 'currency' => 'EUR']]],
                ]]], 200)
                // ۲) کاوشِ دفترچه با نامِ بلند — قیمتِ پایهٔ ارزان
                ->push(['code' => 0, 'data' => ['results' => [
                    ['domain' => 'sn7price9check4base.net', 'status' => 'free',
                        'price' => ['reseller' => ['price' => 5.0, 'currency' => 'EUR']]],
                ]]], 200),
            '*' => Http::response(['code' => 0, 'data' => []], 200),
        ]);

        config([
            'services.openprovider.username' => 'u',
            'services.openprovider.password' => 'p',
            'services.openprovider.base_url' => 'https://api.example.test/v1beta',
        ]);
        \App\Models\Setting::put('pricing_rate_override', '100000');

        $json = $this->check('example.com');
        $net = collect($json['suggestions'])->firstWhere('domain', 'example.net');

        $this->assertNotNull($net);

        // ⚠️ جداکنندهٔ هزارگان همان کامای اسکی است: `cloud_price` از
        //    `number_format` استفاده می‌کند و `fa_num` فقط **رقم‌ها** را
        //    فارسی می‌کند، نه نشانه‌ها.
        $this->assertStringContainsString('۵۰۰,۰۰۰', $net['price'],
            'قیمتِ پیشنهاد از پاسخِ زنده (۹۹ یورو) آمده، نه از دفترچهٔ کش‌شده (۵ یورو)');
    }

    /** ⚠️ رجیسترارِ خواب در لحظهٔ تازه‌سازیِ کش نباید جعبهٔ صفحهٔ اول را بشکند */
    public function test_a_failing_price_book_does_not_break_the_box(): void
    {
        $this->fakeRegistrar([
            ['domain' => 'example.com', 'status' => 'free',
                'price' => ['reseller' => ['price' => 10.0, 'currency' => 'EUR']]],
        ]);

        $json = $this->check('example.com');

        $this->assertTrue($json['result']['available']);
        $this->assertNotNull($json['result']['price']);
    }

    /** فرمانِ گرم‌کننده باید زمان‌بندی شده باشد، وگرنه کش تنبل می‌مانَد */
    public function test_the_price_book_refresh_is_scheduled(): void
    {
        $commands = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($e) => (string) $e->command);

        $this->assertTrue($commands->contains(fn ($c) => str_contains($c, 'domains:price-book')),
            'domains:price-book زمان‌بندی نشده — بازدیدکننده هزینهٔ استعلام را می‌دهد');
    }

    /** ورودیِ بی‌معنا باید ۴۲۲ بگیرد، نه استعلامِ بیهوده به رجیسترار */
    public function test_a_junk_query_is_rejected_without_calling_the_registrar(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([], 200)]);

        $this->postJson(route('domain.check'), ['domain' => '...'])->assertStatus(422);

        Http::assertNothingSent();
    }
}
