<?php

namespace Tests\Feature;

use App\Models\AiUsage;
use App\Models\BusinessEntry;
use App\Services\Ai\AiSettlement;
use App\Services\ExchangeRate;
use App\Services\Finance\BusinessLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

class AiFinanceIntegrationTest extends TestCase
{
    use AiGatewayFixture, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sellableCatalog();
        // Current FX contract, without stale manual overrides or network calls.
        $this->mock(ExchangeRate::class, function ($mock) {
            $mock->shouldReceive('current')->andReturnUsing(fn ($currency = 'USD') => ['currency' => $currency, 'rate_toman' => 100000, 'at' => now()->toIso8601String()]);
            $mock->shouldReceive('toToman')->andReturn(100000);
        });
    }

    public function test_successful_ai_wallet_spend_posts_net_revenue_tax_and_cost_exactly_once(): void
    {
        [$c,$key] = $this->greenKey();
        $this->fakeUsage();
        $this->v1($key, $this->bigBody())->assertOk();
        $u = AiUsage::sole();
        $l = app(BusinessLedger::class);
        $l->recordAiUsage($u);
        $s = $l->summary();
        $this->assertSame((int) $u->sell_irt, $s['revenue']);
        $this->assertSame((int) $u->tax_irt, $s['tax_collected']);
        $this->assertSame((int) $u->cost_irt, $s['expense']);
        $this->assertSame(3, BusinessEntry::where('source_type', $u->getMorphClass())->count());
    }

    public function test_uncollected_ai_overage_is_not_claimed_as_collected_revenue(): void
    {
        [$c,$key] = $this->greenKey(290);
        $this->fakeUsage();
        $this->v1($key, $this->chatBody(['max_tokens' => 4096]))->assertOk();
        $u = AiUsage::sole();
        $s = app(BusinessLedger::class)->summary();
        $this->assertSame(290, $s['revenue'] + $s['tax_collected']);
        $this->assertGreaterThan(0, (int) $u->uncollected_irt);
        $this->assertSame((int) $u->cost_irt, $s['expense']);
    }

    public function test_recovered_ai_refund_corrects_profit_and_vat_without_new_wallet_debits(): void
    {
        [$c,$key] = $this->greenKey();
        $this->fakeUsage();
        $this->v1($key, $this->bigBody())->assertOk();
        $u = AiUsage::sole();
        $u->forceFill(['status' => AiUsage::STATUS_UNKNOWN_CHARGED, 'usage_source' => 'cap'])->save();
        $refund = app(AiSettlement::class)->refundDown($u, ['prompt' => 100, 'completion' => 20, 'cached' => 0, 'reasoning' => null, 'estimated_cost' => null]);
        $this->assertGreaterThan(0, $refund);
        $u = $u->fresh();
        $s = app(BusinessLedger::class)->summary();
        $this->assertSame((int) $u->sell_irt, $s['revenue']);
        $this->assertSame((int) $u->tax_irt, $s['tax_collected']);
        $this->assertSame((int) $u->cost_irt, $s['expense']);
        $this->assertSame(1_000_000 - ((int) $u->charged_irt - (int) $u->refunded_irt), $this->balance($c));
    }
}
