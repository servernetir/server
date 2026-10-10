<?php

namespace Tests\Feature;

use App\Models\AiUsage;
use App\Models\CreditEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

class AiProviderCostRecoveryTest extends TestCase
{
    use RefreshDatabase, AiGatewayFixture;

    private function pending(string $key): AiUsage
    {
        $this->v1($key, $this->chatBody(['max_tokens' => 16]))->assertStatus(502);
        $u = AiUsage::sole();
        // A successful JSON without usage creates a retained, unknown reservation.
        $this->assertSame('unknown_pending', $u->status);
        return $u;
    }

    private function setupCostResponse(array $item): array
    {
        $this->sellableCatalog();
        [$c, $key] = $this->greenKey();
        $this->provider->update(['usage_lookup_url' => 'https://api.deepinfra.com/v1/request-costs']);
        Http::fake([
            '*/chat/completions' => Http::response(['id' => 'req-42', 'choices' => [['message' => ['content' => 'OK']]]]),
            'https://api.deepinfra.com/v1/request-costs' => Http::response(['requests' => [$item]]),
        ]);
        $u = $this->pending($key);
        $this->assertSame('req-42', $u->upstream_request_id);
        return [$c, $u];
    }

    public function test_exact_nano_cost_settles_once_without_fabricated_token_counts(): void
    {
        [$c, $u] = $this->setupCostResponse(['requestId' => 'req-42', 'costNanoUsd' => 18400]);
        $this->artisan('ai:recover-usage')->assertSuccessful();
        $this->artisan('ai:recover-usage')->assertSuccessful();
        $u->refresh();
        $this->assertSame('settled', $u->status);
        $this->assertSame('cost_lookup', $u->usage_source);
        $this->assertSame(19, (int) $u->provider_cost_micro);
        $this->assertSame(19, (int) $u->cost_micro);
        $this->assertNull($u->prompt_tokens);
        $this->assertNull($u->completion_tokens);
        $this->assertNull($u->cached_tokens);
        $this->assertSame(1, CreditEntry::where('reason', 'ai_usage')->count());
        $this->assertSame(1_000_000 - (int) $u->charged_irt, $this->balance($c));
        $this->artisan('ai:explain '.$u->public_id)->expectsOutputToContain('PASS')->assertSuccessful();
        Http::assertSent(fn ($r) => $r->url() === 'https://api.deepinfra.com/v1/request-costs'
            && $r->method() === 'POST' && $r['requestIds'] === ['req-42']);
    }

    public function test_wrong_id_or_non_integer_cost_never_releases_the_reservation(): void
    {
        [, $u] = $this->setupCostResponse(['requestId' => 'another-request', 'costNanoUsd' => 0]);
        $this->artisan('ai:recover-usage')->assertSuccessful();
        $this->assertSame('unknown_pending', $u->fresh()->status);
        $this->assertSame(0, CreditEntry::where('reason', 'ai_usage')->count());
    }

    public function test_known_zero_cost_releases_money_after_matched_lookup(): void
    {
        [$c, $u] = $this->setupCostResponse(['requestId' => 'req-42', 'costNanoUsd' => 0]);
        $this->artisan('ai:recover-usage')->assertSuccessful();
        $this->assertSame('settled', $u->fresh()->status);
        $this->assertSame(0, (int) $u->fresh()->charged_irt);
        $this->assertSame(1_000_000, $this->available($c));
        $this->assertNull($u->fresh()->prompt_tokens);
    }

    public function test_late_cost_lookup_refunds_once_and_explains_the_net_charge(): void
    {
        [$c, $u] = $this->setupCostResponse(['requestId' => 'req-42', 'costNanoUsd' => 18400]);
        app(\App\Services\Ai\AiSettlement::class)->settleCap($u);
        $hold = (int) $u->fresh()->charged_irt;
        $this->artisan('ai:recover-usage --late')->assertSuccessful();
        $this->artisan('ai:recover-usage --late')->assertSuccessful();
        $u->refresh();
        $this->assertGreaterThan(0, (int) $u->refunded_irt);
        $this->assertSame($hold - (int) $u->refunded_irt, (int) $u->sell_irt + (int) $u->tax_irt);
        $this->assertSame(1, CreditEntry::where('reason', 'ai_refund')->count());
        $this->assertSame(1_000_000 - (int) $u->sell_irt - (int) $u->tax_irt, $this->balance($c));
        $this->artisan('ai:explain '.$u->public_id)->expectsOutputToContain('PASS')->assertSuccessful();
    }
}
