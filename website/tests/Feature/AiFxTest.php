<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Ai\AiFx;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * نرخِ ارزِ فروشِ AI (D2): max(دستی، بازار)، سقفِ سن، سربارِ کهنگی، ضامنِ افت —
 * و مهم‌تر از همه: **هیچ اسکرپِ زنده‌ای روی مسیرِ پولی**.
 */
class AiFxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // پرچمِ اسکرپ روشن می‌ماند تا ثابت شود این کلاس خودش اسکرپ نمی‌کند، نه پرچم
        config(['services.exchange.enabled' => true]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function scrape(string $cur, int $rate, ?Carbon $at = null): void
    {
        Cache::put('fx.'.strtolower($cur).'_irt', [
            'currency' => $cur, 'rate_toman' => $rate, 'source' => 'alanchand.com',
            'at' => ($at ?? now())->toIso8601String(),
        ], now()->addHours(6));
    }

    private function fx(): AiFx
    {
        return app(AiFx::class);
    }

    public function test_cold_cache_and_no_override_is_null_without_any_http_call(): void
    {
        $this->assertNull($this->fx()->quote('USD'));
        Http::assertNothingSent();
    }

    public function test_fresh_scrape_is_used(): void
    {
        $this->scrape('USD', 100_000);

        $q = $this->fx()->quote('USD');
        $this->assertSame(100_000, $q->rate);
        $this->assertSame('scraped', $q->source);
    }

    public function test_higher_override_wins_and_lower_override_loses(): void
    {
        $this->scrape('USD', 100_000);

        Setting::put('pricing_usd_rate_override', '120000');
        $this->assertSame(['rate' => 120_000, 'source' => 'override'], $this->pick('USD'));

        Setting::put('pricing_usd_rate_override', '90000');
        Setting::put('ai_fx_hw_usd', null);
        $this->assertSame(['rate' => 100_000, 'source' => 'scraped'], $this->pick('USD'));
    }

    public function test_override_alone_is_usable_but_out_of_range_override_is_ignored(): void
    {
        Setting::put('pricing_usd_rate_override', '100000');
        $this->assertSame(100_000, $this->fx()->quote('USD')->rate);

        Setting::put('ai_fx_hw_usd', null);
        Setting::put('pricing_usd_rate_override', '15000');      // یک صفر کم
        $this->assertNull($this->fx()->quote('USD'));

        Setting::put('pricing_usd_rate_override', '100000000');  // سه صفر زیاد
        $this->assertNull($this->fx()->quote('USD'));
    }

    public function test_scrape_older_than_24h_is_not_sellable(): void
    {
        $this->scrape('USD', 100_000, now()->subHours(25));

        $this->assertNull($this->fx()->quote('USD'));
    }

    public function test_scrape_between_6_and_24h_adds_the_stale_buffer(): void
    {
        $this->scrape('USD', 100_000, now()->subHours(8));

        $q = $this->fx()->quote('USD');
        $this->assertSame(102_000, $q->rate);
        $this->assertSame('scraped+stale', $q->source);
    }

    public function test_scrape_without_timestamp_is_not_trusted(): void
    {
        Cache::put('fx.usd_irt', ['currency' => 'USD', 'rate_toman' => 100_000], now()->addHour());

        $this->assertNull($this->fx()->quote('USD'));
    }

    public function test_ratchet_caps_a_drop_at_3_percent_per_24h(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->scrape('USD', 100_000);
        $this->assertSame(100_000, $this->fx()->quote('USD')->rate);

        // اسکرپِ اشتباه‌خوانده: نصف
        $this->scrape('USD', 50_000);
        $this->assertSame(['rate' => 97_000, 'source' => 'ratchet'], $this->pick('USD'));

        // ۲۵ ساعت بعد: نشانِ کهنه هنوز کف است و به نرخِ مؤثر (۹۷٬۰۰۰) بازنشانی می‌شود
        Carbon::setTestNow('2026-11-21 11:00:00');
        $this->scrape('USD', 50_000);
        $this->assertSame(97_000, $this->fx()->quote('USD')->rate);
        $this->assertSame(97_000, $this->fx()->highWater('USD')['rate']);

        // روزِ بعد: فقط ۳٪ دیگر — افتِ واقعی هم روزی ۳٪ دنبال می‌شود، نه یک‌باره
        Carbon::setTestNow('2026-11-22 12:00:00');
        $this->scrape('USD', 50_000);
        $this->assertSame(94_090, $this->fx()->quote('USD')->rate);   // ⌈97000 · 0.97⌉
    }

    public function test_a_rise_is_followed_instantly(): void
    {
        $this->scrape('USD', 100_000);
        $this->fx()->quote('USD');

        $this->scrape('USD', 150_000);
        $this->assertSame(['rate' => 150_000, 'source' => 'scraped'], $this->pick('USD'));
    }

    public function test_reset_high_water_removes_the_ratchet(): void
    {
        $this->scrape('USD', 100_000);
        $this->fx()->quote('USD');

        $this->fx()->resetHighWater('USD');
        $this->scrape('USD', 50_000);
        $this->assertSame(50_000, $this->fx()->quote('USD')->rate);
    }

    public function test_unsupported_currency_is_null(): void
    {
        Setting::put('pricing_usd_rate_override', '100000');

        $this->assertNull($this->fx()->quote('IRR'));
        $this->assertNull($this->fx()->quote('GBP'));
    }

    public function test_display_rate_prefers_override_and_never_adds_the_stale_buffer(): void
    {
        $this->scrape('EUR', 110_000, now()->subHours(8));
        $this->assertSame(110_000, $this->fx()->displayRate('EUR'));

        Setting::put('pricing_rate_override', '115000');
        $this->assertSame(115_000, $this->fx()->displayRate('EUR'));
    }

    /** @return array{rate:int,source:string} */
    private function pick(string $cur): array
    {
        $q = $this->fx()->quote($cur);

        return ['rate' => $q->rate, 'source' => $q->source];
    }
}
