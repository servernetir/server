<?php

namespace App\Services\IdentityApi;

use App\Models\IdentityPrice;
use Illuminate\Support\Facades\DB;

/** Refresh unchanged public tariffs only; never grants access or approves a new tariff. */
class TariffRenewal
{
    public function renew(array $rows, Catalog $catalog, Controls $controls): array
    {
        if ($controls->get('price_sync_enabled', false) !== true) {
            return ['status' => 'disabled', 'renewed' => [], 'review' => []];
        }
        if (! array_is_list($rows) || count($rows) < 1 || count($rows) > 500) {
            throw new \RuntimeException('invalid_tariff_catalog');
        }
        $byId = [];
        $serviceIds = [];
        foreach ($rows as $row) {
            $id = is_array($row) ? (string) ($row['id'] ?? '') : '';
            if (! preg_match('/^[0-9]{1,8}$/D', $id) || isset($byId[$id])
                || ! is_string($row['service_id'] ?? null) || $row['service_id'] === ''
                || isset($serviceIds[$row['service_id']]) || ! is_int($row['price'] ?? null)
                || $row['price'] < 0 || $row['price'] > 1000000000000) {
                throw new \RuntimeException('invalid_tariff_catalog');
            }
            $byId[$id] = $row;
            $serviceIds[$row['service_id']] = true;
        }
        $hash = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($catalog, $controls, $byId, $hash) {
            // Admin price creation takes the same mutex; an operator's newer/future price wins.
            DB::table('identity_controls')->where('key', 'provider_mutex')->lockForUpdate()->firstOrFail();
            if ($controls->get('price_sync_enabled', false) !== true) {
                return ['status' => 'disabled', 'renewed' => [], 'review' => []];
            }
            $state = ['status' => 'ok', 'observed_at' => now()->toIso8601String(), 'catalog_sha256' => $hash,
                'renewed' => [], 'review' => []];
            foreach ($catalog->all() as $service) {
                $slug = $service['slug'];
                if ($service['contract_blocker'] || $controls->get('service:'.$slug, false) !== true) {
                    continue;
                }
                $previous = IdentityPrice::where('service', $slug)->orderByDesc('effective_at')->orderByDesc('id')->first();
                if (! $previous || $previous->effective_at->isFuture()
                    || $previous->valid_until->gt(now()->addHours(48))) {
                    continue;
                }
                $row = $byId[$service['catalog_id']] ?? null;
                if ($previous->billing_policy !== 'valid_data_v1' || ! $previous->evidence || ! $previous->access_evidence
                    || $previous->source_currency !== 'IRR' || ! $previous->purchase_tax_included
                    || $previous->source_amount <= 0 || $previous->purchase_tax_amount < 0
                    || $previous->purchase_tax_amount >= $previous->source_amount
                    || $previous->markup_bps < 0 || $previous->markup_bps > 10000 || $previous->daily_quota < 1
                    || ! $row || $row['service_id'] !== $service['provider_service_id'] || $row['price'] <= 0
                    || $row['price'] !== $previous->source_amount - $previous->purchase_tax_amount) {
                    $state['review'][] = $slug;
                    continue;
                }
                // Preserve the previously approved tax, markup, billing and access evidence.
                // This is a retail validity window, not a claim of a provider price guarantee.
                $next = $previous->replicate();
                $next->effective_at = now();
                $next->valid_until = now()->addDays(30);
                $next->evidence = 'public-catalog:'.now()->toDateString().'; prior-price:'.$previous->id.'; sha256:'.$hash;
                $next->created_by = null;
                $next->save();
                $controls->audit(null, 'price_renewed', ['previous_id' => $previous->id, 'id' => $next->id,
                    'service' => $slug, 'catalog_sha256' => $hash, 'access_reverified' => false]);
                $state['renewed'][] = $slug;
            }
            $state['status'] = $state['review'] ? 'review_required' : 'ok';
            $controls->put('price_sync_state', $state, 0);

            return $state;
        });
    }
}
