<?php

namespace App\Services\Ai;

use App\Models\AiModel;
use Illuminate\Support\Facades\Lang;

/** Public model facts and examples. Rendering never makes an upstream request. */
final class AiModelPage
{
    private static ?array $sourceSpecs = null;

    public static function indexable(AiModel $model): bool
    {
        return $model->status !== AiModel::STATUS_DEPRECATED
            && ! in_array($model->capabilities['pending_reason'] ?? null, ['removed', 'deprecated'], true);
    }

    public function build(AiModel $model, array $state): array
    {
        $locale = app()->getLocale();
        $words = Lang::get('ai_model', [], $locale);
        $type = $words['types'][$model->category] ?? $words['types']['classification'];
        $values = ['name' => $model->name, 'vendor' => $model->vendor ?: $words['unspecified'],
            'type' => __('ai.'.$model->category), 'id' => $model->slug];
        $replace = fn (string $text) => strtr($text, array_combine(
            array_map(fn ($key) => ':'.$key, array_keys($values)), array_values($values)));
        $family = $this->family($model);
        $intro = $replace($type['intro']);
        $summary = $replace($words['summary']);
        $label = $model->status === 'deprecated' ? __('ai.deprecated') : ($state['ready'] ? __('ai.ready') : __('ai.pending'));
        $description = $replace($words['description']).' '.$label.'.';
        $duplicate = AiModel::where('name', $model->name)->whereKeyNot($model->id)->exists();
        $variant = $duplicate ? ' · '.($model->provider?->name ?: $model->slug) : '';
        $title = $model->name.$variant.' API | '.$words['title_suffix'].' | ServerNet';
        $url = lroute('ai.model', $model->slug);
        $tokenOperation = in_array($model->category, ['chat', 'embedding', 'rerank'], true);
        $nativeContract = $state['quote']?->native;
        $nativePrice = $nativeContract ? AiNative::unitPrice($state['quote']) : null;
        $sourceSpec = null;
        if (! $tokenOperation && $model->provider?->slug === 'deepinfra') {
            self::$sourceSpecs ??= json_decode(file_get_contents(resource_path('content/ai-model-specs.json')), true, flags: JSON_THROW_ON_ERROR);
            $sourceSpec = self::$sourceSpecs[$model->upstream_model] ?? null;
            if ($sourceSpec && ($model->capabilities['catalogue_type'] ?? null) !== $sourceSpec['task']) {
                $sourceSpec = null;
            }
        }
        $reasoningDisabled = $model->category === 'chat' && AiPayload::requiresNonReasoning($model);
        $faq = [
            ['q' => $replace($words['faq_available']), 'a' => $replace($state['ready'] ? $words['available_answer'] : $words['pending_answer'])],
            ['q' => $replace($words['faq_connect']), 'a' => $replace($tokenOperation ? $words['connect_answer'] : ($nativeContract ? $words['native_connect_ready'] : $words['native_answer']))],
            ['q' => $replace($words['faq_price']), 'a' => $replace($tokenOperation ? $type['billing'] : ($nativeContract ? $words['native_billing_ready'] : $words['native_billing']))],
            ['q' => $replace($words['faq_limits']), 'a' => $replace($type['limit'])],
        ];
        $example = $tokenOperation || $nativeContract ? $this->example($model,$nativeContract) : null;
        $vatBp = app(AiVat::class)->iranRateBp();
        $costExample = null;
        if ($tokenOperation && $state['quote']) {
            $completion = $model->category === 'chat' ? min(500, max(1, $model->max_output_tokens)) : 0;
            // Use the same integer arithmetic as settlement, never a second price formula.
            $costExample = app(AiPricing::class)->charge($state['quote'], 1000, 0, $completion, $vatBp);
            $costExample += ['input' => 1000, 'output' => $completion];
        }
        $facts = [
            ['name' => $words['model_id'], 'value' => $model->slug],
            ['name' => $words['developer'], 'value' => $values['vendor']],
            ['name' => $words['operation'], 'value' => $values['type']],
            ['name' => $words['availability'], 'value' => $label],
        ];
        if ($tokenOperation && $model->context_tokens > 0) {
            $facts[] = ['name' => $words['gateway_window'], 'value' => number_format($model->context_tokens).' '.__('ai.tokens')];
        }
        if ($model->category === 'chat') {
            $facts[] = ['name' => __('ai.cap'), 'value' => number_format($model->max_output_tokens).' '.__('ai.tokens')];
        }
        if ($sourceSpec) {
            $facts[] = ['name' => $words['source_task'], 'value' => __('ai_model.tasks.'.$sourceSpec['task'])];
            $facts[] = ['name' => $words['source_unit'], 'value' => __('ai_model.units.'.$sourceSpec['pricing_type'])];
        }
        $service = ['@type' => 'Service', '@id' => $url.'#api', 'name' => $model->name.' API'.$variant,
            'description' => $intro, 'url' => $url, 'serviceType' => $values['type'],
            'provider' => ['@type' => 'Organization', 'name' => 'ServerNet', 'url' => url('/')]];
        if ($state['ready'] && $state['quote'] && $tokenOperation) {
            $rates = [['label' => __('ai.input'), 'rate' => $state['quote']->pIn]];
            if ($model->category === 'chat') {
                $rates[] = ['label' => __('ai.output'), 'rate' => $state['quote']->pOut];
            }
            $service['offers'] = array_map(fn ($rate) => [
                '@type' => 'Offer', 'name' => $rate['label'].' · '.__('ai.price'), 'url' => $url,
                'availability' => 'https://schema.org/InStock',
                'priceSpecification' => ['@type' => 'UnitPriceSpecification',
                    // ISO currency is IRR; the visible price and API wallet use IRT (10 IRR).
                    'priceCurrency' => 'IRR', 'price' => (string) \Brick\Math\BigInteger::of($rate['rate'])->multipliedBy(10),
                    'valueAddedTaxIncluded' => false,
                    'referenceQuantity' => ['@type' => 'QuantitativeValue', 'value' => 1000000, 'unitText' => __('ai.tokens')]],
            ], $rates);
        }
        if ($state['ready'] && $nativePrice) {
            $service['offers']=['@type'=>'Offer','name'=>__('ai_model.native_unit_ceiling'),'url'=>$url,
                'availability'=>'https://schema.org/InStock','priceSpecification'=>['@type'=>'UnitPriceSpecification',
                    'priceCurrency'=>'IRR','maxPrice'=>(string)\Brick\Math\BigInteger::of($nativePrice['sell'])->multipliedBy(10),
                    'valueAddedTaxIncluded'=>false,'referenceQuantity'=>['@type'=>'QuantitativeValue',
                        'value'=>$nativePrice['quantity'],'unitText'=>__('ai_model.units.'.$nativePrice['unit'])]]];
        }
        $schema = ['@context' => 'https://schema.org', '@graph' => [
            ['@type' => 'WebPage', '@id' => $url.'#page', 'url' => $url, 'name' => $title,
                'description' => $description, 'inLanguage' => $locale, 'mainEntity' => ['@id' => $url.'#api'],
                'breadcrumb' => ['@id' => $url.'#breadcrumbs']],
            ['@type' => 'BreadcrumbList', '@id' => $url.'#breadcrumbs', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'AI Gateway', 'item' => lroute('ai.home')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => __('ai.catalog'), 'item' => lroute('ai.models')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $model->name, 'item' => $url],
            ]], $service,
        ]];
        return compact('title', 'description', 'intro', 'summary', 'family', 'faq', 'example', 'costExample',
            'vatBp', 'facts', 'schema', 'url', 'tokenOperation', 'reasoningDisabled', 'sourceSpec','nativePrice','nativeContract')
            + ['uses' => array_map($replace, $type['uses']), 'workflow' => $replace($type['workflow']),
                'limit' => $replace($type['limit']), 'indexable' => self::indexable($model)];
    }

    private function family(AiModel $model): ?string
    {
        $name = strtolower($model->name.' '.$model->vendor);
        // Task-specific advice, not invented benchmark scores or language guarantees.
        foreach (['safety', 'coder', 'code', 'r1', 'deepseek', 'llama', 'qwen', 'gemma', 'gpt', 'claude',
            'gemini', 'grok', 'glm', 'mistral', 'bge', 'e5', 'whisper', 'flux', 'tts'] as $family) {
            if (str_contains($name, $family)) {
                return __('ai_model.families.'.$family, ['name' => $model->name]);
            }
        }
        return null;
    }

    private function example(AiModel $model,?array $native = null): array
    {
        [$path, $scope, $body] = match ($model->category) {
            'embedding' => ['embeddings', 'ai:embedding', ['model' => $model->slug, 'input' => ['Hello world', 'Search documents']]],
            'rerank' => ['rerank', 'ai:rerank', ['model' => $model->slug, 'query' => 'What is the capital of Iran?',
                'documents' => ['Tehran is the capital of Iran.', 'Paris is the capital of France.']]],
            default => ['chat/completions', 'ai:chat', ['model' => $model->slug,
                'messages' => [['role' => 'user', 'content' => 'Hello']], 'max_tokens' => min(128, max(1, $model->max_output_tokens))]],
        };
        if ($native) {
            $path='inference'; $scope='ai:native';$body=['model'=>$model->slug]+$native['defaults'];
            foreach ($native['schema']['required']??[] as $key) {
                if (array_key_exists($key,$body)) continue;
                $rule=$native['schema']['properties'][$key];
                $value=$rule['example']??$rule['enum'][0]??match($rule['type']??'string') {
                    'integer','number'=>$rule['minimum']??1,'boolean'=>false,'array'=>[],default=>'Hello from ServerNet',
                };
                if (in_array($key,$native['media_fields'],true)) {
                    $value='data:'.(str_contains($key,'audio')?'audio/wav':'image/png').';base64,REPLACE_WITH_YOUR_FILE';
                    if (($rule['type']??null)==='array') $value=[$value];
                }
                $body[$key]=$value;
            }
        }
        $json = json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // JSON is double quoted inside a POSIX single quoted string. Escape arbitrary model IDs safely.
        $quoted = str_replace("'", "'\"'\"'", $json);
        $curl = "curl https://servernet.cloud/v1/{$path} \\\n  -H \"Authorization: Bearer \$SERVERNET_API_KEY\" \\\n  -H 'Content-Type: application/json' \\\n  -H 'Idempotency-Key: CHANGE_FOR_EACH_NEW_REQUEST' \\\n  -d '{$quoted}'";
        return compact('path', 'scope', 'body', 'curl');
    }
}
