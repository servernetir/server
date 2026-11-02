<?php

namespace Tests\Feature;

use App\Http\Controllers\Account\CloudStoreController;
use App\Http\Controllers\GpuController;
use App\Models\CloudImage;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Setting;
use App\Services\Cloud\CloudPricingSnapshot;
use App\Services\Cloud\SaladClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\SeedsCatalogPricing;
use Tests\TestCase;

class GpuImageCatalogueBatchTest extends TestCase
{
    use RefreshDatabase, SeedsCatalogPricing;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seedCatalogPricing();
        $this->image('gpu-jupyter', 'quay.io/jupyter/pytorch-notebook:cuda12-latest', ['family' => 'gpu-jupyter']);
        $this->image('gpu-comfyui', 'saladtechnologies/comfyui:1-torch2.8-cuda12.8-latest', ['family' => 'gpu-comfyui']);
        $this->image('gpu-ollama', 'ollama/ollama:latest', ['family' => 'gpu-ollama']);
        $this->image('gpu-ollama', 'ollama/ollama:arm', ['arch' => 'arm', 'version' => '2']);
        $this->image('inactive-app', 'inactive', ['is_active' => false]);
        $this->image('wrong-arch', 'arm-only', ['arch' => 'arm']);
        $this->image('large-memory', 'large-memory', ['min_ram_mb' => 65536]);
        $this->image('large-disk', 'large-disk', ['min_disk_gb' => 200]);
        $this->image('os-only', 'os-only', ['kind' => 'os']);
    }

    public function test_batch_keys_match_the_unchanged_single_offer_method_with_global_slug_providers(): void
    {
        $main = $this->plan('shared-gpu', 'RTX 4090');
        $legacy = $this->plan('legacy-gpu', 'GTX 1080');
        $noApps = $this->plan('no-apps-gpu', 'RTX 4090', ['ram_mb' => 128, 'disk_gb' => 1]);
        $this->plan('shared-gpu', '', ['provider' => 'hetzner', 'provider_ref' => 'foreign-same-slug', 'location_code' => 'tr-istanbul-ii']);
        $this->image('foreign-app', 'foreign-app', ['provider' => 'hetzner', 'family' => 'a-first']);
        Setting::put('cloud_disabled_providers', 'aeza');
        $this->plan('shared-gpu', '', ['provider' => 'aeza', 'provider_ref' => 'disabled-same-slug', 'location_code' => 'de-berlin']);
        $this->image('disabled-provider-app', 'disabled-provider-app', ['provider' => 'aeza']);

        $offers = collect([$main, $legacy, $noApps]);
        $before = $offers->map(fn ($plan) => $plan->fresh()->getRawOriginal())->all();
        $expected = $this->originalKeys($offers);
        $this->assertSame($expected, CloudStoreController::imageKeysForOffers($offers, 'app'));
        $this->assertContains('foreign-app', $expected['shared-gpu'], 'Provider lookup must span countries and city aliases.');
        $this->assertNotContains('foreign-app', $expected['legacy-gpu']);
        $this->assertNotContains('disabled-provider-app', $expected['shared-gpu']);
        $this->assertNotContains('wrong-arch', $expected['shared-gpu']);
        $this->assertNotContains('inactive-app', $expected['shared-gpu']);
        $this->assertNotContains('large-memory', $expected['shared-gpu']);
        $this->assertNotContains('large-disk', $expected['shared-gpu']);
        $this->assertNotContains('os-only', $expected['shared-gpu']);
        $this->assertContains('gpu-jupyter', $expected['shared-gpu']);
        $this->assertNotContains('gpu-jupyter', $expected['legacy-gpu']);
        $this->assertSame([], $expected['no-apps-gpu']);
        $this->assertSame($before, $offers->map(fn ($plan) => $plan->fresh()->getRawOriginal())->all());

        Setting::put('salad_jupyter_sales_paused', '1');
        $this->assertSame($this->originalKeys($offers), CloudStoreController::imageKeysForOffers($offers, 'app'));
        $this->assertNotContains('gpu-jupyter', CloudStoreController::imageKeysForOffers($offers, 'app')['shared-gpu']);
        Setting::put(CloudPricingSnapshot::KEY, '{}');
        $this->assertSame(array_fill_keys($offers->pluck('slug')->all(), []), $this->originalKeys($offers));
        $this->assertSame($this->originalKeys($offers), CloudStoreController::imageKeysForOffers($offers, 'app'));
        Http::assertNothingSent();
    }

    public function test_gpu_full_cards_apps_prices_order_and_deduplication_match_the_original_algorithm_in_all_locales(): void
    {
        $this->plan('first-card', 'RTX 4090');
        $this->plan('duplicate-card', 'RTX 4090');
        $this->plan('different-price', 'RTX 4090', ['price_irt' => 9900000]);
        $this->plan('legacy-card', 'GTX 1080');
        $this->plan('no-apps-card', 'RTX 4090', ['ram_mb' => 128, 'disk_gb' => 1]);
        $this->plan('not-gpu', '', ['gpu_count' => 0]);
        $this->plan('not-stocked', 'RTX 4090', ['in_stock' => false]);
        $this->plan('not-priced', 'RTX 4090', ['price_irt' => 0]);
        $raw = CloudPlan::orderBy('id')->get()->map(fn ($plan) => $plan->getRawOriginal())->all();

        foreach (['fa', 'en', 'tr'] as $locale) {
            app()->setLocale($locale);
            $expected = $this->originalCards();
            $actual = app(GpuController::class)->show()->getData();
            $this->assertSame($expected, $actual['cards'], $locale);
            $this->assertSame(cloud_price(min(array_column($expected, 'hourly_raw'))), $actual['fromHourly']);
            $this->assertSame(collect(SaladClient::APPS)->filter(fn ($app, $key) => collect($expected)->contains(fn ($card) => in_array($key, $card['apps'], true)))->all(), $actual['apps']);
            $this->assertSame($raw, CloudPlan::orderBy('id')->get()->map(fn ($plan) => $plan->getRawOriginal())->all());
            $this->assertCount(4, $actual['cards']);
        }

        Setting::put(CloudPricingSnapshot::KEY, '{}');
        $this->assertSame([], $this->originalCards());
        $this->assertSame([], app(GpuController::class)->show()->getData()['cards']);
        Http::assertNothingSent();
    }

    public function test_image_queries_are_two_per_call_while_the_original_per_offer_provider_gates_remain(): void
    {
        $offers = collect();
        for ($i = 0; $i < 12; $i++) {
            $offers->push($this->plan('query-card-'.$i, 'RTX 4090'));
        }
        // Warm settings first; this measures catalogue queries, not first-request settings bootstrap.
        CloudStoreController::imageKeysFor($offers->first(), 'app');
        DB::enableQueryLog();
        foreach ([1, 12] as $size) {
            $sample = $offers->take($size);
            DB::flushQueryLog();
            $expected = $this->originalKeys($sample);
            $originalQueries = DB::getQueryLog();
            DB::flushQueryLog();
            $this->assertSame($expected, CloudStoreController::imageKeysForOffers($sample, 'app'));
            $batchQueries = DB::getQueryLog();
            $this->assertSame($size * 2, $this->tableQueries($originalQueries, 'cloud_images'));
            $this->assertSame(2, $this->tableQueries($batchQueries, 'cloud_images'));
            $this->assertSame($size, $this->tableQueries($originalQueries, 'cloud_plans'));
            $this->assertSame($size, $this->tableQueries($batchQueries, 'cloud_plans'));
        }
        DB::flushQueryLog();
        $this->assertSame([], CloudStoreController::imageKeysForOffers(collect(), 'app'));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        Http::assertNothingSent();
    }

    private function originalKeys(Collection $offers): array
    {
        $out = [];
        foreach ($offers as $offer) {
            $out[(string) $offer->slug] = CloudStoreController::imageKeysFor($offer, 'app');
        }
        return $out;
    }

    /** Frozen pre-change GPU card mapper, using the unchanged single-offer lookup. */
    private function originalCards(): array
    {
        $cards = [];
        foreach (CloudPlan::offers(SaladClient::LOCATION, true) as $plan) {
            if (blank($plan->gpu_model) || ($hourly = $plan->hourlyIrt()) <= 0) {
                continue;
            }
            $cards[] = [
                'slug' => $plan->slug, 'gpu' => $plan->gpu_model,
                'gpu_count' => max(1, (int) ($plan->gpu_count ?: 1)), 'vcpu' => (int) $plan->vcpu,
                'ram_gb' => (int) round($plan->ram_mb / 1024), 'disk_gb' => (int) $plan->disk_gb,
                'hourly_raw' => $hourly, 'hourly' => cloud_price($hourly),
                'interruptible' => (bool) $plan->is_interruptible,
                'apps' => CloudStoreController::imageKeysFor($plan, 'app'),
            ];
        }
        usort($cards, fn ($a, $b) => $a['hourly_raw'] <=> $b['hourly_raw']);
        $seen = [];
        return array_values(array_filter($cards, function (array $card) use (&$seen): bool {
            $key = $card['gpu'].'|'.$card['gpu_count'].'|'.$card['vcpu'].'|'.$card['ram_gb'].'|'.$card['disk_gb'].'|'.$card['hourly_raw'].'|'.implode(',', $card['apps']);
            if (isset($seen[$key])) { return false; }
            return $seen[$key] = true;
        }));
    }

    private function image(string $key, string $reference, array $overrides = []): CloudImage
    {
        return CloudImage::create(array_replace([
            'provider' => 'salad', 'provider_ref' => $reference, 'key' => $key, 'kind' => 'app',
            'family' => $key, 'version' => '1', 'label' => $key, 'arch' => 'x86',
            'min_ram_mb' => 1024, 'min_disk_gb' => 10, 'is_active' => true,
        ], $overrides));
    }

    private function plan(string $slug, string $gpu, array $overrides = []): CloudPlan
    {
        return CloudPlan::create(array_replace([
            'provider' => 'salad', 'provider_ref' => 'fixture-'.$slug, 'provider_location' => 'global',
            'location_code' => SaladClient::LOCATION, 'slug' => $slug, 'public_name' => $gpu,
            'vcpu' => 8, 'ram_mb' => 30720, 'disk_gb' => 100, 'disk_type' => 'ssd',
            'traffic_gb' => 0, 'cpu_kind' => 'shared', 'arch' => 'x86', 'cost_eur_cents' => 4000,
            'price_eur_cents' => 6000, 'price_irt' => 7200000, 'gpu_model' => $gpu,
            'gpu_count' => 1, 'is_interruptible' => true, 'is_active' => true,
            'in_stock' => true, 'admin_disabled' => false,
        ], $overrides));
    }

    private function tableQueries(array $queries, string $table): int
    {
        return count(array_filter($queries, fn ($query) => (bool) preg_match('/\bfrom\s+["`]?'.preg_quote($table, '/').'["`]?\b/i', $query['query'])));
    }
}
