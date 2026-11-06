<?php

namespace App\Console\Commands;

use App\Services\Hermes\HermesOperations;
use App\Services\Cloud\CloudManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use App\Support\ErrorTracker;

final class HermesOperationsReview extends Command
{
    protected $signature = 'hermes:operations {--digest : Send one daily owner digest} {--provider= : Read-only inventory for this provider}';
    protected $description = 'Read-only infrastructure reconciliation and durable Hermes work tracking';

    public function handle(HermesOperations $operations, CloudManager $cloud): int
    {
        $stage = 'preconditions'; $lock = null; $locked = false;
        try {
            if (! $operations->enabled() || ! $operations->owner()) { $this->line('disabled'); return self::SUCCESS; }
            $stage = 'lock';
            $lock = Cache::lock('hermes:operations-review', 2700);
            if (! $lock->get()) { $this->line('busy'); return self::SUCCESS; }
            $locked = true;
            if ($this->option('digest')) { $stage = 'owner_digest'; $this->line($operations->digest()); return self::SUCCESS; }
            $stage = 'inventory';
            $provider = (string) $this->option('provider');
            $providers = $provider !== '' ? [$provider] : array_keys($cloud->configured());
            $summary = ['inventories_complete' => 0, 'inventories_unavailable' => 0, 'sampled' => 0, 'provider_results' => []];
            foreach ($providers as $slug) {
                if (! array_key_exists($slug, CloudManager::DRIVERS)) { throw new \InvalidArgumentException; }
                try {
                    $r = $operations->scan($slug);
                    $complete = ($r['status'] ?? '') === 'complete';
                    $summary[$complete ? 'inventories_complete' : 'inventories_unavailable']++;
                    $summary['provider_results'][$slug] = ['status' => $complete ? 'complete' : 'unavailable'];
                    if (! $complete) { ErrorTracker::noteOnce('hermes_operations', 'Inventory unavailable: '.$slug, 3600, ['provider' => $slug]); }
                } catch (\Throwable $error) {
                    $summary['inventories_unavailable']++;
                    $summary['provider_results'][$slug] = ['status' => 'unavailable', 'exception_class' => $error::class];
                    ErrorTracker::noteOnce('hermes_operations', 'Inventory exception: '.$slug, 3600,
                        ['provider' => $slug, 'exception_class' => $error::class]);
                }
            }
            $stage = 'service_sample';
            $sample = $operations->refresh();
            $summary['sampled'] = $sample['services_checked'] ?? 0;
            $summary['service_sample_available'] = ($sample['status'] ?? '') === 'sampled';
            if (! $summary['service_sample_available']) {
                ErrorTracker::noteOnce('hermes_operations', 'Service sample unavailable', 3600, ['stage' => 'service_sample']);
            }
            $healthy = $summary['inventories_unavailable'] === 0 && $summary['service_sample_available'];
            $summary['status'] = $healthy ? 'complete' : 'partial';
            $stage = 'health_record';
            $operations->recordHealth($healthy);
            $stage = 'owner_digest';
            $summary['owner_alert'] = $operations->digest(false);
            // No provider IDs, raw errors or customer identifiers in CLI scheduler logs.
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR));
            return $healthy ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $error) {
            try { $operations->recordHealth(false); } catch (\Throwable) {}
            ErrorTracker::noteOnce('hermes_operations', 'Hermes operations unavailable at '.$stage, 3600,
                ['stage' => $stage, 'exception_class' => $error::class]);
            $this->error('Hermes operations unavailable; inspect owner dashboard'); return self::FAILURE;
        }
        finally {
            if ($locked) {
                try { $lock->release(); }
                catch (\Throwable $error) { ErrorTracker::noteOnce('hermes_operations', 'Operations lock release unavailable', 3600, ['exception_class' => $error::class]); }
            }
        }
    }
}
