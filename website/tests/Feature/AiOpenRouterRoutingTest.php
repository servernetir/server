<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiUsage;
use App\Models\Setting;
use App\Services\Ai\AiPayload;
use App\Services\Ai\AiPricing;
use App\Services\Ai\AiProviderRouting;
use App\Services\Ai\AiUsageParser;
use App\Services\Ai\DeepInfraCatalog;
use App\Services\Ai\OpenRouterCatalog;
use App\Services\Ai\PriceBook;
use App\Services\AiProviders\OpenAiCompatibleDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

class AiOpenRouterRoutingTest extends TestCase
{
    use RefreshDatabase, AiGatewayFixture;

    private function routerProvider(array $extra = []): AiProvider
    {
        $p = AiProvider::create($extra + ['slug' => 'openrouter', 'name' => 'OpenRouter', 'driver' => 'OpenAI-Compatible',
            'enabled' => true, 'commercial_enabled' => true, 'live_calls_enabled' => true, 'resale_allowed' => true,
            'agreement_status' => 'owner_assumed', 'fx_fee_bp' => 550, 'billing_currency_code' => 'USD']);
        Setting::putSecret('ai_provider_openrouter_key', 'test-or-key');
        return $p;
    }

    private function feed(array $extra = []): array
    {
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = $extra + ['id' => 'example/model-'.$i, 'name' => 'Model '.$i, 'context_length' => 128000,
                'hugging_face_id' => 'example/Model-'.$i, 'supported_parameters' => ['tools', 'max_tokens'],
                'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['text']],
                'top_provider' => ['max_completion_tokens' => 4096],
                'pricing' => ['prompt' => '0.000000100000000001', 'completion' => '0.0000004']];
        }
        return $rows;
    }

    private function fakeCatalogue(array $rows): void
    {
        Http::fake([
            OpenRouterCatalog::BASE_URL.'/models' => Http::response(['data' => $rows]),
            OpenRouterCatalog::BASE_URL.'/embeddings/models' => Http::response(['data' => [[
                'id' => 'example/embed', 'context_length' => 8192,
                'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['embeddings']],
                'pricing' => ['prompt' => '0.00000002', 'completion' => '0'],
            ]]]),
        ]);
    }

    private function pair(int $input = 100000, int $output = 200000): AiModel
    {
        $this->sellableCatalog(modelOver: ['context_tokens' => 128000], cached: false);
        $p = $this->routerProvider();
        Setting::put('ai_price_routing_enabled', '1');
        Setting::put('ai_uniform_margin', '1');
        $model = AiModel::create(['ai_provider_id' => $p->id, 'slug' => 'or-llama-test',
            'upstream_model' => 'meta-llama/llama-3.3-70b-instruct', 'name' => 'Llama', 'category' => 'chat',
            'status' => 'active', 'context_tokens' => 128000, 'max_output_tokens' => 4096,
            'capabilities' => ['catalogue_source' => 'openrouter', 'catalogue_at' => now()->toIso8601String(),
                'execution_ready' => true, 'catalogue_identity' => strtolower($this->model->upstream_model),
                'openrouter_max_price' => ['prompt' => '0.1', 'completion' => '0.2']]]);
        app(PriceBook::class)->supersede($model, 'input', $input, currencyCode: 'USD');
        app(PriceBook::class)->supersede($model, 'output', $output, currencyCode: 'USD');
        return $model->load('provider');
    }

    public function test_public_catalogue_imports_chat_and_embeddings_without_exposing_a_key(): void
    {
        $this->routerProvider();
        $this->fakeCatalogue($this->feed());
        $result = app(OpenRouterCatalog::class)->sync();
        $this->assertSame(21, $result['execution_ready']);
        $model = AiModel::where('upstream_model', 'example/model-0')->sole();
        $this->assertSame(100001, (int) $model->activePrice('input')->price_micro_units);
        $this->assertTrue(DeepInfraCatalog::isFresh($model));
        $this->assertSame('active', $model->status);
        $this->assertSame(0, AiModel::where('category', 'embedding')->sole()->max_output_tokens);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($r) => $r->hasHeader('Authorization'));
    }

    public function test_access_verification_uses_only_the_official_host_and_returns_no_secret(): void
    {
        $this->routerProvider();
        Http::fake([OpenRouterCatalog::BASE_URL.'/auth/key' => Http::response(['data' => ['label' => 'private-label']])]);
        $this->assertSame('verified', app(OpenRouterCatalog::class)->checkAccess());
        Http::assertSent(fn ($r) => $r->url() === OpenRouterCatalog::BASE_URL.'/auth/key' && $r->hasHeader('Authorization', 'Bearer test-or-key'));
        Http::assertSentCount(1);
    }

    public function test_price_changes_append_history_and_manual_disable_is_preserved(): void
    {
        $this->routerProvider();
        $this->fakeCatalogue($this->feed());
        app(OpenRouterCatalog::class)->sync();
        $m = AiModel::where('upstream_model', 'example/model-0')->sole();
        $first = $m->activePrice('input')->id;
        $m->update(['status' => 'disabled', 'suspended_at' => now()]);
        Http::swap(new \Illuminate\Http\Client\Factory());
        $this->fakeCatalogue($this->feed(['pricing' => ['prompt' => '0.00000005', 'completion' => '0.0000003']]));
        app(OpenRouterCatalog::class)->sync();
        $m->refresh();
        $this->assertNotSame($first, $m->activePrice('input')->id);
        $this->assertSame('disabled', $m->status);
        $this->assertNotNull($m->suspended_at);
        $this->assertSame(2, $m->unitPrices()->where('unit', 'input')->count());
    }

    public function test_malformed_feed_preserves_existing_prices_and_models(): void
    {
        $this->routerProvider();
        $this->fakeCatalogue($this->feed());
        app(OpenRouterCatalog::class)->sync();
        Http::swap(new \Illuminate\Http\Client\Factory());
        $this->fakeCatalogue([]);
        try { app(OpenRouterCatalog::class)->sync(); $this->fail('Incomplete feed accepted.'); }
        catch (\RuntimeException) { $this->assertSame(21, AiModel::count()); }
    }

    public function test_media_free_expired_and_request_pricing_are_not_sold_as_chat(): void
    {
        $this->routerProvider();
        $rows = $this->feed();
        $rows[0]['architecture']['output_modalities'] = ['text', 'image'];
        $rows[1]['id'] .= ':free';
        $rows[2]['pricing']['request'] = '0.05';
        $rows[3]['expiration_date'] = now()->subDay()->toDateString();
        $rows[4]['pricing']['overrides'] = [['min_prompt_tokens' => 32000, 'prompt' => '0.5']];
        $this->fakeCatalogue($rows);
        $result = app(OpenRouterCatalog::class)->sync();
        $this->assertSame(4, $result['pending']);
        $this->assertSame(31999, AiModel::where('upstream_model', 'example/model-4')->sole()->context_tokens);
        $this->assertSame(4, AiModel::where('status', 'disabled')->count());
    }

    public function test_catalogue_selects_landed_cost_and_keeps_unique_models(): void
    {
        $or = $this->pair();
        $router = app(AiProviderRouting::class);
        $this->assertSame($or->id, $router->catalogue(collect([$this->model, $or]))->sole()->id);
        $or->provider->update(['fx_fee_bp' => 2500]);
        app(PriceBook::class)->supersede($or, 'input', 250000, currencyCode: 'USD');
        app(PriceBook::class)->supersede($or, 'output', 400000, currencyCode: 'USD');
        $this->assertSame($this->model->id, $router->catalogue(collect([$this->model, $or]))->sole()->id);
        $or->update(['capabilities' => array_merge($or->capabilities, ['catalogue_identity' => 'other/version'])]);
        $this->assertCount(2, $router->catalogue(collect([$this->model, $or])));
    }

    public function test_requests_choose_input_output_weighting_and_preserve_the_public_model_id(): void
    {
        $or = $this->pair(10000, 1000000);
        $router = app(AiProviderRouting::class);
        $short = ['model' => $this->model->slug, 'messages' => [['role' => 'user', 'content' => 'Hi']], 'max_tokens' => 4000];
        $this->assertSame($this->model->id, $router->forRequest($this->model, $short, 'chat')->id);
        $short['messages'][0]['content'] = str_repeat('a', 10000);
        $short['max_tokens'] = 10;
        $this->assertSame($or->id, $router->forRequest($this->model, $short, 'chat')->id);
        [, $key] = $this->greenKey();
        $this->fakeUsage(10, 1, 0);
        $response = $this->withToken($key)->postJson('/v1/chat/completions', $short);
        $response->assertOk()->assertJsonPath('model', $this->model->slug);
        $usage = AiUsage::latest('id')->firstOrFail();
        $this->assertSame($or->id, $usage->ai_model_id);
        $this->assertSame($this->model->slug, $usage->model_slug);
        $this->assertSame($this->model->slug, $this->model->fresh()->slug);
    }

    public function test_stale_disabled_and_paused_suppliers_cannot_win(): void
    {
        $or = $this->pair();
        $router = app(AiProviderRouting::class);
        $client = ['messages' => [['role' => 'user', 'content' => 'Hi']], 'max_tokens' => 10];
        $or->provider->update(['paused_at' => now()]);
        $this->assertSame($this->model->id, $router->forRequest($this->model, $client, 'chat')->id);
        $or->provider->update(['paused_at' => null]);
        $or->update(['capabilities' => array_merge($or->capabilities, ['catalogue_at' => now()->subHours(7)->toIso8601String()])]);
        $this->assertSame($this->model->id, $router->forRequest($this->model, $client, 'chat')->id);
        $or->update(['status' => 'disabled']);
        $this->assertSame($this->model->id, $router->forRequest($this->model, $client, 'chat')->id);
    }

    public function test_global_margin_overrides_old_per_model_margins_when_uniform_pricing_is_enabled(): void
    {
        $or = $this->pair();
        Setting::put('ai_margin_pct', '7');
        $or->update(['margin_bp' => 4500]);
        $this->assertSame(700, app(AiPricing::class)->quote($or)->marginBp);
        $this->assertSame('global', app(AiPricing::class)->quote($or)->marginSource);
    }

    public function test_actual_funding_invoice_overhead_is_accepted_and_included_in_pricing(): void
    {
        $or = $this->pair();
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/ai/providers/'.$or->provider->id, ['fx_fee_pct' => '33.9'])
            ->assertSessionHasNoErrors();
        $or->refresh()->load('provider');
        $this->assertSame(3390, $or->provider->fx_fee_bp);
        $q = app(AiPricing::class)->quote($or);
        $this->assertSame(3390, $q->feeBp);
        $this->assertSame(AiPricing::perMillion($q->rIn, $q->rate(), 3390, 2500), $q->pIn);
    }

    public function test_deepinfra_discount_applies_once_and_expired_promotions_return_to_base_price(): void
    {
        $this->sellableCatalog();
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = ['model_name' => 'example/discount-'.$i, 'type' => 'text-generation', 'tags' => ['openai'],
                'max_tokens' => 8192, 'pricing' => ['type' => 'tokens', 'cents_per_input_token' => '0.00002',
                    'cents_per_output_token' => '0.00006', 'discount' => '0.3',
                    'discount_ends_at' => now()->addHour()->toIso8601String()]];
        }
        $expired = $rows;
        foreach ($expired as &$row) $row['pricing']['discount_ends_at'] = now()->subHour()->toIso8601String();
        unset($row);
        Http::fake([DeepInfraCatalog::URL => Http::sequence()->push($rows)->push($expired)]);
        app(DeepInfraCatalog::class)->sync();
        $model = AiModel::where('upstream_model', 'example/discount-0')->sole();
        $this->assertSame(140000, (int) $model->activePrice('input')->price_micro_units);
        $this->assertSame(420000, (int) $model->activePrice('output')->price_micro_units);
        $this->travel(61)->minutes();
        $this->assertFalse(DeepInfraCatalog::isFresh($model));
        $this->travelBack();
        app(DeepInfraCatalog::class)->sync();
        $model->refresh();
        $this->assertSame(200000, (int) $model->activePrice('input')->price_micro_units);
        $this->assertTrue(DeepInfraCatalog::isFresh($model));
        $this->assertNull($model->capabilities['catalogue_discount_until']);
    }

    public function test_studio_catalogue_shares_the_cheapest_model_and_uses_servernet_identity(): void
    {
        $or = $this->pair();
        [, $key, $token] = $this->greenKey();
        $token->update(['abilities' => ['ai:chat', 'ai:models:read']]);
        $response = $this->withToken($key)->getJson('/v1/models?studio=1');
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $or->slug)
            ->assertJsonPath('data.0.owned_by', 'servernet');
        $this->assertArrayHasKey('pricing', $response->json('data.0'));
    }

    public function test_driver_sends_price_ceiling_and_ignores_client_routing_and_paid_plugins(): void
    {
        $or = $this->pair();
        Http::fake([OpenRouterCatalog::BASE_URL.'/chat/completions' => Http::response(['choices' => [], 'usage' => []])]);
        $prepared = app(AiPayload::class)->prepare(['messages' => [['role' => 'user', 'content' => 'Hi']],
            'max_tokens' => 10, 'provider' => ['max_price' => ['prompt' => 1000]], 'plugins' => [['id' => 'web']]], $or);
        (new OpenAiCompatibleDriver($or->provider))->chat($or, $prepared->body());
        Http::assertSent(fn ($r) => $r['provider']['sort'] === 'price'
            && $r['provider']['max_price']['prompt'] === '0.1'
            && $r['provider']['allow_fallbacks'] === false && $r['plugins'] === [] && $r['model'] === $or->upstream_model);
    }

    public function test_openrouter_stream_settles_reported_cost_and_replay_does_not_send_again(): void
    {
        $or = $this->pair();
        [, $key] = $this->greenKey();
        $chunks = [['id' => 'private-id', 'model' => 'private-model', 'provider' => 'private-provider', 'choices' => [['index' => 0,
            'delta' => ['content' => 'hello'], 'finish_reason' => null]]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 1, 'cost' => '0.0000012']]];
        $stream = implode('', array_map(fn ($chunk) => 'data: '.json_encode($chunk)."\n\n", $chunks))."data: [DONE]\n\n";
        Http::fake(['*/chat/completions' => Http::response($stream, 200, ['Content-Type' => 'text/event-stream'])]);
        $body = ['model' => $this->model->slug, 'messages' => [['role' => 'user', 'content' => 'Hi']], 'max_tokens' => 10, 'stream' => true];
        $response = $this->withToken($key)->withHeader('Idempotency-Key', 'or-stream')->postJson('/v1/chat/completions', $body)->assertOk();
        $output = $response->streamedContent();
        $this->assertStringContainsString('data: [DONE]', $output);
        $this->assertStringNotContainsString('private-', $output);
        $this->assertStringNotContainsString('"cost"', $output);
        $this->assertSame('settled', AiUsage::sole()->status);
        $this->assertSame($or->id, AiUsage::sole()->ai_model_id);
        $again = $this->withToken($key)->withHeader('Idempotency-Key', 'or-stream')->postJson('/v1/chat/completions', $body)
            ->assertOk()->assertHeader('Idempotent-Replayed', 'true')->streamedContent();
        $this->assertSame($output, $again);
        Http::assertSentCount(1);
    }

    public function test_unknown_request_outcome_does_not_trigger_a_second_supplier_call(): void
    {
        $this->pair();
        [, $key] = $this->greenKey();
        $calls = 0;
        Http::fake(function ($r) use (&$calls) {
            $calls++;
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out after upload');
        });
        $this->withToken($key)->postJson('/v1/chat/completions', ['model' => $this->model->slug,
            'messages' => [['role' => 'user', 'content' => 'Hi']], 'max_tokens' => 10])->assertStatus(504);
        $this->assertSame('unknown_pending', AiUsage::sole()->status);
        $this->assertSame(1, $calls);
    }

    public function test_reported_cost_is_read_losslessly_only_from_usage_in_normal_and_sse_bodies(): void
    {
        $raw = <<<'JSON'
{"choices":[{"message":{"content":"fake cost: 2, and \"cost\": 9"}}],"usage":{"prompt_tokens":3,"completion_tokens":1,"cost":0.00000010000000001},"cost":9}
JSON;
        $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('0.00000010000000001', AiUsageParser::parse($body, $raw)['estimated_cost']);
        $body['usage']['cost'] = null;
        $this->assertNull(AiUsageParser::parse($body, '{"cost":9}')['estimated_cost']);
    }

    public function test_whitelabel_response_removes_supplier_identity_and_private_billing_metadata(): void
    {
        $usage = new AiUsage(['operation' => 'chat', 'public_id' => 'example-id', 'model_slug' => 'servernet-model']);
        $body = \App\Services\Ai\AiCaller::publicBody(['model' => 'upstream/model', 'provider' => 'supplier-name',
            'system_fingerprint' => 'supplier-host', 'usage' => ['cost' => 1, 'is_byok' => false]], $usage);
        $this->assertSame('servernet-model', $body['model']);
        $this->assertArrayNotHasKey('provider', $body);
        $this->assertArrayNotHasKey('system_fingerprint', $body);
        $this->assertArrayNotHasKey('cost', $body['usage']);
        $this->assertArrayNotHasKey('is_byok', $body['usage']);
    }
}
