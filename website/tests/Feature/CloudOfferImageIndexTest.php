<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudPlan;
use App\Models\Setting;
use App\Services\Cloud\ArvanApplicationImages;
use App\Services\Cloud\CloudOfferSelection;
use App\Services\Cloud\SaladClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class CloudOfferImageIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function plan(string $provider, array $changes = []): CloudPlan
    {
        return new CloudPlan(array_replace([
            'provider' => $provider, 'provider_ref' => 'region-a|package', 'provider_location' => 'ir-thr',
            'arch' => 'x86', 'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 100,
            'boot_disk_gb' => 50, 'data_disk_gb' => 50, 'gpu_model' => 'RTX 3090',
        ], $changes));
    }

    private function image(string $provider, string $reference, array $changes = []): void
    {
        CloudImage::create(array_replace([
            'provider' => $provider, 'provider_ref' => $reference, 'key' => $reference,
            'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04', 'label' => 'Fixture',
            'arch' => 'x86', 'min_ram_mb' => 512, 'min_disk_gb' => 20, 'is_active' => true,
        ], $changes));
    }

    public function test_indexed_candidates_match_the_independent_original_image_predicate(): void
    {
        foreach (['fixture', 'other'] as $provider) {
            $this->image($provider, 'ubuntu');
            $this->image($provider, 'arm', ['arch' => 'arm']);
            $this->image($provider, 'any-arch', ['arch' => '']);
            $this->image($provider, 'large', ['min_ram_mb' => 8192]);
            $this->image($provider, 'large-boot', ['min_disk_gb' => 80]);
            $this->image($provider, 'inactive', ['is_active' => false]);
        }
        foreach (['region-a', 'region-ab', 'region-b', ''] as $region) {
            $this->image('lightnode', $region.'|ubuntu');
            $this->image('lightnode', $region.'|windows', ['family' => 'windows']);
        }
        $this->image('lightnode', 'region-a-without-separator');
        $this->image('vpstc', 'region-a|package|ubuntu');
        $this->image('vpstc', 'region-a|ubuntu');
        $this->image('vpstc', 'region-ab|ubuntu');
        $this->image('arvan', 'ubuntu');
        $this->image('arvan', 'windows-blocked', ['family' => 'windows']);
        $this->image('arvan', 'windows-ready', ['family' => 'windows']);
        Setting::put('arvan_windows_ready_image_refs', 'windows-ready');
        foreach (['ir-thr', 'ir-shz'] as $region) {
            $this->image('arvan', 'app:'.$region.':12345678-1234-1234-1234-123456789012', ['kind' => 'app', 'family' => 'gitlab']);
        }
        $this->image('salad', 'quay.io/jupyter/pytorch-notebook:cuda12-latest', ['kind' => 'app', 'family' => 'gpu-jupyter']);
        $this->image('salad', 'saladtechnologies/comfyui:fixture-torch2.8-cuda12.8-test', ['kind' => 'app', 'family' => 'gpu-comfyui']);

        $plans = collect([
            $this->plan('fixture'), $this->plan('fixture', ['arch' => 'arm']),
            $this->plan('fixture', ['arch' => null, 'ram_mb' => 8192, 'boot_disk_gb' => 100]),
            $this->plan('other'), $this->plan('missing'),
            $this->plan('lightnode'), $this->plan('lightnode', ['provider_ref' => 'region-ab|package']),
            $this->plan('lightnode', ['provider_ref' => '|package']),
            $this->plan('lightnode', ['provider_ref' => 'region-a', 'vcpu' => 1]),
            $this->plan('lightnode', ['ram_mb' => 2048]),
            $this->plan('vpstc'), $this->plan('vpstc', ['provider_ref' => 'region-a']),
            $this->plan('arvan'), $this->plan('arvan', ['vcpu' => 8, 'ram_mb' => 16384, 'boot_disk_gb' => 100]),
            $this->plan('salad'), $this->plan('salad', ['gpu_model' => 'GTX 1080']),
            $this->plan('salad', ['gpu_model' => 'Stable Diffusion Compatible']),
        ]);
        $images = CloudImage::query()->usable()->get();
        $selection = new CloudOfferSelection;
        $method = new ReflectionMethod($selection, 'imageSet');
        foreach ($plans as $plan) {
            $this->assertSame($this->originalImageSet($plan, $images), $method->invoke($selection, $plan), $plan->provider.' '.$plan->provider_ref);
        }
        Http::assertNothingSent();
    }

    public function test_large_regional_catalogue_is_loaded_once_and_preserves_every_regional_image(): void
    {
        $images = [];
        for ($i = 0; $i < 2000; $i++) {
            $images[] = ['provider' => 'unrelated', 'provider_ref' => 'unrelated-'.$i, 'key' => 'unrelated-'.$i,
                'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04', 'label' => 'Fixture', 'arch' => 'x86', 'is_active' => true];
        }
        for ($region = 0; $region < 34; $region++) {
            for ($i = 0; $i < 20; $i++) {
                $images[] = ['provider' => 'lightnode', 'provider_ref' => 'region-'.$region.'|image-'.$i, 'key' => 'image-'.$i,
                    'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04', 'label' => 'Fixture', 'arch' => 'x86', 'is_active' => true];
            }
        }
        foreach (array_chunk($images, 100) as $chunk) { CloudImage::insert($chunk); }
        $plans = collect(range(0, 135))->map(fn ($i) => $this->plan('lightnode', ['provider_ref' => 'region-'.($i % 34).'|plan-'.$i]));
        $expected = array_map(fn ($i) => 'os|image-'.$i.'|ubuntu|24.04', range(0, 19));
        sort($expected, SORT_STRING);
        $selection = new CloudOfferSelection;
        $method = new ReflectionMethod($selection, 'imageSet');
        DB::enableQueryLog(); DB::flushQueryLog();
        foreach ($plans as $plan) { $this->assertSame($expected, $method->invoke($selection, $plan)); }
        $this->assertCount(1, DB::getQueryLog());
        Http::assertNothingSent();
    }

    /** Frozen image predicate from before provider/region candidate indexing. */
    private function originalImageSet(CloudPlan $plan, Collection $images): array
    {
        $out = [];
        foreach ($images as $image) {
            if ((string) $image->provider !== (string) $plan->provider
                || ! CloudImage::providerAccessIsAutomated((string) $plan->provider, $image)
                || (filled($image->arch) && filled($plan->arch) && $image->arch !== $plan->arch)
                || (int) $image->min_ram_mb > (int) $plan->ram_mb
                || (int) $image->min_disk_gb > (int) ($plan->boot_disk_gb ?? $plan->disk_gb)) { continue; }
            if ($plan->provider === 'vpstc' && ! str_starts_with((string) $image->provider_ref, $plan->provider_ref.'|')) { continue; }
            if ($plan->provider === 'lightnode') {
                $region = explode('|', (string) $plan->provider_ref)[0];
                if (! str_starts_with((string) $image->provider_ref, $region.'|')
                    || ($image->family === 'windows' && ((int) $plan->vcpu < 2 || (int) $plan->ram_mb < 4096))) { continue; }
            }
            if ($plan->provider === 'arvan' && $image->kind === 'app'
                && (ArvanApplicationImages::imageId((string) $plan->provider_location, (string) $image->provider_ref) === null
                    || (int) $plan->vcpu < ArvanApplicationImages::minCpu((string) $image->family))) { continue; }
            if ($plan->provider === 'salad' && ! SaladClient::imageSupportsGpu((string) $image->provider_ref, (string) $plan->gpu_model)) { continue; }
            $out[] = implode('|', [(string) $image->kind, (string) $image->key, (string) $image->family, (string) $image->version]);
        }
        sort($out, SORT_STRING);
        return array_values(array_unique($out));
    }
}
