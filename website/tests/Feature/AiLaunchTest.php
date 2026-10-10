<?php

namespace Tests\Feature;

use App\Models\AiCall;
use App\Models\AiModel;
use App\Models\AiModelUnitPrice;
use App\Models\AiUsage;
use App\Models\CustomerApiToken;
use App\Services\Ai\DeepInfraCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

class AiLaunchTest extends TestCase
{
    use RefreshDatabase, AiGatewayFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sellableCatalog();
    }

    private function fakeStream(bool $usage = true, bool $done = true): void
    {
        $events = [['id' => 'private-upstream-id', 'model' => 'private-upstream-model',
            'object' => 'chat.completion.chunk', 'choices' => [['index' => 0, 'delta' => ['content' => 'hello'], 'finish_reason' => null]]]];
        if ($usage) {
            $events[] = ['id' => 'private-upstream-id', 'model' => 'private-upstream-model', 'object' => 'chat.completion.chunk',
                'choices' => [], 'usage' => ['prompt_tokens' => 12000, 'completion_tokens' => 900,
                    'prompt_tokens_details' => ['cached_tokens' => 8000], 'estimated_cost' => '0.001']];
        }
        $body = implode('', array_map(fn ($x) => 'data: '.json_encode($x)."\r\n\r\n", $events)).($done ? "data: [DONE]\n\n" : '');
        Http::fake(['*/chat/completions' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);
    }

    public function test_stream_is_settled_and_replayed_once_with_encrypted_response(): void
    {
        [$customer, $plain] = $this->greenKey();
        $this->fakeStream();
        $body = $this->bigBody(['stream' => true]);
        $first = $this->v1($plain, $body, ['Idempotency-Key' => 'stream-1'])->assertOk();
        $output = $first->streamedContent();
        $this->assertStringContainsString('data: [DONE]', $output);
        $this->assertStringContainsString('"servernet_charge_irt":327', $output);
        $this->assertStringNotContainsString('private-upstream', $output);
        $this->assertStringNotContainsString('estimated_cost', $output);
        $this->assertSame(999673, $this->balance($customer));
        $this->assertSame(AiUsage::STATUS_SETTLED, AiUsage::sole()->status);
        $this->assertNull(AiCall::sole()->getRawOriginal('response_body'));
        $this->assertStringNotContainsString('hello', AiCall::sole()->getRawOriginal('response_encrypted'));
        $second = $this->v1($plain, $body, ['Idempotency-Key' => 'stream-1'])->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($output, $second->streamedContent());
        $this->assertSame(999673, $this->balance($customer));
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['stream_options']['include_usage'] === true && $r['max_tokens'] === 4096);
    }

    public function test_stream_without_usage_keeps_hold_for_reconciliation(): void
    {
        [$customer, $plain] = $this->greenKey();
        $this->fakeStream(false);
        $output = $this->v1($plain, $this->bigBody(['stream' => true]))->assertOk()->streamedContent();
        $this->assertStringContainsString('stream_incomplete', $output);
        $this->assertSame(AiUsage::STATUS_UNKNOWN_PENDING, AiUsage::sole()->status);
        $this->assertSame(1000000, $this->balance($customer));
        $this->assertLessThan(1000000, $this->available($customer));
    }

    public function test_stream_truncated_after_usage_does_not_claim_success(): void
    {
        [, $plain] = $this->greenKey();
        $this->fakeStream(true, false);
        $output = $this->v1($plain, $this->bigBody(['stream' => true]))->assertOk()->streamedContent();
        $this->assertStringContainsString('stream_incomplete', $output);
        $this->assertSame(AiUsage::STATUS_UNKNOWN_PENDING, AiUsage::sole()->status);
    }

    public function test_api_errors_have_openai_envelope_and_models_use_explicit_scope(): void
    {
        $this->postJson('/v1/chat/completions', [])->assertStatus(401)->assertJsonPath('error.code', 'invalid_token')->assertHeader('Cache-Control', 'no-store, private');
        [, $plain, $token] = $this->greenKey();
        $this->getJson('/v1/models', ['Authorization' => 'Bearer '.$plain])->assertStatus(403);
        $token->update(['abilities' => ['ai:chat', 'ai:models:read']]);
        $this->getJson('/v1/models', ['Authorization' => 'Bearer '.$plain])->assertOk()->assertJsonPath('data.0.id', $this->model->slug);
        $this->provider->update(['live_calls_enabled' => false]);
        $this->getJson('/v1/models', ['Authorization' => 'Bearer '.$plain])->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_customer_cannot_use_another_customers_project(): void
    {
        [, $plain, $token] = $this->greenKey();
        [, , , $otherProject] = $this->greenKey();
        $token->update(['ai_project_id' => $otherProject->id]);
        Http::fake();
        $this->v1($plain, $this->chatBody())->assertStatus(403)->assertJsonPath('code', 'project_missing');
        Http::assertNothingSent();
    }

    public function test_catalogue_converts_cents_versions_prices_and_rejects_stale_models(): void
    {
        $rows = [];
        for ($i=0; $i<20; $i++) {
            $rows[] = ['model_name' => 'vendor/test-'.$i, 'type' => 'text-generation', 'tags' => ['openai'],
                'max_tokens' => 32768, 'pricing' => ['type' => 'tokens', 'cents_per_input_token' => 0.000023,
                    'cents_per_output_token' => 0.00004]];
        }
        $rows[] = ['model_name' => 'vendor/video', 'type' => 'text-to-video'];
        $rows[] = ['model_name' => 'vendor/retired', 'type' => 'text-generation', 'deprecated' => 1, 'tags' => ['openai'], 'pricing' => $rows[0]['pricing']];
        $rows[] = ['model_name' => 'vendor/tiered', 'type' => 'text-generation', 'tags' => ['openai'], 'pricing' => $rows[0]['pricing'] + ['full' => 'tiered pricing']];
        Http::fake([DeepInfraCatalog::URL => Http::sequence()->push($rows)->push($rows)]);
        $result = app(DeepInfraCatalog::class)->sync();
        $this->assertSame(23, $result['imported']);
        $this->assertSame(20, $result['chat_ready']);
        $model = AiModel::where('upstream_model', 'vendor/test-0')->sole();
        $this->assertSame(230000, $model->activePrice(AiModelUnitPrice::UNIT_INPUT)->price_micro_units);
        $count = AiModelUnitPrice::count();
        app(DeepInfraCatalog::class)->sync();
        $this->assertSame($count, AiModelUnitPrice::count());
        $this->assertSame('disabled', AiModel::where('upstream_model', 'vendor/video')->sole()->status);
        $this->assertSame('disabled', AiModel::where('upstream_model', 'vendor/tiered')->sole()->status);
        $this->assertTrue(DeepInfraCatalog::isFresh($model));
        $this->travel(7)->hours();
        $this->assertFalse(DeepInfraCatalog::isFresh($model));
    }

    public function test_storefront_renders_three_languages_and_private_usage_is_scoped(): void
    {
        foreach (['', '/en', '/tr'] as $locale) {
            $this->get($locale.'/ai')->assertOk()->assertSee('FAQPage', false);
            $this->get($locale.'/ai/models')->assertOk()->assertSee($this->model->name);
            $this->get($locale.'/ai/models/'.$this->model->slug)->assertOk()->assertSee($this->model->name);
            $this->get($locale.'/ai/docs')->assertOk()->assertSee('max_retries=0');
        }
        [$customer, $plain] = $this->greenKey();
        [$other] = $this->greenKey();
        $this->fakeUsage(10, 5, null);
        $this->v1($plain, $this->chatBody())->assertOk();
        $id = AiUsage::sole()->public_id;
        $this->actingAs($customer, 'customer')->get('/account/ai')->assertOk()->assertSee($id);
        $this->actingAs($other, 'customer')->get('/account/ai')->assertOk()->assertDontSee($id);
    }

    public function test_expired_replay_payload_erased_without_deleting_audit(): void
    {
        [, $plain] = $this->greenKey();
        $this->fakeUsage(10, 5, null);
        $this->v1($plain, $this->chatBody(), ['Idempotency-Key' => 'erase-1'])->assertOk();
        $this->travel(25)->hours();
        $this->artisan('ai:prune-replay')->assertSuccessful();
        $this->assertNull(AiCall::sole()->response_encrypted);
        $this->assertSame(1, AiUsage::count());
    }
}
