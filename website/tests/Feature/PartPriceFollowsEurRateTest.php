<?php

namespace Tests\Feature;

use App\Models\ServerPart;
use App\Services\Cloud\CloudPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * قیمتِ قطعه از **یورو** ساخته می‌شود، نه از عددِ تومانیِ ذخیره‌شده.
 *
 * ═══ چرا این قاعده ═══
 *
 * قطعهٔ سرور از بازارِ جهانی خریده می‌شود و قیمتِ واقعی‌اش یورویی است. اگر
 * عددِ تومانی ذخیره می‌شد، با هر جهشِ ارز کلِ کاتالوگ باید دستی به‌روز می‌شد —
 * که در عمل نمی‌شود — و فروشگاه بی‌سروصدا زیرِ قیمتِ خرید می‌فروخت. هیچ خطایی،
 * هیچ لاگی؛ فقط حاشیهٔ سودی که آب می‌رود.
 *
 * ⚠️ ادعای این تست «عددی نشان داده می‌شود» نیست — آن با هر پیاده‌سازیِ غلطی
 * هم صادق است. ادعا این است که **با تغییرِ نرخ، عدد عوض می‌شود**.
 */
class PartPriceFollowsEurRateTest extends TestCase
{
    use RefreshDatabase;

    private function rate(int $toman): void
    {
        Cache::flush();

        $this->mock(CloudPricing::class, fn ($m) => $m->shouldReceive('eurToToman')->andReturn($toman));
    }

    private function part(?int $eurCents, ?int $irtOverride = null, bool $contact = false): ServerPart
    {
        return ServerPart::create([
            'slug'          => 'p-'.uniqid(),
            'category'      => 'cpu',
            'brand'         => 'Intel',
            'condition'     => 'refurb',
            'price_contact' => $contact,
            'price_eur'     => $eurCents,
            'price_irt'     => $irtOverride,
            'active'        => true,
            'name'          => ['fa' => 'تست', 'en' => 'Test', 'tr' => 'Test'],
        ]);
    }

    /**
     * 🔴 قلبِ تست: **همان قطعه**، دو نرخ، دو قیمتِ متفاوت.
     *
     * اگر روزی کسی قیمت را تومانی ذخیره کند و `part_price()` را دور بزند،
     * این ادعا می‌شکند و بقیهٔ ادعاها نمی‌شکنند.
     */
    public function test_the_toman_price_moves_when_the_eur_rate_moves(): void
    {
        $part = $this->part(3400);   // ۳۴٫۰۰ یورو
        $this->app->setLocale('fa');

        $this->rate(100_000);
        $cheap = $part->displayPrice();

        $this->rate(200_000);
        $expensive = $part->displayPrice();

        $this->assertNotSame($cheap, $expensive, 'قیمتِ تومانی باید با نرخِ ارز تکان بخورد');
        $this->assertSame(fa_num(number_format(3_400_000)).' تومان', $cheap);
        $this->assertSame(fa_num(number_format(6_800_000)).' تومان', $expensive);
    }

    /** انگلیسی و ترکی همان یورو را می‌بینند — بی‌هیچ تبدیلی. */
    public function test_english_and_turkish_stay_in_euro(): void
    {
        $part = $this->part(3400);
        $this->rate(500_000);   // نرخِ عمداً عجیب: نباید هیچ اثری داشته باشد

        foreach (['en', 'tr'] as $locale) {
            $this->app->setLocale($locale);
            $this->assertSame('€34.00', $part->displayPrice());
        }
    }

    /**
     * 🔴 نرخ که نبود، `null` — نه عددِ خام، نه صفر.
     *
     * صفر یعنی «رایگان» و روی قطعهٔ سرور فاجعه است؛ عددِ خام یعنی «۳۴ تومان».
     * هر دو بدتر از «استعلام کنید»اند.
     */
    public function test_no_rate_means_ask_us_not_a_guessed_number(): void
    {
        $part = $this->part(3400);
        $this->app->setLocale('fa');
        $this->rate(0);

        $this->assertNull($part->displayPrice());
    }

    /** قطعهٔ استعلامی هیچ‌وقت عدد نمی‌دهد، حتی اگر ستونِ یورو پر باشد. */
    public function test_a_contact_priced_part_never_shows_a_number(): void
    {
        $part = $this->part(3400, null, true);
        $this->rate(200_000);

        foreach (['fa', 'en', 'tr'] as $locale) {
            $this->app->setLocale($locale);
            $this->assertNull($part->displayPrice());
        }
    }

    /** Legacy toman values must not silently replace the public EUR-based price. */
    public function test_legacy_toman_value_does_not_override_displayed_price(): void
    {
        $part = $this->part(3400, 9_000_000);
        $this->rate(200_000);

        $this->app->setLocale('fa');
        $this->assertSame(fa_num(number_format(6_800_000)).' تومان', $part->displayPrice());

        $this->app->setLocale('en');
        $this->assertSame('€34.00', $part->displayPrice());
    }

    /**
     * ⚠️ گردکردن به ۱۰٬۰۰۰ تومان عمدی است.
     *
     * نرخِ ارز چند بار در روز تکان می‌خورد؛ عددِ دقیق یعنی قیمتِ صفحه هر ساعت
     * چند تومان جابه‌جا شود، که هم بدقواره است هم بی‌اعتمادکننده.
     */
    public function test_prices_are_rounded_so_they_do_not_jitter(): void
    {
        $part = $this->part(3333);   // ۳۳٫۳۳ یورو
        $this->app->setLocale('fa');
        $this->rate(222_333);        // نرخی که عمداً عددِ کثیف می‌دهد

        // 33.33 × 222333 = 7,410,358.89 ⇒ گردشده به ۷٬۴۱۰٬۰۰۰
        $this->assertSame(fa_num(number_format(7_410_000)).' تومان', $part->displayPrice());
    }

    /** صفر و منفی هم «استعلام»اند، نه «رایگان». */
    public function test_zero_and_missing_prices_are_never_free(): void
    {
        $this->app->setLocale('fa');
        $this->rate(200_000);

        $this->assertNull(part_price(null));
        $this->assertNull(part_price(0));
        $this->assertNull($this->part(null)->displayPrice());
    }

    /** The structured Offer must use the same currency and amount as the page. */
    public function test_schema_offer_matches_the_visible_localized_price(): void
    {
        $part = $this->part(16806);
        $this->rate(200_000);

        foreach (['fa', 'en', 'tr'] as $locale) {
            $this->app->setLocale($locale);
            $this->assertSame(168.06, $part->eurAmount());
        }

        $this->app->setLocale('fa');
        $html = $this->get('/parts/cpu/'.$part->slug)->assertOk()->getContent();

        $this->assertStringContainsString('"priceCurrency":"IRR"', $html);
        $this->assertStringContainsString('"price":"336100000"', $html);

        $this->app->setLocale('en');
        $english = $this->get('/en/parts/cpu/'.$part->slug)->assertOk()->getContent();
        $this->assertStringContainsString('"priceCurrency":"EUR"', $english);
        $this->assertStringContainsString('"price":"168.06"', $english);
    }
}
