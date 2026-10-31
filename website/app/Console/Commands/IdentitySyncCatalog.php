<?php

namespace App\Console\Commands;

use App\Services\IdentityApi\Catalog;
use App\Services\IdentityApi\Controls;
use App\Services\IdentityApi\TariffRenewal;
use App\Support\ErrorTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class IdentitySyncCatalog extends Command
{
    protected $signature = 'identity:sync-catalog';

    protected $description = 'Discover inquiry catalog drift and optionally renew unchanged, previously approved tariffs';

    public function handle(Catalog $catalog, Controls $controls): int
    {
        if (! $controls->ready()) {
            return self::SUCCESS;
        }
        try {
            $response = Http::acceptJson()->timeout(15)->withOptions(['allow_redirects' => false])->get('https://service.zohal.io/api/v0/services/');
            $rows = $response->json();
            if (! $response->successful() || ! is_array($rows) || ! array_is_list($rows) || count($rows) < 1 || count($rows) > 500) {
                throw new \RuntimeException('invalid_catalog');
            }
            $approved = array_column($catalog->all(), 'catalog_id');
            $new = [];
            $seen = [];
            foreach ($rows as $r) {
                if (! is_array($r) || ! preg_match('/^[0-9]{1,8}$/D', (string) ($r['id'] ?? '')) || ! is_string($r['service_id'] ?? null)) {
                    throw new \RuntimeException('invalid_catalog');
                }
                $id = (string) $r['id'];
                $seen[] = $id;
                if (! in_array($id, $approved, true)) {
                    $new[] = $id;
                }
            }
            $controls->put('catalog_observation', ['at' => now()->toIso8601String(), 'count' => count($rows), 'new_catalog_ids' => $new, 'observed_ids' => $seen], 0);
            $renewal = app(TariffRenewal::class)->renew($rows, $catalog, $controls);
            if ($renewal['review']) {
                ErrorTracker::noteOnce('identity_api', 'Inquiry tariffs require review; changed or unverified rates were not renewed', 3600, ['count' => count($renewal['review'])]);
            }
            if ($new) {
                ErrorTracker::noteOnce('identity_api', 'New inquiry products await contract and tariff review', 3600, ['count' => count($new)]);
            }
            Cache::put('identity:catalog:heartbeat', now()->toIso8601String(), now()->addDay());
            $this->line('observed='.count($rows).' new='.count($new));
            $this->line('price_sync='.$renewal['status'].' renewed='.count($renewal['renewed']));

            return self::SUCCESS;
        } catch (\Throwable) {
            ErrorTracker::noteOnce('identity_api', 'Inquiry catalog sync failed; prior contracts and prices retained', 3600);

            return self::FAILURE;
        }
    }
}
