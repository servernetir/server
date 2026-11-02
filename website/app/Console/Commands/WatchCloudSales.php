<?php

namespace App\Console\Commands;

use App\Services\Cloud\CloudSalesHealth;
use App\Services\Cloud\CloudSalesIncidentPolicy;
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
            $old = is_array($old) ? $old : [];
            try {
                $checks = $health->snapshot();
                if ($checks !== [] && isset($old['providers']['catalogue'])) {
                    $checks['catalogue'] = ['ok' => true, 'offers' => array_sum(array_column($checks, 'offers')),
                        'reason' => 'available', 'label' => 'پایش فروش زیرساخت‌ها'];
                }
            }
            catch (\Throwable) { $checks = ['catalogue' => ['ok' => false, 'offers' => 0, 'reason' => 'probe_failed', 'label' => 'پایش فروش زیرساخت‌ها']]; }
            // A probe failure must not erase the other providers' incident history.
            $state = ['version' => 2, 'checked_at' => now()->toIso8601String(), 'providers' => (array) ($old['providers'] ?? [])];
            foreach ($checks as $provider => $check) {
                $decision = CloudSalesIncidentPolicy::next($check, $old['providers'][$provider] ?? null, now()->timestamp);
                $receipt = $decision['state'];
                $event = $decision['event'];
                $recovery = $event === 'recovery';
                if ($event !== null) {
                    $notifier->baleEvent($recovery ? '✅ فروش زیرساخت دوباره در دسترس است'
                        : ($event === 'reminder' ? '🔴 توقف فروش زیرساخت ادامه دارد' : '🔴 فروش یک زیرساخت متوقف است'),
                        ['زیرساخت' => $check['label'],
                            'علت' => $recovery ? 'دو بررسی پیاپی، عرضه با قیمت معتبر را تأیید کردند.' : CloudSalesHealth::reasonText($check['reason']),
                            'پلن قابل عرضه' => $check['offers'],
                            'مدت رخداد (دقیقه)' => max(0, (int) floor((now()->timestamp - $receipt['incident_started_at']) / 60)),
                            'اقدام' => $recovery ? 'صفحهٔ فروش قابل بررسی است.' : 'اتصال، رسید همگام‌سازی و تنظیمات همین زیرساخت را بررسی کنید.',
                            'زمان تهران' => now('Asia/Tehran')->format('Y-m-d H:i:s')],
                        'https://console.servernet.cloud/admin/cloud');
                }
                $state['providers'][$provider] = $receipt;
            }
            $tmp = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
            if (file_put_contents($tmp, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false
                || ! rename($tmp, $path)) { throw new \RuntimeException('Sales watch receipt write failed.'); }
            $this->line(json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        } finally { fclose($lock); }
    }
}
