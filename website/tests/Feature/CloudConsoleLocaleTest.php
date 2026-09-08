<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

class CloudConsoleLocaleTest extends CloudProvisionTest
{
    #[DataProvider('localizedRoutes')]
    public function test_console_redirect_and_all_runtime_urls_keep_the_current_locale(string $locale): void
    {
        $service = $this->delivered();

        Http::fake(fn () => Http::response([
            'wss_url' => 'wss://console.example.invalid/session',
            'password' => 'secret',
            'action' => [],
        ], 201));

        $response = $this->actingAs($service->customer, 'customer')
            ->post(route($locale.'.account.cloud.console', $service));

        $response->assertRedirectContains('/'.$locale.'/account/cloud/'.$service->id.'/console/view');

        $location = (string) $response->headers->get('Location');
        $html = $this->actingAs($service->customer, 'customer')->get($location)
            ->assertOk()
            ->getContent();
        // Illuminate\Support\Js JSON را با `\/` امن می‌کند؛ برای سنجش URL
        // آن escape نمایشی را نرمال می‌کنیم.
        $html = str_replace('\\/', '/', $html);

        $this->assertStringContainsString('/'.$locale.'/account/cloud/'.$service->id.'/console/ticket', $html);
        $this->assertStringContainsString('/'.$locale.'/account/cloud/'.$service->id, $html);
        $this->assertStringNotContainsString('https://console.servernet.cloud/account/cloud/', $html);
    }

    #[DataProvider('localizedRoutes')]
    public function test_expired_console_ticket_returns_to_the_localized_server_page(string $locale): void
    {
        $service = $this->delivered();
        Cache::forget('cloud-console:'.$service->id.':expired');

        $this->actingAs($service->customer, 'customer')
            ->get(route($locale.'.account.cloud.console.view', [$service, 't' => 'expired']))
            ->assertRedirect(route($locale.'.account.cloud.show', $service));
    }

    public static function localizedRoutes(): array
    {
        return [['en'], ['tr']];
    }
}
