<?php

namespace App\Services\Cloud;

use App\Models\CloudImage;
use App\Models\CloudPlan;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Support\Collection;

/** Live VPS catalogue prices plus the product's explicit 20% markup. Amounts are IRT. */
class ManagedN8nCatalog
{
    // These preloads exist only while building one response, never across a
    // price update, another request, an order or an authenticated customer.
    private ?Collection $batchImages = null;
    private ?Collection $batchSources = null;
    private array $batchSellableSources = [];
    private array $batchSnapshots = [];

    public static function handles(Service $service): bool
    {
        return data_get($service->provision_meta, 'application') === 'n8n';
    }

    public function enabled(): bool
    {
        return Setting::get('n8n_sales_enabled') === '1'
            && Setting::get('n8n_license_confirmed') === '1';
    }

    public function eligible(CloudPlan $plan): bool
    {
        $prefix = ['proxmox' => 'ir-', 'hetzner' => 'de-', 'vpstc' => 'tr-'][$plan->provider] ?? null;
        return $prefix !== null && str_starts_with((string) $plan->location_code, $prefix)
            && in_array($plan->arch, ['x86', 'arm'], true)
            && (int) $plan->ram_mb >= (int) config('managed_n8n.minimum_ram_mb', 2048)
            && (int) $plan->disk_gb >= (int) config('managed_n8n.minimum_disk_gb', 20)
            && ! $plan->isMetal();
    }

    public function image(CloudPlan $plan): ?string
    {
        foreach (['ubuntu-24.04', 'ubuntu-22.04', 'debian-12'] as $key) {
            if (CloudImage::compatibleRefFor($plan, $key, $this->batchImages) !== null) { return $key; }
        }
        return null;
    }

    /** Iran uses the least expensive Turkish tier meeting every purchased resource. */
    public function source(CloudPlan $plan, bool $newOrder = false): ?CloudPlan
    {
        if (! $this->eligible($plan)) { return null; }
        if ($plan->provider !== 'proxmox') { return $plan; }
        $exact = ManagedN8nIranCatalog::owns($plan);
        if ($this->batchSources !== null) {
            return $this->batchSources->first(fn ($p) => ($exact
                ? (int) $p->vcpu === (int) $plan->vcpu && (int) $p->ram_mb === (int) $plan->ram_mb && (int) $p->disk_gb === (int) $plan->disk_gb
                : (int) $p->vcpu >= (int) $plan->vcpu && (int) $p->ram_mb >= (int) $plan->ram_mb && (int) $p->disk_gb >= (int) $plan->disk_gb)
                && $p->arch === $plan->arch && $p->cpu_kind === $plan->cpu_kind
                && (! $newOrder || isset($this->batchSellableSources[$p->id])));
        }
        $q = CloudPlan::where('provider', 'vpstc')->where('location_code', 'like', 'tr-%')
            ->where('vcpu', $exact ? '=' : '>=', (int) $plan->vcpu)->where('ram_mb', $exact ? '=' : '>=', (int) $plan->ram_mb)
            ->where('disk_gb', $exact ? '=' : '>=', (int) $plan->disk_gb)->where('price_irt', '>', 0)
            ->where('arch', $plan->arch)->where('cpu_kind', $plan->cpu_kind);
        if ($newOrder) { $q->sellable(); }
        return $q->orderBy('price_irt')->orderBy('id')->first();
    }

    /** No term discount: prepayment must preserve the approved markup. */
    public function quote(CloudPlan $plan, string $cycle = 'monthly', bool $newOrder = false): int
    {
        if (! in_array($cycle, ['monthly', 'quarterly', 'semiannual', 'yearly'], true)) { return 0; }
        $source = $this->source($plan, $newOrder);
        if (! $source || (int) $source->price_irt <= 0
            || ! $this->snapshotCurrent((string) $source->provider)
            || ! $this->snapshotCurrent((string) $plan->provider)) { return 0; }
        $months = Service::monthsIn($cycle);
        return Product::roundUpToman(intdiv((int) $source->price_irt * $months * 120 + 99, 100));
    }

