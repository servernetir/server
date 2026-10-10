<?php

namespace App\Console\Commands;

use App\Services\Ai\DeepInfraCatalog;
use Illuminate\Console\Command;

class AiSyncCatalog extends Command
{
    protected $signature = 'ai:sync-catalog';
    protected $description = 'Import the official DeepInfra catalogue and versioned token prices';

    public function handle(DeepInfraCatalog $catalog): int
    {
        try {
            $this->line(json_encode($catalog->sync(), JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Catalogue sync failed; existing data preserved.');
            \App\Support\ErrorTracker::noteOnce('ai', 'DeepInfra catalogue sync failed: '.get_class($e), 3600);
            return self::FAILURE;
        }
    }
}
