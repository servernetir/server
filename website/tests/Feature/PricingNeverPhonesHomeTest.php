<?php

namespace Tests\Feature;

use App\Services\ExchangeRate;
use App\Services\Payment\CryptoPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 «نرخ نداریم» باید یک حالتِ **ممکن** باشد.
 *
 * ═══ باگی که این فایل می‌بندد ═══
 *
 * `ExchangeRate::toToman()` و `CryptoPrice::usd()` روی کشِ سرد **خودشان به
 * اینترنت وصل می‌شدند**. یعنی سناریوی «قیمتِ مبنا را نمی‌دانیم» در این سیستم
 * اصلاً رخ‌دادنی نبود: هر کشِ خالی با یک اسکرپِ زنده پر می‌شد.
 *
 * سه گاردِ واقعی روی همین سناریو نشسته بودند — «نرخِ نامعلوم قیمت را پنهان
 * کن»، «رمزارزِ بی‌نرخ عرضه نشود»، «TRX بی‌قیمتِ بازار عرضه نشود» — و هر سه
 * **فقط وقتی سبز بودند که اینترنت قطع باشد**. یعنی سبزیِ سوئیت به در دسترس
 * بودنِ alanchand.com و صرافی گره خورده بود، و آن سه گارد در عمل هیچ‌وقت
 * سنجیده نمی‌شدند.
 *
 * `DomainPricingRateTest` حتی `config('services.exchange.enabled')` را false
 * می‌کرد — پرچمی که **هیچ‌جای کد خوانده نمی‌شد**. همان تلهٔ «مسیرِ غلطِ
 * config = درایورِ خاموش»، این‌بار وارونه: تست فکر می‌کرد چیزی را خاموش کرده
 * و نکرده بود.
 *
 * حالا پرچم واقعی است و در `phpunit.xml` خاموش. این فایل خودِ پرچم را قفل
 * می‌کند، نه رفتارِ لایه‌های بالاتر را (آن‌ها تستِ خودشان را دارند).
 */
class PricingNeverPhonesHomeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠️ ادعا روی **خودِ درخواست** است، نه روی خروجی.
     *
     * 🔴 نسخهٔ اولِ همین تست `preventStrayRequests()` می‌گذاشت و بعد
     * `assertNull` روی خروجی. **گاز نمی‌گرفت**: `refresh()` تماسِ HTTP را در
     * `try/catch (\Throwable)` دارد، پس استثنای stray را می‌بلعد و `null`
     * برمی‌گرداند — یعنی خروجی در هر دو حالت `null` است و تست با کلیدِ
     * خاموشیِ **حذف‌شده** هم سبز می‌مانْد. با mutation test لو رفت.
     *
     * `assertNothingSent()` تنها چیزی است که واقعاً «به بیرون وصل نشد» را
     * می‌سنجد.
     */
    public function test_a_cold_exchange_rate_never_reaches_the_internet(): void
    {
        Http::fake();          // اگر تماسی برود، این‌جا ثبت می‌شود
        Cache::flush();

        $rate = app(ExchangeRate::class)->toToman('EUR');

        Http::assertNothingSent();
        $this->assertNull($rate, 'نرخِ سرد باید null بماند — هر عددی یعنی از بیرون گرفته شده');
    }

    public function test_a_cold_crypto_price_never_reaches_the_internet(): void
    {
        Http::fake();
        Cache::flush();

        $price = app(CryptoPrice::class)->usd('TRX');

        Http::assertNothingSent();
        $this->assertNull($price, 'قیمتِ سردِ رمزارز باید null بماند');
    }

    /**
     * ⚠️ تستِ سیم‌کشی: پرچم باید همان‌جایی باشد که کد نگاه می‌کند.
     *
     * این تست عمداً چیزی را `config([...])` نمی‌کند — چون `config()` هر مسیری
     * را که نام ببری می‌سازد و تستی که خودش مقدار را ست کند، هرگز سیم‌کشیِ
     * واقعی را نمی‌سنجد. (همان درسِ `bale_relay`.)
     */
    public function test_the_kill_switch_lives_where_the_code_reads_it(): void
    {
        $raw = require config_path('services.php');

        $this->assertArrayHasKey('exchange', $raw, 'بلوکِ exchange در config/services.php نیست');
        $this->assertArrayHasKey('enabled', $raw['exchange']);

        // و phpunit واقعاً خاموشش کرده — وگرنه دو تستِ بالا بی‌معنی‌اند
        $this->assertFalse((bool) config('services.exchange.enabled'),
            'در محیطِ تست باید خاموش باشد، وگرنه سوئیت به اینترنت وصل می‌شود');
    }

    /**
     * روشن که باشد، رفتارِ پروداکشن دست‌نخورده است — پرچم نباید بی‌سروصدا
     * دریافتِ زنده را برای همیشه بکشد.
     */
    public function test_switching_it_on_restores_the_live_fetch(): void
    {
        config()->set('services.exchange.enabled', true);
        Cache::flush();

        Http::fake(['*' => Http::response('<span>۲۱۵٬۱۰۰</span>')]);

        // فقط ادعا می‌کنیم که **تلاش** می‌شود؛ نتیجهٔ پارس مالِ تستِ خودش است
        app(ExchangeRate::class)->refresh('EUR');

        Http::assertSentCount(1);
    }
}
