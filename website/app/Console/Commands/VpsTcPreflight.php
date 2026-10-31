<?php

namespace App\Console\Commands;

use App\Services\Cloud\VpsTcPreflight as Preflight;
use Illuminate\Console\Command;

class VpsTcPreflight extends Command
{
    protected $signature = 'cloud:vpstc-preflight {--product= : Optional numeric product ID} {--category= : Optional numeric category ID} {--billing-only : Check native currency and payment fees without catalog reads}';

    protected $description = 'Read-only VPS.TC account/catalog preflight; never orders or enables sales';

    public function handle(Preflight $preflight): int
    {
        $product = $this->option('product');
        $category = $this->option('category');
        $report = $preflight->inspect($product === null ? null : (string) $product, $category === null ? null : (string) $category, (bool) $this->option('billing-only'));
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
