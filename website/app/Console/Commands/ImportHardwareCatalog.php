<?php

namespace App\Console\Commands;

use App\Services\Shop\HardwareCatalogImport;
use Illuminate\Console\Command;

final class ImportHardwareCatalog extends Command
{
    protected $signature = 'shop:import-hardware {--apply} {--rollback} {--receipt=} {--manifest=}';
    protected $description = 'Plan or apply the reviewed hardware catalog with a guarded private receipt';

    public function handle(HardwareCatalogImport $import): int
    {
        $receipt = (string) $this->option('receipt');
        if (($this->option('apply') || $this->option('rollback')) && ($receipt === '' || preg_match('~^(?:/|[A-Za-z]:[\\\\/])~', $receipt) !== 1)) {
            $this->error('A private absolute receipt path is required.');
            return self::FAILURE;
        }
        $manifest = $this->option('manifest') ?: database_path('catalog/hardware-products-20261014.json');
        $summary = $this->option('rollback') ? $import->rollback($receipt) : $import->apply($manifest, $receipt, (bool) $this->option('apply'));
        $this->line(json_encode($summary, JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
