<?php

namespace Tests\Feature;

use App\Http\Controllers\DomainCheckController;
use App\Services\Domain\TldPriceBook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * قیمتِ پسوندهای زیرِ جعبهٔ جست‌وجوی صفحهٔ اول باید **زنده** باشد.
 *
 * ═══ نقصی که این را لازم کرد ═══
 *
 * چیپ‌ها از WHMCS می‌خواندند — سامانهٔ قدیمی‌ای که داریم از آن مهاجرت
 * می‌کنیم. فروشِ واقعی از OpenProvider می‌آید، پس صفحهٔ اول قیمتی نشان می‌داد
 * که دیگر قیمتِ فروشِ ما نبود. و چون WHMCS پاسخ می‌داد، هیچ‌جا خطایی دیده
 * نمی‌شد: نه ۵۰۰، نه ردیف در ردیاب، فقط عددِ غلط.
 *
 * ⚠️ این تست بیش از «قیمت درست است» را می‌سنجد. سنگین‌ترین ادعایش این است که
 * رندرِ صفحهٔ اول **هیچ تماسی با رجیسترار نمی‌گیرد**.
 */
class HomeTldPricesAreLiveTest extends TestCase
{
    use RefreshDatabase;

    /** کشِ دفترچه را با قیمتِ دلخواه گرم می‌کند — همان کاری که کرون می‌کند. */
    private function warmBook(array $prices): void
    {
        $book = app(TldPriceBook::class);

        $ref = new \ReflectionMethod($book, 'cacheKey');
        $ref->setAccessible(true);

        $norm = new \ReflectionMethod($book, 'normalise');
        $norm->setAccessible(true);

        Cache::put($ref->invoke($book, $norm->invoke($book, DomainCheckController::SUGGEST)), $prices, 600);
    }

    private function chips(string $html): array
    {
        preg_match_all('~data-tld="([^"]+)"[^>]*><b>[^<]*</b><i>([^<]*)</i>~', $html, $m);

        return array_combine($m[1], array_map('trim', $m[2]));
    }

    /**
     * عددِ داخلِ چیپ، به تومان.
     *
     * ⚠️ مقایسهٔ رشته‌ای این‌جا شکننده است: `site_price()` قیمت را با نرخِ
     * زنده مقیاس می‌کند و به ۱۰٬۰۰۰ گرد می‌کند، پس ۱٬۷۷۷٬۰۰۰ روی صفحه
     * ۱٬۷۸۰٬۰۰۰ می‌شود. نسخهٔ اولِ این تست دقیقاً همین‌جا افتاد — و درست هم
     * افتاد: ادعای «عددِ عیناً برابر» چیزی است که هیچ‌وقت درست نبوده.
     */
    private function toman(string $chip): int
    {
        $en = strtr($chip, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);

        return (int) preg_replace('~\D~', '', $en);
    }

    /** 🔴 عددِ چیپ باید از دفترچهٔ قیمتِ رجیسترار بیاید. */
    public function test_the_chip_price_comes_from_the_price_book(): void
    {
        $this->warmBook(['com' => 1_777_000, 'net' => 2_555_000]);

        $chips = $this->chips($this->get('/')->assertOk()->getContent());

        $this->assertArrayHasKey('.com', $chips, 'چیپِ .com روی صفحهٔ اول نیست');

        /*
        | ⚠️ محدودهٔ ±۱٪، نه برابریِ دقیق: قیمت با نرخِ زنده مقیاس و به ۱۰٬۰۰۰
        |    گرد می‌شود. ادعای مهم این است که عدد از **دفترچه** آمده، نه از
        |    فهرستِ دستی — و آن با فاصله‌اش از ۱٬۲۹۰٬۰۰۰ِ config ثابت می‌شود.
        */
        $this->assertEqualsWithDelta(1_777_000, $this->toman($chips['.com']), 20_000,
            'قیمتِ .com از دفترچه نیامد');
        $this->assertEqualsWithDelta(2_555_000, $this->toman($chips['.net']), 30_000);

        $manual = collect(config('servernet.tlds'))->firstWhere('tld', '.com')['irt'] ?? 0;
        $this->assertNotEquals($manual, $this->toman($chips['.com']),
            'عدد هنوز همان قیمتِ دستیِ config است — دفترچه اصلاً خوانده نشد');
    }

