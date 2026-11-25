<?php

namespace App\Services;

/** Cheap filesystem observation; never walks, deletes or rewrites cache data. */
class DiskPressure
{
    /** @return array{level:string,free_bytes:?int,total_bytes:?int,used_pct:?float} */
    public function inspect(?string $path = null): array
    {
        $path ??= (string) config('cache.stores.file.path', storage_path());
        if ($path === '') return self::classify(false, false);

        // FileStore may lazily create its root. Its nearest existing parent
        // is on the intended filesystem unless the path is already a mount.
        while (! is_dir($path)) {
            if (file_exists($path) || is_link($path)) return self::classify(false, false);
            $parent = dirname($path);
            if ($parent === $path || $parent === '.') return self::classify(false, false);
            $path = $parent;
        }

        return self::classify(@disk_total_space($path), @disk_free_space($path));
    }

    /** @return array{level:string,free_bytes:?int,total_bytes:?int,used_pct:?float} */
    public static function classify(float|int|false $total, float|int|false $free): array
    {
        if ($total === false || $free === false || ! is_finite((float) $total)
            || ! is_finite((float) $free) || $total <= 0 || $free < 0 || $free > $total) {
            return ['level' => 'unknown', 'free_bytes' => null, 'total_bytes' => null, 'used_pct' => null];
        }

        $warnPct = max(1, min(99, (int) config('operations_health.disk.warn_percent', 85)));
        $criticalPct = max($warnPct, min(100, (int) config('operations_health.disk.critical_percent', 95)));
        // Absolute reserves protect large volumes; proportional caps prevent a
        // small healthy filesystem from being described as permanently full.
        $warnFree = min(max(0, (int) config('operations_health.disk.warn_free_gib', 8)) * 1073741824, $total * (1 - $warnPct / 100));
        $criticalFree = min(max(0, (int) config('operations_health.disk.critical_free_gib', 2)) * 1073741824, $total * (1 - $criticalPct / 100));
        $used = 100 * (1 - $free / $total);
        $level = $used >= $criticalPct || $free <= $criticalFree ? 'critical'
            : ($used >= $warnPct || $free < $warnFree ? 'warn' : 'ok');

        return ['level' => $level, 'free_bytes' => (int) $free, 'total_bytes' => (int) $total, 'used_pct' => round($used, 2)];
    }
}
