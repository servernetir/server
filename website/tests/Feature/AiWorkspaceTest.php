<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\Setting;
use App\Services\Ai\AiAvailability;
use App\Services\Ai\AiModelPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

class AiWorkspaceTest extends TestCase
{
    use RefreshDatabase, AiGatewayFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sellableCatalog(modelOver: ['vendor' => 'Meta', 'context_tokens' => 128000,
            'description' => 'A specific multilingual model for document analysis and conversations.']);
        Http::preventStrayRequests();
    }

    public function test_comparison_has_real_gateway_facts_and_localized_canonical_pages(): void
    {
        foreach (['' => 'fa', '/en' => 'en', '/tr' => 'tr'] as $prefix => $locale) {
            $this->get($prefix.'/ai/compare')->assertOk()
                ->assertSee('31,050')->assertSee('54,000')->assertSee('128,000')
                ->assertSee('rel="canonical" href="http://localhost'.$prefix.'/ai/compare"', false)
                ->assertDontSee('noindex,follow', false)->assertDontSee('ai_experience.');
            $this->get($prefix.'/ai/compare?models='.$this->model->slug)->assertOk()
                ->assertSee('noindex,follow', false)
                ->assertSee('property="og:url" content="http://localhost'.$prefix.'/ai/compare"', false); 
        }
        $this->get('/sitemap.xml')->assertOk()->assertSee('/ai/compare</loc>', false)->assertSee('/tr/ai/compare</loc>', false);
        $this->get('/llms.txt')->assertOk()->assertSee('/ai/compare');
        Http::assertNothingSent();
    }

    public function test_comparison_validates_bounds_unknown_ids_and_injection_without_mutating_catalogue(): void
    {
        $before = $this->model->fresh()->toArray();
        foreach (['models[]=llama-3.3-70b', 'models=a,b,c,d', 'models='.urlencode('<script>alert(1)</script>'), 'models='.str_repeat('a', 601)] as $query) {
            $this->get('/ai/compare?'.$query)->assertStatus(400);
        }
        $this->get('/ai/models?q[]=invalid')->assertStatus(400);
        $this->get('/ai/models?type[]=invalid')->assertStatus(400);
        $this->get('/ai/compare?models=does-not-exist')->assertNotFound();
        $this->get('/ai/compare?models='.$this->model->slug.','.$this->model->slug)->assertOk()->assertViewHas('models', fn ($models) => $models->count() === 1);
        $this->assertSame($before, $this->model->fresh()->toArray());
        $this->assertDatabaseCount('ai_calls', 0);
        Http::assertNothingSent();
    }

    public function test_mixed_operations_and_pending_rates_are_not_presented_as_free_or_comparable(): void
    {
        $image = AiModel::create(['ai_provider_id' => $this->provider->id, 'slug' => 'pending-image',
            'upstream_model' => 'private/image', 'name' => 'Pending Image', 'category' => 'image', 'status' => 'disabled']);
        $this->get('/en/ai/compare?models='.$this->model->slug.','.$image->slug)->assertOk()
            ->assertSee('Different operations are selected.')->assertSee('Not listed')
            ->assertDontSee('private/image')->assertDontSee('sk-test');
        Setting::put('ai_sales_open', '0');
        $this->get('/ai/compare?models='.$this->model->slug)->assertOk()->assertViewHas('models', fn ($cards) => $cards->first()['state']['ready'] === false);
    }

    public function test_model_description_is_specific_plain_text_and_never_exposes_embedded_credentials(): void
    {
        $this->model->update(['description' => '<b>Document analysis</b> <script>window.bad=1</script> https://supplier.invalid/internal']);
        $page = app(AiModelPage::class)->build($this->model, app(AiAvailability::class)->inspect($this->model));
        $this->assertStringContainsString('Document analysis', $page['sourceDescription']);
        $this->assertStringNotContainsString('<', $page['sourceDescription']);
        $this->assertStringNotContainsString('window.bad', $page['sourceDescription']);
        $this->assertStringNotContainsString('supplier.invalid', $page['sourceDescription']);
        $this->get('/ai/models/'.$this->model->slug)->assertOk()->assertSee('Document analysis')
            ->assertDontSee('<script>window.bad=1</script>', false)->assertSee('data-ai-estimator', false);
        $this->model->update(['description' => 'Authorization: Bearer internal-secret-value']);
        $other = app(AiModelPage::class)->build($this->model->fresh(), app(AiAvailability::class)->inspect($this->model));
        $this->assertSame('', $other['sourceDescription']);
    }

    public function test_examples_choose_a_chat_id_and_faq_schema_matches_visible_answers(): void
    {
        $this->model->update(['slug' => 'zz-chat']);
        AiModel::create(['ai_provider_id' => $this->provider->id, 'slug' => 'aa-embedding', 'upstream_model' => 'private/embed',
            'name' => 'Embedding', 'category' => 'embedding', 'status' => 'active', 'context_tokens' => 4000]);
        $html = $this->get('/en/ai')->assertOk()->assertSee('zz-chat')->getContent();
        $this->assertStringNotContainsString('models.data[0].id', $html);
        $this->assertStringContainsString('Idempotency-Key', $html);
        $page = app(AiModelPage::class)->build($this->model, app(AiAvailability::class)->inspect($this->model));
        $faq = collect($page['schema']['@graph'])->firstWhere('@type', 'FAQPage');
        foreach (['python', 'javascript', 'curl'] as $language) {
            $this->assertStringContainsString('zz-chat', $page['example'][$language]);
            $this->assertStringContainsString('Idempotency-Key', $page['example'][$language]);
            $this->assertStringNotContainsString('private/', $page['example'][$language]);
        }
        $this->assertCount(count($page['faq']), $faq['mainEntity']);
        $this->assertSame($page['faq'][0]['a'], $faq['mainEntity'][0]['acceptedAnswer']['text']);
        $html = $this->get('/en/ai/models/zz-chat')->assertOk()->getContent();
        $this->assertStringContainsString('data-ai-estimate-config', $html);
        $this->assertStringNotContainsString('private/embed', $html);
    }

    public function test_related_models_keep_smaller_unsigned_contexts_and_order_nearby_limits(): void
    {
        foreach (['farther-context' => 64000, 'near-context' => 129000] as $slug => $context) {
            $m = $this->model->replicate(); $m->slug = $slug; $m->upstream_model = 'example/'.$slug;
            $m->context_tokens = $context; $m->save();
        }
        $this->get('/ai/models/'.$this->model->slug)->assertOk()->assertViewHas('related',
            fn ($cards) => $cards->pluck('model.slug')->all() === ['near-context', 'farther-context']);
        Http::assertNothingSent();
    }

    public function test_workspace_translations_have_identical_key_trees_and_render_in_all_languages(): void
    {
        $flatten = function ($value, $prefix = '') use (&$flatten) {
            $result = [];
            foreach ($value as $key => $item) $result = [...$result, ...(is_array($item) ? $flatten($item, $prefix.$key.'.') : [$prefix.$key])];
            sort($result);
            return $result;
        };
        $base = $flatten(require lang_path('en/ai_experience.php'));
        foreach (['fa', 'tr'] as $locale) $this->assertSame($base, $flatten(require lang_path($locale.'/ai_experience.php')));
        foreach (['', '/en', '/tr'] as $prefix) {
            foreach (['/ai', '/ai/models', '/ai/compare', '/ai/models/'.$this->model->slug] as $url) {
                $html = $this->get($prefix.$url)->assertOk()->assertDontSee('ai_experience.')->getContent();
                $this->assertSame(1, preg_match_all('~<h1(?:\s|>)~', $html));
                preg_match('~<script type="application/json" id="ai-experience-config">(.*?)</script>~s', $html, $match);
                $this->assertIsArray(json_decode($match[1], true, flags: JSON_THROW_ON_ERROR));
            }
        }
        Http::assertNothingSent();
    }
}
