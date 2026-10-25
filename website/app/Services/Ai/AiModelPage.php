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
        $nativeContract = $state['quote']?->native;
        $readyNative = $state['ready'] && $nativeContract;
        $legacyCompletion = ($nativeContract['protocol'] ?? null) === 'gap-legacy-completions';
        $operationLabel = $words['tasks'][$nativeContract['task'] ?? ''] ?? __('ai.'.$model->category);
        $values = ['name' => $model->name, 'vendor' => $model->vendor ?: $words['unspecified'],
            'type' => $operationLabel, 'id' => $model->slug];
        $replace = fn (string $text) => strtr($text, array_combine(
            array_map(fn ($key) => ':'.$key, array_keys($values)), array_values($values)));
        $family = $this->family($model);
        $catalogueText = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', (string) $model->description);
        $sourceDescription = trim(preg_replace('/\s+/u', ' ', strip_tags($catalogueText)));
        // Catalogue prose stays plain text; never expose embedded credentials or raw supplier URLs.
        if (preg_match('/(?:sk-[A-Za-z0-9]{8,}|Bearer\s+|api[_-]?key\s*[:=])/i', $sourceDescription)) $sourceDescription = '';
        $sourceDescription = preg_replace('~https?://\S+~u', '', $sourceDescription);
        $sourceDescription = \Illuminate\Support\Str::limit($sourceDescription, 4000);
        $evaluation = __($model->category === 'chat' && $model->context_tokens > 0 ? 'ai_experience.fact_context' : 'ai_experience.fact_input',
            ['name' => $model->name, 'context' => number_format($model->context_tokens), 'output' => number_format($model->max_output_tokens)]);
        $intro = $replace($readyNative ? $words[$legacyCompletion ? 'native_legacy_intro_ready' : 'native_intro_ready'] : $type['intro']);
        $workflow = $replace($readyNative ? $words['native_workflow_ready'] : $type['workflow']);
        if ($readyNative && in_array($model->category, ['video', 'music'], true)) {
            $workflow .= ' '.$words['native_workflow_async'];
        }
        if ($readyNative && ! $legacyCompletion && ($nativeContract['protocol'] ?? null) !== 'gap-systemone') {
            $workflow .= ' '.$words['native_file_download_ready'];
        }
        $limit = $replace($readyNative ? $words['native_limit_ready'] : $type['limit']);
        $uses = array_map($replace, $readyNative && $legacyCompletion ? $words['native_legacy_uses'] : $type['uses']);
        $nativeBilling = $replace($readyNative ? $words[($nativeContract['pricing']['type'] ?? '') === 'token_usage'
            ? 'native_token_billing_ready' : 'native_billing_ready'] : $words['native_billing']);
        $summary = $replace($words['summary']);
        $label = $model->status === 'deprecated' ? __('ai.deprecated') : ($state['ready'] ? __('ai.ready') : __('ai.pending'));
        $description = $replace($words['description']).' '.$label.'.';
        $duplicate = AiModel::where('name', $model->name)->whereKeyNot($model->id)->exists();
        $variant = $duplicate ? ' · '.($model->provider?->name ?: $model->slug) : '';
        $title = $model->name.$variant.' API | '.$words['title_suffix'].' | ServerNet';
        $url = lroute('ai.model', $model->slug);
        $tokenOperation = in_array($model->category, ['chat', 'embedding', 'rerank'], true);
        $nativePrice = $nativeContract ? AiNative::unitPrice($state['quote']) : null;
        $nativeNote = isset($words['native_formulas'][$nativeContract['bound_strategy']??''])
            ? $words['native_formulas'][$nativeContract['bound_strategy']] : null;
        if ($nativeNote !== null) $nativeNote = str_replace([':seconds', ':size_limit'],
            [(string) ($nativeContract['max_input_video_seconds'] ?? '10'),
                isset($nativeContract['max_input_video_dimension']) ? $words['native_video_size_safe'] : '1080p'], $nativeNote);
        $sourceSpec = null;
        if (! $tokenOperation && $model->provider?->slug === 'deepinfra') {
            self::$sourceSpecs ??= json_decode(file_get_contents(resource_path('content/ai-model-specs.json')), true, flags: JSON_THROW_ON_ERROR);
            $sourceSpec = self::$sourceSpecs[$model->upstream_model] ?? null;
            if ($sourceSpec && ($model->capabilities['catalogue_type'] ?? null) !== $sourceSpec['task']) {
                $sourceSpec = null;
            }
        }
        $gatewaySpec = null;
        if ($nativeContract && in_array($model->provider?->slug, ['gapgpt', 'deepinfra'], true)) {
            $deepinfra = $model->provider?->slug === 'deepinfra';
            $schema = $nativeContract['schema'];
            $blocked = $deepinfra ? [...($nativeContract['blocked_fields'] ?? []), 'model', 'webhook', 'service_tier', 'fail_fast', 'stream'] : [];
            $fields = [];
            foreach ($schema['properties'] as $name => $rule) {
                if (in_array($name, $blocked, true)) continue;
                if ($deepinfra) $rule = $this->nativeDisplayRule($rule, $schema);
                $field = ['name' => $name, 'required' => in_array($name, $schema['required'] ?? [], true)] + $rule;
                if (is_array($field['type'] ?? null)) $field['type'] = implode(' | ', $field['type']);
                if (array_key_exists($name, $nativeContract['defaults'])) {
                    $field['default'] = $nativeContract['defaults'][$name];
                }
                if ($deepinfra) {
                    // Show the accepted gateway contract, not supplier URL examples or hidden controls.
                    if (array_key_exists($name, $nativeContract['defaults']) || isset($rule['default'])) {
                        $field['required'] = false;
                    } elseif (array_key_exists('default', $field)) {
                        unset($field['default']); // The validator does not fill a schema default of null.
                    }
                    $type = $rule['type'] ?? 'string';
                    $field['type'] = $type.(($rule['nullable'] ?? false) ? ' | null' : '');
                    if (isset($nativeContract['limits'][$name])) {
                        $limit = $nativeContract['limits'][$name];
                        if (! isset($field['maximum']) || \Brick\Math\BigDecimal::of((string) $limit)->isLessThan((string) $field['maximum'])) {
                            $field['maximum'] = $limit;
                        }
                        if (isset($field['enum'])) $field['enum'] = array_values(array_filter($field['enum'],
                            fn ($value) => ! is_int($value) && ! is_float($value)
                                || \Brick\Math\BigDecimal::of((string) $value)->isLessThanOrEqualTo((string) $field['maximum'])));
                    }
                    if ($type === 'array') $field['maxItems'] = min(8, $rule['maxItems'] ?? 8);
                    if ($type === 'string') $field['maxBytes'] = 8000;
                    if (in_array($name, $nativeContract['media_fields'] ?? [], true)) {
                        $media = $nativeContract['media_types'][$name] ?? (str_contains($name, 'audio') ? 'audio' : 'image');
                        $field['format'] = 'data:'.match ($media) {
                            'video' => 'video/mp4', 'audio' => 'audio/wav', default => 'image/(png|jpeg|webp)',
                        }.';base64';
                        if ($type === 'string') $field['maxBytes'] = 8_000_000;
                    }
                }
                $fields[] = $field;
            }
            if ($fields !== []) $gatewaySpec = ['schema_date' => $nativeContract['schema_date'], 'fields' => $fields];
        }
        $reasoningSupplierUncontrolled = $model->category === 'chat' && $model->provider?->slug === 'gapgpt'
            && in_array($model->upstream_model, ['deepseek-reasoner','deepseek-v4-flash','grok-4.3','grok-4.5','grok-4.6'],true);
        $reasoningDisabled = $model->category === 'chat' && ! $reasoningSupplierUncontrolled
            && AiPayload::requiresNonReasoning($model);
        $faq = [
            ['q' => $replace($words['faq_available']), 'a' => $replace($state['ready'] ? $words['available_answer'] : $words['pending_answer'])],
            ['q' => $replace($words['faq_connect']), 'a' => $replace($tokenOperation ? $words['connect_answer'] : ($readyNative ? $words['native_connect_ready'] : $words['native_answer']))],
            ['q' => $replace($words['faq_price']), 'a' => $tokenOperation ? $replace($type['billing']) : $nativeBilling],
            ['q' => $replace($words['faq_limits']), 'a' => $limit],
        ];
        $example = $tokenOperation || $nativeContract ? $this->example($model,$nativeContract) : null;
        $costExample = null;
        if ($tokenOperation && $state['quote']) {
            $completion = $model->category === 'chat' ? min(500, max(1, $model->max_output_tokens)) : 0;
            // Public examples show the usage price. Payment uses the account's applicable tax.
            // Keep settlement's integer arithmetic rather than a second price formula.
            $costExample = app(AiPricing::class)->charge($state['quote'], 1000, 0, $completion, 0);
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
                ['@type' => 'ListItem', 'position' => 3, 'name' => __('ai.'.$model->category),
                    'item' => lroute('ai.models', ['type' => $model->category])],
                ['@type' => 'ListItem', 'position' => 4, 'name' => $model->name, 'item' => $url],
            ]], $service,
            ['@type' => 'FAQPage', '@id' => $url.'#faq', 'mainEntity' => array_map(fn ($item) =>
                ['@type' => 'Question', 'name' => $item['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']]], $faq)],
        ]];
        return compact('title', 'description', 'intro', 'summary', 'family', 'faq', 'example', 'costExample', 'sourceDescription', 'evaluation',
            'facts', 'schema', 'url', 'tokenOperation', 'reasoningDisabled', 'reasoningSupplierUncontrolled', 'sourceSpec', 'gatewaySpec',
            'nativePrice', 'nativeContract', 'nativeNote', 'nativeBilling', 'operationLabel', 'uses', 'workflow', 'limit')
            + ['indexable' => self::indexable($model)];
    }

    private function nativeDisplayRule(array $rule, array $schema): array
    {
        // Follow the same definitions/first non-null choice used by the native input validator.
        if (isset($rule['$ref'])) return $schema['definitions'][basename($rule['$ref'])] ?? [];
        if (isset($rule['anyOf'])) {
            foreach ($rule['anyOf'] as $option) if (($option['type'] ?? '') !== 'null') {
                return $this->nativeDisplayRule($option, $schema) + $rule
                    + ['nullable' => count(array_filter($rule['anyOf'], fn ($item) => ($item['type'] ?? '') === 'null')) > 0];
            }
        }
        return $rule;
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
                    $kind=$native['media_types'][$key]??(str_contains($key,'audio')?'audio':'image');
                    $value='data:'.match($kind){'video'=>'video/mp4','audio'=>'audio/wav',default=>'image/png'}.';base64,REPLACE_WITH_YOUR_FILE';
                    if (($rule['type']??null)==='array') $value=[$value];
                }
                $body[$key]=$value;
            }
            if (($native['bound_strategy']??'')==='reference_media') $body['media']=[['type'=>'reference_image','url'=>'data:image/png;base64,REPLACE_WITH_YOUR_FILE']];
            if (isset($body['key_points'])) $body['key_points']=[['type'=>'positive','x'=>1,'y'=>1]];
        }
        $json = json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // JSON is double quoted inside a POSIX single quoted string. Escape arbitrary model IDs safely.
        $quoted = str_replace("'", "'\"'\"'", $json);
        $curl = "curl https://servernet.cloud/v1/{$path} \\\n  -H \"Authorization: Bearer \$SERVERNET_API_KEY\" \\\n  -H 'Content-Type: application/json' \\\n  -H 'Idempotency-Key: CHANGE_FOR_EACH_NEW_REQUEST' \\\n  -d '{$quoted}'";
        $pythonJson = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS);
        $python = "import json, os, urllib.request\nfrom uuid import uuid4\n\nbody = json.loads(".$pythonJson.")\nrequest = urllib.request.Request(\n    \"https://servernet.cloud/v1/{$path}\",\n    data=json.dumps(body).encode(),\n    headers={\n        \"Authorization\": \"Bearer \" + os.environ[\"SERVERNET_API_KEY\"],\n        \"Content-Type\": \"application/json\",\n        \"Idempotency-Key\": str(uuid4()),\n    },\n    method=\"POST\",\n)\nwith urllib.request.urlopen(request) as response:\n    print(json.load(response))";
        $javascript = "import { randomUUID } from 'node:crypto';\n\nconst response = await fetch('https://servernet.cloud/v1/{$path}', {\n  method: 'POST',\n  headers: {\n    Authorization: 'Bearer ' + process.env.SERVERNET_API_KEY,\n    'Content-Type': 'application/json',\n    'Idempotency-Key': randomUUID(),\n  },\n  body: JSON.stringify(".$json."),\n});\nif (!response.ok) throw new Error('HTTP ' + response.status);\nconsole.log(await response.json());";
        return compact('path', 'scope', 'body', 'curl', 'python', 'javascript');
    }
}
