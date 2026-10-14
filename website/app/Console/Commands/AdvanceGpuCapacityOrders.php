<?php

namespace App\Console\Commands;

use App\Services\Cloud\GpuCapacityOrders;
use Illuminate\Console\Command;
use App\Support\ErrorTracker;
use Illuminate\Support\Facades\Log;

class AdvanceGpuCapacityOrders extends Command
{
    protected $signature = 'cloud:advance-gpu-capacity {--limit=20}';
    protected $description = 'Check unpaid GPU requests and settle only verified first delivery';

    public function handle(GpuCapacityOrders $orders): int
    {
        $started = hrtime(true);
        $startedAt = now()->toIso8601String();
        $limit = max(1, min(50, (int) $this->option('limit')));
        $counts = [];
        $candidates = 0;
        try {
            $batch = $orders->candidates($limit);
            $candidates = $batch->count();
            foreach ($batch as $service) {
                try {
                    $state = $orders->advance($service);
                    $counts[$state] = ($counts[$state] ?? 0) + 1;
                } catch (\Throwable $e) {
                    ErrorTracker::noteOnce('cloud', 'GPU request #'.$service->id.' could not advance: '.get_class($e), 900,
                        ['service' => $service->id]);
                    $counts['error'] = ($counts['error'] ?? 0) + 1;
                }
            }
        } catch (\Throwable $e) {
            ErrorTracker::noteOnce('cloud', 'GPU capacity queue scan failed: '.get_class($e), 900);
            $counts['error'] = ($counts['error'] ?? 0) + 1;
        }
        $failed = ($counts['error'] ?? 0) > 0;
        try {
            // A private, bounded success/failure trail survives cron's discarded stdout.
            // Only operational counts are recorded: no orders, credentials or exception text.
            Log::build(['driver' => 'daily', 'path' => storage_path('logs/gpu-capacity.log'),
                'days' => 7, 'level' => 'info', 'permission' => 0600, 'locking' => true])
                ->log($failed ? 'error' : 'info', 'GPU capacity queue run finished', [
                    'started_at' => $startedAt, 'finished_at' => now()->toIso8601String(),
                    'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'pid' => getmypid(),
                    'limit' => $limit, 'candidates' => $candidates, 'outcomes' => $counts,
                    'status' => $failed ? 'failed' : 'ok',
                ]);
        } catch (\Throwable $e) {
            ErrorTracker::noteOnce('cloud', 'GPU capacity run audit unavailable: '.get_class($e), 900);
            $failed = true;
        }
        $this->line(json_encode($counts));
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
