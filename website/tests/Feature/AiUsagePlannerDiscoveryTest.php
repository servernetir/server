<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Setting;
use App\Services\Ai\AiAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

class AiUsagePlannerDiscoveryTest extends TestCase
{
    use RefreshDatabase, AiGatewayFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sellableCatalog(modelOver: ['vendor' => 'Meta', 'context_tokens' => 128000]);
        Http::preventStrayRequests();
    }

    public function test_localized_home_and_catalogue_links_land_on_the_existing_model_calculator(): void
    {
        $before = $this->model->fresh()->toArray();
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            $destination = 'http://localhost'.$prefix.'/ai/models/'.$this->model->slug;
            foreach (['/ai', '/ai/models'] as $source) {
                $html = $this->get($prefix.$source)->assertOk()->getContent();
                $link = $this->nodes($html)->query('//article[contains(@class,"ai-card")]//a[@href="'.$destination.'#usage-planner"]');
                $this->assertSame(1, $link->length);
                $this->assertSame(trans('ai_experience.estimator', [], $locale), trim($link->item(0)->textContent));
            }
            $modelHtml = $this->get($prefix.'/ai/models/'.$this->model->slug)->assertOk()->getContent();
            $nodes = $this->nodes($modelHtml);
            $this->assertSame(1, $nodes->query('//*[@id="usage-planner"]//*[@data-ai-estimator]')->length);
            $this->assertSame(1, $nodes->query('//nav[contains(@class,"ai-jump")]//a[@href="#usage-planner"]')->length);
        }
        $this->assertSame($before, $this->model->fresh()->toArray());
        $this->assertDatabaseCount('ai_calls', 0);
        Http::assertNothingSent();
    }

    public function test_input_only_token_models_have_the_same_localized_discovery_path(): void
    {
        foreach (['embedding', 'rerank'] as $category) {
            $this->model->update(['category' => $category]);
            foreach (['', '/en', '/tr'] as $prefix) {
                $path = $prefix.'/ai/models/'.$this->model->slug;
                $this->get($prefix.'/ai/models')->assertOk()->assertSee('href="http://localhost'.$path.'#usage-planner"', false);
                $html = $this->get($path)->assertOk()->getContent();
                $nodes = $this->nodes($html);
                $this->assertSame(1, $nodes->query('//nav[contains(@class,"ai-jump")]//a[@href="#usage-planner"]')->length);
                $this->assertSame(1, $nodes->query('//*[@id="usage-planner"]//*[@data-ai-estimator]')->length);
                $this->assertSame(0, $nodes->query('//*[@id="usage-planner"]//input[@name="output"]')->length);
            }
        }
        Http::assertNothingSent();
    }

    public function test_pending_quoted_models_keep_the_existing_calculator_discoverable(): void
    {
        Setting::put('ai_sales_open', '0');
        $state = app(AiAvailability::class)->inspect($this->model);
        $this->assertFalse($state['ready']);
        $this->assertNotNull($state['quote']);
        foreach (['', '/en', '/tr'] as $prefix) {
            $path = $prefix.'/ai/models/'.$this->model->slug;
            $this->get($prefix.'/ai/models')->assertOk()->assertSee('href="http://localhost'.$path.'#usage-planner"', false);
            $this->get($path)->assertOk()->assertSee('href="#usage-planner"', false)->assertSee('id="usage-planner"', false);
        }
        Http::assertNothingSent();
    }

    public function test_missing_quotes_and_media_cards_never_link_to_a_missing_token_calculator(): void
    {
        $quote = app(AiAvailability::class)->inspect($this->model)['quote'];
        foreach (['fa', 'en', 'tr'] as $locale) {
            app()->setLocale($locale);
            foreach (array_diff(AiModel::CATEGORIES, ['chat', 'embedding', 'rerank']) as $category) {
                $media = $this->model->replicate();
                $media->category = $category;
                $html = view('pages.ai.card', ['card' => ['model' => $media, 'state' => ['ready' => false, 'quote' => $quote]]])->render();
                $this->assertStringNotContainsString('#usage-planner', $html);
            }
        }
        $this->model->unitPrices()->delete();
        foreach (['', '/en', '/tr'] as $prefix) {
            $path = $prefix.'/ai/models/'.$this->model->slug;
            $this->get($prefix.'/ai/models')->assertOk()->assertDontSee($path.'#usage-planner', false);
            $this->get($path)->assertOk()->assertDontSee('href="#usage-planner"', false)->assertDontSee('id="usage-planner"', false);
        }
        Http::assertNothingSent();
    }

    private function nodes(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new \DOMXPath($document);
    }
}
