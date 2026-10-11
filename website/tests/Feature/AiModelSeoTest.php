<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiModelUnitPrice;
use App\Services\Ai\AiAvailability;
use App\Services\Ai\AiModelPage;
use App\Services\Ai\AiPayload;
use App\Services\Ai\AiPricing;
use App\Services\Ai\PriceBook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

class AiModelSeoTest extends TestCase
{
    use RefreshDatabase, AiGatewayFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sellableCatalog(modelOver: ['vendor' => 'Meta', 'context_tokens' => 128000]);
        Http::preventStrayRequests();
    }

    private function nodes(string $html): array
    {
        preg_match_all('~<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>~s', $html, $matches);
        $nodes = [];
        foreach ($matches[1] as $json) {
            $document = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            array_push($nodes, ...($document['@graph'] ?? [$document]));
        }
        return $nodes;
    }

    public function test_all_service_types_have_localized_facts_and_honest_offers(): void
    {
        foreach (AiModel::CATEGORIES as $category) {
            $model = $category === 'chat' ? $this->model : AiModel::create([
                'ai_provider_id' => $this->provider->id, 'slug' => 'example-'.$category,
                'upstream_model' => 'private/'.$category, 'name' => 'Example '.$category,
                'vendor' => 'Developer', 'category' => $category, 'status' => 'disabled',
                'context_tokens' => 4096, 'max_output_tokens' => 1024,
            ]);
            foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
                $path = $prefix.'/ai/models/'.$model->slug;
                $html = $this->get($path)->assertOk()->assertSee($model->name)->getContent();
                $this->assertStringContainsString('rel="canonical" href="http://localhost'.$path.'"', $html);
                foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $other => $altPrefix) {
                    $this->assertStringContainsString('hreflang="'.$other.'" href="http://localhost'.$altPrefix.'/ai/models/'.$model->slug.'"', $html);
                }
                $this->assertStringNotContainsString('ai_model.', $html);
                $dom = new \DOMDocument;
                @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
                $content = (new \DOMXPath($dom))->query('//article[@data-ai-model]')->item(0)->textContent;
                $this->assertStringNotContainsString(':name', $content);
                $this->assertStringNotContainsString('private/', $html);
                $this->assertStringNotContainsString('sk-test', $html);
                $this->assertSame(1, preg_match_all('~<h1(?:\s|>)~', $html));
                $service = collect($this->nodes($html))->firstWhere('@type', 'Service');
                $this->assertNotNull($service);
                $this->assertSame($category === 'chat', isset($service['offers']));
                $page = collect($this->nodes($html))->firstWhere('@type', 'WebPage');
                $this->assertSame($locale, $page['inLanguage']);
                $this->assertStringNotContainsString('noindex', $html);
                if (! in_array($category, ['chat', 'embedding', 'rerank'], true)) {
                    $this->assertStringNotContainsString('/v1/chat/completions', $html);
                    $this->assertStringNotContainsString('<strong>0</strong>', $html);
                }
            }
        }
    }

    public function test_offer_currency_and_worked_cost_match_the_billing_engine(): void
    {
        $state = app(AiAvailability::class)->inspect($this->model);
        $page = app(AiModelPage::class)->build($this->model, $state);
        $offer = $page['schema']['@graph'][2]['offers'][0]['priceSpecification'];
        $this->assertSame('IRR', $offer['priceCurrency']);
        $this->assertSame('310500', $offer['price']);
        $this->assertFalse($offer['valueAddedTaxIncluded']);
        $this->assertSame(1000000, $offer['referenceQuantity']['value']);
        $this->assertSame(59, $page['costExample']['sell']);
        $this->assertSame(6, $page['costExample']['tax']);
        $this->assertSame(65, $page['costExample']['charged']);
        $expected = app(AiPricing::class)->charge($state['quote'], 1000, 0, 500, 1000);
        $this->assertSame($expected['charged'], $page['costExample']['charged']);
    }

    public function test_pending_and_retired_pages_do_not_publish_a_sale_offer(): void
    {
        foreach (['disabled', 'deprecated'] as $status) {
            $this->model->update(['status' => $status]);
            $html = $this->get('/ai/models/'.$this->model->slug)->assertOk()->getContent();
            $service = collect($this->nodes($html))->firstWhere('@type', 'Service');
            $this->assertArrayNotHasKey('offers', $service);
            $this->assertSame($status === 'deprecated', str_contains($html, 'noindex,follow'));
        }
        $this->model->update(['status' => 'active', 'capabilities' => ['pending_reason' => 'removed']]);
        $this->assertFalse(AiModelPage::indexable($this->model));
        $this->get('/ai/models/'.$this->model->slug)->assertOk()->assertSee('noindex,follow', false);
    }

    public function test_sitemap_and_page_indexability_match_for_current_and_removed_models(): void
    {
        $current = AiModel::create(['ai_provider_id' => $this->provider->id, 'slug' => 'current-image',
            'upstream_model' => 'vendor/current-image', 'name' => 'Current Image', 'category' => 'image', 'status' => 'disabled']);
        $removed = AiModel::create(['ai_provider_id' => $this->provider->id, 'slug' => 'removed-chat',
            'upstream_model' => 'vendor/removed-chat', 'name' => 'Removed Chat', 'category' => 'chat', 'status' => 'disabled', 'capabilities' => ['pending_reason' => 'removed']]);
        $retired = AiModel::create(['ai_provider_id' => $this->provider->id, 'slug' => 'retired-chat',
            'upstream_model' => 'vendor/retired-chat', 'name' => 'Retired Chat', 'category' => 'chat', 'status' => 'deprecated']);
        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->assertStringContainsString($prefix.'/ai/models/'.$current->slug.'</loc>', $sitemap);
            $this->assertStringNotContainsString('/ai/models/'.$removed->slug.'</loc>', $sitemap);
            $this->assertStringNotContainsString('/ai/models/'.$retired->slug.'</loc>', $sitemap);
        }
    }

    public function test_catalogue_pagination_keeps_its_canonical_and_alternate_page_number(): void
    {
        for ($i = 0; $i < 25; $i++) {
            AiModel::create(['ai_provider_id' => $this->provider->id, 'slug' => 'model-'.$i,
                'upstream_model' => 'vendor/model-'.$i, 'name' => 'Model '.$i, 'category' => 'chat', 'status' => 'disabled']);
        }
        $html = $this->get('/en/ai/models?page=2&utm_source=campaign')->assertOk()->getContent();
        $this->assertStringContainsString('rel="canonical" href="http://localhost/en/ai/models?page=2"', $html);
        $this->assertStringContainsString('hreflang="tr" href="http://localhost/tr/ai/models?page=2"', $html);
        $this->assertStringNotContainsString('noindex', $html);
        $this->assertSame(1, preg_match_all('/<main\b/i', $html));
        $this->get('/ai/models?q=Llama')->assertOk()->assertSee('noindex,follow', false);
        $this->get('/ai/models?type=embedding')->assertOk()->assertSee('noindex,follow', false);
    }

    public function test_examples_use_the_public_model_and_the_correct_operation(): void
    {
        foreach (['chat' => ['chat/completions', 'ai:chat'], 'embedding' => ['embeddings', 'ai:embedding'], 'rerank' => ['rerank', 'ai:rerank']] as $type => [$path, $scope]) {
            $this->model->update(['category' => $type]);
            if ($type !== 'chat') {
                $this->model->unitPrices()->where('unit', AiModelUnitPrice::UNIT_OUTPUT)->delete();
            }
            $page = app(AiModelPage::class)->build($this->model, app(AiAvailability::class)->inspect($this->model));
            $this->assertSame($path, $page['example']['path']);
            $this->assertSame($scope, $page['example']['scope']);
            $this->assertSame($this->model->slug, $page['example']['body']['model']);
            $this->assertStringNotContainsString($this->model->upstream_model, $page['example']['curl']);
            if ($type !== 'chat') {
                $this->assertArrayNotHasKey('max_tokens', $page['example']['body']);
            }
        }
    }

    public function test_reasoning_presentation_matches_the_enforced_request_mode(): void
    {
        $this->model->update(['upstream_model' => 'Qwen/Qwen3.8-Max', 'capabilities' => ['reasoning' => true]]);
        $page = app(AiModelPage::class)->build($this->model, app(AiAvailability::class)->inspect($this->model));
        $this->assertTrue($page['reasoningDisabled']);
        $prepared = app(AiPayload::class)->prepare($this->chatBody(['max_tokens' => 16]), $this->model);
        $this->assertSame('none', $prepared->forward['reasoning_effort']);
    }

    public function test_model_names_are_escaped_in_metadata_and_structured_data(): void
    {
        $this->model->update(['name' => 'Model </title><script>alert(1)</script>']);
        $html = $this->get('/ai/models/'.$this->model->slug)->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertNotEmpty($this->nodes($html));
    }

    public function test_localized_content_has_the_same_complete_key_tree(): void
    {
        $flatten = function ($value, $prefix = '') use (&$flatten) {
            $keys = [];
            foreach ($value as $key => $item) {
                $path = $prefix.$key;
                $keys = [...$keys, ...(is_array($item) ? $flatten($item, $path.'.') : [$path])];
            }
            sort($keys);
            return $keys;
        };
        $base = $flatten(require lang_path('en/ai_model.php'));
        foreach (['fa', 'tr'] as $locale) {
            $this->assertSame($base, $flatten(require lang_path($locale.'/ai_model.php')));
        }
    }

    public function test_native_schema_is_specific_and_cannot_activate_or_price_the_model(): void
    {
        $this->model->update(['upstream_model' => 'Qwen/Qwen3-TTS', 'name' => 'Qwen3-TTS',
            'category' => 'audio', 'status' => 'disabled', 'capabilities' => ['catalogue_type' => 'text-to-speech']]);
        $page = app(AiModelPage::class)->build($this->model, app(AiAvailability::class)->inspect($this->model));
        $this->assertSame('input_character_length', $page['sourceSpec']['pricing_type']);
        $input = collect($page['sourceSpec']['fields'])->firstWhere('name', 'input');
        $this->assertSame(4000, $input['maxLength']);
        $this->assertTrue($input['required']);
        $this->assertNull($page['example']);
        $this->assertNull($page['costExample']);
        $this->assertArrayNotHasKey('offers', $page['schema']['@graph'][2]);
        $this->get('/ai/models/'.$this->model->slug)->assertOk()->assertSee('4000')->assertDontSee('ai_model.tasks.');
        $this->assertSame('disabled', $this->model->fresh()->status);
        $this->model->update(['capabilities' => ['catalogue_type' => 'automatic-speech-recognition']]);
        $other = app(AiModelPage::class)->build($this->model, app(AiAvailability::class)->inspect($this->model));
        $this->assertNull($other['sourceSpec']);
    }
}
