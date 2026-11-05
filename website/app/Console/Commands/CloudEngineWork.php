<?php

namespace App\Console\Commands;

use App\Models\EngineOperation;
use App\Services\Cloud\Engine\EngineWorker;
use Illuminate\Console\Command;

class CloudEngineWork extends Command
{
    protected $signature = 'cloud-engine:work {--limit=25 : Maximum operations advanced by one durable phase}';
    protected $description = 'Advance bounded, leased Cloud Engine infrastructure operations';

    public function handle(EngineWorker $worker): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $count = 0;
        foreach (EngineOperation::whereIn('status', ['pending', 'running', 'uncertain'])
            ->orderBy('updated_at')->limit($limit)->get() as $operation) {
            $count += (int) $worker->tick($operation);
        }
        $this->info('Advanced '.$count.' operation phases.');
        return self::SUCCESS;
    }
}
