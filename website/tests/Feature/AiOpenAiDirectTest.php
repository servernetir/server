<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiUsage;
use App\Models\Setting;
use App\Services\Ai\AdditionalProviderCatalog;
use App\Services\Ai\DeepInfraCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

class AiOpenAiDirectTest extends TestCase
{
    use AiGatewayFixture, RefreshDatabase;

    private const MODEL_URL = 'https://api.openai.com/v1/models';
    private const CHAT_URL = 'https://api.openai.com/v1/chat/completions';
    private const DOCS_URL = 'https://developers.openai.com/api/docs/models/gpt-5.4-mini.md';

    public function test_catalogue_discovers_a_known_model_and_keeps_new_models_off(): void
    {
        $provider = AiProvider::bySlug('openai');
        $this->assertNotNull($provider);
        $this->assertFalse($provider->enabled);
        $this->assertFalse($provider->commercial_enabled);
        $this->assertFalse($provider->resale_allowed);
        $this->assertSame(AiProvider::STATUS_NONE, $provider->agreement_status);
        $this->assertNull($provider->apiKey());

        $provider->update(['enabled' => true]);
        Setting::putSecret('ai_provider_openai_key', 'fixture-openai-key');
        Http::preventStrayRequests();
        Http::fake([
            self::MODEL_URL => Http::response(['data' => [['id' => 'gpt-5.4-mini'], ['id' => 'unpriced-model']]]),
            self::DOCS_URL => Http::response($this->modelDocument()),
        ]);

        $this->artisan('ai:sync-catalog', ['--provider' => 'openai'])->assertSuccessful();
        $model = $provider->models()->sole();
        $this->assertSame('GPT-5.4 mini', $model->name);
        $this->assertSame('disabled', $model->status);
        $this->assertSame(272_000, $model->context_tokens);
        $this->assertSame(750_000, $model->activePrice('input')->price_micro_units);
        $this->assertSame(75_000, $model->activePrice('cached_input')->price_micro_units);
        $this->assertSame(4_500_000, $model->activePrice('output')->price_micro_units);
        $this->assertTrue(DeepInfraCatalog::isFresh($model));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->url() === self::MODEL_URL && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer fixture-openai-key'));
        Http::assertSent(fn (Request $request) => $request->url() === self::DOCS_URL && ! $request->hasHeader('Authorization'));
    }

    public function test_bad_price_document_preserves_existing_price_and_unavailable_model_closes_it(): void
    {
        $provider = AiProvider::bySlug('openai');
        Setting::putSecret('ai_provider_openai_key', 'fixture-openai-key');
        Http::preventStrayRequests();
        Http::fake([
            self::MODEL_URL => Http::sequence()->push(['data' => [['id' => 'gpt-5.4-mini']]])
                ->push(['data' => [['id' => 'gpt-5.4-mini']]])->push(['data' => [['id' => 'unpriced-model']]]),
            self::DOCS_URL => Http::sequence()->push($this->modelDocument())
                ->push(str_replace('| 1M tokens |', '| 1K tokens |', $this->modelDocument())),
        ]);
        $catalogue = app(AdditionalProviderCatalog::class);
        $catalogue->sync('openai');
        $model = $provider->models()->sole();
        $model->update(['status' => 'active']);
        $oldPriceId = $model->activePrice('input')->id;
        try {
            $catalogue->sync('openai');
            $this->fail('Unexpected OpenAI pricing should stop the entire catalogue update.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Invalid or duplicate OpenAI token rate.', $error->getMessage());
        }
        $this->assertSame($oldPriceId, $model->activePrice('input')->id);
        $this->assertSame('active', $model->fresh()->status);

        $catalogue->sync('openai');
        $this->assertSame('disabled', $model->fresh()->status);
        $this->assertFalse(DeepInfraCatalog::isFresh($model->fresh()));
    }

    public function test_verified_direct_chat_sends_only_to_openai_and_settles_actual_usage(): void
    {
        $this->sellableCatalog();
        $provider = AiProvider::bySlug('openai');
        $provider->update(['enabled' => true, 'commercial_enabled' => true, 'live_calls_enabled' => true,
            'resale_allowed' => true, 'agreement_status' => AiProvider::STATUS_SIGNED, 'fx_fee_bp' => 0]);
        Setting::putSecret('ai_provider_openai_key', 'fixture-openai-key');
        Setting::put('ai_provider_openai_base_url', 'https://incorrect.example/v1');
        Http::preventStrayRequests();
        Http::fake([
            self::MODEL_URL => Http::response(['data' => [['id' => 'gpt-5.4-mini']]]),
            self::DOCS_URL => Http::response($this->modelDocument()),
            self::CHAT_URL => Http::response([
                'id' => 'chatcmpl-fixture', 'choices' => [['message' => ['role' => 'assistant', 'content' => 'OK']]],
                'usage' => ['prompt_tokens' => 14, 'completion_tokens' => 12,
                    'prompt_tokens_details' => ['cached_tokens' => 0], 'completion_tokens_details' => ['reasoning_tokens' => 4]],
            ]),
        ]);
        app(AdditionalProviderCatalog::class)->sync('openai');
        $model = $provider->models()->sole();
        $model->update(['status' => 'active']);
        [$customer, $key] = $this->greenKey(tokenOver: ['abilities' => ['ai:chat', 'ai:models:read']]);
        $this->withToken($key)->getJson('/v1/models?studio=1')->assertOk()
            ->assertJsonFragment(['id' => $model->slug, 'name' => 'GPT-5.4 mini']);

        $body = $this->chatBody(['model' => $model->slug, 'max_tokens' => 24, 'max_completion_tokens' => 32,
            'service_tier' => 'priority', 'store' => true, 'temperature' => 0.2]);
        $this->v1($key, $body)->assertOk()->assertJsonPath('choices.0.message.content', 'OK');
        Http::assertSent(fn (Request $request) => $request->url() === self::CHAT_URL
            && $request->hasHeader('Authorization', 'Bearer fixture-openai-key')
            && $request['model'] === 'gpt-5.4-mini' && $request['max_completion_tokens'] === 24
            && $request['service_tier'] === 'default' && $request['store'] === false
            && ! isset($request['max_tokens']) && ! isset($request['temperature']));
        $usage = AiUsage::sole();
        $this->assertSame(AiUsage::STATUS_SETTLED, $usage->status);
        $this->assertSame(1_000_000 - $usage->charged_irt, $this->balance($customer));
        $this->assertGreaterThan(0, $usage->charged_irt);

        $this->v1($key, $this->bigBody(['model' => $model->slug, 'messages' => [['role' => 'user', 'content' => str_repeat('x', 272_000)]]]))
            ->assertStatus(413);
        $model->update(['capabilities' => []]);
        $this->assertFalse(DeepInfraCatalog::isFresh($model));
        $this->v1($key, $body)->assertStatus(503)->assertJsonPath('code', 'catalogue_unavailable');
        Http::assertSentCount(3);
    }

    private function modelDocument(): string
    {
        return <<<'MARKDOWN'
Model ID: `gpt-5.4-mini`

## Pricing

### Text tokens

| Metric | Price | Unit |
| --- | ---: | --- |
| Input | $0.75 | 1M tokens |
| Cached input | $0.075 | 1M tokens |
| Output | $4.5 | 1M tokens |

## Endpoints

| Endpoint | Route | Support |
| --- | --- | --- |
| Chat Completions | `v1/chat/completions` | Supported |
MARKDOWN;
    }
}
