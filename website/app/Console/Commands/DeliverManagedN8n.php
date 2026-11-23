<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Services\Cloud\ManagedN8n;
use App\Services\Cloud\VpsTcRenewal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class DeliverManagedN8n extends Command
{
    protected $signature = 'cloud:n8n {--limit=5}';
    protected $description = 'Install and verify paid dedicated n8n instances and maintain prepaid Turkish allocations';

    public function handle(ManagedN8n $delivery, VpsTcRenewal $renewal): int
    {
        // Reuse the existing scheduler; keep catalogue sizes current without
        // running provisioning, payments or a second host cron for this task.
        try {
            $catalogProgress = app(\App\Services\Cloud\ManagedN8nIranCatalog::class)->sync(true);
        } catch (\App\Services\Cloud\CatalogRefreshBusy) {
            // The next natural run follows an in-progress price publication.
            $catalogProgress = ['status' => 'deferred'];
        }
        $services = Service::where('provision_meta->application', 'n8n')->where('status', 'active')
            ->where('provision_status', 'done')->with('cloudInstance')->orderBy('id')->get();
        $limit = max(1, min(20, (int) $this->option('limit')));
        $attempted = 0; $ready = 0;
        foreach ($services as $service) {
            $instance = $service->cloudInstance;
            if (! $instance) { continue; }
            if ($instance->provider === 'vpstc') { $renewal->retainManagedN8n($service); }
            $state = data_get($instance->meta, 'n8n.state');
            if (in_array($state, ['ready', 'failed'], true) || $attempted >= $limit) { continue; }
            $attempted++;
            $ready += $delivery->advance($service) ? 1 : 0;
        }
        Cache::put('n8n:delivery-progress', ['checked_at' => now()->toIso8601String(),
            'catalog' => $catalogProgress,
            'attempted' => $attempted, 'ready' => $ready,
            'pending' => $services->filter(fn ($s) => ! in_array(data_get($s->cloudInstance?->meta, 'n8n.state'), ['ready'], true))->count()], 3600);
        $this->line("n8n: {$attempted} attempts, {$ready} ready.");
        return self::SUCCESS;
    }
}
