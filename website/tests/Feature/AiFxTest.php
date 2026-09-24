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

        // ۲۵ ساعت بعد، بی‌هیچ تماسی در میان: آخرین سطل (۱۰۰٬۰۰۰) هنوز مرجع است —
        // مکثِ طولانی افتِ بزرگ را یک‌جا نمی‌پذیرد
        Carbon::setTestNow('2026-11-21 11:00:00');
        $this->scrape('USD', 50_000);
        $this->assertSame(97_000, $this->fx()->quote('USD')->rate);
        $this->assertSame(97_000, $this->fx()->highWater('USD')['rate']);

        // روزِ بعد: فقط ۳٪ دیگر — افتِ واقعی هم روزی ۳٪ دنبال می‌شود، نه یک‌باره
        Carbon::setTestNow('2026-11-22 12:00:00');
        $this->scrape('USD', 50_000);
        $this->assertSame(94_090, $this->fx()->quote('USD')->rate);   // ⌈97000 · 0.97⌉
    }

    /**
     * سناریوی بازبینیِ پیش از انتشار: نشانِ تکیِ نسخهٔ اول ۱۰۰٬۰۰۰ ← ۹۴٬۰۹۰ را در
     * ۷ دقیقه راه می‌داد (۵٫۹٪). با پنجرهٔ ساعتی، قدمِ دوم فقط وقتی ممکن است که
     * آخرین فروش به ۱۰۰٬۰۰۰ بیش از ۲۴ ساعت گذشته باشد.
     */
    public function test_two_steps_never_fit_inside_24h_of_steady_traffic(): void
    {
        // ترافیکِ پیوسته به ۱۰۰٬۰۰۰ — هر ساعت یک تماس، تا ۰۹:۵۵ ِ روزِ بعد
        for ($t = Carbon::parse('2026-11-20 10:30:00'); $t->lt('2026-11-21 09:56:00'); $t->addHour()) {
            Carbon::setTestNow($t->copy());
            $this->scrape('USD', 100_000);
            $this->assertSame(100_000, $this->fx()->quote('USD')->rate);
        }
        Carbon::setTestNow('2026-11-21 09:55:00');
        $this->scrape('USD', 100_000);
        $this->assertSame(100_000, $this->fx()->quote('USD')->rate);

        // اسکرپِ خراب درست نزدیکِ ۲۴ ساعتگیِ نخستین فروش
        foreach (['2026-11-21 09:56:00', '2026-11-21 10:01:00', '2026-11-21 10:02:00', '2026-11-21 20:00:00', '2026-11-22 09:59:00'] as $at) {
            Carbon::setTestNow($at);
            $this->scrape('USD', 50_000);
            $this->assertSame(97_000, $this->fx()->quote('USD')->rate, "در {$at} نرخ بیش از ۳٪ زیرِ فروشِ ۲۴ ساعتِ اخیر رفت");
        }

        // فقط وقتی آخرین سطلِ ۱۰۰٬۰۰۰ (ساعتِ ۰۹) از پنجره بیرون رفت
        Carbon::setTestNow('2026-11-22 10:00:00');
        $this->scrape('USD', 50_000);
        $this->assertSame(94_090, $this->fx()->quote('USD')->rate);
    }

    public function test_malformed_record_closes_sales_instead_of_disabling_the_guard(): void
    {
        $this->scrape('USD', 50_000);

        foreach ([
            '{"rate":100000,"at":"2026-11-20T10:00:00+00:00"}',     // قالبِ نسخهٔ اول
            '{"v":2,"h":{"2026112010":100000.0}}',                  // float
            '{"v":2,"h":{"2026112010":"100000"}}',                  // رشته
            '{"v":2,"h":{"yesterday":100000}}',                     // کلیدِ بی‌معنا
            'not json',
        ] as $raw) {
            Setting::put('ai_fx_hw_usd', $raw);
            $this->assertNull($this->fx()->quote('USD'), "ردیفِ خراب باید فروش را ببندد: {$raw}");
            $this->assertSame($raw, Setting::get('ai_fx_hw_usd'), 'ردیفِ خراب نباید بی‌صدا بازنویسی شود');
            $this->assertTrue($this->fx()->highWater('USD')['malformed']);
        }

        // بازنشانیِ آگاهانه راهِ برگشت است
        $this->fx()->resetHighWater('USD');
        $this->assertSame(50_000, $this->fx()->quote('USD')->rate);
        Http::assertNothingSent();
    }

    public function test_stale_buffered_rate_above_five_million_keeps_the_guard(): void
    {
        $this->scrape('USD', 4_950_000, now()->subHours(8));

        $this->assertSame(5_049_000, $this->fx()->quote('USD')->rate);   // ⌈4,950,000 · 1.02⌉
        $this->assertFalse($this->fx()->highWater('USD')['malformed']);

        // اسکرپِ خرابِ بعدی باز هم فقط ۳٪
        $this->scrape('USD', 50_000);
        $this->assertSame(4_897_530, $this->fx()->quote('USD')->rate);   // ⌈5,049,000 · 0.97⌉
    }

    public function test_steady_state_writes_the_record_once_per_hour(): void
    {
        Carbon::setTestNow('2026-11-20 10:05:00');
        $this->scrape('USD', 100_000);

        $writes = 0;
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$writes) {
            if (preg_match('/^\s*(insert|update)\b.*\bsettings\b/i', $q->sql)) {
                $writes++;
            }
        });

        for ($i = 0; $i < 20; $i++) {
            Carbon::setTestNow(Carbon::parse('2026-11-20 10:05:00')->addMinutes($i * 2));
            $this->fx()->quote('USD');
        }
        $this->assertSame(1, $writes, 'هر نوشتنِ Setting کشِ تنظیمات را خالی می‌کند؛ باید یک بار در ساعت باشد');

        Carbon::setTestNow('2026-11-20 11:01:00');
        $this->fx()->quote('USD');
        $this->assertSame(2, $writes);
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
