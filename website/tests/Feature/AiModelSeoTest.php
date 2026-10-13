<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\AiModelUnitPrice;
use App\Models\Setting;
use App\Services\Ai\AiAvailability;
use App\Services\Ai\AiModelPage;
use App\Services\Ai\AiNative;
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
        $this->assertSame(0, $page['costExample']['tax']);
        $this->assertSame(59, $page['costExample']['charged']);
        $expected = app(AiPricing::class)->charge($state['quote'], 1000, 0, 500, 0);
        $this->assertSame($expected['charged'], $page['costExample']['charged']);
        $settlement = app(AiPricing::class)->charge($state['quote'], 1000, 0, 500, 1000);
        $this->assertSame(6, $settlement['tax']);
        $this->assertSame(65, $settlement['charged']);
    }

    public function test_public_storefront_and_model_content_omit_tax_notices(): void
    {
        foreach (['', '/en', '/tr'] as $prefix) {
            foreach (['/ai', '/ai/models', '/ai/docs', '/ai/models/'.$this->model->slug] as $path) {
                $html = $this->get($prefix.$path)->assertOk()->getContent();
                $dom = new \DOMDocument;
                @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
                $blocks = (new \DOMXPath($dom))->query('//*[contains(concat(" ", normalize-space(@class), " "), " ai-wrap ")]');
                $this->assertGreaterThan(0, $blocks->length);
                foreach ($blocks as $block) {
                    $this->assertDoesNotMatchRegularExpression('/\b(?:tax|VAT|vergi)\b|مالیات/ui', $block->textContent);
                }
            }
        }
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
        $this->assertFalse($page['reasoningSupplierUncontrolled']);
        $prepared = app(AiPayload::class)->prepare($this->chatBody(['max_tokens' => 16]), $this->model);
        $this->assertSame('none', $prepared->forward['reasoning_effort']);
    }

    public function test_gap_reasoning_routes_disclose_shared_completion_limit_without_claiming_thinking_is_disabled(): void
    {
        $this->provider->update(['slug'=>'gapgpt']);
        $this->model->update(['upstream_model'=>'deepseek-v4-flash', 'status'=>'disabled',
            'capabilities'=>['reasoning'=>true]]);
        foreach (['deepseek-reasoner','deepseek-v4-flash','grok-4.3','grok-4.5','grok-4.6'] as $upstream) {
            $this->model->update(['upstream_model'=>$upstream]);
            foreach (['fa'=>'', 'en'=>'/en', 'tr'=>'/tr'] as $locale=>$prefix) {
                app()->setLocale($locale);
                $model = $this->model->fresh(['provider']);
                $page = app(AiModelPage::class)->build($model, app(AiAvailability::class)->inspect($model));
                $this->assertTrue($page['reasoningSupplierUncontrolled']);
                $this->assertFalse($page['reasoningDisabled']);
                $this->get($prefix.'/ai/models/'.$model->slug)->assertOk()
                    ->assertSee(\Illuminate\Support\Facades\Lang::get('ai_model.reasoning_supplier_uncontrolled', [], $locale))
                    ->assertDontSee(\Illuminate\Support\Facades\Lang::get('ai_model.reasoning_disabled', [], $locale));
            }
        }
        $this->model->update(['upstream_model'=>'grok-4']);
        $other = $this->model->fresh(['provider']);
        $page = app(AiModelPage::class)->build($other, app(AiAvailability::class)->inspect($other));
        $this->assertFalse($page['reasoningSupplierUncontrolled']);
        $this->assertTrue($page['reasoningDisabled']);
        $this->provider->update(['slug'=>'deepinfra']);
        $this->model->update(['upstream_model'=>'deepseek-v4-flash']);
        $other = $this->model->fresh(['provider']);
        $page = app(AiModelPage::class)->build($other, app(AiAvailability::class)->inspect($other));
        $this->assertFalse($page['reasoningSupplierUncontrolled']);
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

    public function test_gap_legacy_page_describes_ready_execution_and_keeps_pending_copy(): void
    {
        $this->gapNativeModel('babbage-002', 'classification');
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            app()->setLocale($locale);
            $state = app(AiAvailability::class)->inspect($this->model);
            $this->assertTrue($state['ready']);
            $page = app(AiModelPage::class)->build($this->model, $state);
            $this->assertStringContainsString('prompt', $page['intro']);
            $this->assertStringContainsString('/v1/inference', $page['workflow']);
            $this->assertStringContainsString('X-ServerNet-Charge-Irt', $page['workflow']);
            $this->assertStringNotContainsString(__('ai_model.native_file_download_ready'), $page['workflow']);
            $this->assertSame(__('ai_model.tasks.text-generation'), $page['operationLabel']);
            $this->assertSame(['model' => $this->model->slug, 'max_tokens' => 256, 'prompt' => 'Hello from ServerNet'], $page['example']['body']);
            $this->assertSame('inference', $page['example']['path']);
            $this->assertSame('ai:native', $page['example']['scope']);
            $prepared = app(AiNative::class)->prepare($page['example']['body'], $this->model);
            $this->assertSame(['max_tokens' => 256, 'prompt' => 'Hello from ServerNet', 'n' => 1, 'best_of' => 1, 'stream' => false], $prepared->forward);
            $this->get($prefix.'/ai/models/'.$this->model->slug)->assertOk()
                ->assertSee($page['intro'])->assertSee($page['workflow'])
                ->assertSee(__('ai_model.units.call'))->assertDontSee(__('ai_model.types.classification.workflow'));

            $this->model->update(['status' => 'disabled']);
            $pending = app(AiModelPage::class)->build($this->model, app(AiAvailability::class)->inspect($this->model));
            $this->assertSame(__('ai_model.types.classification.workflow'), $pending['workflow']);
            $this->assertSame($page['example']['body'], $pending['example']['body']);
            $this->assertArrayNotHasKey('offers', $pending['schema']['@graph'][2]);
            $this->get($prefix.'/ai/models/'.$this->model->slug)->assertOk()->assertSee(__('ai_model.pending_example'));
            $this->model->update(['status' => 'active']);
        }
    }

    public function test_gap_parameter_table_matches_enforced_request_limits_and_defaults(): void
    {
        $contract = $this->gapNativeModel('gpt-image-1-mini', 'image');
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            app()->setLocale($locale);
            $page = app(AiModelPage::class)->build($this->model, app(AiAvailability::class)->inspect($this->model));
            $this->assertNull($page['sourceSpec']);
            $this->assertStringContainsString(__('ai_model.native_file_download_ready'), $page['workflow']);
            $fields = collect($page['gatewaySpec']['fields'])->keyBy('name');
            $this->assertTrue($fields['prompt']['required']);
            $this->assertSame(4096, $fields['prompt']['maxBytes']);
            $this->assertSame(['low'], $fields['quality']['enum']);
            $this->assertSame('low', $fields['quality']['default']);
            $this->assertSame(1, $fields['n']['maximum']);
            $this->get($prefix.'/ai/models/'.$this->model->slug)->assertOk()
                ->assertSee('data-ai-input-schema="gateway"', false)->assertSee(__('ai_model.gateway_specs'))
                ->assertSee(__('ai_model.maxBytes'))->assertSee('4096')->assertSee('1024x1024')->assertSee('low')
                ->assertSee(__('ai_model.native_file_download_ready'))
                ->assertDontSee(__('ai_model.source_date', ['date' => $contract['schema_date']]));
            $this->model->update(['status'=>'disabled']);
            $pending = app(AiModelPage::class)->build($this->model, app(AiAvailability::class)->inspect($this->model));
            $this->assertStringNotContainsString(__('ai_model.native_file_download_ready'), $pending['workflow']);
            $this->model->update(['status'=>'active']);
        }
        $valid = app(AiNative::class)->prepare(['prompt' => str_repeat('a', 4096)], $this->model);
        $this->assertSame('low', $valid->forward['quality']);
        try {
            app(AiNative::class)->prepare(['prompt' => str_repeat('a', 4097)], $this->model);
            $this->fail('The displayed UTF-8 byte limit must be enforced.');
        } catch (\App\Services\Ai\AiRequestException $error) {
            $this->assertSame('invalid_payload', $error->errorCode);
        }
    }

    private function gapNativeModel(string $name, string $category): array
    {
        $contracts = json_decode(file_get_contents(resource_path('content/gapgpt-operation-contracts.json')), true, flags: JSON_THROW_ON_ERROR);
        $contract = $contracts[$name];
        $this->provider->update(['slug' => 'gapgpt', 'name' => 'GapGPT']);
        Setting::putSecret('ai_provider_gapgpt_key', 'sk-test');
        Setting::put('ai_provider_gapgpt_base_url', 'https://api.gapgpt.app/v1');
        $this->model->update(['upstream_model' => $name, 'name' => $name, 'vendor' => 'OpenAI', 'category' => $category,
            'capabilities' => ['native_tariff_hash' => AiNative::fingerprint($contract['pricing']),
                'catalogue_source' => 'gapgpt', 'catalogue_at' => now()->toIso8601String(), 'execution_ready' => true]]);
        $this->model->refresh()->setRelation('provider', $this->provider);
        return $contract;
    }

    public function test_bria_gateway_table_has_actual_inputs_defaults_and_no_empty_catalogue_table_in_all_locales(): void
    {
        $this->deepinfraNativeModel('Bria/video_increase_resolution', 'video');
        foreach (['fa' => '', 'en' => '/en', 'tr' => '/tr'] as $locale => $prefix) {
            app()->setLocale($locale);
            $state = app(AiAvailability::class)->inspect($this->model);
            $this->assertTrue($state['ready']);
            $page = app(AiModelPage::class)->build($this->model, $state);
            $this->assertSame([], $page['sourceSpec']['fields']);
            $fields = collect($page['gatewaySpec']['fields'])->keyBy('name');
            $this->assertSame(['video', 'desired_increase', 'preserve_audio', 'output_container_and_codec'], $fields->keys()->all());
            $this->assertTrue($fields['video']['required']);
            $this->assertSame('string', $fields['video']['type']);
            $this->assertSame('data:video/mp4;base64', $fields['video']['format']);
            $this->assertSame(8_000_000, $fields['video']['maxBytes']);
            $this->assertSame([2, 4], $fields['desired_increase']['enum']);
            $this->assertSame(2, $fields['desired_increase']['default']);
            $this->assertSame('integer', $fields['desired_increase']['type']);
            $this->assertSame(true, $fields['preserve_audio']['default']);
            $this->assertSame('mp4_h264', $fields['output_container_and_codec']['default']);
            $this->assertSame(['mp4_h264'], $fields['output_container_and_codec']['enum']);
            $html = $this->get($prefix.'/ai/models/'.$this->model->slug)->assertOk()
                ->assertSee(__('ai_model.gateway_specs'))->assertSee('desired_increase')->assertSee('mp4_h264')
                ->assertDontSee('webhook')->assertDontSee('data-ai-input-schema="catalogue"', false)->getContent();
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new \DOMXPath($dom);
            $this->assertSame(1, $xpath->query('//table[@data-ai-input-schema="gateway"]')->length);
            $this->assertSame(4, $xpath->query('//table[@data-ai-input-schema="gateway"]/tbody/tr')->length);
            $this->assertStringNotContainsString('ai_model.', $html);
        }
        $this->assertSame('active', $this->model->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_deepinfra_ref_and_anyof_display_match_the_enforced_voice_contract(): void
    {
        $this->deepinfraNativeModel('Qwen/Qwen3-TTS', 'audio');
        $page = app(AiModelPage::class)->build($this->model, app(AiAvailability::class)->inspect($this->model));
        $fields = collect($page['gatewaySpec']['fields'])->keyBy('name');
        $this->assertSame('string', $fields['voice']['type']);
        $this->assertSame('Vivian', $fields['voice']['default']);
        $this->assertContains('Serena', $fields['voice']['enum']);
        $this->assertSame('Auto', $fields['language']['default']);
        $this->assertContains('English', $fields['language']['enum']);
        $this->assertSame(4000, $fields['input']['maxLength']);
        $this->assertSame(8000, $fields['input']['maxBytes']);
        foreach (['webhook', 'service_tier', 'fail_fast', 'stream'] as $name) $this->assertFalse($fields->has($name));
        $prepared = app(AiNative::class)->prepare(['input' => 'Hello', 'voice' => 'Serena', 'language' => 'English'], $this->model);
        $this->assertSame('Serena', $prepared->forward['voice']);
        $this->assertSame('English', $prepared->forward['language']);
        $this->assertSame(8000, strlen(app(AiNative::class)->prepare(['input' => str_repeat('😀', 2000)], $this->model)->forward['input']));
        try {
            app(AiNative::class)->prepare(['input' => str_repeat('😀', 2001)], $this->model);
            $this->fail('The displayed 8000-byte limit must also apply below the schema character limit.');
        } catch (\App\Services\Ai\AiRequestException $error) {
            $this->assertSame('invalid_payload', $error->errorCode);
            $this->assertStringContainsString('Native text is too large.', $error->getMessage());
        }
        foreach ([['voice' => 'arbitrary-private-voice'], ['webhook' => 'https://callback.invalid']] as $extra) {
            try {
                app(AiNative::class)->prepare(['input' => 'Hello'] + $extra, $this->model);
                $this->fail('The public accepted fields and resolved voice enum must match enforcement.');
            } catch (\App\Services\Ai\AiRequestException $error) {
                $this->assertSame('invalid_payload', $error->errorCode);
            }
        }
        $this->get('/ai/models/'.$this->model->slug)->assertOk()->assertSee('Serena')->assertSee('English')
            ->assertDontSee('service_tier')->assertDontSee('webhook')->assertDontSee('#/definitions/');
        Http::assertNothingSent();
    }

    public function test_empty_catalogue_schema_does_not_render_an_empty_parameter_table(): void
    {
        $this->model->update(['upstream_model' => 'Bria/video_increase_resolution', 'category' => 'video',
            'status' => 'disabled', 'capabilities' => ['catalogue_type' => 'text-to-video']]);
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->get($prefix.'/ai/models/'.$this->model->slug)->assertOk()->assertDontSee('data-ai-input-schema=', false);
        }
        $this->assertSame('disabled', $this->model->fresh()->status);
        Http::assertNothingSent();
    }

    private function deepinfraNativeModel(string $name, string $category): array
    {
        $contracts = json_decode(file_get_contents(resource_path('content/ai-native-contracts.json')), true, flags: JSON_THROW_ON_ERROR);
        $contract = $contracts['deepinfra:'.$name];
        if ($binary = getenv('AI_TEST_FFMPEG_BINARY')) config(['ai.media_ffmpeg_binary' => $binary]);
        if (! empty($contract['requires_video_decoder']) && ! \App\Services\Ai\AiVideoInput::available()) {
            $this->markTestSkipped('The Bria public ready contract requires the trusted video decoder.');
        }
        $this->model->update(['upstream_model' => $name, 'name' => $name, 'category' => $category,
            'capabilities' => ['catalogue_type' => $contract['task'], 'native_tariff_hash' => $contract['tariff_hash'],
                'execution_ready' => true]]);
        $this->model->refresh()->setRelation('provider', $this->provider);
        return $contract;
    }
}
