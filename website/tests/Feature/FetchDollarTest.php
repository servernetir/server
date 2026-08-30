<?php

namespace Tests\Feature;

use App\Services\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `fx:dollar` — نرخِ دلار.
 *
 * 🔴 چرا این تست‌ها نوشته شدند: روی سرورِ زنده این فرمان **هر ساعت** با
 * `Undefined array key "usd_irt"` می‌ترکید و زمان‌بندِ لاراول یک خطای ۵۰۰ ثبت
 * می‌کرد. علتش یک ناهم‌خوانیِ نامِ کلید بود — سرویس `rate_toman` می‌دهد و فرمان
 * `usd_irt` می‌خواست.
 *
 * ⚠️ ظرافتی که تشخیص را سخت کرده بود: نرخ **درست ذخیره می‌شد**، چون `Cache::put`
 * پیش از انفجار اجرا می‌شود. پس قیمت‌گذاری سالم بود و تنها نشانه، سیلِ خطای
 * ساعتی بود.
 */
class FetchDollarTest extends TestCase
{
    use RefreshDatabase;
    /**
     * ⚠️ این کلاس عمداً **مسیرِ دریافتِ زنده** را می‌سنجد (با transportِ فِیک)،
     * پس کلیدِ خاموشیِ سراسریِ تست را صریح روشن می‌کند.
     *
     * `phpunit.xml` آن را خاموش می‌گذارد تا هیچ تستی ناخواسته به اینترنت وصل
     * نشود — دلیلش در PricingNeverPhonesHomeTest. روشن‌کردنِ صریح این‌جا یعنی
     * «می‌دانم دارم چه می‌کنم»، نه یک نشتِ تصادفی.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.exchange.enabled', true);
    }


    private function fakeRate(int $toman): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*alanchand.com*' => Http::response(
            '<div>قیمت دلار: '.number_format($toman).' تومان</div>'
            .'<span>'.number_format($toman).'</span><b>'.number_format($toman).'</b>'
        )]);
    }

    public function test_it_succeeds_and_reports_the_rate(): void
    {
        $this->fakeRate(92500);

        $this->artisan('fx:dollar')->assertSuccessful();
    }

    /** 🔴 خروجِ ناموفق یعنی خطای ساعتی در ردیابِ خطا */
    public function test_it_does_not_crash_on_the_success_path(): void
    {
        $this->fakeRate(92500);

        $code = $this->artisan('fx:dollar')->run();

        $this->assertSame(0, $code, 'خروج با کدِ غیرصفر یعنی زمان‌بند خطا ثبت می‌کند');
    }

    /**
     * ⚠️ کلیدِ کش `fx.usd_irt` است — و منشأِ همان اشتباه: نامِ **کلیدِ کش** با
     * نامِ **کلیدِ آرایه** قاطی شده بود. این تست عمداً از کلیدِ واقعی استفاده
     * می‌کند، وگرنه با کشِ خالی هم سبز می‌شد و چیزی را نمی‌سنجید.
     */
    public function test_show_mode_reads_the_cached_rate(): void
    {
        Cache::put('fx.usd_irt', [
            'currency' => 'USD', 'rate_toman' => 91000,
            'source' => 'test', 'at' => now()->toIso8601String(),
        ], now()->addHour());

        $this->artisan('fx:dollar --show')
            ->expectsOutputToContain('91,000')
            ->assertSuccessful();
    }

    public function test_show_mode_is_fine_with_no_cached_rate(): void
    {
        Cache::flush();

        $this->artisan('fx:dollar --show')->assertSuccessful();
    }

    /** شکستِ واقعیِ شبکه باید FAILURE بدهد ولی **نترکد** */
    public function test_a_fetch_failure_fails_cleanly(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*alanchand.com*' => Http::response('no numbers here', 200)]);

        $code = $this->artisan('fx:dollar')->run();

        $this->assertSame(1, $code);
    }

    /** کلیدی که فرمان می‌خواند باید همانی باشد که سرویس می‌دهد */
    public function test_the_service_and_the_command_agree_on_the_key(): void
    {
        $this->fakeRate(92500);

        $row = app(ExchangeRate::class)->refresh('USD');

        $this->assertIsArray($row);
        $this->assertArrayHasKey('rate_toman', $row);
        $this->assertArrayNotHasKey('usd_irt', $row,
            'اگر روزی این کلید برگردد، فرمان هم باید با آن هماهنگ شود');
    }
}
