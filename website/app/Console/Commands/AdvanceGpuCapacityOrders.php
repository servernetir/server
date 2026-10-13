<?php

namespace App\Console\Commands;

use App\Services\Cloud\GpuCapacityOrders;
use Illuminate\Console\Command;
use App\Support\ErrorTracker;

class AdvanceGpuCapacityOrders extends Command
{
    protected $signature = 'cloud:advance-gpu-capacity {--limit=20}';
    protected $description = 'Check unpaid GPU requests and settle only verified first delivery';

    public function handle(GpuCapacityOrders $orders): int
    {
        $counts = [];
        foreach ($orders->candidates(max(1, min(50, (int) $this->option('limit')))) as $service) {
            try {
                $state = $orders->advance($service);
                $counts[$state] = ($counts[$state] ?? 0) + 1;
            } catch (\Throwable $e) {
                ErrorTracker::noteOnce('cloud', 'GPU request #'.$service->id.' could not advance: '.get_class($e), 900,
                    ['service' => $service->id]);
                $counts['error'] = ($counts['error'] ?? 0) + 1;
            }
        }
        $this->line(json_encode($counts));
        return self::SUCCESS;
    }
}
