<?php

namespace App\Console\Commands;

use App\Services\Growth\HermesSupervisor;
use Illuminate\Console\Command;

class RunHermesSupervisor extends Command
{
    protected $signature = 'hermes:supervise {--dry-run : Observe and propose without any writes}';

    protected $description = 'Record bounded Growth supervision; only expire stale verified knowledge, with no model calls or outbound messages';

    public function handle(HermesSupervisor $supervisor): int
    {
        $this->line(json_encode($supervisor->tick((bool) $this->option('dry-run')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
