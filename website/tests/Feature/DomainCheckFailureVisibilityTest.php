<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\DomainQuote;
use App\Services\Domain\DomainSearch;
use App\Services\Domain\OpenProviderClient;
use Database\Seeders\BillingFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «نتوانستیم استعلام کنیم» هرگز نباید «این دامنه ثبت شده است» خوانده شود.
 *
 * ═══ باگی که این فایل قفلش می‌کند ═══
 *
 * `OpenProviderClient::check()` تنها متدِ نتیجه‌دارِ آن کلاس بود که پاکتِ
 * `{ok, code, message}` نمی‌داد؛ فقط `data.results` را برمی‌داشت و `code` را
 * اصلاً نمی‌خواند. پس خطای ۱۹۶، بدنهٔ غیرِ JSON، قطعیِ شبکه و «توکن نداریم»
 * همگی به یک آرایهٔ خالیِ یکسان می‌رسیدند و `DomainSearch` آن را «هیچ‌کدام آزاد
 * نیست» می‌خواند. نتیجه روی صفحهٔ مشتری: هر ۶۴ پیشنهاد «ثبت‌شده».
 *
 * ═══ قواعدِ خودِ تست ═══
 *
 * 🔴 هیچ تماسِ واقعی با رجیسترار زده نمی‌شود. حسابِ ما نزدِ اوپن‌پروایدر یک بار
 *    به‌خاطرِ تماسِ زیاد علامت خورده؛ هر تست با `Http::swap` شروع و با یک
 *    `Http::fake()` **تنها** ادامه می‌یابد (استابِ دوم روی استابِ اول سایه
 *    می‌اندازد و تست بی‌صدا هیچ‌چیز نمی‌سنجد).
 *
 * 🔴 پاسخِ ساختگی باید **شکلِ واقعیِ اوپن‌پروایدر** باشد، نه شکلی که پارسرِ ما
 *    دوست دارد: `domain` رشتهٔ صاف، هر دو شاخهٔ `price.product` و
 *    `price.reseller`، `desc` و `warnings`، و ردیفِ `status: "error"` برای
 *    پسوندی که رسیلری نمی‌فروشد.
 */
class DomainCheckFailureVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BillingFoundationSeeder::class);

        config([
            'services.openprovider.username' => 'u',
            'services.openprovider.password' => 'p',
            'services.openprovider.margin'   => ['default' => 25],
        ]);

        Cache::put('fx.usd_irt', [
            'currency' => 'USD', 'rate_toman' => 100000,
            'source' => 'test', 'at' => now()->toIso8601String(),
        ], now()->addHour());
    }

    /**
     * یک `Http::fake()` تنها، بعد از تعویضِ کارخانه — تا استابِ همه‌گیرِ
     * جای دیگری نتواند این را بی‌اثر کند.
     */
    private function fakeCheck(mixed $checkResponse): void
    {
        Http::swap(new Factory);
        Http::fake([
            '*/auth/login*'    => Http::response(['code' => 0, 'data' => ['token' => 'tok'], 'desc' => ''], 200),
            '*/domains/check*' => $checkResponse,
        ]);
    }

    /** پاسخِ واقعیِ موفق — همان شکلی که v1beta می‌دهد. */
    private function realisticSuccess(array $results): array
    {
        return ['code' => 0, 'desc' => '', 'warnings' => [], 'data' => ['results' => $results]];
    }

    private function customer(): Customer
    {
        $c = Customer::create([
            'email' => 'd'.random_int(1000, 99999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ]);

        CustomerProfile::create([
            'customer_id' => $c->id, 'type' => 'individual', 'is_default' => true,
            'status' => 'verified', 'email' => $c->email, 'mobile' => '09123456789',
            'country' => 'IR', 'city' => 'تهران', 'address' => 'خیابان نمونه',
            'postal_code' => '1234567890', 'first_name' => 'ا', 'last_name' => 'ا',
        ]);

        return $c;
    }

    // ═══════════════════════ ۱) پاکتِ خطا در کلاینت ═══════════════════════

    /**
     * ریشهٔ باگ: `check()` باید کدِ رجیسترار را برگرداند، نه آرایهٔ خالی.
     *
     * ⚠️ این API روی خطا HTTP 500 می‌دهد؛ کدِ واقعی در بدنه است. پس شرطِ
     * درست هرگز به وضعیتِ HTTP نگاه نمی‌کند.
     */
    public function test_check_reports_the_registrar_code_instead_of_a_bare_empty_array(): void
    {
        $this->fakeCheck(Http::response(
            ['code' => 196, 'desc' => 'Authentication/Authorization Failed', 'data' => null],
            500
        ));

        $res = app(OpenProviderClient::class)->check([['name' => 'zhina', 'extension' => 'shop']]);

        $this->assertIsArray($res);
        $this->assertArrayHasKey('ok', $res, 'check() باید پاکتِ یکنواخت بدهد، نه فهرستِ خام');
        $this->assertFalse($res['ok']);
        $this->assertSame(196, $res['code'], 'کدِ واقعیِ رجیسترار باید به فراخوان برسد');
        $this->assertSame([], $res['results']);
        $this->assertStringContainsString('Authentication', $res['message']);
    }

    public function test_a_successful_check_reports_ok_with_the_rows(): void
    {
        $this->fakeCheck(Http::response($this->realisticSuccess([
            ['domain' => 'zhina.com', 'status' => 'free', 'is_premium' => false,
             'price' => ['product' => ['price' => 12.0, 'currency' => 'USD'],
                         'reseller' => ['price' => 8.0, 'currency' => 'USD']]],
        ]), 200));

        $res = app(OpenProviderClient::class)->check([['name' => 'zhina', 'extension' => 'com']]);

        $this->assertTrue($res['ok']);
        $this->assertSame(0, $res['code']);
        $this->assertCount(1, $res['results']);
        $this->assertSame('zhina.com', $res['results'][0]['domain']);
    }

    // ═══════════════════════ ۲) استعلامِ شکست‌خورده ═══════════════════════

    /**
     * 🔴 تستِ اصلی. خطای احراز هویت = هیچ ردیفی «ثبت‌شده» نیست.
     */
    public function test_a_registrar_error_marks_every_row_unknown_never_taken(): void
    {
        $this->fakeCheck(Http::response(
            ['code' => 196, 'desc' => 'Authentication/Authorization Failed', 'data' => null],
            500
        ));

        $out = app(DomainSearch::class)->search('zhina.shop', ['shop', 'com', 'net']);

        $this->assertCount(3, $out);

        foreach ($out as $r) {
            $this->assertSame('unknown', $r['status'], $r['domain'].' نباید «گرفته‌شده» شود');
            $this->assertSame('lookup_failed', $r['reason']);
            $this->assertFalse($r['available'], 'استعلامِ نشده هرگز «آزاد» هم نیست');
            $this->assertFalse($r['orderable']);
            $this->assertNull($r['price_toman']);
            $this->assertNull($r['quote_id']);
        }

        // هیچ قیمتی ساخته نشده باشد — استعلامِ نشده نباید فاکتور بسازد
        $this->assertSame(0, DomainQuote::count());
    }

    public function test_a_transport_failure_marks_every_row_unknown(): void
    {
        $this->fakeCheck(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $out = app(DomainSearch::class)->search('zhina.shop', ['shop', 'com']);

        $this->assertCount(2, $out);
        $this->assertSame(['unknown', 'unknown'], array_column($out, 'status'));
        $this->assertSame(['lookup_failed', 'lookup_failed'], array_column($out, 'reason'));
    }

    public function test_a_non_json_body_marks_every_row_unknown(): void
    {
        // گیت‌وی یک صفحهٔ HTML می‌دهد — نه JSON، نه code
        $this->fakeCheck(Http::response('<html><body>502 Bad Gateway</body></html>', 502));

        $out = app(DomainSearch::class)->search('zhina.shop', ['shop']);

        $this->assertSame('unknown', $out[0]['status']);
        $this->assertSame('lookup_failed', $out[0]['reason']);
    }

    /**
     * پاسخی که `code: 0` است ولی اصلاً `data.results` ندارد هم یک خرابی است،
     * نه «هیچ‌کدام آزاد نیست».
     */
    public function test_rows_the_registrar_never_answered_are_unknown_not_taken(): void
    {
        // پاسخِ ناقصِ واقعی: ۳ پسوند پرسیدیم، فقط یکی جواب آمد
        $this->fakeCheck(Http::response($this->realisticSuccess([
            ['domain' => 'zhina.com', 'status' => 'free', 'is_premium' => false,
             'price' => ['product' => ['price' => 12.0, 'currency' => 'USD'],
                         'reseller' => ['price' => 8.0, 'currency' => 'USD']]],
        ]), 200));

        $out = app(DomainSearch::class)->search('zhina', ['com', 'net', 'org']);
        $byDomain = collect($out)->keyBy('domain');

        $this->assertTrue($byDomain['zhina.com']['available']);
        $this->assertSame(1_000_000, $byDomain['zhina.com']['price_toman']);

        foreach (['zhina.net', 'zhina.org'] as $missing) {
            $this->assertSame('unknown', $byDomain[$missing]['status'], "$missing بی‌جواب مانده، نه گرفته‌شده");
            $this->assertSame('no_response', $byDomain[$missing]['reason']);
            $this->assertFalse($byDomain[$missing]['available']);
        }
    }

    // ═══════════════════════ ۳) خطای سطرِ تکی ═══════════════════════

    /**
     * 🔴 شکلِ واقعیِ اوپن‌پروایدر برای پسوندی که رسیلری نمی‌فروشد:
     * `{"domain":"…","status":"error","reason":"…"}`
     *
     * قبلاً هر چیزی جز `free` یعنی «گرفته‌شده» — یعنی همین ردیف به مشتری
     * می‌گفت «این نام قبلاً ثبت شده»، در حالی که اصلاً بررسی نشده بود.
     */
    public function test_a_per_row_error_status_is_not_reported_as_registered(): void
    {
        $this->fakeCheck(Http::response($this->realisticSuccess([
            ['domain' => 'zhina.com', 'status' => 'free', 'is_premium' => false,
             'price' => ['product' => ['price' => 12.0, 'currency' => 'USD'],
                         'reseller' => ['price' => 8.0, 'currency' => 'USD']]],
            ['domain' => 'zhina.shop', 'status' => 'active'],
            ['domain' => 'zhina.tr', 'status' => 'error',
             'reason' => 'Extension is not available for this reseller'],
        ]), 200));

        $out = collect(app(DomainSearch::class)->search('zhina', ['com', 'shop', 'tr']))->keyBy('domain');

        // آزاد
        $this->assertTrue($out['zhina.com']['available']);
        $this->assertTrue($out['zhina.com']['orderable']);

        // واقعاً گرفته‌شده — این باید همان «ثبت‌شده» بماند
        $this->assertSame('unavailable', $out['zhina.shop']['status']);
        $this->assertSame('active', $out['zhina.shop']['reason']);

        // بررسی‌نشده — نه آزاد، نه گرفته‌شده
        $this->assertSame('unknown', $out['zhina.tr']['status'], 'ردیفِ error نباید «ثبت‌شده» شود');
        $this->assertSame('check_error', $out['zhina.tr']['reason']);
        $this->assertFalse($out['zhina.tr']['available']);
        $this->assertFalse($out['zhina.tr']['orderable']);
    }

    public function test_an_empty_status_string_is_unknown_not_taken(): void
    {
        $this->fakeCheck(Http::response($this->realisticSuccess([
            ['domain' => 'zhina.com'],
        ]), 200));

        $r = app(DomainSearch::class)->search('zhina.com', ['com'])[0];

        $this->assertSame('unknown', $r['status']);
        $this->assertSame('no_status', $r['reason']);
        $this->assertFalse($r['available']);
    }

    /**
     * محافظ در جهتِ مخالف: اصلاحِ بالا نباید دامنهٔ واقعاً گرفته‌شده را
     * «استعلام نشد» کند — وگرنه فروشگاه پُر می‌شود از ردیفِ مبهم.
     */
    public function test_a_genuinely_registered_domain_still_reads_as_registered(): void
    {
        $this->fakeCheck(Http::response($this->realisticSuccess([
            ['domain' => 'google.com', 'status' => 'active'],
        ]), 200));

        $r = app(DomainSearch::class)->search('google.com', ['com'])[0];

        $this->assertSame('unavailable', $r['status']);
        $this->assertFalse($r['available']);
        $this->assertSame('active', $r['reason']);
    }

    // ═══════════════════════ ۴) شکلِ کلیدِ دامنه ═══════════════════════

    /**
     * `/domains/check` نامِ دامنه را رشته‌ای می‌دهد ولی `/domains` شیء
     * `{name, extension}`. اگر روزی این یکی هم شیء بدهد، شکلِ قبلی یک
     * «Array to string conversion» پرتاب می‌کرد، کنترلر می‌بلعیدش و صفحه
     * «نتیجه‌ای پیدا نشد» می‌شد — یعنی یک تغییرِ کوچکِ رجیسترار، کلِ فروشگاهِ
     * دامنه را خاموش می‌کرد.
     */
    public function test_an_object_shaped_domain_key_still_maps_instead_of_throwing(): void
    {
        $this->fakeCheck(Http::response($this->realisticSuccess([
            ['domain' => ['name' => 'zhina', 'extension' => 'com'], 'status' => 'free',
             'price' => ['reseller' => ['price' => 8.0, 'currency' => 'USD']]],
        ]), 200));

        $r = app(DomainSearch::class)->search('zhina.com', ['com'])[0];

        $this->assertTrue($r['available'], 'شکلِ شیءِ دامنه نباید کلِ استعلام را از بین ببرد');
        $this->assertSame(1_000_000, $r['price_toman']);
    }

    // ═══════════════════════ ۵) ترافیکِ رجیسترار ═══════════════════════

    /**
     * 🔴 حسابِ ما یک بار به‌خاطرِ «تماسِ زیاد» علامت خورده.
     *
     * چون این API خطای منطقی را هم HTTP 500 می‌دهد، `retry(2)`ِ بی‌شرط یعنی
     * هر «۱۹۶» سه بار فرستاده می‌شد. با احتسابِ ورودِ دوبارهٔ `call()`، یک
     * جستجوی ساده شش استعلام + چند تلاشِ ورود می‌ساخت. حالا فقط خرابیِ
     * حمل‌ونقل تکرار می‌شود.
     */
    public function test_a_business_error_is_not_retried_against_the_registrar(): void
    {
        $this->fakeCheck(Http::response(
            ['code' => 196, 'desc' => 'Authentication/Authorization Failed', 'data' => null],
            500
        ));

        app(DomainSearch::class)->search('zhina.shop', ['shop']);

        $checks = 0;
        Http::assertSent(function ($r) use (&$checks) {
            if (str_contains($r->url(), '/domains/check')) {
                $checks++;
            }

            return true;
        });

        // یک استعلام + یک تلاشِ دوباره پس از گرفتنِ توکنِ تازه (منطقِ ۱۹۶ در call())
        $this->assertSame(2, $checks, 'پاسخِ ۵۰۰ِ منطقی نباید دوباره فرستاده شود');
    }

    // ═══════════════════════ ۶) لبهٔ کاربر ═══════════════════════

    /**
     * دادهٔ صفحهٔ پنل — نه پیل‌های HTML، چون ویوی پنل جای دیگری اصلاح می‌شود.
     * چیزی که این‌جا قفل می‌شود: کنترلر هرگز ردیفی تحویلِ ویو نمی‌دهد که
     * «گرفته‌شده» ادعا کند در حالی که استعلام شکست خورده.
     */
    public function test_the_panel_never_hands_the_view_a_taken_row_after_a_failed_lookup(): void
    {
        $this->fakeCheck(Http::response(['code' => 196, 'desc' => 'Authentication/Authorization Failed'], 500));

        $results = $this->actingAs($this->customer(), 'customer')
            ->get(route('account.domains', ['register' => 'zhina.shop']))
            ->assertOk()
            ->viewData('results');

        $this->assertNotEmpty($results);

        foreach ($results as $r) {
            $this->assertSame('unknown', $r['status']);
            $this->assertSame('lookup_failed', $r['reason']);
            $this->assertNotSame('unavailable', $r['status']);
        }
    }

    /*
    | 🔴 آخرین متر — چیزی که مشتری واقعاً *می‌بیند*.
    |
    | تست بالا فقط داده را می‌سنجد (`viewData`). ولی کلِ این باگ از جایی آمد که
    | داده و نمایش دو چیز بودند: لایهٔ سرویس می‌توانست درست بگوید «نمی‌دانم» و
    | ویو همچنان «ثبت‌شده» چاپ کند، چون فقط روی `available` شاخه می‌زد. پس
    | این‌جا روی خودِ HTML ادعا می‌کنیم.
    */
    public function test_a_failed_lookup_is_never_printed_as_registered(): void
    {
        $this->fakeCheck(Http::response(['code' => 196, 'desc' => 'Authentication/Authorization Failed'], 500));

        $html = $this->actingAs($this->customer(), 'customer')
            ->get(route('account.domains', ['register' => 'zhina.shop']))
            ->assertOk()
            ->getContent();

        /*
        | ⚠️ روی خودِ نشانه‌گذاریِ قرص ادعا می‌کنیم، نه روی واژه.
        | واژهٔ «ثبت‌شده» به‌طور مشروع جای دیگری از همین صفحه هم هست
        | (`ui.dsr_taken_pill` در رشته‌های JSِ جستجوی عمومی)، پس ادعای
        | گشادِ «این واژه نباشد» به‌دلیلِ غلط قرمز می‌شد.
        */
        $this->assertStringNotContainsString('pnl-pill mute">ثبت‌شده', $html);
        $this->assertStringContainsString('pnl-pill warn">استعلام نشد', $html);

        // و صفحه باید صریح بگوید چرا، نه اینکه دیوارِ خاکستری تحویل بدهد.
        $this->assertStringContainsString(__('ui.dsr_lookup_failed'), $html);

        /*
        | 🔴 گزارشِ خودِ کارفرما: «از پنل استعلام می‌کنم، می‌گوید در دسترس
        | نیست.» او یک دامنه نمی‌دید — بنرِ خرابیِ استعلام را می‌دید، که متنش
        | «استعلام دامنه در این لحظه **در دسترس نیست**» بود. در واژگانِ دامنه
        | «در دسترس نیست» یعنی «این نام گرفته شده»، پس یک قطعیِ رجیسترار عیناً
        | شبیهِ یک جوابِ محصولی خوانده می‌شد.
        |
        | ⚠️ این ادعا عمداً روی **عبارت** است نه روی کلید: اگر روزی کسی متنِ
        | تازه را دوباره به همان الگو برگردانَد، همین‌جا قرمز می‌شود.
        */
        $this->assertStringNotContainsString('در دسترس نیست', $html,
            'عبارتِ «در دسترس نیست» در واژگانِ دامنه یعنی «گرفته‌شده» — '
            .'برای خرابیِ استعلام به کار نرود');

        // ⚠️ هیچ فرمِ سفارشی نباید رندر شود — چیزی که استعلام نشده فروختنی نیست.
        $this->assertStringNotContainsString('name="quote_id"', $html);
    }

    /** بازوی مقابل: دامنهٔ واقعاً گرفته‌شده باید همچنان «ثبت‌شده» بخورد. */
    public function test_a_genuinely_taken_domain_is_still_printed_as_registered(): void
    {
        $this->fakeCheck(Http::response([
            'code' => 0,
            'data' => ['results' => [['domain' => 'zhina.shop', 'status' => 'active']]],
        ], 200));

        $html = $this->actingAs($this->customer(), 'customer')
            ->get(route('account.domains', ['register' => 'zhina.shop']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('pnl-pill mute">ثبت‌شده', $html);

        /*
        | ⚠️ ردیف‌های دیگر عمداً «استعلام نشد» می‌خورند و این درست است: رجیسترار
        | فقط به همین یک دامنه پاسخ داد و بقیهٔ TLDها بی‌جواب ماندند. پس این
        | تست در واقع حالتِ **مخلوط** را می‌سنجد — که سخت‌ترین حالت است.
        |
        | چیزی که نباید باشد، بنرِ سطحِ صفحه است: وقتی دستِ‌کم یک پاسخِ واقعی
        | داریم، خرابیِ جزئی نباید مثل قطعیِ کامل داد زده شود.
        */
        $this->assertStringNotContainsString('استعلام دامنه در این لحظه در دسترس نیست', $html);
    }

    /** صفحهٔ عمومی هم همان داده را می‌گیرد — «استعلام نشد» جدا از «گرفته‌شده». */
    public function test_the_public_search_endpoint_reports_unknown_on_a_failed_lookup(): void
    {
        $this->fakeCheck(Http::response(['code' => 196, 'desc' => 'Authentication/Authorization Failed'], 500));

        $json = $this->postJson(route('domain.search.check'), ['q' => 'zhina', 'tlds' => ['com', 'net']])
            ->assertOk()
            ->json('results');

        $this->assertCount(2, $json);

        foreach ($json as $r) {
            $this->assertSame('unknown', $r['status']);
            // ⚠️ `reason` هم سنجیده می‌شود: بدونِ آن، همین ادعا با کدِ معیوبِ
            // قبلی هم سبز می‌شد (آن هم 'unknown' می‌داد، فقط با 'no_response')
            // و تست هیچ‌چیز تشخیص نمی‌داد.
            $this->assertSame('lookup_failed', $r['reason']);
            $this->assertFalse($r['available']);
            $this->assertFalse($r['orderable']);
        }
    }
}
