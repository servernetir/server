<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiModelUnitPrice;
use App\Services\Ai\AiPayload;
use App\Services\Ai\AiRequestException;
use App\Services\Ai\DeepInfraCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

class AiExpandedCatalogTest extends TestCase
{
    use RefreshDatabase, AiGatewayFixture;

    private function rows(): array
    {
        $rows = [];
        for ($i=0; $i<20; $i++) {
            $rows[] = ['model_name' => 'test/embed-'.$i, 'type' => 'embeddings', 'max_tokens' => 8192,
                'pricing' => ['type' => 'input_tokens', 'cents_per_input_token' => '0.000001']];
        }
        return $rows;
    }

    public function test_input_catalog_prices_only_input_and_keeps_manual_disable(): void
    {
        $this->sellableCatalog();
        $rows = $this->rows();
        $rows[] = ['model_name' => 'test/rerank', 'type' => 'reranker', 'pricing' => $rows[0]['pricing']];
        Http::fake([DeepInfraCatalog::URL => Http::sequence()->push($rows)->push($rows)]);
        app(DeepInfraCatalog::class)->sync();
        $m = AiModel::where('upstream_model', 'test/embed-0')->sole();
        $this->assertSame('active', $m->status);
        $this->assertSame(10000, $m->activePrice('input')->price_micro_units);
        $this->assertNull($m->activePrice('output'));
        $this->assertFalse($m->capability('streaming'));
        $m->update(['status' => 'disabled', 'suspended_at' => now()]);
        app(DeepInfraCatalog::class)->sync();
        $this->assertSame('disabled', $m->fresh()->status);
        $this->assertNotNull($m->fresh()->suspended_at);
    }

    public function test_tiered_chat_is_limited_to_verified_first_tier(): void
    {
        $this->sellableCatalog();
        $rows = $this->rows();
        $row = ['model_name' => 'Qwen/Qwen3.7-Max', 'type' => 'text-generation', 'tags' => ['openai'], 'max_tokens' => 256000,
            'pricing' => ['type' => 'tokens', 'cents_per_input_token' => '0.00025', 'cents_per_output_token' => '0.00075',
                'full' => '$2.50 in $7.50 out $0.50 cached <= 32K, $5.0 in $15 out $1.0 cached <= 128K, $6.25 in $18.50 out $1.25 cached > 128K']];
        $rows[] = $row;
        $changed = $rows;
        $changed[20]['pricing']['full'] .= ' changed';
        Http::fake([DeepInfraCatalog::URL => Http::sequence()->push($rows)->push($changed)]);
        app(DeepInfraCatalog::class)->sync();
        $m = AiModel::where('upstream_model', $row['model_name'])->sole();
        $this->assertSame(32000, $m->context_tokens);
        $this->assertSame('active', $m->status);
        $this->assertSame(2500000, $m->activePrice(AiModelUnitPrice::UNIT_INPUT)->price_micro_units);
        app(AiPayload::class)->prepare(['messages' => [['role' => 'user', 'content' => 'Hello']], 'max_tokens' => 16], $m);
        try {
            app(AiPayload::class)->prepare(['messages' => [['role' => 'user', 'content' => str_repeat('x', 31900)]], 'max_tokens' => 128], $m);
            $this->fail('Tier overflow was accepted');
        } catch (AiRequestException $e) {
            $this->assertSame(413, $e->status);
        }
        app(DeepInfraCatalog::class)->sync();
        $this->assertSame('disabled', $m->fresh()->status);
        $this->assertFalse(DeepInfraCatalog::isFresh($m->fresh()));
    }
}
