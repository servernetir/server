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
            if (CloudImage::compatibleRefFor($plan, $key) !== null) { return $key; }
        }
        return null;
    }

    /** Iran uses the least expensive Turkish tier meeting every purchased resource. */
    public function source(CloudPlan $plan, bool $newOrder = false): ?CloudPlan
    {
        if (! $this->eligible($plan)) { return null; }
        if ($plan->provider !== 'proxmox') { return $plan; }
        $q = CloudPlan::where('provider', 'vpstc')->where('location_code', 'like', 'tr-%')
            ->where('vcpu', '>=', (int) $plan->vcpu)->where('ram_mb', '>=', (int) $plan->ram_mb)
            ->where('disk_gb', '>=', (int) $plan->disk_gb)->where('price_irt', '>', 0)
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
            || ! app(CloudPricingSnapshot::class)->current((string) $source->provider)
            || ! app(CloudPricingSnapshot::class)->current((string) $plan->provider)) { return 0; }
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
        return CloudPlan::query()->sellable()->whereIn('provider', ['proxmox', 'hetzner', 'vpstc'])
            ->orderBy('ram_mb')->orderBy('vcpu')->orderBy('price_irt')->get()
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
    }
}
