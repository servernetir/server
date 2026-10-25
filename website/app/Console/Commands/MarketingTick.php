<?php

namespace App\Console\Commands;

use App\Services\Marketing\CampaignRunner;
use App\Support\ErrorTracker;
use Illuminate\Console\Command;

class MarketingTick extends Command
{
    protected $signature = 'marketing:tick {--dry-run : Read only: no grants, budget commitments or SMS}';

    protected $description = 'Process approved campaign automations, reservations and marketing outbox';

    public function handle(CampaignRunner $runner): int
    {
        try {
            $this->line(json_encode($runner->tick((bool) $this->option('dry-run')), JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            ErrorTracker::note('marketing', $e, ['area' => 'campaign-tick']);
            $this->error('Campaign scheduler failed; inspect the error tracker.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
