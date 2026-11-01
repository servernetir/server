<?php

namespace App\Console\Commands;

use App\Services\Cloud\CloudSalesHealth;
use App\Services\Notify\AdminNotifier;
use Illuminate\Console\Command;

class WatchCloudSales extends Command
{
    protected $signature = 'cloud:watch-sales';
    protected $description = 'Check each infrastructure sales state and notify managers in Bale with its cause';

    public function handle(CloudSalesHealth $health, AdminNotifier $notifier): int
    {
        $path = storage_path('app/cloud-sales-watch.json');
        $lock = fopen($path.'.lock', 'c+b');
        if ($lock === false) { return self::FAILURE; }
        if (! flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return self::SUCCESS; }
        try {
            $old = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
            try { $checks = $health->snapshot(); }
            catch (\Throwable) { $checks = ['catalogue' => ['ok' => false, 'offers' => 0, 'reason' => 'probe_failed', 'label' => 'پایش فروش زیرساخت‌ها']]; }
            $state = ['checked_at' => now()->toIso8601String(), 'providers' => []];
            foreach ($checks as $provider => $check) {
                $previous = $old['providers'][$provider] ?? null;
                $last = (int) ($previous['last_attempt_at'] ?? 0);
                $manual = $check['reason'] === 'admin_disabled';
                $recovery = $check['ok'] && is_array($previous) && ($previous['ok'] ?? true) === false;
                $changed = is_array($previous) && (($previous['ok'] ?? true) !== $check['ok']
                    || ($previous['reason'] ?? '') !== $check['reason']);
                $alert = ! $check['ok'] && ($changed || (! is_array($previous) && ! $manual)
                    || (! $manual && now()->timestamp - $last >= 1800));
                if ($alert || $recovery) {
                    $notifier->baleEvent($recovery ? '✅ فروش زیرساخت دوباره در دسترس است' : '🔴 فروش یک زیرساخت متوقف است',
                        ['زیرساخت' => $check['label'],
                            'علت' => $recovery ? 'پلن قابل عرضه با قیمت معتبر دوباره موجود است.' : CloudSalesHealth::reasonText($check['reason']),
                            'پلن قابل عرضه' => $check['offers'],
                            'اقدام' => $recovery ? 'صفحهٔ فروش قابل بررسی است.' : 'اتصال، رسید همگام‌سازی و تنظیمات همین زیرساخت را بررسی کنید.',
                            'زمان تهران' => now('Asia/Tehran')->format('Y-m-d H:i:s')],
                        'https://console.servernet.cloud/admin/cloud');
                    $last = now()->timestamp;
                }
                $state['providers'][$provider] = $check + ['last_attempt_at' => $last];
            }
            $tmp = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
            if (file_put_contents($tmp, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false
                || ! rename($tmp, $path)) { throw new \RuntimeException('Sales watch receipt write failed.'); }
            $this->line(json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        } finally { fclose($lock); }
    }
}
