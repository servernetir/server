<?php

namespace App\Console\Commands;

use App\Models\EngineNode;
use App\Services\Cloud\Engine\NodeHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class CloudEngineHealth extends Command
{
    protected $signature = 'cloud-engine:health {--all : Include nodes with sales disabled}';

    protected $description = 'Read and verify dedicated-node health and declared CPU/RAM capacity';

    public function handle(NodeHealth $health): int
    {
        if (! Schema::hasTable('cloud_engine_nodes')) {
            $this->line('Cloud Engine inventory is not installed.');

            return self::SUCCESS;
        }
        $failed = 0;
        foreach (EngineNode::query()->when(! $this->option('all'), fn ($q) => $q->where('sales_enabled', true))->orderBy('id')->cursor() as $node) {
            $result = $health->check($node);
            $this->line('Node '.$node->id.': '.$result['state']);
            $failed += (int) ! $result['ok'];
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
