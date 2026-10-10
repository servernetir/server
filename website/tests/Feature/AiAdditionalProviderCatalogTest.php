<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Setting;
use App\Services\Ai\AdditionalProviderCatalog;
use App\Services\Ai\AiPayload;
use App\Services\Ai\DeepInfraCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAdditionalProviderCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function gapPrices(): string
    {
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = ['model_name' => 'test-'.$i, 'model_type' => 'text', 'pricing_type' => 'token',
                'input_token_price' => '0.1', 'output_token_price' => '0.4', 'provider' => 'Test'];
        }
        // Keep the literal decimals exactly as the provider emits them.
        return str_replace(['"0.1"', '"0.4"'], ['0.10000000000000001', '0.4'], json_encode(['data' => $rows]));
    }

    private function deepseekPrices(): string
    {
        $rows = [['MODEL', 'deepseek-flash<sup>(1)</sup>', 'deepseek-v4-pro'],
            ['1M INPUT TOKENS<br>(CACHE HIT)', 'OFF-PEAK', '$0.003', '$0.022'], ['PEAK', '$0.006', '$0.044'],
            ['1M INPUT TOKENS<br>(CACHE MISS)', 'OFF-PEAK', '$0.15', '$0.66'], ['PEAK', '$0.3', '$1.32'],
            ['1M OUTPUT TOKENS', 'OFF-PEAK', '$0.6', '$1.98'], ['PEAK', '$1.2', '$3.96']];
        return '<table>'.implode('', array_map(fn ($r) => '<tr><td>'.implode('</td><td>', $r).'</td></tr>', $rows)).'</table>';
    }

    private function provider(string $slug): AiProvider
    {
        $p = AiProvider::firstOrCreate(['slug' => $slug], ['name' => $slug, 'driver' => 'OpenAI-Compatible']);
        Setting::putSecret('ai_provider_'.$slug.'_key', 'test-key');
        return $p;
    }

    public function test_raw_decimal_prices_round_up_and_unsupported_meters_stay_closed(): void
    {
        $rows = AdditionalProviderCatalog::gapRows($this->gapPrices());
        $this->assertSame(100001, $rows['test-0']['rates']['input']);
        $raw = str_replace('"model_type":"text"', '"model_type":"image"', $this->gapPrices());
        $this->assertSame([], AdditionalProviderCatalog::gapRows($raw)['test-0']['rates']);
        $this->expectException(\RuntimeException::class);
        AdditionalProviderCatalog::gapRows('{"data":[]}');
    }

    public function test_model_discovery_price_versions_manual_disable_and_removed_models(): void
    {
        $p = $this->provider('gapgpt');
        $models = ['data' => [['id' => 'test-0'], ['id' => 'test-1']]];
        Http::fake([
            'https://api.gapgpt.app/v1/models' => Http::sequence()->push($models)->push($models)->push(['data' => [['id' => 'test-1']]]),
            AdditionalProviderCatalog::GAPGPT_PRICES => Http::sequence()->push($this->gapPrices())->push(str_replace('0.4', '0.5', $this->gapPrices()))->push($this->gapPrices()),
        ]);
        $result = app(AdditionalProviderCatalog::class)->sync('gapgpt');
        $this->assertSame(2, $result['execution_ready']);
        $m = $p->models()->where('upstream_model', 'test-0')->sole();
        $this->assertSame('disabled', $m->status);
        $this->assertTrue(DeepInfraCatalog::isFresh($m));
        $originalPrice = $m->activePrice('output')->id;
        $m->update(['status' => 'active', 'suspended_at' => now()]);
        app(AdditionalProviderCatalog::class)->sync('gapgpt');
        $this->assertNotSame($originalPrice, $m->fresh()->activePrice('output')->id);
        $this->assertSame('active', $m->fresh()->status);
        $this->assertNotNull($m->fresh()->suspended_at);
        app(AdditionalProviderCatalog::class)->sync('gapgpt');
        $this->assertSame('disabled', $m->fresh()->status);
        $this->assertFalse(DeepInfraCatalog::isFresh($m->fresh()));
        Http::assertSent(fn ($r) => $r->url() === AdditionalProviderCatalog::GAPGPT_PRICES && ! $r->hasHeader('Authorization'));
    }

    public function test_peak_rates_and_model_order_are_unambiguous(): void
    {
        $prices = AdditionalProviderCatalog::deepseekRows($this->deepseekPrices());
        $this->assertSame(['cached_input' => 6000, 'input' => 300000, 'output' => 1200000], $prices['deepseek-flash']['rates']);
        $this->assertSame(3960000, $prices['deepseek-v4-pro']['rates']['output']);
        $this->expectException(\RuntimeException::class);
        AdditionalProviderCatalog::deepseekRows(str_replace('deepseek-flash', 'unknown-model', $this->deepseekPrices()));
    }

    public function test_deepseek_import_and_non_thinking_request_remain_bounded(): void
    {
        $p = $this->provider('deepseek');
        Http::fake(['https://api.deepseek.com/models' => Http::response(['data' => [['id' => 'deepseek-flash'], ['id' => 'deepseek-v4-pro']]]),
            AdditionalProviderCatalog::DEEPSEEK_PRICES => Http::response($this->deepseekPrices())]);
        app(AdditionalProviderCatalog::class)->sync('deepseek');
        $m = $p->models()->where('upstream_model', 'deepseek-flash')->with('provider')->sole();
        $prepared = app(AiPayload::class)->prepare(['messages' => [['role' => 'user', 'content' => 'OK']], 'max_tokens' => 16,
            'thinking' => ['type' => 'enabled']], $m);
        $this->assertSame(['type' => 'disabled'], $prepared->body()['thinking']);
        $this->assertSame(16, $prepared->maxOutput);
        $m->update(['capabilities' => array_merge($m->capabilities, ['catalogue_at' => now()->subHours(7)->toIso8601String()])]);
        $this->assertFalse(DeepInfraCatalog::isFresh($m->fresh()));
    }

    public function test_top_level_cache_hit_tokens_are_accounted_without_guessing(): void
    {
        $usage = \App\Services\Ai\AiUsageParser::parse(['usage' => ['prompt_tokens' => 30, 'completion_tokens' => 2,
            'prompt_cache_hit_tokens' => 12]]);
        $this->assertSame(12, $usage['cached']);
    }

    public function test_unpriced_cache_write_and_hosted_tools_are_rejected(): void
    {
        $model = new AiModel(['max_output_tokens' => 16]);
        foreach ([['messages' => [['role' => 'user', 'content' => 'OK', 'cache_control' => ['type' => 'ephemeral']]]],
            ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'OK', 'cache_control' => ['type' => 'ephemeral']]]]]],
            ['messages' => [['role' => 'user', 'content' => 'OK']], 'tools' => [['type' => 'web_search']]]] as $body) {
            try { app(AiPayload::class)->prepare($body, $model); $this->fail('Unpriced feature accepted.'); }
            catch (\App\Services\Ai\AiRequestException $e) { $this->assertSame(400, $e->status); }
        }
    }
}
