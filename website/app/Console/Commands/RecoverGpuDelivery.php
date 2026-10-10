<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Services\Cloud\GpuDeliveryRecovery;
use App\Support\ErrorTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RecoverGpuDelivery extends Command
{
    protected $signature = 'cloud:recover-gpu-delivery {--limit=10} {--dry-run}';
    protected $description = 'لغو و بازگشت اعتبار GPU تحویل‌نشده و پیگیری توقف واقعی زیرساخت';

    public function handle(GpuDeliveryRecovery $recovery): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $dryRun = (bool) $this->option('dry-run');
        $counts = [];
        $errors = 0;
        if (! $dryRun) {
            foreach (Service::whereIn('status', Service::DEAD_STATUSES)
                ->where('provision_meta->gpu_delivery_recovery->pending_stop', true)
                ->orderBy('updated_at')->limit($limit)->get() as $service) {
                try {
                    $key = $recovery->retryStop($service) ? 'stop_confirmed' : 'stop_pending';
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                } catch (\Throwable $e) {
                    $errors++;
                    ErrorTracker::note('cloud', $e, ['area' => 'gpu-stop', 'service' => $service->id]);
                }
            }
        }
        $cursor = $dryRun ? 0 : (int) Cache::get('gpu-delivery-recovery.cursor', 0);
        foreach ($recovery->candidates($limit, $cursor) as $service) {
            try {
                $result = $recovery->recover($service, $dryRun);
                $key = $result['action'];
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                if (in_array($key, ['cancelled', 'would_cancel'], true)) {
                    $this->line(json_encode(['service' => $service->id] + $result, JSON_UNESCAPED_UNICODE));
                }
            } catch (\Throwable $e) {
                $errors++;
                ErrorTracker::note('cloud', $e, ['area' => 'gpu-delivery-recovery', 'service' => $service->id]);
            }
            if (! $dryRun) {
                // A temporarily failing early order must not starve later paid orders.
                Cache::forever('gpu-delivery-recovery.cursor', $service->id);
            }
        }
        $this->info(json_encode(['dry_run' => $dryRun, 'counts' => $counts, 'errors' => $errors]));
        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
