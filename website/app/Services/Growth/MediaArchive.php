<?php

namespace App\Services\Growth;

use App\Models\GrowthAsset;
use App\Models\GrowthContent;
use App\Models\GrowthJob;
use App\Services\SafeUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class MediaArchive
{
    public function fromProvider(GrowthContent $item, GrowthJob $job, array $response): GrowthAsset
    {
        if ($old = GrowthAsset::where('job_id', $job->id)->first()) {
            return $old;
        }
        $encoded = data_get($response, 'data.0.b64_json');
        $image = data_get($response, 'images.0');
        if (! $encoded && is_string($image) && str_starts_with($image, 'data:image/')) {
            $encoded = substr($image, strpos($image, ',') + 1);
        }
        if (is_string($encoded)) {
            if (strlen($encoded) > 34_000_000 || ($bytes = base64_decode($encoded, true)) === false) {
                throw new \RuntimeException('invalid_image');
            }
        } else {
            $url = data_get($response, 'image_url') ?? data_get($response, 'data.0.url') ?? (is_string($image) ? $image : data_get($image, 'url'));
            if (! is_string($url)) {
                throw new \RuntimeException('media_output_unavailable');
            }
            $bytes = $this->download($url);
        }

        return $this->save($item, $bytes, 'Generated via configured API; review rights and visual claims before publishing.', $job->id);
    }

    public function download(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || ! in_array($host, config('ai_growth.media_hosts', []), true)) {
            throw new \RuntimeException('media_host_not_allowed');
        }
        $records = array_merge(dns_get_record($host, DNS_A) ?: [], dns_get_record($host, DNS_AAAA) ?: []);
        $ips = array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
        if (! $ips || count(array_filter($ips, fn ($ip) => ! SafeUrl::isPublicIp($ip)))) {
            throw new \RuntimeException('unsafe_media_address');
        }
        // Pin DNS after validation. Redirects and environment proxy bypass are disabled.
        $ip = str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0];
        $res = Http::connectTimeout(5)->timeout(40)->withoutRedirecting()->withOptions([
            'proxy' => '', 'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$ip]],
            'progress' => function ($total, $downloaded) {
                if ($total > config('ai_growth.max_asset_bytes') || $downloaded > config('ai_growth.max_asset_bytes')) {
                    throw new \RuntimeException('asset_too_large');
                }
            },
        ])->get($url);
        if (! $res->successful()) {
            throw new \RuntimeException('media_download_failed');
        }

        return $res->body();
    }

    public function save(GrowthContent $item, string $bytes, string $rights, ?int $jobId = null): GrowthAsset
    {
        if (strlen($bytes) < 32 || strlen($bytes) > config('ai_growth.max_asset_bytes')) {
            throw new \RuntimeException('asset_too_large');
        }
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp'], true)
            || $info[0] * $info[1] > 20_000_000) {
            throw new \RuntimeException('invalid_image');
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image) {
            throw new \RuntimeException('invalid_image');
        }
        // Deterministic brand mark; no claim that this is an official logo asset.
        $color = imagecolorallocate($image, 255, 255, 255);
        $bg = imagecolorallocatealpha($image, 10, 20, 36, 25);
        $w = imagesx($image);
        $h = imagesy($image);
        imagefilledrectangle($image, max(0, $w - 168), max(0, $h - 32), $w, $h, $bg);
        imagestring($image, 4, max(2, $w - 158), max(2, $h - 24), 'servernet.cloud', $color);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        if (strlen($bytes) > config('ai_growth.max_asset_bytes')) {
            throw new \RuntimeException('asset_too_large');
        }
        $hash = hash('sha256', $bytes);
        $file = 'growth/'.$item->id.'/'.$hash.'.png';
        if (! Storage::disk('local')->put($file, $bytes)) {
            throw new \RuntimeException('archive_write_failed');
        }

        return GrowthAsset::create(['content_id' => $item->id, 'job_id' => $jobId, 'path' => $file, 'mime' => 'image/png',
            'sha256' => $hash, 'bytes' => strlen($bytes), 'rights' => $rights]);
    }
}
