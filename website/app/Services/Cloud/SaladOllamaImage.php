<?php

namespace App\Services\Cloud;

use App\Models\Setting;
use App\Models\CloudImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Approved Ollama release for new deliveries. Runtime upgrades are separate:
 * refreshing the catalog must never restart an existing customer container.
 * Discover stable upstream releases only in the scheduled refresh command.
 * Checkout reads a verified, digest-pinned reference without network calls.
 */
final class SaladOllamaImage
{
    public const REPO = 'ollama/ollama';
    public const VERSION = '0.40.17';
    public const FLOOR = self::VERSION;
    public const SETTING = 'salad_ollama_tag';
    public const RELEASE_SETTING = 'salad_ollama_stable_release';
    public const RELEASE_URL = 'https://api.github.com/repos/ollama/ollama/releases/latest';
    public const TAG_URL = 'https://hub.docker.com/v2/namespaces/ollama/repositories/ollama/tags/';

    public function ref(): string
    {
        $release = $this->approved(Setting::get(self::RELEASE_SETTING));
        return self::REPO.':'.$this->tag().($release ? '@'.$release['digest'] : '');
    }

    public function tag(): string
    {
        return $this->approved(Setting::get(self::RELEASE_SETTING))['version'] ?? self::VERSION;
    }

    private function approved(?string $raw): ?array
    {
        $data = json_decode($raw ?? '', true);
        return is_array($data) && ($data['source'] ?? '') === 'official-stable-linux-amd64'
            && is_string($data['version'] ?? null) && preg_match('/\A\d+\.\d+\.\d+\z/', $data['version'])
            && version_compare($data['version'], self::FLOOR, '>=')
            && is_string($data['digest'] ?? null) && preg_match('/\Asha256:[a-f0-9]{64}\z/', $data['digest'])
            ? $data : null;
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
        $version = $this->tag();
        if (Setting::get(self::SETTING, '') !== $version) {
            Setting::put(self::SETTING, $version);
        }
        return $version;
    }

    /** Metadata-only refresh. Existing container groups are never modified. */
    public function discoverStable(): array
    {
        try {
            $release = Http::acceptJson()->connectTimeout(3)->timeout(8)->withoutRedirecting()->get(self::RELEASE_URL);
            $upstream = $release->json();
            if (! $release->successful() || ! is_array($upstream)
                || ($upstream['draft'] ?? true) !== false || ($upstream['prerelease'] ?? true) !== false
                || ! is_string($upstream['tag_name'] ?? null)
                || ! preg_match('/\Av(\d+\.\d+\.\d+)\z/', $upstream['tag_name'], $match)
                || version_compare($match[1], self::FLOOR, '<')) {
                return ['ok' => false, 'reason' => 'invalid_stable_release', 'version' => $this->tag()];
            }
            $version = $match[1];
            $image = Http::acceptJson()->connectTimeout(3)->timeout(8)->withoutRedirecting()->get(self::TAG_URL.$version);
            $metadata = $image->json();
            $digest = null;
            if ($image->successful() && is_array($metadata) && ($metadata['name'] ?? '') === $version
                && ($metadata['tag_status'] ?? '') === 'active') {
                foreach ((array) ($metadata['images'] ?? []) as $entry) {
                    if (is_array($entry) && ($entry['os'] ?? '') === 'linux'
                        && ($entry['architecture'] ?? '') === 'amd64' && ($entry['status'] ?? '') === 'active'
                        && is_string($entry['digest'] ?? null) && preg_match('/\Asha256:[a-f0-9]{64}\z/', $entry['digest'])) {
                        $digest = $entry['digest']; break;
                    }
                }
            }
            if ($digest === null) {
                return ['ok' => false, 'reason' => 'official_amd64_image_unavailable', 'version' => $this->tag()];
            }
            $candidate = ['source' => 'official-stable-linux-amd64', 'version' => $version,
                'digest' => $digest, 'verified_at' => now()->toIso8601String()];
            return DB::transaction(function () use ($candidate) {
                Setting::firstOrCreate(['key' => self::RELEASE_SETTING], ['value' => null]);
                $row = Setting::where('key', self::RELEASE_SETTING)->lockForUpdate()->firstOrFail();
                $current = $this->approved($row->value);
                // Never downgrade, or silently replace the digest of an approved tag.
                $changed = ! $current || version_compare($candidate['version'], $current['version'], '>');
                $chosen = $changed ? $candidate : $current;
                $imageId = $this->alignCatalog($chosen);
                // Clear settings caches only after catalogue work succeeds. No
                // cached settings reads occur inside this write transaction.
                Setting::put(self::RELEASE_SETTING, json_encode($chosen, JSON_THROW_ON_ERROR));
                Setting::put(self::SETTING, $chosen['version']);
                Setting::put('salad_ollama_stable_checked_at', now()->toIso8601String());
                return ['ok' => true, 'changed' => $changed, 'version' => $chosen['version'], 'image_id' => $imageId];
            });
        } catch (\Throwable) {
            return ['ok' => false, 'reason' => 'release_refresh_unavailable', 'version' => $this->tag()];
        }
    }

    private function alignCatalog(array $release): ?int
    {
        return DB::transaction(function () use ($release) {
            $rows = CloudImage::where('provider', 'salad')->where('key', 'gpu-ollama')->lockForUpdate()->get()
                ->filter(fn ($row) => self::isOurs((string) $row->provider_ref));
            $source = $rows->firstWhere('is_active', true) ?? $rows->first();
            if ($source === null) { return null; }
            $ref = self::REPO.':'.$release['version'].'@'.$release['digest'];
            $image = $rows->firstWhere('provider_ref', $ref);
            if ($image === null) { $image = $source->replicate(); $image->provider_ref = $ref; }
            $image->is_active = $rows->contains(fn ($row) => $row->is_active) && Setting::where('key', 'salad_ollama_sales_paused')->value('value') !== '1';
            $image->version = $release['version'];
            $image->save();
            foreach ($rows as $old) {
                if ($old->id !== $image->id && $old->is_active) { $old->update(['is_active' => false]); }
            }
            return $image->id;
        });
    }

}
