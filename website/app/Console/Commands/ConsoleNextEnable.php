<?php

namespace App\Console\Commands;

use App\Support\ConsoleNext;
use Illuminate\Console\Command;

class ConsoleNextEnable extends Command
{
    protected $signature = 'console-next:enable';

    protected $description = 'Enable the parallel customer console through its private local state marker';

    public function handle(): int
    {
        ConsoleNext::setEnabled(true);
        $this->info('Parallel customer console enabled. The existing /account interface remains active.');

        return self::SUCCESS;
    }
}
