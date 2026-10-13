<?php

namespace Tests\Feature;

use App\Models\AiCall;
use App\Models\AiUsage;
use App\Models\CreditEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

class AiSupplierCostPrivacyTest extends TestCase
{
    use RefreshDatabase, AiGatewayFixture;

    public function test_supplier_wholesale_cost_is_hidden_from_live_response_and_replay_without_changing_usage(): void
    {
        $this->sellableCatalog();
        [$customer, $key] = $this->greenKey();
        $this->fakeUsage(extraUsage: ['cost' => 0.0000034692, 'cost_details' => [
            'upstream_inference_cost' => 0.0000034692, 'upstream_inference_prompt_cost' => 0.000000826,
            'upstream_inference_completions_cost' => 0.0000026432]]);
        $headers = ['Idempotency-Key' => 'wholesale-cost-private'];
        $first = $this->v1($key, $this->bigBody(), $headers)->assertOk()
            ->assertJsonMissingPath('usage.cost')->assertJsonMissingPath('usage.cost_details')
            ->assertJsonPath('usage.prompt_tokens', 12000)->assertJsonPath('usage.completion_tokens', 900);
        $after = $this->balance($customer);
        $second = $this->v1($key, $this->bigBody(), $headers)->assertOk()->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonMissingPath('usage.cost')->assertJsonMissingPath('usage.cost_details');
        $this->assertSame($first->json(), $second->json());
        $this->assertSame($after, $this->balance($customer));
        $this->assertSame(1, AiUsage::count()); $this->assertSame(1, CreditEntry::where('reason', 'ai_usage')->count());
        $stored = AiCall::sole()->response_encrypted['usage'];
        $this->assertArrayNotHasKey('cost', $stored); $this->assertArrayNotHasKey('cost_details', $stored);
        Http::assertSentCount(1);
    }
}
