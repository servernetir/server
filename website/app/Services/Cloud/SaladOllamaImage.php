<?php

namespace App\Services\Cloud;

use App\Models\Setting;

/**
 * Approved Ollama release for new deliveries. Runtime upgrades are separate:
 * refreshing the catalog must never restart an existing customer container.
 * Pin the release instead of automatically shipping untested registry tags.
 */
final class SaladOllamaImage
{
    public const REPO = 'ollama/ollama';
    public const VERSION = '0.40.17';
    public const FLOOR = self::VERSION;
    public const SETTING = 'salad_ollama_tag';

    public function ref(): string
    {
        return self::REPO.':'.self::VERSION;
    }

    public function tag(): string
    {
        return self::VERSION;
    }

    /** Recognize saved orders and existing deployments without changing them. */
    public static function isOurs(string $imageRef): bool
    {
        return in_array(self::repoOf($imageRef), [
            self::REPO,
            'saladtechnologies/ollama',
            'saladtechnologies/ollama-llama3.1-recipe',
        ], true);
    }

    public static function repoOf(string $imageRef): string
    {
        $ref = explode('@', trim($imageRef), 2)[0];
        $pos = strrpos($ref, ':');

        if ($pos === false || str_contains(substr($ref, $pos), '/')) {
            return $ref;
        }

        return substr($ref, 0, $pos);
    }

    /** Keep the displayed setting aligned; no registry calls or runtime writes. */
    public function refresh(): string
    {
        if (Setting::get(self::SETTING, '') !== self::VERSION) {
            Setting::put(self::SETTING, self::VERSION);
        }

        return self::VERSION;
    }
}
