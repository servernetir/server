<?php

namespace App\Services\Studio;

final class StudioDownloads
{
    public function release(): ?array
    {
        if (! config('studio.downloads_enabled') || ! app(StudioAuthorization::class)->configured()) return null;
        $path = config('studio.release_manifest');
        if (! is_file($path) || filesize($path) > 16000) return null;
        $data = json_decode(file_get_contents($path), true);
        if (! is_array($data) || ($data['public_ready'] ?? false) !== true
            || ! is_string($data['version'] ?? null) || ! is_string($data['source_sha'] ?? null)
            || ! is_array($data['files'] ?? null)
            || ! preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/', $data['version'] ?? '')
            || ! preg_match('/\A[a-f0-9]{40}\z/', $data['source_sha'] ?? '')) return null;
        $expected = ['windows_x64' => 'win-x64.exe', 'macos_arm64' => 'mac-arm64.dmg', 'macos_x64' => 'mac-x64.dmg'];
        if ($data['files'] === [] || array_diff(array_keys($data['files']), array_keys($expected))) return null;
        $downloads = [];
        foreach ($expected as $key => $suffix) {
            // A platform is published only when explicitly included and verified.
            // Windows can ship while the Mac packages are still in preparation.
            if (! array_key_exists($key, $data['files'])) continue;
            $file = $data['files'][$key];
            if (! is_array($file) || ! is_string($file['sha256'] ?? null)) return null;
            $name = 'ServerNet-Studio-'.$data['version'].'-'.$suffix;
            $local = rtrim(config('studio.downloads_directory'), '/').'/'.$name;
            if (($file['file'] ?? '') !== $name || ($file['signed'] ?? false) !== true
                || ($key !== 'windows_x64' && ($file['notarized'] ?? false) !== true)
                || ! preg_match('/\A[a-f0-9]{64}\z/', $file['sha256'] ?? '')
                || ! is_int($file['size'] ?? null) || $file['size'] <= 0
                || ! is_file($local) || is_link($local) || filesize($local) !== $file['size']) return null;
            $downloads[$key] = ['url' => 'https://servernet.cloud/downloads/studio/'.$name, 'sha256' => $file['sha256']];
        }
        return ['version' => $data['version'], 'downloads' => $downloads];
    }

    public function previews(): ?array
    {
        // A separately approved native-tested preview cannot satisfy release().
        if (! app(StudioAuthorization::class)->configured()) return null;
        $path = config('studio.preview_manifest', storage_path('app/studio/preview.json'));
        if (! is_string($path) || ! is_file($path) || is_link($path) || ! is_readable($path) || filesize($path) > 16000) return null;
        $json = file_get_contents($path, false, null, 0, 16001);
        if ($json === false || strlen($json) > 16000) return null;
        $data = json_decode($json, true);
        if (! is_array($data) || ($data['public_preview'] ?? false) !== true
            || ($data['unsigned_test'] ?? false) !== true
            || ! is_string($data['version'] ?? null) || ! preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/', $data['version'])
            || ! is_string($data['source_sha'] ?? null) || ! preg_match('/\A[a-f0-9]{40}\z/', $data['source_sha'])
            || ! is_array($data['files'] ?? null) || $data['files'] === []) return null;

        $platforms = ['windows_x64' => 'win-x64.exe', 'macos_arm64' => 'mac-arm64.zip', 'macos_x64' => 'mac-x64.zip'];
        if (array_diff(array_keys($data['files']), array_keys($platforms))) return null;
        $officialDirectory = config('studio.downloads_directory');
        $directory = config('studio.preview_directory');
        if ($directory === null && is_string($officialDirectory) && $officialDirectory !== '') {
            $directory = dirname($officialDirectory).'/studio-preview';
        }
        if (! is_string($directory) || ! is_dir($directory) || is_link(rtrim($directory, '/'))) return null;

        $downloads = [];
        foreach ($platforms as $key => $suffix) {
            if (! array_key_exists($key, $data['files'])) continue;
            $file = $data['files'][$key];
            $name = 'ServerNet-Studio-UNSIGNED-TEST-'.$data['version'].'-'.$suffix;
            $local = rtrim($directory, '/').'/'.$name;
            // The private manifest records the operator's independently verified
            // digest and native test receipt. Check file identity and size on
            // every request without rehashing hundreds of megabytes.
            if (! is_array($file) || ($file['file'] ?? '') !== $name
                || ($file['native_verified'] ?? false) !== true
                || ! is_string($file['sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $file['sha256'])
                || ! is_int($file['size'] ?? null) || $file['size'] <= 0
                || ! is_file($local) || is_link($local) || filesize($local) !== $file['size']) return null;
            $downloads[$key] = ['url' => 'https://servernet.cloud/downloads/studio-preview/'.$name, 'sha256' => $file['sha256']];
        }

        return ['version' => $data['version'], 'source_sha' => $data['source_sha'], 'downloads' => $downloads];
    }
}
