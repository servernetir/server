<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudPlan;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LightNodeImageSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function plan(string $region = 'ph-test', array $overrides = []): CloudPlan
    {
        return new CloudPlan(array_replace([
            'provider' => 'lightnode', 'provider_ref' => $region.'|'.$region.'-a|package',
            'provider_location' => $region.'|'.$region.'-a', 'vcpu' => 2,
            'ram_mb' => 4096, 'disk_gb' => 150, 'boot_disk_gb' => 50,
            'data_disk_gb' => 100, 'arch' => 'x86',
        ], $overrides));
    }

    private function image(string $region, string $family = 'windows', array $overrides = []): CloudImage
    {
        return CloudImage::create(array_replace([
            'provider' => 'lightnode', 'provider_ref' => $region.'|image-'.$family,
            'key' => $family === 'windows' ? 'windows-2022' : 'ubuntu-24.04',
            'kind' => 'os', 'family' => $family, 'label' => $family, 'arch' => 'x86',
            'min_disk_gb' => 50, 'min_ram_mb' => 512, 'is_active' => true,
        ], $overrides));
    }

    public function test_windows_and_linux_select_their_own_region_even_when_another_region_was_imported_first(): void
    {
        foreach (['bg-test', 'ph-test', 'tr-test'] as $region) {
            foreach (['windows', 'ubuntu'] as $family) {
                $this->image($region, $family);
            }
        }
        foreach (['bg-test', 'ph-test', 'tr-test'] as $region) {
            foreach (['windows-2022' => 'windows', 'ubuntu-24.04' => 'ubuntu'] as $key => $family) {
                $this->assertSame($region.'|image-'.$family, CloudImage::compatibleRefFor($this->plan($region), $key));
            }
        }
    }

    public function test_data_disk_capacity_cannot_satisfy_a_larger_boot_image(): void
    {
        $this->image('ph-test', overrides: ['min_disk_gb' => 80]);
        $this->assertNull(CloudImage::compatibleRefFor($this->plan(), 'windows-2022'));
        $this->assertNotNull(CloudImage::compatibleRefFor($this->plan(overrides: ['boot_disk_gb' => 100]), 'windows-2022'));
    }

    public function test_windows_requires_two_cpus_and_four_gib_even_if_image_metadata_is_less_restrictive(): void
    {
        $this->image('ph-test');
        foreach ([['vcpu' => 1], ['ram_mb' => 2048]] as $overrides) {
            $this->assertNull(CloudImage::compatibleRefFor($this->plan(overrides: $overrides), 'windows-2022'));
        }
        $this->assertNotNull(CloudImage::compatibleRefFor($this->plan(), 'windows-2022'));
    }

    public function test_missing_wrong_region_and_disabled_images_are_unavailable(): void
    {
        $this->image('bg-test');
        $this->image('ph-test', overrides: ['is_active' => false]);
        $this->assertNull(CloudImage::compatibleRefFor($this->plan(), 'windows-2022'));
    }

    public function test_malformed_or_inconsistent_plan_image_bindings_cannot_be_advertised(): void
    {
        $image = $this->image('ph-test');
        foreach ([['provider_ref' => 'ph-test|package'], ['provider_location' => 'ph-test|other-zone'], ['provider_ref' => 'ph-test|ph-test-a|']] as $overrides) {
            $this->assertNull(CloudImage::compatibleRefFor($this->plan(overrides: $overrides), 'windows-2022'));
        }
        $image->update(['provider_ref' => 'ph-test|']);
        $this->assertNull(CloudImage::compatibleRefFor($this->plan(), 'windows-2022'));
    }

    public function test_live_arvan_windows_access_gate_is_preserved_without_blocking_linux(): void
    {
        $image = $this->image('ir-test', overrides: ['provider' => 'arvan', 'provider_ref' => 'windows-template']);
        $plan = $this->plan(overrides: ['provider' => 'arvan', 'boot_disk_gb' => null]);
        $this->assertNull(CloudImage::compatibleRefFor($plan, 'windows-2022'));
        Setting::put('arvan_windows_ready_image_refs', 'other,windows-template');
        $this->assertSame('windows-template', CloudImage::compatibleRefFor($plan, 'windows-2022'));
        $image->update(['family' => 'ubuntu', 'key' => 'ubuntu-24.04']);
        Setting::put('arvan_windows_ready_image_refs', '');
        $this->assertSame('windows-template', CloudImage::compatibleRefFor($plan, 'ubuntu-24.04'));
    }
}
