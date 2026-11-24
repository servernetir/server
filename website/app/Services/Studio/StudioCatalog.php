<?php

namespace App\Services\Studio;

use App\Models\AiModel;
use App\Services\Ai\AiNative;
use App\Services\Ai\AiPriceQuote;
use App\Services\Ai\AiPricing;
use App\Services\Ai\AiStudioVision;

final class StudioCatalog
{
    private const HIDDEN = [
        'model', 'stream', 'webhook', 'service_tier', 'fail_fast',
        'disable_safety_filter', 'enable_safety_checker', 'safety_tolerance',
        'visual_input_content_moderation', 'visual_output_content_moderation', 'prompt_content_moderation',
    ];

    public static function model(AiModel $model, AiPriceQuote $quote, int $vatBp, bool $nativeAccess): array
    {
        $contract = $quote->native;
        $prices = $contract ? AiNative::unitPrice($quote) : null;
        return [
            'name' => $model->name,
            'vision' => AiStudioVision::supports($model),
            'pricing' => $prices
                ? ['unit' => $prices['unit'], 'quantity' => $prices['quantity'],
                    'amount_irt' => $prices['sell'] + AiPricing::tax($prices['sell'], $vatBp)]
                : (in_array($model->category, ['chat', 'embedding', 'rerank'], true)
                    ? ['unit' => 'tokens', 'quantity' => 1_000_000,
                        'input_irt' => $quote->pIn + AiPricing::tax($quote->pIn, $vatBp)]
                        + ($model->category === 'chat'
                            ? ['output_irt' => $quote->pOut + AiPricing::tax($quote->pOut, $vatBp)] : [])
                    : null),
            'studio' => $contract ? ['native_access' => $nativeAccess, 'fields' => self::fields($contract)] : null,
        ];
    }

    public static function fields(array $contract): ?array
    {
        $schema = $contract['schema'] ?? [];
        $hidden = array_merge(self::HIDDEN, $contract['blocked_fields'] ?? []);
        $defaults = $contract['defaults'] ?? [];
        $fields = [];
        foreach ($schema['properties'] ?? [] as $name => $rule) {
            if (! is_array($rule)) continue;
            $required = in_array($name, $schema['required'] ?? [], true);
            $defaulted = array_key_exists($name, $defaults) || array_key_exists('default', $rule);
            $descriptor = in_array($name, $hidden, true) ? null : self::descriptor(
                $name, $rule, $schema, $required && ! $defaulted, $contract, 0
            );
            if ($descriptor === null) {
                if ($required && ! $defaulted) return null;
                continue;
            }
            if (isset($contract['limits'][$name]) && is_numeric($contract['limits'][$name]) && in_array($descriptor['type'], ['integer','number'], true)) {
                $descriptor['maximum'] = min($descriptor['maximum'] ?? PHP_FLOAT_MAX, (float) $contract['limits'][$name]);
            }
            if (array_key_exists($name, $defaults) && ! in_array($descriptor['type'], ['file','array','object'], true)) {
                $descriptor['default'] = $defaults[$name];
            }
            $fields[] = $descriptor;
        }
        return $fields ?: null;
    }

    private static function descriptor(string $name, array $rule, array $schema, bool $required, array $contract, int $depth): ?array
    {
        if ($depth > 5 || ! preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,49}\z/', $name)) return null;
        // Resolve the same schema shapes as the gateway validator.
        if (isset($rule['$ref'])) $rule = $schema['definitions'][basename($rule['$ref'])] ?? [];
        foreach ($rule['anyOf'] ?? [] as $variant) {
            if (($variant['type'] ?? '') === 'null') continue;
            unset($rule['anyOf']);
            return self::descriptor($name, $variant + $rule, $schema, $required, $contract, $depth + 1);
        }
        $type = $rule['type'] ?? 'string';
        $file = in_array($name, $contract['media_fields'] ?? [], true) || in_array($name, ['url','reference_voice'], true)
            && ($contract['bound_strategy'] ?? '') === 'reference_media';
        $media = $contract['media_types'][$name] ?? match (true) {
            $name === 'url' => 'reference',
            str_contains($name, 'audio'), $name === 'reference_voice' => 'audio',
            str_contains($name, 'video'), $name === 'mask' && ($contract['bound_strategy'] ?? '') === 'input_video_duration' => 'video',
            default => 'image',
        };
        if ($file && ! in_array($type, ['array','string'], true)) return null;
        if (! in_array($type, ['string','integer','number','boolean','array','object'], true)) return null;
        $field = ['name' => $name, 'type' => $file && $type === 'string' ? 'file' : $type, 'required' => $required];
        $admitted = ['minimum','maximum','minItems','maxItems','minLength','maxLength','multipleOf','enum','default'];
        $field += array_intersect_key($rule, array_flip($admitted));
        if ($file && $type === 'string') return ['media' => $media] + $field;
        if ($type === 'array') {
            $field['maxItems'] = min(8, (int) ($field['maxItems'] ?? 8));
            $field['item'] = $file ? ['name' => 'item', 'type' => 'file', 'required' => true, 'media' => $media]
                : self::descriptor('item', $rule['items'] ?? [], $schema, true, $contract, $depth + 1);
            if ($field['item'] === null) return null;
        }
        if ($type === 'object') {
            $field['fields'] = [];
            foreach ($rule['properties'] ?? [] as $child => $info) {
                $childRequired = in_array($child, $rule['required'] ?? [], true);
                $nested = self::descriptor($child, $info, $schema, $childRequired, $contract, $depth + 1);
                if ($nested === null && $childRequired) return null;
                if ($nested !== null) $field['fields'][] = $nested;
            }
        }
        return $field;
    }
}
