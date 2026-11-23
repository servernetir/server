<?php

namespace App\Console\Commands;

use App\Services\Cloud\ManagedN8nIranCatalog;
use Illuminate\Console\Command;

class SyncManagedN8nCatalog extends Command
{
    protected $signature = 'cloud:n8n-catalog {--apply : Persist only managed n8n Iranian catalogue rows}';
    protected $description = 'Preview or synchronize Iranian n8n sizes from the verified Turkish catalogue; creates no server';

    public function handle(ManagedN8nIranCatalog $catalog): int
    {
        $this->line(json_encode($catalog->sync((bool) $this->option('apply')), JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
