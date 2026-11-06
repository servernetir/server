<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudPlan;
use App\Models\Setting;
use App\Services\Cloud\CloudDominance;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\CloudOfferSelection;
use App\Services\Cloud\CloudPricingSnapshot;
use App\Services\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudDominancePartitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::put('cloud_margin_pct', '0');
        $this->mock(ExchangeRate::class, fn ($mock) => $mock->shouldReceive('toToman')->with('EUR')->andReturn(100000));
        $this->mock(CloudPricingSnapshot::class, fn ($mock) => $mock->shouldReceive('current')->andReturn(true));
        $this->mock(CloudManager::class, fn ($mock) => $mock->shouldReceive('capabilitiesForPlan')->andReturn([
            'console' => false, 'rebuild' => true,
        ]));
        CloudImage::create(['provider' => 'fixture', 'provider_ref' => 'ubuntu', 'key' => 'ubuntu-24.04',
            'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04', 'label' => 'Ubuntu 24.04',
            'arch' => 'x86', 'is_active' => true]);
    }

    private function plan(string $name, string $city, array $changes = []): CloudPlan
    {
        return new CloudPlan(array_replace([
            'provider' => 'fixture', 'provider_ref' => $name, 'location_code' => $city,
            'slug' => $name, 'public_name' => $name, 'vcpu' => 2, 'ram_mb' => 2048,
            'disk_gb' => 40, 'disk_type' => 'nvme', 'traffic_gb' => 1000,
            'cpu_kind' => 'shared', 'arch' => 'x86', 'price_eur_cents' => 200,
            'price_irt' => 200000, 'cost_eur_cents' => 100, 'setup_eur_cents' => 0,
            'is_active' => true, 'in_stock' => true, 'admin_disabled' => false,
        ], $changes));
    }

    public function test_global_catalogue_keeps_the_same_products_and_order_as_pairwise_contract_checks(): void
    {
        $plans = collect([
            $this->plan('istanbul-dear', 'tr-istanbul-ii', ['price_irt' => 300000, 'price_eur_cents' => 300]),
            $this->plan('berlin', 'de-berlin'),
            $this->plan('istanbul-cheap', 'tr-istanbul'),
            $this->plan('frankfurt', 'de-frankfurt'),
            $this->plan('berlin-setup-tradeoff', 'de-berlin', ['price_irt' => 100000, 'price_eur_cents' => 100, 'setup_eur_cents' => 200]),
            $this->plan('berlin-arm', 'de-berlin', ['arch' => 'arm']),
            $this->plan('frankfurt-out-of-stock', 'de-frankfurt', ['in_stock' => false, 'price_irt' => 100000, 'price_eur_cents' => 100]),
        ]);
        $reference = $this->referencePrune($plans);
        $actual = CloudDominance::prune($plans);

        $this->assertSame($reference->all(), $actual->all());
        $this->assertSame(['berlin', 'istanbul-cheap', 'frankfurt', 'berlin-setup-tradeoff', 'berlin-arm', 'frankfurt-out-of-stock'], $actual->pluck('public_name')->all());
        Http::assertNothingSent();
    }

    public function test_unique_city_catalogue_remains_unchanged_without_loading_contract_dependencies(): void
    {
        $plans = collect(range(1, 800))->map(fn ($i) => $this->plan('plan-'.$i, 'fixture-city-'.$i));
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();

        $this->assertSame($plans->all(), CloudDominance::prune($plans)->all());
        $this->assertSame([], \Illuminate\Support\Facades\DB::getQueryLog());
        Http::assertNothingSent();
    }

    public function test_same_city_resource_and_price_ladder_preserves_every_tradeoff(): void
    {
        $plans = collect(range(1, 80))->map(fn ($i) => $this->plan('size-'.$i, 'de-berlin', [
            'vcpu' => $i, 'ram_mb' => 1024 * $i, 'disk_gb' => 20 * $i,
            'price_irt' => 100000 * $i, 'price_eur_cents' => 100 * $i,
        ]));

        $this->assertSame($this->referencePrune($plans)->all(), CloudDominance::prune($plans)->all());
        $this->assertSame($plans->all(), CloudDominance::prune($plans)->all());
        Http::assertNothingSent();
    }

    public function test_pure_fact_buckets_keep_the_frozen_contract_results_for_a_mixed_catalogue(): void
    {
        $base = $this->plan('base', 'tr-istanbul', ['network_terms' => ['quota' => 1000, 'overage' => ['rate' => 0, 'unit' => 'GB']]]);
        $changes = [
            ['location_code' => 'tr-istanbul-ii', 'price_irt' => 300000, 'price_eur_cents' => 300],
            ['location_code' => 'de-berlin'], ['arch' => 'arm'], ['cpu_kind' => 'dedicated'],
            ['gpu_model' => 'RTX 3090', 'gpu_count' => 1], ['gpu_count' => 2], ['gpu_vram_mb' => 8192],
            ['is_interruptible' => true], ['disk_type' => 'ssd'], ['disk_type' => 'NVME'],
            ['network_terms' => ['overage' => ['unit' => 'GB', 'rate' => 0], 'quota' => 1000]],
            ['network_terms' => ['quota' => '1000', 'overage' => ['rate' => 0, 'unit' => 'GB']]],
            ['network_terms' => null], ['network_terms' => 'invalid'],
            ['setup_eur_cents' => 200, 'price_irt' => 100000, 'price_eur_cents' => 100],
            ['is_active' => false], ['in_stock' => false], ['admin_disabled' => true],
            ['price_irt' => 0], ['price_eur_cents' => 0], ['vcpu' => 4, 'ram_mb' => 4096],
        ];
        $plans = collect([$base]);
        foreach ($changes as $i => $change) {
            $plans->push($this->plan('variant-'.$i, 'tr-istanbul', array_replace([
                'network_terms' => ['quota' => 1000, 'overage' => ['rate' => 0, 'unit' => 'GB']],
            ], $change)));
        }
        // Preserve prior tolerance and object identity for repeated/mixed inputs.
        $plans->push($base, null, new \stdClass);
        $this->assertSame($this->referencePrune($plans)->all(), CloudDominance::prune($plans)->all());
        Http::assertNothingSent();
    }

    public function test_facts_are_rebuilt_after_resource_price_or_availability_changes_between_calls(): void
    {
        $good = $this->plan('good', 'de-berlin', ['vcpu' => 4, 'ram_mb' => 4096]);
        $bad = $this->plan('bad', 'de-berlin', ['price_irt' => 300000, 'price_eur_cents' => 300]);
        $plans = collect([$bad, $good]);
        $this->assertSame([$good], CloudDominance::prune($plans)->all());
        foreach ([['vcpu' => 1], ['arch' => 'arm'], ['gpu_model' => 'RTX 3090'],
            ['network_terms' => ['quota' => 100]], ['price_irt' => 0], ['in_stock' => false]] as $change) {
            $original = $good->getAttributes();
            $good->fill($change);
            $this->assertSame($this->referencePrune($plans)->all(), CloudDominance::prune($plans)->all());
            $this->assertSame([$bad, $good], CloudDominance::prune($plans)->all());
            $good->setRawAttributes($original);
            $this->assertSame([$good], CloudDominance::prune($plans)->all());
        }
        Http::assertNothingSent();
    }

    public function test_nonfinancial_casts_are_bounded_while_money_and_eligibility_are_not_memoized(): void
    {
        $plans = collect(range(1, 80))->map(function ($i) {
            $plan = new CountingDominanceFactsPlan;
            $plan->setRawAttributes($this->plan('size-'.$i, 'de-berlin', [
                'vcpu' => $i, 'ram_mb' => 1024 * $i, 'disk_gb' => 20 * $i,
                'price_irt' => 100000 * $i, 'price_eur_cents' => 100 * $i,
                'network_terms' => ['quota' => 1000],
            ])->getAttributes());
            return $plan;
        });
        $this->assertSame($plans->all(), CloudDominance::prune($plans)->all());
        foreach (['location_code', 'arch', 'cpu_kind', 'network_terms', 'gpu_model', 'gpu_count',
            'vcpu', 'ram_mb', 'disk_gb', 'traffic_gb', 'disk_type'] as $field) {
            $reads = $plans->sum(fn ($plan) => $plan->reads[$field] ?? 0);
            $this->assertLessThanOrEqual($plans->count() * 2, $reads, $field);
        }
        foreach (['price_irt', 'is_active', 'in_stock', 'admin_disabled'] as $field) {
            $this->assertGreaterThan($plans->count(), $plans->sum(fn ($plan) => $plan->reads[$field] ?? 0), $field);
        }
        Http::assertNothingSent();
    }

    /** Frozen pre-optimization predicate, independent of the production helper. */
    private function referencePrune($plans)
    {
        $selection = new CloudOfferSelection;
        return $plans->reject(fn ($b) => $plans->contains(fn ($a) => $this->referenceDominates($a, $b, $selection)))->values();
    }

    private function referenceDominates($a, $b, CloudOfferSelection $selection): bool
    {
        if ($a === $b || ! $a instanceof CloudPlan || ! $b instanceof CloudPlan) { return false; }
        if (! $a->is_active || ! $a->in_stock || $a->admin_disabled
            || ! $selection->sameContract($a, $b) || ! $selection->pricesNoWorse($a, $b)) { return false; }
        if ((string) $a->arch !== (string) $b->arch || (string) $a->cpu_kind !== (string) $b->cpu_kind
            || ($a->network_terms ?? null) != ($b->network_terms ?? null)
            || strtolower(trim((string) $a->gpu_model)) !== strtolower(trim((string) $b->gpu_model))
            || (int) $a->gpu_count !== (int) $b->gpu_count) { return false; }
        $pa = (int) $a->price_irt; $pb = (int) $b->price_irt;
        if ($pa <= 0 || $pb <= 0 || $pa > $pb) { return false; }
        $rank = fn ($p) => ['nvme' => 3, 'ssd' => 2, 'hdd' => 1][strtolower((string) $p->disk_type)] ?? 0;
        $dims = [[(int) $a->vcpu, (int) $b->vcpu], [(int) $a->ram_mb, (int) $b->ram_mb],
            [(int) $a->disk_gb, (int) $b->disk_gb], [(int) $a->traffic_gb, (int) $b->traffic_gb], [$rank($a), $rank($b)]];
        foreach ($dims as [$av, $bv]) { if ($av < $bv) { return false; } }
        if ($selection->priceDominates($a, $b)) { return true; }
        foreach ($dims as [$av, $bv]) { if ($av > $bv) { return true; } }
        return false;
    }
}

class CountingDominanceFactsPlan extends CloudPlan
{
    public array $reads = [];

    public function getAttribute($key)
    {
        $this->reads[$key] = ($this->reads[$key] ?? 0) + 1;
        return parent::getAttribute($key);
    }
}
