<?php

namespace Tests\Feature;

use App\Models\AiCall;
use App\Models\AiUsage;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

class AiInputOperationsTest extends TestCase
{
    use RefreshDatabase, AiGatewayFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sellableCatalog(['fx_fee_bp' => 0], ['category' => 'embedding'], false);
        Setting::put('ai_margin_pct', '5');
        $this->model->unitPrices()->where('unit', 'output')->delete();
    }

    private function requestEmbedding(string $key, array $extra = [], array $headers = [])
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$key] + $headers)->postJson('/v1/embeddings',
            $extra + ['model' => $this->model->slug, 'input' => ['Hello', 'World']]);
    }

    public function test_embeddings_are_input_only_settled_and_replayed_once(): void
    {
        [$customer, $key] = $this->greenKey(tokenOver: ['abilities' => ['ai:embedding']]);
        Http::fake(['*/embeddings' => Http::response(['object' => 'list', 'model' => 'private/model',
            'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2]]],
            'usage' => ['prompt_tokens' => 6, 'total_tokens' => 6, 'estimated_cost' => '0.00000138']])]);
        $one = $this->requestEmbedding($key, headers: ['Idempotency-Key' => 'embedding-once'])->assertOk();
        $usage = AiUsage::sole();
        $this->assertSame('embedding', $usage->operation);
        $this->assertSame('settled', $usage->status);
        $this->assertNull($usage->output_price_id);
        $this->assertSame(0, (int) $usage->completion_tokens);
        $this->assertSame(500, (int) $usage->margin_bp);
        $this->assertSame(1_000_000 - (int) $usage->charged_irt, $this->balance($customer));
        $this->assertGreaterThanOrEqual((int) $usage->cost_irt, (int) $usage->sell_irt);
        $two = $this->requestEmbedding($key, headers: ['Idempotency-Key' => 'embedding-once'])
            ->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($one->json(), $two->json());
        $this->assertSame(1, AiUsage::count());
        $this->assertSame(1, AiCall::count());
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => ! isset($r['max_tokens']) && ! isset($r['stream']));
    }

    public function test_chat_key_cannot_spend_on_embeddings(): void
    {
        [, $key] = $this->greenKey();
        Http::fake();
        $this->requestEmbedding($key)->assertStatus(403)->assertJsonPath('code', 'insufficient_scope');
        Http::assertNothingSent();
        $this->assertSame(0, AiUsage::count());
    }

    public function test_media_token_arrays_and_streams_are_rejected_before_money(): void
    {
        [, $key] = $this->greenKey(tokenOver: ['abilities' => ['ai:embedding']]);
        Http::fake();
        foreach ([['input' => [1, 2]], ['input' => [['type' => 'image_url', 'url' => 'https://example.invalid']]],
            ['input' => []], ['input' => array_fill(0, 129, 'hello')], ['stream' => true], ['encoding_format' => 'base64']] as $invalid) {
            $this->requestEmbedding($key, $invalid)->assertStatus(400);
        }
        Http::assertNothingSent();
        $this->assertSame(0, AiUsage::count());
    }

    public function test_missing_usage_holds_funds_and_does_not_invent_zero_cost(): void
    {
        [$customer, $key] = $this->greenKey(tokenOver: ['abilities' => ['ai:embedding']]);
        Http::fake(['*/embeddings' => Http::response(['data' => []])]);
        $this->requestEmbedding($key)->assertStatus(502)->assertHeader('x-should-retry', 'false');
        $this->assertSame('unknown_pending', AiUsage::sole()->status);
        $this->assertSame(1_000_000, $this->balance($customer));
        $this->assertLessThan(1_000_000, $this->available($customer));
    }

    public function test_input_only_budget_and_wallet_fail_before_provider_call(): void
    {
        [, $key] = $this->greenKey(0, tokenOver: ['abilities' => ['ai:embedding']]);
        Http::fake();
        $this->requestEmbedding($key)->assertStatus(402);
        [, $budgetKey] = $this->greenKey(projectOver: ['monthly_budget_irt' => 1, 'budget_period' => 'monthly'], tokenOver: ['abilities' => ['ai:embedding']]);
        $this->requestEmbedding($budgetKey, ['input' => str_repeat('x', 50000)])->assertStatus(402)->assertJsonPath('code', 'budget_exceeded');
        Http::assertNothingSent();
        $this->assertSame(0, AiUsage::count());
    }

    public function test_rerank_uses_native_cost_and_never_generates_output_tokens(): void
    {
        $this->model->update(['category' => 'rerank', 'upstream_model' => 'Qwen/Qwen3-Reranker-0.6B']);
        [, $key] = $this->greenKey(tokenOver: ['abilities' => ['ai:rerank']]);
        Http::fake(['*/inference/*' => Http::response('{"scores":[0.8],"input_tokens":80,"request_id":"private-id","inference_status":{"status":"succeeded","cost":0.0000184}}')]);
        $this->withHeaders(['Authorization' => 'Bearer '.$key])->postJson('/v1/rerank', [
            'model' => $this->model->slug, 'queries' => ['capital?'], 'documents' => ['Tehran'],
        ])->assertOk()->assertJsonPath('scores.0', 0.8);
        $usage = AiUsage::sole();
        $this->assertSame('rerank', $usage->operation);
        $this->assertSame('settled', $usage->status);
        $this->assertSame(19, (int) $usage->provider_cost_micro);
        $this->assertSame('private-id', $usage->upstream_request_id);
        $this->assertSame(0, (int) $usage->completion_tokens);
        Http::assertSent(fn ($r) => $r['queries'] === ['capital?'] && ! isset($r['model']) && ! isset($r['max_tokens']));
    }

    public function test_rerank_rejects_nonparallel_arrays_and_media(): void
    {
        $this->model->update(['category' => 'rerank']);
        [, $key] = $this->greenKey(tokenOver: ['abilities' => ['ai:rerank']]);
        Http::fake();
        $this->withHeaders(['Authorization' => 'Bearer '.$key])->postJson('/v1/rerank', [
            'model' => $this->model->slug, 'queries' => ['a'], 'documents' => ['b', 'c'],
        ])->assertStatus(400);
        Http::assertNothingSent();
    }

    public function test_native_failure_or_wrong_cost_field_cannot_be_settled_as_success(): void
    {
        $parser = \App\Services\Ai\AiUsageParser::class;
        $failed = ['scores' => [0.8], 'input_tokens' => 80, 'inference_status' => ['status' => 'failed', 'cost' => 0]];
        $this->assertNull($parser::parse($failed, json_encode($failed), 'rerank'));
        $body = ['cost' => 99, 'scores' => [0.8], 'input_tokens' => 80,
            'inference_status' => ['status' => 'succeeded', 'cost' => '0.0000184']];
        $this->assertSame('0.0000184', $parser::parse($body, json_encode($body), 'rerank')['estimated_cost']);
        $this->assertNull($parser::parse(['data' => [], 'usage' => ['prompt_tokens' => 0]], '', 'embedding'));
        $live = ['scores' => [0.8], 'input_tokens' => 82, 'inference_status' => ['runtime_ms' => 83, 'cost' => '8.2e-7', 'tokens_input' => 82]];
        $this->assertSame('8.2e-7', $parser::parse($live, json_encode($live), 'rerank')['estimated_cost']);
        unset($live['inference_status']['cost']);
        $this->assertNull($parser::parse($live, json_encode($live), 'rerank'));
    }

    public function test_provider_daily_cap_counts_actual_cost_above_catalog_cost(): void
    {
        [, $key] = $this->greenKey(tokenOver: ['abilities' => ['ai:embedding']]);
        Http::fake(['*/embeddings' => Http::response(['data' => [['embedding' => [0.1]]],
            'usage' => ['prompt_tokens' => 6, 'estimated_cost' => '0.00000138']])]);
        $this->requestEmbedding($key)->assertOk();
        AiUsage::sole()->update(['cost_micro' => 1, 'provider_cost_micro' => 1000001]);
        $this->provider->update(['daily_cost_cap_micro' => 1000000]);
        $this->requestEmbedding($key)->assertStatus(503)->assertJsonPath('code', 'provider_daily_cap');
        Http::assertSentCount(1);
    }

    public function test_recovered_models_use_verified_reasoning_and_template_bounds(): void
    {
        $this->model->update(['category' => 'chat', 'upstream_model' => 'Qwen/Qwen3.8-Flash']);
        $body = ['messages' => [['role' => 'user', 'content' => 'Reply OK.']], 'max_tokens' => 16, 'reasoning_effort' => 'high'];
        $prepared = app(\App\Services\Ai\AiPayload::class)->prepare($body, $this->model);
        $this->assertSame('none', $prepared->body()['reasoning_effort']);
        foreach (['Qwen/Qwen3.7-Max', 'ByteDance/Seed-2.0-mini', 'ByteDance/Seed-1.8', 'ByteDance/Seed-2.0-code'] as $id) {
            $this->model->update(['upstream_model' => $id]);
            $this->assertSame('none', app(\App\Services\Ai\AiPayload::class)->prepare($body, $this->model)->body()['reasoning_effort']);
        }
        $this->model->update(['upstream_model' => 'nvidia/Nemotron-Content-Safety-3.5']);
        $prepared = app(\App\Services\Ai\AiPayload::class)->prepare($body, $this->model);
        $this->assertGreaterThanOrEqual(469, $prepared->maxInput);
        $this->assertArrayNotHasKey('reasoning_effort', $prepared->body());
    }

    public function test_gap_completion_limit_is_forwarded_and_settled_without_extra_debit(): void
    {
        $this->provider->update(['slug' => 'gapgpt']);
        Setting::put('ai_provider_gapgpt_base_url', 'https://api.gapgpt.app/v1');
        Setting::putSecret('ai_provider_gapgpt_key', 'test-only');
        $this->model->update(['category' => 'chat', 'upstream_model' => 'gpt-4-0613', 'context_tokens' => 4096]);
        app(\App\Services\Ai\PriceBook::class)->supersede($this->model, 'output', 400000, currencyCode: 'USD');
        [$customer, $key] = $this->greenKey();
        Http::fake(function ($r) {
            $this->assertSame(16, $r['max_completion_tokens']);
            $this->assertArrayNotHasKey('max_tokens', $r->data());
            return Http::response(['choices' => [['message' => ['content' => 'OK']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2]]);
        });
        foreach (['gpt-4-0613', 'gpt-4-turbo-2024-04-09'] as $id) {
            $this->model->update(['upstream_model' => $id]);
            $this->withHeaders(['Authorization' => 'Bearer '.$key])->postJson('/v1/chat/completions',
                ['model' => $this->model->slug, 'messages' => [['role' => 'user', 'content' => 'OK']], 'max_tokens' => 16])->assertOk();
        }
        $this->assertSame(2, AiUsage::where('status', 'settled')->count());
        $this->assertSame(1000000 - (int) AiUsage::sum('charged_irt'), $this->balance($customer));
        $this->model->update(['upstream_model' => 'grok-4']);
        $prepared = app(\App\Services\Ai\AiPayload::class)->prepare(['messages' => [['role' => 'user', 'content' => 'OK']],
            'max_tokens' => 16, 'reasoning_effort' => 'high'], $this->model);
        $this->assertSame('none', $prepared->body()['reasoning_effort']);
    }
}