    /**
     * 🔴 سنگین‌ترین ادعا: رندرِ صفحهٔ اول هیچ تماسی با رجیسترار نمی‌گیرد.
     *
     * `forTlds()` روی کشِ سرد استعلامِ زنده می‌زند. اگر روزی کسی
     * `cachedForTlds()` را با آن عوض کند، هر بازدیدکننده — و هر خزنده — به یک
     * تماسِ API تبدیل می‌شود. حسابِ ما یک بار به‌خاطرِ تماسِ زیاد از آی‌پیِ
     * ایران علامت خورده؛ این تست همان را غیرممکن می‌کند.
     */
    public function test_rendering_the_home_page_never_calls_the_registrar(): void
    {
        /*
        | 🔴 اعتبارنامه **لازم** است وگرنه این تست تو‌خالی می‌شود.
        |
        | بی‌آن، `DomainSearch` پیش از هر تماسی برمی‌گردد و تست حتی با
        | `forTlds()`ِ خطرناک هم سبز می‌مانَد — با تستِ جهش دقیقاً همین رخ داد.
        | با اعتبارنامه، مسیرِ استعلام واقعاً تا لبهٔ شبکه می‌رود و
        | `preventStrayRequests()` می‌تواند کارش را بکند.
        */
        config([
            'services.openprovider.username' => 'u',
            'services.openprovider.password' => 'p',
        ]);

        /*
        | ⚠️ `Http::fake()` + `assertNothingSent()`، نه `preventStrayRequests()`.
        |
        | 🔴 دومی استثنا پرتاب می‌کند و `TldPriceBook::quote()` هر استثنایی را
        | در یک `catch (\Throwable)` می‌بلعد و لاگ می‌کند. یعنی صفحه ۲۰۰
        | می‌مانَد، تست سبز می‌شود، و تماسِ زنده **واقعاً انجام شده**. نسخهٔ اولِ
        | همین تست دقیقاً همین بود و در آزمونِ جهش سبز ماند — یعنی مهم‌ترین
        | ادعایش را اصلاً نمی‌سنجید.
        |
        | `fake()` تلاش را **ثبت** می‌کند به‌جای پرتاب، پس بلعیدنِ استثنا
        | پنهانش نمی‌کند.
        */
        Http::fake();

        Cache::flush();                    // کشِ کاملاً سرد — بدترین حالت

        $this->get('/')->assertOk();
        $this->get('/en')->assertOk();
        $this->get('/tr')->assertOk();

        Http::assertNothingSent();
    }

    /**
     * 🔴 فهرست باید **همانی** باشد که کرون گرم می‌کند.
     *
     * کلیدِ کش از فهرستِ پسوندها ساخته می‌شود. فهرستِ متفاوت یعنی کشِ همیشه
     * خالی و بازگشتِ بی‌صدا به استعلامِ زنده — نقصی که هیچ تستی جز این
     * نمی‌گیردش، چون صفحه همچنان ۲۰۰ می‌دهد و قیمت هم درست است.
     */
    public function test_the_page_reads_exactly_the_list_the_cron_warms(): void
    {
        $this->warmBook(['com' => 1_777_000]);

        $chips = $this->chips($this->get('/')->assertOk()->getContent());

        $this->assertEqualsWithDelta(1_777_000, $this->toman($chips['.com'] ?? '0'), 20_000,
            'کشی که با فهرستِ SUGGEST گرم شد خوانده نشد — یعنی کلیدِ کش فرق دارد');
    }