    public function hourly(CloudPlan $plan): int
    {
        if ($plan->provider === 'vpstc' || ! $this->eligible($plan)) { return 0; }
        $monthly = $this->quote($plan);
        if ($monthly <= 0) { return 0; }
        $rate = $plan->provider === 'hetzner'
            ? intdiv($plan->hourlyIrt() * 120 + 99, 100)
            : intdiv($monthly + 719, 720);
        return intdiv($rate + 99, 100) * 100;
    }

    public function hourlyEur(CloudPlan $plan): int
    {
        $rate = (int) app(CloudPricing::class)->eurToToman();
        return $rate > 0 ? (int) ceil($this->hourly($plan) * 100 / $rate) : 0;
    }

    /** @return Collection<int, CloudPlan> */
    public function plans(): Collection
    {
        $plans = CloudPlan::query()->sellable()->whereIn('provider', ['proxmox', 'hetzner', 'vpstc'])->get();
        // The owned n8n product has its own switch; ordinary Proxmox VPS sales
        // can remain closed. Stock, exact-plan quarantine and prices still apply.
        if (Setting::get('n8n_iran_sales_enabled') === '1'
            && $this->snapshotCurrent('proxmox')) {
            $owned = CloudPlan::where('provider', 'proxmox')->where('location_code', 'like', 'ir-%')
                ->where('is_active', true)->where('in_stock', true)->where('admin_disabled', false)
                ->where('cost_eur_cents', '>', 0)->where('price_irt', '>', 0)
                ->whereNotIn('id', CloudPricingSnapshot::excludedPlanIds())->get();
            $plans = $plans->merge($owned);
        }
        $mirrored = CloudPlan::where('provider', 'proxmox')
            ->where('provider_ref', 'like', ManagedN8nIranCatalog::PREFIX.'%')->exists();
        return $plans->sortBy([['ram_mb', 'asc'], ['vcpu', 'asc'], ['price_irt', 'asc']])
            ->filter(fn (CloudPlan $p) => ! $mirrored || $p->provider !== 'proxmox' || ManagedN8nIranCatalog::owns($p))
            ->filter(fn (CloudPlan $p) => $this->eligible($p) && $this->image($p) !== null
                && $this->quote($p, newOrder: true) > 0)
            ->unique(fn (CloudPlan $p) => $p->location_code.'|'.$p->slug)->values();
    }

    public function find(string $slug, string $location, bool $hourly = false): ?CloudPlan
    {
        if (! $this->enabled()) { return null; }
        return $this->plans()->first(fn (CloudPlan $p) => $p->slug === $slug
            && $p->location_code === $location && (! $hourly || $this->hourly($p) > 0));
    }

    public function cards(): array
    {
        $this->batchImages = CloudImage::usable()->whereIn('provider', ['proxmox', 'hetzner', 'vpstc'])
            ->whereIn('key', ['ubuntu-24.04', 'ubuntu-22.04', 'debian-12'])->get();
        $this->batchSources = CloudPlan::where('provider', 'vpstc')->where('location_code', 'like', 'tr-%')
            ->where('price_irt', '>', 0)->orderBy('price_irt')->orderBy('id')->get();
        $this->batchSellableSources = array_fill_keys(CloudPlan::sellable()->where('provider', 'vpstc')
            ->where('location_code', 'like', 'tr-%')->pluck('id')->all(), true);
        try {
            return $this->plans()->map(function (CloudPlan $p) {
            $cycles = [];
            foreach (['monthly', 'quarterly', 'semiannual', 'yearly'] as $cycle) {
                $cycles[$cycle] = $this->quote($p, $cycle, true);
            }
            return ['slug' => $p->slug, 'location' => $p->location_code,
                'country' => strtoupper(substr($p->location_code, 0, 2)),
                'name' => $p->public_name, 'cpu' => (int) $p->vcpu, 'ram' => $p->ramLabel(),
                'disk' => $p->diskLabel(), 'image' => $this->image($p),
                'cycles' => $cycles, 'hourly' => $this->hourly($p)];
            })->all();
        } finally {
            $this->batchImages = $this->batchSources = null;
            $this->batchSellableSources = $this->batchSnapshots = [];
        }
    }

    private function snapshotCurrent(string $provider): bool
    {
        if ($this->batchImages === null) { return app(CloudPricingSnapshot::class)->current($provider); }
        return $this->batchSnapshots[$provider] ??= app(CloudPricingSnapshot::class)->current($provider);
    }
}
