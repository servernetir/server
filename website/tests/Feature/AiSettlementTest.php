<?php

namespace Tests\Feature;

use App\Models\AiModelUnitPrice;
use App\Models\AiUsage;
use App\Models\CreditEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

/**
 * تسویه از مصرفِ واقعی (m5-spec §4.C) — کش‌شده، مصرفِ صفر، بیرون از سقف، کیفِ خالی،
 * رانشِ بهای ارائه‌دهنده، و خطِ قرمزِ «هرگز زیرِ بها».
 */
class AiSettlementTest extends TestCase
{
    use AiGatewayFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sellableCatalog();
    }

    public function test_missing_cached_row_bills_cached_tokens_at_full_input_price(): void
    {
        AiModelUnitPrice::where('ai_model_id', $this->model->id)->where('unit', 'cached_input')->delete();
        [$c, $plain] = $this->greenKey();
        $this->fakeUsage(12_000, 900, 8_000);

        $this->v1($plain, $this->bigBody())->assertOk();

        // همهٔ ۱۲٬۰۰۰ ورودی به ۳۱٬۰۵۰: ⌈(12000·31050 + 900·54000)/1e6⌉ = ⌈421.2⌉ = 422 ؛ مالیات ۴۳
        $u = AiUsage::sole();
        $this->assertSame([422, 43, 465], [(int) $u->sell_irt, (int) $u->tax_irt, (int) $u->charged_irt]);
        $this->assertGreaterThanOrEqual((int) $u->cost_irt, (int) $u->sell_irt);
    }

    public function test_reasoning_tokens_are_recorded_but_never_charged_twice(): void
    {
        [$c, $plain] = $this->greenKey();
        $this->fakeUsage(12_000, 900, 8_000, ['completion_tokens_details' => ['reasoning_tokens' => 600]]);

        $this->v1($plain, $this->bigBody())->assertOk();

        $u = AiUsage::sole();
        $this->assertSame(600, (int) $u->reasoning_tokens);
        $this->assertSame(327, (int) $u->charged_irt, 'استدلال داخلِ completion است، نه اضافه بر آن');
    }

    public function test_zero_usage_charges_nothing_and_writes_no_ledger_row(): void
    {
        [$c, $plain] = $this->greenKey();
        $this->fakeUsage(0, 0, null);

        $this->v1($plain, $this->chatBody())->assertOk();

        $u = AiUsage::sole();
        $this->assertSame(AiUsage::STATUS_SETTLED, $u->status);
        $this->assertSame(0, (int) $u->charged_irt);
        $this->assertSame(0, CreditEntry::where('reason', 'like', 'ai_%')->count());
        $this->assertSame(1_000_000, $this->available($c));
    }

    public function test_usage_beyond_the_bound_charges_the_overage_and_suspends_the_model(): void
    {
        [$c, $plain] = $this->greenKey();
        $this->fakeUsage(12_000, 900, 8_000);   // ۱۲٬۰۰۰ توکن با بدنهٔ چندبایتی ⇒ بیرون از سقف

        $this->v1($plain, $this->chatBody())->assertOk();

        $u = AiUsage::sole();
        $this->assertSame('over_bound', $u->review_reason);
        $this->assertGreaterThan((int) $u->hold_irt, (int) $u->charged_irt);

        // شارژ سقف نخورده: پایه از رزرو + مازاد برداشتِ دوم
        $rows = CreditEntry::where('customer_id', $c->id)->where('reason', 'like', 'ai_%')->pluck('amount', 'reason');
        $this->assertSame(-(int) $u->hold_irt, (int) $rows['ai_usage']);
        $this->assertSame(-((int) $u->charged_irt - (int) $u->hold_irt), (int) $rows['ai_usage_overage']);
        $this->assertSame(0, (int) $u->uncollected_irt);
        $this->assertSame(1_000_000 - 327, $this->balance($c));
        $this->assertNotNull($this->model->fresh()->suspended_at, 'مدل باید خودکار معلق شود');
    }

    public function test_overage_on_an_empty_wallet_is_uncollected_never_a_negative_balance(): void
    {
        [$c, $plain] = $this->greenKey(290);    // فقط کمی بیش از سقفِ رزروِ این بدنهٔ کوچک
        $this->fakeUsage(12_000, 900, 8_000);

        $this->v1($plain, $this->chatBody(['max_tokens' => 4096]))->assertOk();

        $u = AiUsage::sole();
        $this->assertSame(327, (int) $u->charged_irt);
        $this->assertSame(0, $this->balance($c), 'کیفِ مشترک هرگز منفی نمی‌شود');
        $this->assertSame(327 - 290, (int) $u->uncollected_irt);
    }

    public function test_provider_cost_above_book_raises_the_floor_and_flags_drift(): void
    {
        [$c, $plain] = $this->greenKey();
        // دفترِ ما ۲۲۰۰ میکرو؛ ارائه‌دهنده ۰٫۰۰۲۳ دلار (۴٫۵٪ بیشتر — زیرِ حاشیهٔ امنِ ۱۰٪ ِ رزرو)
        $this->fakeUsage(12_000, 900, 8_000, ['estimated_cost' => 0.0023]);

        $this->v1($plain, $this->bigBody())->assertOk();

        $u = AiUsage::sole();
        $this->assertSame(2_300, (int) $u->provider_cost_micro);
        $this->assertSame('cost_drift', $u->review_reason);
        $this->assertSame(311, (int) $u->sell_irt);         // کفِ ضدِ ضرر روی بهای گزارش‌شده، نه ۲۹۷
        $this->assertSame(343, (int) $u->charged_irt);
        $this->assertSame(249, (int) $u->cost_irt);
        $this->assertNull($this->model->fresh()->suspended_at, 'رانشِ بها فقط هشدار است، نه تعلیق');
    }

    public function test_english_customer_without_country_still_pays_vat(): void
    {
        [$c, $plain] = $this->greenKey(1_000_000, 'en');
        $this->fakeUsage(12_000, 900, 8_000);

        $this->v1($plain, $this->bigBody())->assertOk();

        $u = AiUsage::sole();
        $this->assertSame(['no_country', 1000, 30], [$u->vat_basis, (int) $u->vat_bp, (int) $u->tax_irt]);
    }

    public function test_every_charge_passes_ai_explain(): void
    {
        [, $plain] = $this->greenKey();
        $this->fakeUsage(12_000, 900, 8_000);
        $this->v1($plain, $this->bigBody())->assertOk();

        $this->artisan('ai:explain', ['public_id' => AiUsage::sole()->public_id])
            ->expectsOutputToContain('PASS')->assertSuccessful();
    }
}
