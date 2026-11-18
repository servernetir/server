<?php

namespace App\Services\Ai;

use App\Models\AiModel;

final class AiStudioVision
{
    public static function supports(AiModel $model): bool
    {
        // The current DeepSeek Flash API supports vision. No remote URL or provider
        // selected by the browser is admitted to this input path.
        return $model->category === 'chat' && $model->provider?->slug === 'deepseek'
            && $model->upstream_model === 'deepseek-flash';
    }

    public static function inputBound(array $part, AiModel $model): int
    {
        $value = $part['image_url']['url'] ?? null;
        if (! self::supports($model) || array_diff(array_keys($part), ['type','image_url']) !== []
            || ! is_array($part['image_url'] ?? null) || array_keys($part['image_url']) !== ['url']
            || ! is_string($value) || strlen($value) > 2_800_000
            || ! preg_match('/\Adata:(image\/(?:png|jpeg|webp));base64,([A-Za-z0-9+\/]+={0,2})\z/', $value, $match)) {
            throw new AiRequestException('unsupported_parameter', 'Only a supported chat model may receive an inline image.', 400);
        }
        $bytes = base64_decode($match[2], true);
        $size = $bytes === false ? false : @getimagesizefromstring($bytes);
        if (! $size || ($size['mime'] ?? '') !== $match[1] || $size[0] < 1 || $size[1] < 1
            || $size[0] > 1024 || $size[1] > 1024) {
            throw new AiRequestException('invalid_payload', 'The image must be at most 1024 pixels on each side.', 422);
        }
        // DeepSeek Flash's documented image limit is 1,024 billed input tokens.
        // https://api-docs.deepseek.com/guides/vision/#token-usage
        return 1024;
    }
}
