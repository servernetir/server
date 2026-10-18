<?php

namespace Tests\Feature;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\CloudImage;
use App\Models\CloudPlan;
use App\Models\Setting;
use App\Services\Cloud\ArvanApplicationImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\NavasanRates;
use Tests\TestCase;

class CloudReadyApplicationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        NavasanRates::cloudSnapshot();
        Http::preventStrayRequests();
    }

    private function plan(): CloudPlan
    {
        return CloudPlan::create([
            'provider' => 'arvan', 'provider_ref' => 'synthetic-flavour', 'provider_location' => 'ir-thr-si1',
            'location_code' => 'ir-tehran', 'public_name' => 'Synthetic VPS', 'slug' => 'synthetic-app-plan',
            'vcpu' => 8, 'ram_mb' => 16384, 'disk_gb' => 80, 'arch' => 'x86',
            'cost_eur_cents' => 300, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ]);
    }

    private function application(string $region, string $name = 'Docker-Ubuntu'): CloudImage
    {
        $rows = ArvanApplicationImages::normalize($region, [[
            'id' => '11111111-2222-3333-4444-555555555555', 'name' => $name, 'image_version' => '1.0',
            'disk' => 0, 'ram' => 0, 'ssh_password' => true, 'price' => ['pph' => 0, 'ppd' => 0, 'ppm' => 0],
        ]]);
        $this->assertCount(1, $rows);
        return CloudImage::create(['provider' => 'arvan', 'is_active' => true] + $rows[0]);
    }

    public function test_an_application_key_resolves_only_to_the_selected_plans_region(): void
    {
        $plan = $this->plan();
        $foreign = $this->application('ir-tbz-sh1');
        $this->assertNull(CloudImage::compatibleRefFor($plan, $foreign->key));
        $this->assertNotContains($foreign->key, CloudStoreController::imageKeysFor($plan, 'app'));

        $local = $this->application('ir-thr-si1');
        $this->assertSame($foreign->key, $local->key);
        $this->assertSame($local->provider_ref, CloudImage::compatibleRefFor($plan, $local->key));
        $this->assertContains($local->key, CloudStoreController::imageKeysFor($plan, 'app'));
        Http::assertNothingSent();
    }

    public function test_heavy_application_resource_floors_match_between_picker_and_provisioning(): void
    {
        $plan = $this->plan();
        $app = $this->application('ir-thr-si1', 'Gitlab');
        $this->assertContains($app->key, CloudStoreController::imageKeysFor($plan, 'app'));
        $this->assertSame($app->provider_ref, CloudImage::compatibleRefFor($plan, $app->key));
        foreach ([['vcpu' => 7], ['ram_mb' => 16383], ['disk_gb' => 79], ['boot_disk_gb' => 79], ['arch' => 'arm']] as $insufficient) {
            $plan->forceFill(['vcpu' => 8, 'ram_mb' => 16384, 'disk_gb' => 80, 'boot_disk_gb' => null, 'arch' => 'x86']);
            $plan->forceFill($insufficient)->save();
            $this->assertNotContains($app->key, CloudStoreController::imageKeysFor($plan, 'app'), json_encode($insufficient));
            $this->assertNull(CloudImage::compatibleRefFor($plan, $app->key), json_encode($insufficient));
        }
        Http::assertNothingSent();
    }

    public function test_regional_application_support_preserves_the_windows_access_allowlist(): void
    {
        $plan = $this->plan();
        $image = CloudImage::create([
            'provider' => 'arvan', 'provider_ref' => 'synthetic-windows-image', 'key' => 'synthetic-windows',
            'kind' => 'os', 'family' => 'windows', 'label' => 'Windows Server', 'arch' => 'x86', 'is_active' => true,
        ]);
        $this->assertNull(CloudImage::compatibleRefFor($plan, $image->key));
        Setting::put('arvan_windows_ready_image_refs', 'different-image');
        $this->assertNull(CloudImage::compatibleRefFor($plan, $image->key));
        Setting::put('arvan_windows_ready_image_refs', $image->provider_ref);
        $this->assertSame($image->provider_ref, CloudImage::compatibleRefFor($plan, $image->key));
        Http::assertNothingSent();
    }
}
