<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\Setting;
use App\Services\Cloud\ArvanClient;
use Illuminate\Support\Facades\Http;

class ArvanExternalConsoleTest extends CloudProvisionTest
{
    private function arvanService()
    {
        $service = $this->delivered();
        CloudInstance::where('service_id', $service->id)->update([
            'provider' => 'arvan', 'provider_ref' => 'ir-tbz-sh1:owned-vm',
        ]);
        Setting::putSecret('arvan_api_token', 'Apikey test');
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['data' => [
            'url' => 'https://console.arvaniaas.ir/ir-tbz-sh1/vnc_lite.html?token=ephemeral&host=evil.invalid&path=steal&password=unexpected',
        ]]));

        return $service;
    }

    public function test_owner_gets_only_the_sanitized_official_page_without_cache(): void
    {
        $service = $this->arvanService();
        $response = $this->actingAs($service->customer, 'customer')
            ->post(route('account.cloud.console', $service));
        $response->assertRedirect('https://console.arvaniaas.ir/ir-tbz-sh1/vnc_lite.html?token=ephemeral');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        Http::assertSentCount(1);
    }

    public function test_another_customer_cannot_request_the_console(): void
    {
        $service = $this->arvanService();
        $response = $this->actingAs($this->customer(), 'customer')
            ->post(route('account.cloud.console', $service));
        $this->assertContains($response->status(), [403, 404]);
        Http::assertNothingSent();
    }

    public function test_suspended_service_cannot_request_the_console(): void
    {
        $service = $this->arvanService();
        $service->update(['status' => 'suspended']);
        $this->actingAs($service->customer, 'customer')
            ->post(route('account.cloud.console', $service))->assertRedirect();
        Http::assertNothingSent();
    }

    public function test_untrusted_console_destinations_are_rejected(): void
    {
        Setting::putSecret('arvan_api_token', 'Apikey test');
        foreach ([
            'http://console.arvaniaas.ir/ir-tbz-sh1/vnc_lite.html?token=x',
            'https://console.arvaniaas.ir:8443/ir-tbz-sh1/vnc_lite.html?token=x',
            'https://user@console.arvaniaas.ir/ir-tbz-sh1/vnc_lite.html?token=x',
            'https://console.arvaniaas.ir/other/vnc_lite.html?token=x',
            'https://console.arvaniaas.ir/ir-tbz-sh1/vnc_lite.html?token[]=x',
        ] as $url) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake(fn () => Http::response(['data' => ['url' => $url]]));
            $this->assertFalse((new ArvanClient)->console('ir-tbz-sh1:owned-vm')['ok']);
        }
    }

    public function test_stock_windows_never_calls_the_linux_only_password_reset(): void
    {
        $service = $this->delivered();
        CloudImage::create([
            'provider' => 'arvan', 'provider_ref' => 'windows-stock-2025',
            'key' => 'windows-2025-arvan', 'kind' => 'os', 'family' => 'windows',
            'version' => '2025', 'label' => 'Windows Server 2025', 'is_active' => true,
        ]);
        CloudInstance::where('service_id', $service->id)->update([
            'provider' => 'arvan', 'provider_ref' => 'ir-tbz-sh1:windows-vm',
            'image_key' => 'windows-2025-arvan', 'root_password_enc' => null,
        ]);
        Http::preventStrayRequests();

        $this->actingAs($service->customer, 'customer')
            ->post(route('account.cloud.password', $service))
            ->assertRedirect()
            ->assertSessionHasErrors();

        Http::assertNothingSent();
    }
}
