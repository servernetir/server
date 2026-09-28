<?php

namespace Tests\Feature;

use App\Models\AiReservation;
use App\Models\AiUsage;
use App\Models\CreditEntry;
use App\Services\Ai\AiSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

/**
 * آشتی‌دهنده و دو سازوکارِ مستقلش برای اینکه پولِ کسی گیر نماند (m5-spec §4.D، MS-2):
 * جاروبِ درون‌خطی (بی کرون) و پشتوانهٔ ۲۴ ساعتهٔ `scopeHolding`.
 */
class AiReconcilerTest extends TestCase
{
    use AiGatewayFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sellableCatalog();
        $this->fakeUpstream();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** یک ردیفِ مصرف در وضعیتِ دلخواه، از مسیرِ واقعیِ رزرو */
    private function openRow(string $plain, string $status): AiUsage
    {
        $this->upstreamMode = 'timeout';
        $this->v1($plain, $this->chatBody())->assertStatus(504);
        $this->upstreamMode = 'ok';

        $u = AiUsage::latest('id')->first();
        $u->update(['status' => $status]);

        return $u->fresh();
    }

    public function test_reserved_past_decide_by_is_released_and_can_never_be_sent_later(): void
    {
        [$c, $plain] = $this->greenKey();
        $u = $this->openRow($plain, AiUsage::STATUS_RESERVED);
        $u->update(['decide_by' => now()->subMinute()]);

        $this->artisan('ai:reconcile')->assertSuccessful();

        $this->assertSame(AiUsage::STATUS_RELEASED, $u->fresh()->status);
        $this->assertSame(1_000_000, $this->available($c));
        // B1: فرستنده‌ای که دیر رسید نمی‌تواند بفرستد
        $this->assertFalse(app(AiSettlement::class)->markSending($u->fresh()));
    }

    public function test_sending_past_decide_by_becomes_unknown_with_the_hold_kept(): void
    {
        [$c, $plain] = $this->greenKey();
        $u = $this->openRow($plain, AiUsage::STATUS_SENDING);
        $u->update(['decide_by' => now()->subMinute()]);

        $this->artisan('ai:reconcile')->assertSuccessful();

        $this->assertSame(AiUsage::STATUS_UNKNOWN_PENDING, $u->fresh()->status);
        $this->assertSame(1_000_000 - (int) $u->hold_irt, $this->available($c));
    }

    public function test_settle_pending_is_finished_once_from_the_persisted_numbers(): void
    {
        [$c, $plain] = $this->greenKey();
        $u = $this->openRow($plain, AiUsage::STATUS_SETTLE_PENDING);
        $u->update(['sell_irt' => 100, 'tax_irt' => 10, 'charged_irt' => 110, 'cost_irt' => 80, 'usage_source' => 'provider']);

        $this->artisan('ai:reconcile')->assertSuccessful();
        $this->artisan('ai:reconcile')->assertSuccessful();

        $this->assertSame(AiUsage::STATUS_SETTLED, $u->fresh()->status);
        $this->assertSame(1, CreditEntry::where('reason', 'ai_usage')->count());
        $this->assertSame(1_000_000 - 110, $this->balance($c));
        $this->assertSame(1_000_000 - 110, $this->available($c));
    }

    public function test_legacy_micro_reservations_are_only_expired_never_settled(): void
    {
        [$c] = $this->greenKey();
        $legacy = AiReservation::create([
            'customer_id' => $c->id, 'currency_code' => 'IRT', 'amount_irt' => 43, 'status' => 'pending',
            'purpose' => 'ai', 'expires_at' => now()->subMinute(), 'pricing_version' => 0,
        ]);

        $this->artisan('ai:reconcile')->assertSuccessful();

        $this->assertSame(AiReservation::STATUS_EXPIRED, $legacy->fresh()->status);
        $this->assertSame(0, CreditEntry::where('reason', 'like', 'ai_%')->count());
    }

    public function test_inline_sweep_frees_a_stale_hold_with_no_scheduler_at_all(): void
    {
        [$c, $plain] = $this->greenKey();
        $stale = $this->openRow($plain, AiUsage::STATUS_RESERVED);
        $stale->update(['decide_by' => now()->subMinute()]);

        $this->v1($plain, $this->bigBody())->assertOk();

        $this->assertSame(AiUsage::STATUS_RELEASED, $stale->fresh()->status, 'درخواستِ بعدیِ همین مشتری جارو کرد');
    }

    public function test_m5_hold_counts_until_the_24h_backstop_then_frees(): void
    {
        [$c, $plain] = $this->greenKey();
        $u = $this->openRow($plain, AiUsage::STATUS_UNKNOWN_PENDING);
        $held = 1_000_000 - (int) $u->hold_irt;

        Carbon::setTestNow(now()->addHour());
        $this->assertSame($held, $this->available($c), 'پس از یک ساعت هنوز نگه داشته است (بی‌مهلت)');

        Carbon::setTestNow(now()->addHours(24));
        $this->assertSame(1_000_000, $this->available($c), 'پس از ۲۴ ساعت پشتوانه آزادش می‌کند');
    }
}
