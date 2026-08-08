<?php

namespace Tests\Feature;

use App\Services\Cloud\CloudPricing;
use App\Services\ExchangeRate;
use App\Support\ErrorTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 زنجیرهٔ ارز: **بلند شکست بخور، ولی هرگز نرخ نساز.**
 *
 * روی سرورِ زنده این زنجیره دیده شد:
 *
 *   `fx:dollar` روی یک کلیدِ نبود می‌ترکید (کدِ خروجِ ۱)
 *     → `schedule:run` هر ساعت یک خطای ۵۰۰ ثبت می‌کرد
 *     → `cloud:sync` هم با `TypeError` می‌مرد
 *     → قیمتِ یورو تازه نمی‌شد ⇒ `price_irt = 0` ⇒ `scopeSellable` همهٔ
 *       سرورهای مجازی را از فروشگاه بیرون می‌گذاشت.
 *
 * درسِ اصلی: خواندنِ مستقیمِ `$row['rate_toman']` — هر کجای کد — یک بمبِ ساعتی
 * است. اگر شکلِ پاسخِ بالادست عوض شود باید **نال** بگیریم، نه استثنا؛ و نال
 * یعنی «نمی‌دانم»، نه «صفر».
 *
 * ⚠️ نرخِ غلط از نبودِ نرخ بدتر است: با نرخِ ساختگی، سرورِ ۵۰ یورویی به قیمتِ
 * هیچ فروخته می‌شود. پس هیچ تستی این‌جا نباید مقدارِ جایگزین را مجاز بشمارد.
 */
class ExchangeRateShapeGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ErrorTracker::clear();
    }

    /** ردیفِ کهنه/ناهم‌شکل در کش نباید هیچ‌جا `Undefined array key` بدهد */
    public function test_a_malformed_cached_row_is_ignored_not_exploded(): void
    {
        // دقیقاً شکلی که یک‌بار انفجار داد: کلیدِ نرخ اصلاً وجود ندارد
        Cache::put('fx.usd_irt', ['currency' => 'USD', 'usd_irt' => 91000], now()->addHour());

        $this->assertNull(app(ExchangeRate::class)->current('USD'),
            'ردیفِ ناهم‌شکل باید نال شود، نه یک آرایهٔ نیم‌بند که بعداً می‌ترکد');
    }

    /** و فرمان هم با همان ردیف باید کدِ ۰ بدهد — کدِ ۱ یعنی خطای ساعتی در ردیاب */
    public function test_the_hourly_command_survives_a_malformed_cached_row(): void
    {
        Cache::put('fx.usd_irt', ['currency' => 'USD', 'usd_irt' => 91000], now()->addHour());

        $this->artisan('fx:dollar --show')
            ->expectsOutputToContain('هنوز نرخی ذخیره نشده')
            ->assertExitCode(0);
    }

    /** ردیفِ خرابی که بی‌صدا رد شود همان سکوت است — باید در ردیاب دیده شود */
    public function test_a_malformed_row_is_reported_to_the_error_tracker(): void
    {
        Cache::put('fx.eur_irt', ['currency' => 'EUR', 'rate_toman' => 'خیلی'], now()->addHour());

        app(ExchangeRate::class)->current('EUR');

        $notes = array_values(array_filter(
            ErrorTracker::recent(50, 'error'),
            fn ($e) => ($e['area'] ?? '') === 'pricing'
        ));

        $this->assertNotEmpty($notes, 'شکلِ ناشناختهٔ نرخ باید بلند باشد، نه خاموش');
    }

    /**
     * 🔴 مهم‌ترین ادعای این فایل: **هرگز نرخ اختراع نکن.**
     *
     * نرخِ بیرونِ بازهٔ عاقلانه (مثلاً یک عددِ تصادفیِ صفحه) باید دور ریخته شود.
     * صفر یا حدس یعنی فروشِ سرور زیرِ بهای خرید.
     */
    public function test_an_out_of_range_rate_is_never_used(): void
    {
        Cache::put('fx.eur_irt', ['currency' => 'EUR', 'rate_toman' => 7], now()->addHour());

        Http::swap(new Factory);
        Http::fake(['*alanchand.com*' => Http::response('nothing parseable here', 200)]);

        $this->assertNull(app(ExchangeRate::class)->current('EUR'));
        $this->assertNull(app(ExchangeRate::class)->toToman('EUR'),
            'نمی‌دانیم ⇒ نال. عددِ جایگزین یعنی قیمت‌گذاریِ دروغین.');

        // و قیمت‌گذار هم صفر می‌دهد، که `scopeSellable` پلن را از فروشگاه
        // بیرون می‌گذارد — رفتارِ عمدیِ مستندشده در CLAUDE.md.
        $this->assertSame(0, app(CloudPricing::class)->toman(5000));
    }

    /** صفحهٔ غیرقابل‌تجزیه: مقدارِ قبلی دست‌نخورده می‌مانَد و چیزی جعل نمی‌شود */
    public function test_an_unparseable_page_keeps_the_previous_value(): void
    {
        Cache::put('fx.usd_irt', [
            'currency' => 'USD', 'rate_toman' => 91000, 'at' => now()->toIso8601String(),
        ], now()->addHour());

        Http::swap(new Factory);
        Http::fake(['*alanchand.com*' => Http::response('<div>no prices at all</div>', 200)]);

        $this->assertNull(app(ExchangeRate::class)->refresh('USD'));
        $this->assertSame(91000, app(ExchangeRate::class)->current('USD')['rate_toman']);
    }

    /**
     * `cloud:sync` نباید روی **گزارش‌دادن** بمیرد.
     *
     * دو `TypeError` در ردیابِ زنده روی همین چند خط بود؛ نتیجه‌اش این بود که
     * فرمان با کدِ ۱ تمام می‌شد و کاتالوگ و قیمتِ سرورها تازه نمی‌شد.
     */
    public function test_cloud_sync_survives_a_report_without_a_rate(): void
    {
        $this->mock(\App\Services\Cloud\CloudCatalogSync::class, function ($m) {
            $m->shouldReceive('sync')->andReturn([
                'ok' => true,
                'providers' => ['aeza' => ['ok' => true]],   // کلیدهای شمارشی نیستند
                // 'rate' عمداً نیست
            ]);
        });

        $this->artisan('cloud:sync')->assertExitCode(0);
    }

    /** ارائه‌دهندهٔ ناموفقِ بی‌پیام هم نباید فرمان را بکشد */
    public function test_cloud_sync_survives_a_provider_row_without_a_message(): void
    {
        $this->mock(\App\Services\Cloud\CloudCatalogSync::class, function ($m) {
            $m->shouldReceive('sync')->andReturn([
                'ok' => false, 'rate' => null,
                'providers' => ['aeza' => ['ok' => false]],
            ]);
        });

        $this->artisan('cloud:sync')->assertExitCode(0);
    }
}
