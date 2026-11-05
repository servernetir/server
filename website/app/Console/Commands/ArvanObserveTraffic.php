<?php

namespace App\Console\Commands;

use App\Services\Cloud\ArvanObservationRegistry;
use Illuminate\Console\Command;
use Throwable;

/** Observation only; it cannot invoke the supplier meter or a power action. */
final class ArvanObserveTraffic extends Command
{
    protected $signature = 'cloud:observe-arvan {--limit=20 : Maximum enrollment records per bounded pass}';
    protected $description = 'Read approved Arvan guest counters over the account-private Unix socket';

    public function handle(): int
    {
        $limit = (string) $this->option('limit');
        if (preg_match('/\A(?:[1-9]|1[0-9]|20)\z/D', $limit) !== 1) {
            $this->error('observation_limit_invalid');
            return self::INVALID;
        }
        try {
            $result = (new ArvanObservationRegistry(base_path()))->run((int) $limit);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('trusted_observation_unavailable');
            return self::FAILURE;
        }
    }
}
