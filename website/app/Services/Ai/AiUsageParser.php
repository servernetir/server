<?php

namespace App\Services\Ai;

/**
 * خواندنِ `usage` از پاسخِ ارائه‌دهنده (m5-spec §4.C1).
 *
 * `prompt_tokens` و `completion_tokens` باید عددِ صحیحِ نامنفی باشند؛ هر چیزِ دیگر
 * (غایب، رشته، منفی، اعشاری) ⇒ null ⇒ مسیرِ «نامعلوم» (نگه‌داشتن، بازیابی، سقف)،
 * هرگز «صفر»: مصرفِ حدسیِ صفر یعنی کارِ واقعیِ ارائه‌دهنده را مجانی دادن.
 *
 * - کش‌شده: `prompt_tokens_details.cached_tokens` (اختیاری؛ به سقفِ prompt بسته می‌شود)
 * - استدلال: `completion_tokens_details.reasoning_tokens` — فقط نمایش؛ داخلِ
 *   completion است و دو بار شارژ نمی‌شود (D4)
 * - `estimated_cost`: از **متنِ خامِ** پاسخ خوانده می‌شود، نه از JSON ِ رمزگشایی‌شده —
 *   `json_decode` آن را float می‌کرد و رقمِ آخرِ هزینه گم می‌شد.
 */
final class AiUsageParser
{
    /**
     * @return array{prompt:int,completion:int,cached:int,reasoning:?int,estimated_cost:?string}|null
     */
    public static function parse(array $body, string $raw = '', string $operation = 'chat',?array $native=null): ?array
    {
        if ($operation === 'native') return AiNative::parse($body,$raw,$native);
        if (in_array($operation, ['embedding', 'rerank'], true)) {
            if (isset($body['error']) || ($operation === 'embedding' && (! is_array($body['data'] ?? null) || $body['data'] === []))
                || ($operation === 'rerank' && ((isset($body['inference_status']['status']) && $body['inference_status']['status'] !== 'succeeded')
                    || ! is_array($body['scores'] ?? null) || $body['scores'] === []))) {
                return null;
            }
            $prompt = $operation === 'embedding' ? ($body['usage']['prompt_tokens'] ?? null) : ($body['input_tokens'] ?? null);
            if (! self::count($prompt)) {
                return null;
            }
            $cost = $operation === 'rerank'
                ? (preg_match('/"inference_status"\s*:\s*\{[^{}]*"cost"\s*:\s*"?([0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)"?/', $raw, $match) === 1 ? $match[1] : null)
                : self::estimatedCost($raw, $body['usage'] ?? []);
            if ($operation === 'rerank' && ($cost === null || ! array_is_list($body['scores'])
                || array_filter($body['scores'], fn ($score) => ! is_numeric($score) || ! is_finite((float) $score)) !== [])) return null;
            return ['prompt' => $prompt, 'completion' => 0, 'cached' => 0, 'reasoning' => null, 'estimated_cost' => $cost];
        }
        $u = $body['usage'] ?? null;

        if (! is_array($u) || ! self::count($u['prompt_tokens'] ?? null) || ! self::count($u['completion_tokens'] ?? null)) {
            return null;
        }

        $prompt = $u['prompt_tokens'];
        $cached = $u['prompt_tokens_details']['cached_tokens'] ?? $u['prompt_cache_hit_tokens'] ?? 0;
        $reasoning = $u['completion_tokens_details']['reasoning_tokens'] ?? null;

        return [
            'prompt' => $prompt,
            'completion' => $u['completion_tokens'],
            'cached' => self::count($cached) ? min($cached, $prompt) : 0,
            'reasoning' => self::count($reasoning) ? $reasoning : null,
            'estimated_cost' => self::estimatedCost($raw, $u),
        ];
    }

    private static function count(mixed $v): bool
    {
        return is_int($v) && $v >= 0;
    }

    /** رقم‌به‌رقم از متنِ خام؛ اگر متنِ خام نبود، از مقدارِ رشته‌ایِ JSON */
    private static function estimatedCost(string $raw, array $usage): ?string
    {
        if ($raw !== '' && preg_match('/"estimated_cost"\s*:\s*"?([0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)"?/', $raw, $m) === 1) {
            return $m[1];
        }

        $v = $usage['estimated_cost'] ?? null;

        return is_string($v) && is_numeric($v) ? $v : null;
    }
}
