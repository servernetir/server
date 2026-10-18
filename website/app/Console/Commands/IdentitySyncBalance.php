<?php

namespace App\Console\Commands;

use App\Services\IdentityApi\BalanceSync;
use Illuminate\Console\Command;

class IdentitySyncBalance extends Command
{
    protected $signature = 'identity:sync-balance {--force : Read the actual provider balance even when the last attempt is recent}';

    protected $description = 'Refresh provider wallet evidence without buying credit or changing budgets';

    public function handle(BalanceSync $sync): int
    {
        try {
            $status = $sync->refreshIfDue((bool) $this->option('force'));
            $this->line('balance_sync='.$status);

            return in_array($status, ['failed', 'not_configured'], true) ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable) {
            $this->error('balance_sync=failed');

            return self::FAILURE;
        }
    }
}
