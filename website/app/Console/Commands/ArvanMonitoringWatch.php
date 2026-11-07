<?php

namespace App\Console\Commands;

use App\Services\Cloud\ArvanMonitoringHealth;
use App\Services\Notify\AdminNotifier;
use App\Support\ErrorTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/** Watches persisted evidence only: never polls a guest, bills or changes power. */
final class ArvanMonitoringWatch extends Command
{
    protected $signature = 'cloud:watch-arvan-monitoring';
    protected $description = 'Alert the owner on missing or stale Arvan monitoring, independently of the meter';

    public function handle(ArvanMonitoringHealth $health, AdminNotifier $notify): int
    {
        $directory = storage_path('app/private/arvan-monitoring-watch');
        File::ensureDirectoryExists($directory, 0700);
        $lock = fopen($directory.'/watch.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { return self::SUCCESS; }
        try {
            $report = $health->inspect();
            $path = $directory.'/last-attempt.json';
            $old = is_file($path) ? json_decode(file_get_contents($path), true) : [];
            $bad = $report['issues'] !== [];
            $signature = hash('sha256', json_encode($report['issues'], JSON_THROW_ON_ERROR));
            $recovered = !$bad && $report['checked'] > 0 && ($old['bad'] ?? false) === true;
            $due = $bad && (($old['signature'] ?? '') !== $signature || time() - ($old['attempted_at'] ?? 0) >= 1800);
            if ($due || $recovered) {
                $notify->baleEvent($bad ? '⚠️ پایش ترافیک آروان در دسترس نیست' : '✅ پایش ترافیک آروان دوباره برقرار شد',
                    $bad ? ['سرویس‌ها' => $report['summary'], 'وضعیت' => 'دادهٔ نامعتبر یا قطع پایش، مصرف صفر محسوب نمی‌شود.',
                        'اقدام' => 'اتصال Node Exporter و جمع‌آورنده را بررسی کنید.']
                        : ['وضعیت' => 'برای تمام سرورهای در حال اجرا، دادهٔ تازه و منطبق با همان سرور ثبت شده است.'],
                    'https://console.servernet.cloud/admin/errors');
                // This records an attempt, not a delivery receipt. Retry an open incident every 30 minutes.
                File::put($path, json_encode(['bad' => $bad, 'signature' => $signature, 'attempted_at' => time()], JSON_THROW_ON_ERROR));
                chmod($path, 0600);
            }
            if ($bad) {
                ErrorTracker::noteOnce('monitoring', 'Arvan traffic monitoring is missing or stale', 1800,
                    ['affected_count' => count($report['issues'])]);
            }
            $this->line(json_encode(['checked' => $report['checked'], 'affected' => count($report['issues']),
                'notification_attempted' => $due || $recovered], JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
    }
}
