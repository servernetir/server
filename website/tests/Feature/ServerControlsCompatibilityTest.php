<?php

namespace Tests\Feature;

use App\Models\{CloudImage, CloudInstance, CloudLocation, CloudPlan, Customer, Service, Setting};
use App\Services\Cloud\CloudOperations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServerControlsCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Setting::putSecret('lightnode_api_token', 'fixture-token');
    }

    private function image(string $key, array $extra = []): CloudImage
    {
        return CloudImage::create(array_replace([
            'provider' => 'lightnode', 'provider_ref' => 'ph-test|'.$key,
            'key' => $key, 'family' => str_starts_with($key, 'windows') ? 'windows' : 'ubuntu',
            'kind' => 'os', 'label' => $key, 'arch' => 'x86', 'min_ram_mb' => 512,
            'min_disk_gb' => 20, 'is_active' => true,
        ], $extra));
    }

    private function server(): Service
    {
        $c = Customer::create(['email' => 'controls@example.com', 'phone' => '09120000001', 'password' => bcrypt('fixture'), 'status' => 'active', 'locale' => 'fa']);
        CloudLocation::create(['code' => 'ph-test', 'country' => 'PH', 'city' => 'Manila', 'is_active' => true]);
        $p = CloudPlan::create(['provider' => 'lightnode', 'provider_ref' => 'ph-test|ph-a|s2', 'provider_location' => 'ph-test|ph-a', 'location_code' => 'ph-test', 'slug' => 'controls-test', 'public_name' => '2 CPU', 'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 150, 'boot_disk_gb' => 50, 'arch' => 'x86', 'is_active' => true, 'in_stock' => true]);
        $s = Service::create(['customer_id' => $c->id, 'name' => 'Windows test', 'username' => 'Administrator', 'status' => 'active', 'provision_status' => 'done', 'cloud_plan_id' => $p->id, 'cloud_image_key' => 'windows-2022', 'cycle' => 'hourly', 'hourly_rate_irt' => 16500, 'currency_code' => 'IRT', 'price' => 0, 'tax_percent' => 0]);
        $i = CloudInstance::create(['service_id' => $s->id, 'provider' => 'lightnode', 'provider_ref' => 'fixture-server', 'image_key' => 'windows-2022', 'location_code' => 'ph-test', 'ipv4' => '185.51.200.9', 'status' => 'running', 'ready_notified_at' => now(), 'specs' => ['vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 150, 'boot_disk_gb' => 50, 'arch' => 'x86']]);
        $this->image('windows-2022');
        return $s->fresh();
    }

    public function test_windows_never_generates_ssh_on_any_provider_but_linux_still_does(): void
    {
        foreach (['lightnode', 'aeza', 'arvan', 'proxmox', 'ovh', 'hetzner', 'hetzner-robot'] as $provider) {
            $this->image('windows-2022', ['provider' => $provider, 'provider_ref' => $provider.'-win']);
            $i = new CloudInstance(['provider' => $provider, 'image_key' => 'windows-2022', 'ipv4' => '185.51.200.9']);
            $this->assertNull($i->sshCommand());
            $this->assertSame('mstsc /v:185.51.200.9', $i->rdpCommand());
        }
        $linux = new CloudInstance(['provider' => 'lightnode', 'image_key' => 'ubuntu-24.04', 'ipv4' => '185.51.200.9']);
        $this->assertSame('ssh root@185.51.200.9', $linux->sshCommand());
        $this->assertNull((new CloudInstance(['provider' => 'salad', 'image_key' => 'gpu-jupyter', 'ipv4' => '185.51.200.9']))->sshCommand());
    }

    public function test_windows_cards_show_rdp_user_and_no_ssh_in_three_languages(): void
    {
        $s = $this->server();
        foreach (['fa', 'en', 'tr'] as $locale) {
            app()->setLocale($locale);
            $html = view('account.partials.card-server', ['s' => $s, 'secBalance' => 1000000])->render();
            $this->assertStringContainsString('mstsc /v:185.51.200.9', $html);
            $this->assertStringContainsString('Administrator', $html);
            $this->assertStringNotContainsString('ssh root@', $html);
            $this->assertStringNotContainsString(__('ui.cs_ssh_label'), $html);
        }
        $s->username = 'root';
        $this->assertStringContainsString('Administrator', view('account.partials.card-server', ['s' => $s, 'secBalance' => 1000000])->render());
    }

    public function test_server_page_contains_existing_otp_delete_flow_and_closed_page_has_no_controls(): void
    {
        $s = $this->server();
        $this->actingAs($s->customer, 'customer')->get('/account/cloud/'.$s->id)
            ->assertOk()->assertSee('/services/'.$s->id.'/terminate/start', false)->assertSee('service-lifecycle');
        $s->update(['status' => 'cancelled']);
        $this->get('/account/cloud/'.$s->id)->assertOk()->assertSee(__('ui.cs_closed_h'))->assertDontSee('service-lifecycle')->assertDontSee('name="image"', false)->assertDontSee('mstsc /v:', false);
        $this->get('/account/cloud/'.$s->id.'/status')->assertOk()->assertJson(['closed' => true, 'ready' => false]);
        Http::assertNothingSent();
    }

    public function test_gpu_reboot_and_robot_power_off_are_rejected_without_supplier_calls(): void
    {
        $s = $this->server();
        $this->actingAs($s->customer, 'customer');
        $s->cloudInstance->update(['provider' => 'salad', 'image_key' => 'gpu-jupyter']);
        $this->get('/account/cloud/'.$s->id)->assertOk()->assertDontSee('value="reboot"', false)->assertDontSee('name="image"', false);
        $this->post('/account/cloud/'.$s->id.'/power', ['action' => 'reboot'])->assertSessionHasErrors();
        $s->cloudInstance->update(['provider' => 'hetzner-robot']);
        $this->post('/account/cloud/'.$s->id.'/power', ['action' => 'off'])->assertSessionHasErrors();
        $this->post('/account/cloud/'.$s->id.'/power', ['action' => 'on'])->assertSessionHasErrors();
        Http::assertNothingSent();
    }

    public function test_internal_windows_has_no_linux_password_reset_or_unsafe_clone_reinstall(): void
    {
        $s = $this->server();
        Setting::putSecret('proxmox_token_secret', 'fixture-token');
        $this->image('windows-2022', ['provider' => 'proxmox', 'provider_ref' => '900']);
        $s->cloudPlan->update(['provider' => 'proxmox']);
        $s->cloudInstance->update(['provider' => 'proxmox']);
        $this->actingAs($s->customer, 'customer')->get('/account/cloud/'.$s->id)->assertOk()
            ->assertDontSee('/cloud/'.$s->id.'/password', false)->assertDontSee('name="image"', false);
        $this->post('/account/cloud/'.$s->id.'/rebuild', ['image' => 'windows-2022', 'confirm' => 'DELETE'])->assertSessionHasErrors();
        $this->post('/account/cloud/'.$s->id.'/password')->assertSessionHasErrors();
        Http::assertNothingSent();
    }

    public function test_external_console_transport_uses_a_new_tab_when_the_provider_supports_it(): void
    {
        $s = $this->server();
        $s->cloudPlan->update(['provider' => 'arvan']);
        $s->cloudInstance->update(['provider' => 'arvan']);
        $url = 'https://console.example.com/fixture';
        $driver = \Mockery::mock(\App\Services\Cloud\ArvanClient::class)->makePartial();
        $driver->shouldReceive('capabilities')->andReturn(array_replace($driver->capabilities(), ['console' => true]));
        $driver->shouldReceive('console')->once()->with('fixture-server')->andReturn(['ok' => true, 'url' => $url, 'transport' => 'external']);
        app()->instance(\App\Services\Cloud\ArvanClient::class, $driver);
        $this->actingAs($s->customer, 'customer')->post('/account/cloud/'.$s->id.'/console')
            ->assertRedirect($url)->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->get('/account/cloud/'.$s->id)->assertOk()->assertSee('target="_blank" rel="noopener noreferrer"', false);
        Http::assertNothingSent();
    }

    public function test_provider_without_console_capability_does_not_advertise_a_console_form(): void
    {
        $s = $this->server();
        $s->cloudPlan->update(['provider' => 'arvan']);
        $s->cloudInstance->update(['provider' => 'arvan']);
        $driver = \Mockery::mock(\App\Services\Cloud\ArvanClient::class)->makePartial();
        $driver->shouldReceive('capabilities')->andReturn(array_replace($driver->capabilities(), ['console' => false]));
        app()->instance(\App\Services\Cloud\ArvanClient::class, $driver);
        $this->actingAs($s->customer, 'customer')->get('/account/cloud/'.$s->id)->assertOk()
            ->assertDontSee('action="'.lroute('account.cloud.console', $s).'"', false);
        Http::assertNothingSent();
    }

    public function test_reinstall_picker_and_post_reject_wrong_region_boot_ram_arch_and_unsupported_provider(): void
    {
        $s = $this->server();
        $this->image('ubuntu-24.04');
        $this->image('wrong-region', ['provider_ref' => 'bg-test|wrong']);
        $this->image('big-boot', ['min_disk_gb' => 80]);
        $this->image('big-ram', ['min_ram_mb' => 8192]);
        $this->image('arm-only', ['arch' => 'arm']);
        $ops = app(CloudOperations::class);
        $this->assertSame(['ubuntu-24.04', 'windows-2022'], array_column($ops->rebuildableImages($s)['images'], 'key'));
        $this->actingAs($s->customer, 'customer');
        foreach (['wrong-region', 'big-boot', 'big-ram', 'arm-only'] as $key) {
            $this->post('/account/cloud/'.$s->id.'/rebuild', ['image' => $key, 'confirm' => 'DELETE'])->assertSessionHasErrors();
        }
        $s->cloudInstance->update(['specs' => ['vcpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 150, 'boot_disk_gb' => 50, 'arch' => 'x86']]);
        $this->assertNull($ops->rebuildImageRef($s->fresh(), 'windows-2022'));
        $s->cloudInstance->update(['provider' => 'salad', 'image_key' => 'gpu-jupyter']);
        $this->post('/account/cloud/'.$s->id.'/rebuild', ['image' => 'ubuntu-24.04', 'confirm' => 'DELETE'])->assertSessionHasErrors();
        $this->post('/account/cloud/'.$s->id.'/password')->assertSessionHasErrors();
        Http::assertNothingSent();
    }
}
