<?php

namespace App\Console\Commands;

use App\Services\Cloud\GpuSalesHealth;
use App\Services\Notify\AdminNotifier;
use Illuminate\Console\Command;

/** Minute watchdog, independent of the slower and potentially unhealthy supplier jobs. */
class WatchGpuSales extends Command
{
    protected $signature = 'cloud:watch-gpu-sales';
    protected $description = 'Read GPU checkout availability and alert the existing manager Bale destinations';

    public function handle(GpuSalesHealth $health, AdminNotifier $notifier): int
    {
        $path = storage_path('app/gpu-sales-watch.json');
        $lock = fopen($path.'.lock', 'c+b');
        if ($lock === false) { return self::FAILURE; }
        if (! flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return self::SUCCESS; }
        try {
            try { $check = $health->snapshot(); }
            catch (\Throwable) {
                $check = ['enabled' => true, 'ok' => false, 'offers' => 0, 'reason' => 'probe_failed'];
            }
            $old = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            if (! $check['enabled']) { $this->line('GPU sales not configured.'); return self::SUCCESS; }
            $now = now()->timestamp;
            $last = is_array($old) ? (int) ($old['last_attempt_at'] ?? 0) : 0;
            $recovery = $check['ok'] && is_array($old) && ($old['ok'] ?? true) === false;
            $alert = ! $check['ok'] && (! is_array($old) || ($old['ok'] ?? true) !== false
                || ($old['reason'] ?? null) !== $check['reason'] || $now - $last >= 1800);
            if ($alert || $recovery) {
                $reason = match ($check['reason']) {
                    'stale_price_snapshot' => 'قیمت‌های کاتالوگ با نرخ ارز فعلی هماهنگ نیستند.',
                    'probe_failed' => 'بررسی قابلیت خرید GPU کامل نشد؛ سلامت فروش تأیید نشده است.',
                    default => 'هیچ کارت GPU با قیمت معتبر و برنامهٔ قابل سفارش در صفحهٔ فروش موجود نیست.',
                };
                $notifier->baleEvent($recovery ? '✅ فروش GPU دوباره در دسترس است' : '🔴 فروش GPU متوقف شده است',
                    ['وضعیت' => $recovery ? 'کارت و برنامهٔ قابل سفارش دوباره موجود است.' : $reason,
                        'عرضهٔ قابل سفارش' => $check['offers'], 'زمان تهران' => now('Asia/Tehran')->format('Y-m-d H:i:s'),
                        'صفحه فروش' => 'https://servernet.cloud/gpu'], 'https://console.servernet.cloud/admin/cloud');
                // Attempt receipt only. The notifier can queue a fallback; repeat
                // persistent incidents every 30 minutes, never assume delivery.
                $last = $now;
            }
            $state = $check + ['checked_at' => now()->toIso8601String(), 'last_attempt_at' => $last];
            $tmp = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
            if (file_put_contents($tmp, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false
                || ! rename($tmp, $path)) { throw new \RuntimeException('GPU watch receipt write failed.'); }
            $this->line(json_encode($state, JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } finally { fclose($lock); }
    }
}
