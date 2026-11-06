<?php

namespace App\Console\Commands;

use App\Services\Cloud\CatalogRefreshRequests;
use App\Services\Cloud\CloudCatalogSync;
use Illuminate\Console\Command;

class ProcessCatalogRefresh extends Command
{
    protected $signature = 'cloud:refresh-requested';

    protected $description = 'Process the catalog refresh requested from the admin panel outside the HTTP request';

    public function handle(CatalogRefreshRequests $requests, CloudCatalogSync $sync): int
    {
        $state = $requests->process($sync);
        $this->line('Catalog refresh: '.$state);

        return in_array($state, ['failed', 'partial', 'unavailable'], true) ? self::FAILURE : self::SUCCESS;
    }
}