    /**
     * 🔴 پسوندی که نمی‌فروشیم روی صفحهٔ اول تبلیغ نمی‌شود.
     *
     * `.ir` به‌خواستِ کارفرما فعلاً فروخته نمی‌شود و در `UNSOLD_TLDS` است.
     * تبلیغِ پسوندی که سبدِ خرید قبولش نمی‌کند، بازدیدکننده را به بن‌بست
     * می‌بَرد — و آن از نبودِ چیپ بدتر است.
     *
     * ⚠️ صافی `DomainSearch::sells()` است نه فهرستی جدا، پس اگر روزی `.ir`
     * فروخته شود برداشتنش از `UNSOLD_TLDS` کافی است تا همه‌جا با هم برگردد.
     */
    public function test_a_tld_we_do_not_sell_is_never_advertised(): void
    {
        $this->warmBook(['com' => 1_777_000]);

        $chips = $this->chips($this->get('/')->assertOk()->getContent());

        foreach (array_keys($chips) as $tld) {
            $this->assertTrue(\App\Services\Domain\DomainSearch::sells($tld),
                "«{$tld}» را نمی‌فروشیم ولی روی صفحهٔ اول تبلیغ شد");
        }

        $this->assertArrayNotHasKey('.ir', $chips);
    }

    /**
     * ⚠️ همان قاعده روی مسیرِ اضطراریِ کشِ سرد.
     *
     * فهرستِ دستیِ `config('servernet.tlds')` هنوز `.ir` دارد. بی‌صافی، آن
     * مسیر پسوندِ نافروشی را از درِ پشتی برمی‌گرداند — و چون فقط روی کشِ سرد
     * رخ می‌دهد، ماه‌ها دیده نمی‌شود.
     */
    public function test_the_cold_cache_fallback_also_hides_unsold_tlds(): void
    {
        Cache::flush();

        $chips = $this->chips($this->get('/')->assertOk()->getContent());

        $this->assertNotEmpty($chips, 'با کشِ سرد هیچ چیپی نماند');
        $this->assertArrayNotHasKey('.ir', $chips, '.ir از مسیرِ اضطراری برگشت');
    }

    /**
     * ⚠️ پسوندِ بی‌قیمت اصلاً نشان داده نمی‌شود.
     *
     * نه «۰ تومان» و نه «—». همان قاعدهٔ `site_price()`: صفر یعنی «نمی‌دانم».
     */
    public function test_a_tld_without_a_price_is_omitted_not_shown_as_zero(): void
    {
        // فقط .com قیمت دارد؛ بقیه نه دفترچه دارند نه قیمتِ دستی
        config(['servernet.tlds' => [['tld' => '.com', 'irt' => 1_000_000]]]);
        $this->warmBook(['com' => 1_777_000]);

        $chips = $this->chips($this->get('/')->assertOk()->getContent());

        /*
        | ⚠️ عدد را می‌سنجم، نه رشته را. نسخهٔ اول
        |    `assertStringNotContainsString('۰ تومان', …)` بود و روی
        |    «۱٬۷۸۰٬۰۰۰ تومان» شکست — چون آن رشته واقعاً به «۰ تومان» ختم
        |    می‌شود. ادعای درست «مقدارش صفر نباشد» است.
        */
        foreach ($chips as $tld => $price) {
            $this->assertNotSame('—', $price, "«{$tld}» بی‌قیمت نمایش داده شد");
            $this->assertGreaterThan(0, $this->toman($price), "«{$tld}» صفر تومان نشان داد");
        }
    }

    /** ⚠️ کشِ سرد ⇒ فهرستِ دستی، نه جعبهٔ خالی. */
    public function test_a_cold_cache_falls_back_to_the_manual_list(): void
    {
        Cache::flush();

        $chips = $this->chips($this->get('/')->assertOk()->getContent());

        $this->assertNotEmpty($chips, 'با کشِ سرد هیچ چیپی نماند — جعبه خالی می‌شود');
    }

    /** ⚠️ نسخهٔ انگلیسی هم باید عدد داشته باشد، نه تومانِ خام. */
    public function test_the_english_page_shows_a_converted_price(): void
    {
        $this->warmBook(['com' => 1_777_000]);

        $html = $this->get('/en')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~data-tld="\.com"[^>]*><b>[^<]*</b><i>[^<]*\d~', $html,
            'چیپِ انگلیسی عدد ندارد');
        $this->assertStringNotContainsString('تومان', $this->chips($html)['.com'] ?? '',
            'صفحهٔ انگلیسی قیمتِ تومانی نشان داد');
    }
}
