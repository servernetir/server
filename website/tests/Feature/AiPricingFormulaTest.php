<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiModelUnitPrice;
use App\Models\AiProvider;
use App\Models\Setting;
use App\Services\Ai\AiPricing;
use App\Services\Ai\AiPricingException;
use App\Services\Ai\PriceBook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * فرمولِ قیمتِ فروشِ AI و شرط‌های فروختنی‌بودن (m5-spec §3، D3، D13).
 *
 * همهٔ عددها بردارهای دست‌محاسبه‌شدهٔ دقیق‌اند — همان مثالِ کارشدهٔ مشخصات
 * (DeepInfra-مانند، R = ۱۰۰٬۰۰۰، سربار ۸٪، حاشیه ۲۵٪، مالیات ۱۰٪). اگر یکی از
 * این‌ها سرخ شد، یعنی شارژِ مشتری عوض شده؛ «درست‌کردنِ انتظار» ممنوع تا وقتی
 * کسی دلیلش را در مشخصات نوشته باشد.
 */
class AiPricingFormulaTest extends TestCase
{
    use RefreshDatabase;

    private AiProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Setting::put('pricing_usd_rate_override', '100000');
        Setting::put('pricing_rate_override', '110000');
        Setting::put('ai_margin_pct', '25');

        $this->provider = AiProvider::create([
            'slug' => 'acme-fx', 'name' => 'Acme', 'driver' => 'OpenAI-Compatible',
            'enabled' => true, 'commercial_enabled' => true, 'resale_allowed' => true,
            'agreement_status' => 'signed', 'live_calls_enabled' => true,
            'priority' => 100, 'billing_currency_code' => 'USD',
            'fx_fee_bp' => 800,
        ]);
    }

    private function model(array $prices = ['input' => 230_000, 'cached_input' => 115_000, 'output' => 400_000], string $currency = 'USD'): AiModel
    {
        $slug = 'acme/llama-'.uniqid();
        $m = AiModel::create([
            'ai_provider_id' => $this->provider->id,
            'slug' => $slug, 'upstream_model' => $slug,
            'name' => 'Llama', 'category' => AiModel::CATEGORY_CHAT,
            'status' => AiModel::STATUS_ACTIVE,
        ]);

        foreach ($prices as $unit => $micros) {
            (new PriceBook)->supersede($m, $unit, $micros, currencyCode: $currency);
        }

        return $m->fresh('provider');
    }

    private function pricing(): AiPricing
    {
        return app(AiPricing::class);
    }

    private function reasonsFor(AiModel $m): array
    {
        try {
            $this->pricing()->quote($m);
        } catch (AiPricingException $e) {
            return [$e->errorCode, $e->reasons];
        }
        $this->fail('انتظار می‌رفت مدل فروختنی نباشد.');
    }

    /* ═══ بردارهای دقیق ═══ */

    public function test_worked_example_prices_per_million(): void
    {
        $q = $this->pricing()->quote($this->model());

        $this->assertSame(100_000, $q->rate());
        $this->assertSame(31_050, $q->pIn);
        $this->assertSame(15_525, $q->pCached);
        $this->assertSame(54_000, $q->pOut);
    }

    public function test_vector_with_a_remainder_rounds_up_once(): void
    {
        // 123457 · 98765 · 10750 · 11234 / 10¹⁴ = 14725.2159… ⇒ 14726
        $this->assertSame(14_726, AiPricing::perMillion(123_457, 98_765, 750, 1_234));
    }

    public function test_worked_example_call_charges_327_iranian_and_297_foreign(): void
    {
        $q = $this->pricing()->quote($this->model());

        $c = $this->pricing()->charge($q, prompt: 12_000, cached: 8_000, completion: 900, vatBp: 1000);
        $this->assertSame(297, $c['sell_book']);
        $this->assertSame(297, $c['sell_floor']);
        $this->assertSame(297, $c['sell']);
        $this->assertSame(30, $c['tax']);
        $this->assertSame(327, $c['charged']);
        $this->assertSame(2_200, $c['cost_micro']);
        $this->assertSame(238, $c['cost_irt']);

        $f = $this->pricing()->charge($q, 12_000, 8_000, 900, vatBp: 0);
        $this->assertSame(297, $f['charged']);
        $this->assertSame(0, $f['tax']);
    }

    public function test_worked_example_hold_is_506(): void
    {
        $q = $this->pricing()->quote($this->model());
        $h = $this->pricing()->hold($q, maxInput: 6_336, maxOutput: 4_096, vatBp: 1000);

        $this->assertSame(['sell' => 460, 'tax' => 46, 'total' => 506], $h);
    }

    public function test_eur_display_matches_spec(): void
    {
        $this->assertSame(2_823, AiPricing::eurTenThousandths(31_050, 110_000));
        $this->assertSame(1_412, AiPricing::eurTenThousandths(15_525, 110_000));
        $this->assertSame(4_910, AiPricing::eurTenThousandths(54_000, 110_000));
        $this->assertSame(27, AiPricing::eurTenThousandths(297, 110_000));   // €0.0027
        $this->assertNull(AiPricing::eurTenThousandths(297, 0));             // نرخ نداریم ⇒ یورو پنهان
    }

    public function test_zero_usage_charges_zero_and_one_token_charges_one(): void
    {
        $q = $this->pricing()->quote($this->model());

        $this->assertSame(0, $this->pricing()->charge($q, 0, 0, 0, 1000)['charged']);

        $one = $this->pricing()->charge($q, 1, 0, 0, 1000);
        $this->assertSame(1, $one['sell']);       // ⌈31,050/10⁶⌉ — هیچ حداقلِ شارژی نیست
        $this->assertSame(1, $one['tax']);        // مالیات هم رو به بالا
        $this->assertGreaterThanOrEqual($one['cost_irt'], $one['sell']);
    }

    public function test_cached_tokens_are_clamped_to_prompt(): void
    {
        $q = $this->pricing()->quote($this->model());
        $c = $this->pricing()->charge($q, prompt: 100, cached: 5_000, completion: 0, vatBp: 0);

        $this->assertSame(0, $c['u']);
        $this->assertSame(100, $c['c']);
    }

    public function test_missing_cached_row_bills_cached_tokens_at_full_input_price(): void
    {
        $q = $this->pricing()->quote($this->model(['input' => 230_000, 'output' => 400_000]));

        $this->assertSame($q->pIn, $q->pCached);
        $this->assertNull($q->cachedPriceId);
    }

    public function test_provider_estimated_cost_above_book_raises_the_floor_and_flags_drift(): void
    {
        $q = $this->pricing()->quote($this->model());

        // دفترِ ما ۲۲۰۰ میکرو می‌گوید؛ ارائه‌دهنده ۰٫۰۰۵ دلار (۵۰۰۰ میکرو) گزارش می‌کند
        $c = $this->pricing()->charge($q, 12_000, 8_000, 900, 1000, estimatedCost: '0.005');

        $this->assertSame(5_000, $c['estimated_micro']);
        $this->assertTrue($c['cost_drift']);
        $this->assertSame(675, $c['sell_floor']);         // ⌈5e9 · 1e5 · 1.35e8 / 1e20⌉
        $this->assertSame(675, $c['sell']);
        $this->assertSame(540, $c['cost_irt']);           // ⌈5e9 · 1e5 · 10800 / 1e16⌉
        $this->assertGreaterThanOrEqual($c['cost_irt'], $c['sell']);

        // عددِ نامعتبر یا منفی نادیده گرفته می‌شود — نه صفر، نه خطا
        $this->assertNull($this->pricing()->charge($q, 10, 0, 0, 0, 'abc')['estimated_micro']);
        $this->assertNull($this->pricing()->charge($q, 10, 0, 0, 0, '-1')['estimated_micro']);
    }

    public function test_overflow_is_request_too_large_not_a_500(): void
    {
        try {
            AiPricing::perMillion(PHP_INT_MAX, 5_000_000, 2_500, 50_000);
            $this->fail('سرریز باید خطای کنترل‌شده بدهد.');
        } catch (AiPricingException $e) {
            $this->assertSame(AiPricingException::TOO_LARGE, $e->errorCode);
        }
    }

    public function test_eur_provider_uses_the_eur_rate(): void
    {
        $this->provider->update(['billing_currency_code' => 'EUR']);
        $q = $this->pricing()->quote($this->model(['input' => 230_000, 'output' => 400_000], 'EUR'));

        $this->assertSame('EUR', $q->currency);
        $this->assertSame(110_000, $q->rate());
        $this->assertSame(34_155, $q->pIn);        // ⌈230000 · 110000 · 1.35e8 / 1e14⌉
    }

    /* ═══ حاشیه — از رشته، بی float ═══ */

    public function test_percent_parses_to_bp_without_float(): void
    {
        $this->assertSame(1250, AiPricing::percentToBp('12.5'));
        $this->assertSame(1205, AiPricing::percentToBp('12.05'));
        $this->assertSame(2500, AiPricing::percentToBp('25'));
        $this->assertSame(1250, AiPricing::percentToBp('۱۲٫۵'));
        $this->assertNull(AiPricing::percentToBp('12.345'));   // رد، نه گرد
        $this->assertNull(AiPricing::percentToBp('-5'));
        $this->assertNull(AiPricing::percentToBp('1e2'));
        $this->assertNull(AiPricing::percentToBp(''));

        $this->assertSame('12.5', AiPricing::bpToPercent(1250));
        $this->assertSame('12.05', AiPricing::bpToPercent(1205));
        $this->assertSame('25', AiPricing::bpToPercent(2500));
        $this->assertSame('', AiPricing::bpToPercent(null));
    }

    public function test_unset_margin_closes_sales_with_no_cloud_default(): void
    {
        Setting::put('ai_margin_pct', null);

        [$code, $reasons] = $this->reasonsFor($this->model());
        $this->assertSame(AiPricingException::PRICING_INCOMPLETE, $code);
        $this->assertContains('margin_unset', $reasons);
    }

    public function test_zero_margin_is_refused(): void
    {
        Setting::put('ai_margin_pct', '0');
        $this->assertContains('margin_unset', $this->reasonsFor($this->model())[1]);

        Setting::put('ai_margin_pct', '25');
        $m = $this->model();
        $m->update(['margin_bp' => 0]);
        $this->assertContains('margin_invalid', $this->reasonsFor($m->fresh('provider'))[1]);
    }

    public function test_model_margin_overrides_global(): void
    {
        $m = $this->model();
        $m->update(['margin_bp' => 1000]);

        $q = $this->pricing()->quote($m->fresh('provider'));
        $this->assertSame(1000, $q->marginBp);
        $this->assertSame('model', $q->marginSource);
        $this->assertSame(27_324, $q->pIn);        // ⌈230000 · 1e5 · 10800 · 11000 / 1e14⌉
    }

    /* ═══ فروختنی‌بودن (D13) ═══ */

    public function test_null_fee_makes_the_provider_unsellable(): void
    {
        $this->provider->update(['fx_fee_bp' => null]);

        $this->assertContains('fee_unset', $this->reasonsFor($this->model())[1]);
    }

    public function test_zero_fee_is_an_explicit_and_valid_claim(): void
    {
        $this->provider->update(['fx_fee_bp' => 0]);

        $this->assertSame(0, $this->pricing()->quote($this->model())->feeBp);
    }

    public function test_provider_or_global_rows_alone_are_refused(): void
    {
        $m = AiModel::create([
            'ai_provider_id' => $this->provider->id, 'slug' => 'acme/no-own-rows',
            'upstream_model' => 'x', 'name' => 'X', 'category' => 'chat', 'status' => 'active',
        ]);

        foreach (['input', 'output'] as $unit) {
            AiModelUnitPrice::create([
                'ai_provider_id' => $this->provider->id, 'ai_model_id' => null, 'unit' => $unit,
                'billing_unit' => '1m_tokens', 'price_micro_units' => 1, 'currency_code' => 'USD',
                'version' => 1, 'active' => true, 'effective_from' => now(),
            ]);
            AiModelUnitPrice::create([
                'ai_provider_id' => null, 'ai_model_id' => null, 'unit' => $unit,
                'billing_unit' => '1m_tokens', 'price_micro_units' => 1, 'currency_code' => 'USD',
                'version' => 1, 'active' => true, 'effective_from' => now(),
            ]);
        }

        [, $reasons] = $this->reasonsFor($m->fresh('provider'));
        $this->assertContains('no_input_price', $reasons);
        $this->assertContains('no_output_price', $reasons);
    }

    public function test_row_currency_different_from_provider_currency_is_refused(): void
    {
        $this->assertContains('currency_mismatch', $this->reasonsFor($this->model(currency: 'EUR'))[1]);
    }

    public function test_cached_above_input_is_refused(): void
    {
        $m = $this->model(['input' => 100_000, 'cached_input' => 200_000, 'output' => 400_000]);

        $this->assertContains('cached_above_input', $this->reasonsFor($m)[1]);
    }

    public function test_rate_above_cap_is_refused(): void
    {
        $m = $this->model(['input' => PriceBook::MAX_SELL_RATE_MICROS + 1, 'output' => 400_000]);

        $this->assertContains('rate_out_of_range', $this->reasonsFor($m)[1]);
    }

    public function test_no_usable_rate_is_fx_unavailable(): void
    {
        Setting::put('pricing_usd_rate_override', null);

        [$code] = $this->reasonsFor($this->model());
        $this->assertSame(AiPricingException::FX_UNAVAILABLE, $code);
    }

    public function test_sale_gates_list_every_closed_flag(): void
    {
        $this->provider->update(['commercial_enabled' => false, 'agreement_status' => 'none']);

        $gates = $this->pricing()->saleGates($this->model());
        $this->assertContains('provider_not_commercial', $gates);
        $this->assertContains('agreement_unsigned', $gates);
        $this->assertContains('sales_closed', $gates);

        Setting::put('ai_sales_open', '1');
        $this->assertNotContains('sales_closed', $this->pricing()->saleGates($this->model()));
    }
}
