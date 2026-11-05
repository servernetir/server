<?php

namespace App\Console\Commands;

use App\Support\ConsoleNext;
use Illuminate\Console\Command;

class ConsoleNextDisable extends Command
{
    protected $signature = 'console-next:disable';

    protected $description = 'Disable the parallel customer console through its private local state marker';

    public function handle(): int
    {
        ConsoleNext::setEnabled(false);
        $this->info('Parallel customer console disabled. The existing /account interface remains active.');

        return self::SUCCESS;
    }
}
