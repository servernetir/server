<?php

namespace App\Services\Cloud;

/** Regional, provider-listed application images. No scripts or paid licences. */
final class ArvanApplicationImages
{
    // Conservative sales sizes, in addition to the provider's declared minimums.
    // Heavy single-node apps need room for their bundled services, not just booting.
    private const APPS = [
        'docker' => ['Docker', 1024, 10, 1],
        'wordpress' => ['WordPress', 1024, 20, 1],
        'nodejs' => ['Node.js', 1024, 10, 1],
        'lamp' => ['LAMP Stack', 2048, 20, 1],
        'nginx' => ['NGINX', 512, 10, 1],
        'django' => ['Django', 1024, 10, 1],
        'gitlab' => ['GitLab', 16384, 80, 8],
        'jitsi' => ['Jitsi Meet', 8192, 25, 4],
        'bigbluebutton' => ['BigBlueButton', 16384, 50, 8],
    ];

    public static function imageId(string $region, string $reference): ?string
    {
        $parts = explode(':', $reference);
        if (count($parts) !== 3 || $parts[0] !== 'app' || $parts[1] !== $region
            || ! preg_match('/^[a-z0-9-]+$/D', $region)
            || ! preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $parts[2])) {
            return null;
        }

        return $parts[2];
    }

    public static function minCpu(string $family): int
    {
        return self::APPS[$family][3] ?? PHP_INT_MAX;
    }

    /** @return array<int,array<string,mixed>> */
    public static function normalize(string $region, array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) { continue; }
            $name = strtolower(trim((string) ($row['name'] ?? '')));
            // Image availability does not imply a paid control-panel licence.
            if (preg_match('/cpanel|plesk|directadmin/i', $name)) { continue; }
            $family = null;
            foreach (array_keys(self::APPS) as $candidate) {
                if (str_contains($name, $candidate)) { $family = $candidate; break; }
            }
            if ($family === null) { continue; }
            $price = $row['price'] ?? null;
            if (! is_array($price)) { continue; }
            foreach (['pph', 'ppd', 'ppm'] as $period) {
                if (! array_key_exists($period, $price) || ! is_numeric($price[$period])
                    || (float) $price[$period] !== 0.0) { continue 2; }
            }
            if (! in_array($row['ssh_password'] ?? false, [true, 1, '1'], true)) { continue; }
            $id = (string) ($row['image_id'] ?? $row['id'] ?? '');
            $reference = 'app:'.$region.':'.$id;
            if (self::imageId($region, $reference) === null) { continue; }
            $version = trim((string) ($row['image_version'] ?? ''));
            if ($version === '' || ! preg_match('/^[a-z0-9][a-z0-9._-]{0,31}$/iD', $version)) { continue; }
            $variant = trim((string) preg_replace('/[^a-z0-9]+/', '-', $name), '-');
            $spec = self::APPS[$family];
            $out[] = [
                'provider_ref' => $reference,
                'key' => substr('app-ir-'.$variant.'-'.strtolower($version), 0, 64),
                'kind' => 'app', 'family' => $family, 'version' => $version,
                'label' => $spec[0].' '.$version,
                'arch' => 'x86',
                'min_disk_gb' => max($spec[2], (int) ($row['disk'] ?? $row['min_disk'] ?? 0)),
                'min_ram_mb' => max($spec[1], (int) ($row['ram'] ?? $row['min_ram'] ?? 0)),
            ];
        }

        return $out;
    }
}
