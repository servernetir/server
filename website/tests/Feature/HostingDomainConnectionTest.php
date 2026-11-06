<?php

namespace Tests\Feature;

use App\Models\{Customer, Server, Service};
use App\Services\Dns\HostingDomainConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class HostingDomainConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::create(['email' => uniqid('hosting-').'@example.test', 'phone' => '0912'.random_int(1000000, 9999999), 'password' => 'fixture-only', 'status' => 'active', 'locale' => 'fa']);
    }

    private function hosting(Customer $customer): Service
    {
        $server = Server::create(['name' => 'Fixture hosting', 'type' => 'whm', 'country' => 'IR', 'hostname' => 'host.example.test', 'server_ip' => '203.0.113.10', 'nameservers' => 'NS1.EXAMPLE.TEST., ns2.example.test', 'status' => 'active']);
        return Service::create(['customer_id' => $customer->id, 'server_id' => $server->id, 'name' => 'Fixture', 'domain' => 'site.example.test', 'price' => 1000, 'tax_percent' => 0, 'currency_code' => 'IRT', 'cycle' => 'monthly', 'status' => 'active', 'provision_status' => 'done']);
    }

    public function test_other_customers_cannot_inspect_a_service(): void
    {
        $service = $this->hosting($this->customer());
        $connection = Mockery::mock(HostingDomainConnection::class);
        $connection->shouldNotReceive('inspect');
        $this->app->instance(HostingDomainConnection::class, $connection);
        $this->actingAs($this->customer(), 'customer')->getJson('/account/services/'.$service->id.'/domain-connection')->assertNotFound();
    }

    public function test_results_are_private_cached_and_invalidated_when_the_assigned_server_changes(): void
    {
        Cache::setDefaultDriver('array');
        $customer = $this->customer(); $service = $this->hosting($customer);
        $connection = Mockery::mock(HostingDomainConnection::class);
        $connection->shouldReceive('inspect')->twice()->andReturn(['state' => 'connected', 'observed_ns' => [], 'checked_at' => '2026-11-02T12:00:00Z']);
        $this->app->instance(HostingDomainConnection::class, $connection);
        $url = '/account/services/'.$service->id.'/domain-connection';
        $first = $this->actingAs($customer, 'customer')->getJson($url)->assertOk()->assertJsonPath('state', 'connected');
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
        $this->getJson($url)->assertOk();
        $service->server->update(['nameservers' => 'ns3.example.test,ns4.example.test']);
        $this->getJson($url)->assertOk();
    }

    public function test_old_ip_only_green_cache_is_not_reused(): void
    {
        Cache::setDefaultDriver('array');
        $customer = $this->customer(); $service = $this->hosting($customer);
        $fingerprint = hash('sha256', json_encode([
            $service->domain, $service->server_id, $service->server_ip,
            $service->server?->server_ip, HostingDomainConnection::nameservers($service),
        ]));
        Cache::put('hosting-domain:v1:'.$service->id.':'.$fingerprint, ['state' => 'connected'], 120);
        $connection = Mockery::mock(HostingDomainConnection::class);
        $connection->shouldReceive('inspect')->once()->andReturn(['state' => 'needs_setup', 'observed_ns' => ['other.example'], 'checked_at' => '2026-11-03T05:00:00Z']);
        $this->app->instance(HostingDomainConnection::class, $connection);
        $this->actingAs($customer, 'customer')->getJson('/account/services/'.$service->id.'/domain-connection')
            ->assertOk()->assertJsonPath('state', 'needs_setup');
    }

    public function test_nameservers_come_from_the_current_server_and_are_normalized(): void
    {
        $service = $this->hosting($this->customer());
        $service->provision_meta = ['nameservers' => 'old.example.test'];
        $this->assertSame(['ns1.example.test', 'ns2.example.test'], HostingDomainConnection::nameservers($service));
    }

    public function test_undelivered_services_cannot_be_inspected(): void
    {
        $customer = $this->customer(); $service = $this->hosting($customer);
        $service->update(['provision_status' => 'pending']);
        $connection = Mockery::mock(HostingDomainConnection::class);
        $connection->shouldNotReceive('inspect');
        $this->app->instance(HostingDomainConnection::class, $connection);
        $this->actingAs($customer, 'customer')->getJson('/account/services/'.$service->id.'/domain-connection')->assertNotFound();
    }

    public function test_the_component_renders_in_all_three_languages_with_current_nameservers(): void
    {
        $service = $this->hosting($this->customer());
        foreach (['fa', 'en', 'tr'] as $locale) {
            app()->setLocale($locale);
            $html = view('account.partials.hosting-domain-connection', ['s' => $service, 'type' => 'whm'])->render();
            $this->assertStringContainsString('ns1.example.test', $html);
            $this->assertStringContainsString('ns2.example.test', $html);
            $this->assertStringContainsString(__('hosting-connection.guide'), $html);
            $this->assertStringNotContainsString('hosting-connection.title', $html);
            $this->assertStringNotContainsString('old.example.test', $html);
        }
    }
}

