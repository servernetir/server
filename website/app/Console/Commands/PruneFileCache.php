<?php

namespace App\Console\Commands;

use App\Services\MaintenanceReceipt;
use App\Services\Notify\AdminNotifier;
use App\Support\ErrorTracker;
use FilesystemIterator;
use Illuminate\Console\Command;
use Throwable;

class PruneFileCache extends Command
{
    protected $signature = 'cache:prune-expired-file {--dry-run : Inspect without removing entries or sending alerts}';

    protected $description = 'Prune expired Laravel file-cache entries and monitor disk usage';

    public function handle(): int
    {
        $root = rtrim((string) config('cache.stores.file.path'), '/\\');
        if ($root === '' || ! is_dir($root)) {
            $this->info('File cache directory is absent.');

            return self::SUCCESS;
        }

        if (is_link($root) || realpath($root) !== $root) {
            $this->error('Unexpected file cache directory.');

            return self::FAILURE;
        }

        // This lock is independent of the cache store being cleaned. It also
        // carries one small snapshot for the next run's growth comparison.
        $lockPath = (string) config('cache.file_prune.state_path', storage_path('app/cache-file-prune.lock'));
        $lock = is_link($lockPath) ? false : @fopen($lockPath, 'c+b');
        if ($lock === false) {
            $this->error('Cannot open cache maintenance lock.');

            return self::FAILURE;
        }

        $monitor = app(MaintenanceReceipt::class);
        $run = null;
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                $this->info('File cache maintenance is already running.');

                return self::SUCCESS;
            }

            $dryRun = (bool) $this->option('dry-run');
            if (! $dryRun) $run = $monitor->start('file_cache_prune');
            $previous = json_decode(stream_get_contents($lock) ?: '{}', true);
            $cutoff = time() - max(60, (int) config('cache.file_prune.grace_seconds', 300));
            $stats = ['bytes' => 0, 'removed_bytes' => 0, 'expired' => 0, 'skipped' => 0];
            $diskTotal = @disk_total_space($root);
            $diskFree = @disk_free_space($root);

            // Laravel FileStore uses sha1(key), sharded by its first four hex digits.
            // Traverse only that layout, never sessions, uploads or arbitrary files.
            foreach (new FilesystemIterator($root) as $first) {
                if (! $first->isDir() || $first->isLink() || ! preg_match('/^[a-f0-9]{2}$/D', $first->getFilename())) {
                    continue;
                }

                foreach (new FilesystemIterator($first->getPathname()) as $second) {
                    if (! $second->isDir() || $second->isLink() || ! preg_match('/^[a-f0-9]{2}$/D', $second->getFilename())) {
                        continue;
                    }

                    $prefix = $first->getFilename().$second->getFilename();
                    foreach (new FilesystemIterator($second->getPathname()) as $entry) {
                        if ($entry->isLink() || ! $entry->isFile() || ! preg_match('/^'.$prefix.'[a-f0-9]{36}$/D', $entry->getFilename())) {
                            continue;
                        }
                        $this->inspect($entry->getPathname(), $cutoff, $dryRun, $stats);
                    }
                }
            }

            $remaining = max(0, $stats['bytes'] - $stats['removed_bytes']);
            $this->line(json_encode($stats + ['remaining_bytes' => $remaining, 'dry_run' => $dryRun], JSON_THROW_ON_ERROR));

            if ($dryRun) {
                return self::SUCCESS;
            }

            $last = is_array($previous) && isset($previous['bytes'], $previous['at']) && time() - (int) $previous['at'] <= 7200
                ? (int) $previous['bytes'] : $remaining;
            $growth = max(0, $stats['bytes'] - $last);
            $used = $diskTotal > 0 && $diskFree !== false ? (int) ceil(100 * (1 - $diskFree / $diskTotal)) : null;
            $sizeLimit = max(1, (int) config('cache.file_prune.alert_size_mb', 4096)) * 1048576;
            $growthLimit = max(1, (int) config('cache.file_prune.alert_growth_mb', 1024)) * 1048576;

            $snapshot = json_encode(['at' => time(), 'bytes' => $remaining], JSON_THROW_ON_ERROR);
            rewind($lock);
            if (! ftruncate($lock, 0) || fwrite($lock, $snapshot) !== strlen($snapshot) || ! fflush($lock)) {
                throw new \RuntimeException('Cannot record cache maintenance snapshot.');
            }

            $monitor->finish('file_cache_prune', $run, true, ['remaining_bytes' => $remaining,
                'removed_bytes' => $stats['removed_bytes'], 'expired' => $stats['expired']]);

            // Disk-only incidents belong to the native system-health probe;
            // this command still owns cache-specific abnormal growth alerts.
            if ($stats['bytes'] >= $sizeLimit || max($growth, $stats['removed_bytes']) >= $growthLimit) {
                $rows = [
                    'حجم پیش از پاک‌سازی' => $this->mib($stats['bytes']),
                    'حجم پاک‌شده' => $this->mib($stats['removed_bytes']),
                    'حجم باقی‌مانده' => $this->mib($remaining),
                    'افزایش نسبت به اجرای قبل' => $this->mib($growth),
                    'اشغال دیسک پیش از پاک‌سازی' => $used === null ? 'نامعلوم' : $used.'%',
                    'فایل‌های منقضی حذف‌شده' => $stats['expired'],
                ];
                ErrorTracker::noteOnce('cache', 'افزایش غیرعادی حجم کش فایل', 21600);
                if (ErrorTracker::throttlePassed('file-cache-disk-alert', 21600)) {
                    app(AdminNotifier::class)->baleEvent('⚠️ افزایش حجم Cache سرورنت', $rows, url('/admin/errors'));
                }
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $monitor->finish('file_cache_prune', $run, false);
            ErrorTracker::noteOnce('cache', $e, 3600);
            $this->error('File cache maintenance failed.');

            return self::FAILURE;
        } finally {
            fclose($lock);
        }
    }

    private function inspect(string $path, int $cutoff, bool $dryRun, array &$stats): void
    {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before === false || ($before['mode'] & 0170000) !== 0100000) {
            $stats['skipped']++;

            return;
        }

        $bytes = $before['blocks'] >= 0 ? $before['blocks'] * 512 : $before['size'];
        $stats['bytes'] += $bytes;
        $file = @fopen($path, 'rb');
        if ($file === false) {
            $stats['skipped']++;

            return;
        }

        try {
            if (! flock($file, LOCK_EX | LOCK_NB)) {
                $stats['skipped']++;

                return;
            }

            $opened = fstat($file);
            $header = fread($file, 10);
            if ($opened === false || $opened['nlink'] !== 1 || $header === false
                || ! preg_match('/^[0-9]{10}$/D', $header) || (int) $header >= $cutoff) {
                return;
            }

            clearstatcache(true, $path);
            $current = @lstat($path);
            foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime', 'nlink'] as $key) {
                if ($current === false || $before[$key] !== $opened[$key] || $current[$key] !== $opened[$key]) {
                    $stats['skipped']++;

                    return;
                }
            }

            if ($dryRun || @unlink($path)) {
                $stats['expired']++;
                $stats['removed_bytes'] += $bytes;
            } else {
                $stats['skipped']++;
            }
        } finally {
            fclose($file);
        }
    }

    private function mib(int $bytes): string
    {
        return number_format($bytes / 1048576, 1).' MiB';
    }
}
