<?php

namespace Tests\Feature;

use App\Models\AiUsage;
use App\Models\CreditEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

/**
 * تماسِ نامعلوم (m5-spec §4.G؛ تصمیمِ مالک: «به بهای واقعی، تا سقفِ رزرو؛ هرگز جذب نکن»).
 *
 *   ۳ تلاشِ بازیابی بی‌نتیجه ⇒ شارژ تا سقف (`unknown_charged`)
 *   مصرفِ واقعی پیدا شد ⇒ تسویه به همان، نه سقف
 *   بعداً پیدا شد (`--late`) ⇒ بازپرداختِ خودکارِ اختلاف؛ هرگز برداشتِ دوم
 */
class AiUnknownRecoveryTest extends TestCase
{
    use AiGatewayFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sellableCatalog();
        $this->fakeUpstream();
    }

    /** یک تماسِ نامعلوم (مهلتِ خواندن پس از ارسال) */
    private function unknownCall(string $plain, array $headers = []): AiUsage
    {
        $this->upstreamMode = 'timeout';
        $this->v1($plain, $this->bigBody(), $headers)->assertStatus(504);
        $this->upstreamMode = 'ok';

        return AiUsage::latest('id')->first();
    }

    private function capIt(): void
    {
        foreach (range(1, 3) as $_) {
            $this->artisan('ai:recover-usage')->assertSuccessful();
        }
    }

    public function test_three_fruitless_probes_then_cap_is_charged(): void
    {
        [$c, $plain] = $this->greenKey();
        $u = $this->unknownCall($plain);

        $this->artisan('ai:recover-usage')->assertSuccessful();
        $this->artisan('ai:recover-usage')->assertSuccessful();
        $this->assertSame(AiUsage::STATUS_UNKNOWN_PENDING, $u->fresh()->status, 'پیش از سومین تلاش سقف نمی‌خورد');
        $this->artisan('ai:recover-usage')->assertSuccessful();

        $u->refresh();
        $this->assertSame(AiUsage::STATUS_UNKNOWN_CHARGED, $u->status);
        $this->assertSame((int) $u->hold_irt, (int) $u->charged_irt);
        $this->assertSame('unknown', $u->review_reason);
        $this->assertSame(1_000_000 - (int) $u->hold_irt, $this->balance($c));
        $this->assertGreaterThanOrEqual((int) $u->cost_irt, (int) $u->sell_irt, 'حتی سقف هم زیرِ بهای بدترین حالت نیست');
    }

    public function test_usage_found_by_lookup_settles_on_real_usage_not_the_cap(): void
    {
        [$c, $plain] = $this->greenKey();
        $u = $this->unknownCall($plain);
        $u->update(['upstream_request_id' => 'req-42']);
        $this->provider->update(['usage_lookup_url' => 'https://api.deepinfra.com/usage/{id}']);
        $this->lookupUsage = $this->workedUsage();

        $this->artisan('ai:recover-usage')->assertSuccessful();

        $u->refresh();
        $this->assertSame(AiUsage::STATUS_SETTLED, $u->status);
        $this->assertSame('recovered', $u->usage_source);
        $this->assertSame(327, (int) $u->charged_irt);
        $this->assertSame(1_000_000 - 327, $this->balance($c));
    }

    public function test_late_recovery_refunds_the_difference_once_and_never_debits_more(): void
    {
        [$c, $plain] = $this->greenKey();
        $u = $this->unknownCall($plain);
        $this->capIt();
        $u->refresh();
        $hold = (int) $u->hold_irt;
        $this->assertSame(AiUsage::STATUS_UNKNOWN_CHARGED, $u->status);

        $u->update(['upstream_request_id' => 'req-7']);
        $this->provider->update(['usage_lookup_url' => 'https://api.deepinfra.com/usage/{id}']);
        $this->lookupUsage = $this->workedUsage();

        $this->artisan('ai:recover-usage --late')->assertSuccessful();
        $this->artisan('ai:recover-usage --late')->assertSuccessful();   // بار دوم بی‌اثر

        $u->refresh();
        $this->assertSame($hold - 327, (int) $u->refunded_irt);
        $this->assertSame('recovered_refund', $u->review_reason);
        $this->assertSame(1, CreditEntry::where('reason', 'ai_refund')->count());
        $this->assertSame(1, CreditEntry::where('reason', 'ai_usage')->count(), 'هرگز برداشتِ دوم');
        $this->assertSame(1_000_000 - 327, $this->balance($c), 'خالصِ شارژ = مصرفِ واقعی');
    }

    public function test_retry_with_the_same_key_after_a_cap_charge_is_409(): void
    {
        [$c, $plain] = $this->greenKey();
        $this->unknownCall($plain, ['Idempotency-Key' => 'k-u']);
        $this->capIt();

        $this->v1($plain, $this->bigBody(), ['Idempotency-Key' => 'k-u'])
            ->assertStatus(409)->assertJsonPath('code', 'upstream_outcome_unknown')->assertHeader('x-should-retry', 'false');
    }
}
