<?php

namespace App\Services\Cloud;

use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/** Product-owned sizes, synchronized from the verified Turkish catalogue, never a VM purchase. */
class ManagedN8nIranCatalog
{
    public const PREFIX = 'n8n-ir-';

    public static function owns(CloudPlan $plan): bool
    {
        return $plan->provider === 'proxmox' && str_starts_with((string) $plan->provider_ref, self::PREFIX);
    }

    public function sync(bool $apply = false): array
    {
        return app(CatalogRefreshRequests::class)->exclusive(function () use ($apply) {
            $catalog = app(ManagedN8nCatalog::class);
            if (! $catalog->enabled() || Setting::get('n8n_iran_sales_enabled') !== '1') {
                return ['status' => 'disabled', 'count' => 0];
            }
            $snapshot = app(CloudPricingSnapshot::class);
            if (! $snapshot->current('vpstc') || ! $snapshot->current('proxmox')) {
                return ['status' => 'waiting_for_verified_prices', 'count' => 0];
            }
            $city = strtolower(trim((string) (Setting::get('proxmox_ir_city') ?? 'tehran')));
            $location = CloudNaming::locationCode('IR', $city, 'ir');
            if ($city === '' || ! CloudLocation::where('code', $location)->where('is_active', true)->exists()) {
                return ['status' => 'waiting_for_location', 'count' => 0];
            }
            // x86 shared CPU is the actual owned host contract. Unsupported sizes
            // do not become a promise just because a foreign catalogue lists them.
            $sources = CloudPlan::sellable()->where('provider', 'vpstc')
                ->where('location_code', 'like', 'tr-%')->where('arch', 'x86')->where('cpu_kind', 'shared')
                ->whereBetween('vcpu', [1, 64])->whereBetween('ram_mb', [$catalog->minimumRamMb('proxmox'), 524288])
                ->whereBetween('disk_gb', [20, 4000])->orderBy('price_irt')->orderBy('id')->get()
                ->filter(fn ($p) => $catalog->eligible($p) && $catalog->image($p) !== null)
                ->unique(fn ($p) => $p->vcpu.'-'.$p->ram_mb.'-'.$p->disk_gb)->values();
            $specs = [];
            foreach ($sources as $source) {
                $key = self::PREFIX.$source->vcpu.'-'.$source->ram_mb.'-'.$source->disk_gb;
                $specs[$key] = [
                    'provider_location' => (string) (Setting::get('proxmox_node') ?: 'ir'),
                    'public_name' => CloudNaming::planName((int) $source->vcpu, (int) $source->ram_mb, 'shared'),
                    'slug' => 'n8n-'.CloudNaming::planSlug((int) $source->vcpu, (int) $source->ram_mb,
                        (int) $source->disk_gb, $location, 'shared'),
                    'vcpu' => (int) $source->vcpu, 'ram_mb' => (int) $source->ram_mb,
                    'disk_gb' => (int) $source->disk_gb, 'disk_type' => 'ssd',
                    // Iranian network and disk contracts are independent of Turkey.
                    'traffic_gb' => 1000, 'cpu_kind' => 'shared', 'arch' => 'x86',
                    'cost_eur_cents' => (int) $source->cost_eur_cents,
                    'price_eur_cents' => (int) $source->price_eur_cents,
                    'price_irt' => (int) $source->price_irt, 'is_active' => true, 'in_stock' => true,
                    'sort' => min(65535, (int) $source->vcpu * 1000 + intdiv((int) $source->ram_mb, 1024)),
                ];
            }
            $result = ['status' => $apply ? 'applied' : 'preview', 'count' => count($specs),
                'created' => 0, 'updated' => 0, 'retired' => 0];
            if (! $apply) { return $result; }
            return DB::transaction(function () use ($specs, $location, $result) {
                foreach ($specs as $ref => $spec) {
                    $plan = CloudPlan::firstOrNew(['provider' => 'proxmox',
                        'provider_ref' => $ref, 'location_code' => $location]);
                    $new = ! $plan->exists;
                    // Admin decisions, quarantine, existing service/invoice data and
                    // ordinary Proxmox products are deliberately outside this write set.
                    $plan->fill($spec);
                    if ($plan->isDirty()) {
                        $plan->synced_at = now();
                        $plan->save();
                        $result[$new ? 'created' : 'updated']++;
                    }
                }
                $retired = CloudPlan::where('provider', 'proxmox')->where('provider_ref', 'like', self::PREFIX.'%')
                    ->where('is_active', true)->get()->filter(fn ($p) => $p->location_code !== $location
                        || ! array_key_exists($p->provider_ref, $specs));
                foreach ($retired as $plan) { $plan->update(['is_active' => false]); $result['retired']++; }
                return $result;
            });
        });
    }
}
