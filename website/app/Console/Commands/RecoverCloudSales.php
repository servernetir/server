<?php

namespace App\Console\Commands;

use App\Models\{CloudPlan, Setting};
use App\Services\Cloud\{CatalogRefreshBusy, CloudCatalogSync, CloudManager, CloudPricingSnapshot};
use App\Services\ExchangeRate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Retry catalogue/price reads only. Never create, renew, power or charge a service. */
class RecoverCloudSales extends Command
{
    protected $signature = 'cloud:recover-sales';
    protected $description = 'Recover failed cloud quotes with independent, bounded retry intervals';
    public const STATE_KEY = 'cloud_sales_recovery_v1';
    private const DELAYS = [300, 900, 3600, 10800, 21600];

    public function handle(CloudCatalogSync $catalog, CloudPricingSnapshot $snapshot, CloudManager $manager, ExchangeRate $fx): int
    {
        $lock = Cache::lock('cloud:sales-recovery', 1800);
        if (! $lock->get()) { return self::SUCCESS; }
        try {
            $state = json_decode((string) Setting::get(self::STATE_KEY, '{}'), true);
            $state = is_array($state) ? $state : [];
            if ($snapshot->rates() === null || (Setting::get('vpstc_currency') === 'TRY' && $fx->toToman('TRY') === null)) {
                $this->attempt($state, 'fx', function () use ($snapshot): bool {
                    $this->call('fx:dollar');
                    // An optional rejected TRY must not hide core recovery failure/success.
                    return $snapshot->current() && app(ExchangeRate::class)->toToman('TRY') !== null;
                });
            } else { unset($state['fx']); }
            if (! $snapshot->current()) {
                // A stale global signature with valid rates needs coordinated repricing.
                if ($snapshot->rates() !== null) {
                    $this->attempt($state, 'prices', function () use ($catalog, $snapshot): bool {
                        $catalog->reprice();
                        return $snapshot->current();
                    });
                }
                if (! $snapshot->current()) { return self::SUCCESS; }
            } else { unset($state['prices']); }

            $excluded = $snapshot->excludedProviders();
            $quarantined = CloudPlan::whereIn('id', CloudPricingSnapshot::excludedPlanIds())
                ->where('admin_disabled', false)->pluck('provider')->unique()->all();
            $budget = 2; // Avoid a catalogue storm; each supplier has its own next attempt.
            foreach ($manager->configured() as $provider => $driver) {
                if (CloudPlan::providerIsDisabled($provider)) { continue; }
                $plans = CloudPlan::where('provider', $provider)->where('admin_disabled', false);
                if (! $plans->exists()) { continue; }
                $needs = in_array($provider, $excluded, true) || in_array($provider, $quarantined, true)
                    || ! (clone $plans)->where('is_active', true)->exists();
                if (! $needs) { unset($state[$provider]); continue; }
                if ($budget <= 0 || ! $this->due($state, $provider)) { continue; }
                $budget--;
                $this->attempt($state, $provider, function () use ($catalog, $snapshot, $provider): bool {
                    $report = $catalog->sync($provider);
                    if (($report['providers'][$provider]['ok'] ?? false) !== true) { return false; }
                    // A successful catalogue read alone does not publish a usable quote.
                    $catalog->reprice();
                    return $snapshot->current($provider)
                        && CloudPlan::where('provider', $provider)->sellable()->exists()
                        && ! CloudPlan::where('provider', $provider)->where('admin_disabled', false)
                            ->whereIn('id', CloudPricingSnapshot::excludedPlanIds())->exists();
                });
            }
            return self::SUCCESS;
        } finally {
            try {
                if (isset($state)) { Setting::put(self::STATE_KEY, json_encode($state, JSON_THROW_ON_ERROR)); }
            } finally { $lock->release(); }
        }
    }

    private function due(array $state, string $key): bool
    {
        return (int) ($state[$key]['next_at'] ?? 0) <= now()->timestamp;
    }

    private function attempt(array &$state, string $key, callable $operation): void
    {
        if (! $this->due($state, $key)) { return; }
        try {
            if ($operation()) { unset($state[$key]); $this->line($key.': recovered'); return; }
        } catch (CatalogRefreshBusy) {
            return; // Another accepted refresh owns the shared lease; do not consume an attempt.
        } catch (\Throwable $e) {
            // No raw supplier response, customer data or credentials in recovery state.
            $this->warn($key.': verification failed ('.$e::class.').');
        }
        $attempt = max(0, (int) ($state[$key]['attempts'] ?? 0)) + 1;
        $state[$key] = ['attempts' => $attempt, 'last_at' => now()->timestamp,
            'next_at' => now()->timestamp + self::DELAYS[min($attempt - 1, count(self::DELAYS) - 1)]];
        $this->line($key.': retry scheduled');
    }
}
