<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\Setting;
use App\Services\Customer\IranSalesGate;
use App\Services\Customer\IranSalesVisibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IranSalesVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_guest_visibility_uses_the_existing_purchase_gate_and_preserves_other_destinations(): void
    {
        $this->assertTrue(IranSalesVisibility::restricted());
        foreach (['IR', 'ir-tehran'] as $country) {
            $this->assertFalse(IranSalesVisibility::allowsCountry($country));
        }
        foreach (['DE', 'gb-birmingham', null] as $country) {
            $this->assertTrue(IranSalesVisibility::allowsCountry($country));
        }
        foreach (['/vps/iran', '/en/dedicated/iran', '/tr/hosting/iran',
            '/cloud/ir-tehran', '/account/cloud-store?location=ir-tehran',
            '/account/cloud-store?country=IR', '/account/cloud-store?loc=ir-mashhad'] as $url) {
            $this->assertFalse(IranSalesVisibility::allowsUrl($url), $url);
        }
        $this->assertTrue(IranSalesVisibility::allowsUrl('/vps/germany'));
        $this->assertTrue(IranSalesVisibility::allowsUrl('/cloud/gb-birmingham'));
        Http::assertNothingSent();
    }

    public function test_verified_customer_is_not_restricted_by_a_cached_guest_decision(): void
    {
        $this->assertTrue(IranSalesVisibility::restricted());
        $customer = Customer::create([
            'email' => 'visibility@example.test', 'phone' => '09121234567',
            'password' => 'fixture-password', 'status' => 'active', 'locale' => 'fa',
        ]);
        CustomerProfile::create([
            'customer_id' => $customer->id, 'is_default' => true, 'type' => 'individual',
            'status' => 'verified', 'email' => $customer->email, 'mobile' => $customer->phone,
        ]);
        $this->actingAs($customer, 'customer');

        $this->assertFalse(IranSalesVisibility::restricted());
        $this->assertTrue(IranSalesVisibility::allowsCountry('IR'));
        $this->assertTrue(IranSalesVisibility::allowsUrl('/vps/iran'));
        Http::assertNothingSent();
    }

    public function test_explicit_gate_toggle_is_respected(): void
    {
        Setting::put(IranSalesGate::SETTING, '1');
        $this->assertFalse(IranSalesVisibility::restricted());
        $this->assertTrue(IranSalesVisibility::allowsCountry('IR'));
        Http::assertNothingSent();
    }

    public function test_menu_removes_only_restricted_items_and_empty_groups(): void
    {
        $menu = ['vps' => ['groups' => [
            ['items' => [
                ['route' => ['catalog', ['category' => 'vps', 'slug' => 'iran']]],
                ['route' => ['cloud.location', ['location' => 'ir-tehran']]],
                ['url' => '/en/vps/iran'],
                ['iso' => 'IR'],
                ['slug' => 'iran'],
                ['route' => ['catalog', ['category' => 'vps', 'slug' => 'germany']]],
            ]],
            ['items' => [['href' => '/cloud/ir-tehran']]],
        ]]];
        $visible = IranSalesVisibility::menu($menu);
        $this->assertCount(1, $visible['vps']['groups']);
        $this->assertSame(
            [['route' => ['catalog', ['category' => 'vps', 'slug' => 'germany']]]],
            $visible['vps']['groups'][0]['items']
        );
        $this->assertCount(6, $menu['vps']['groups'][0]['items']);
        Http::assertNothingSent();
    }

    public function test_auth_failure_keeps_iran_hidden_without_blocking_other_countries(): void
    {
        Auth::shouldReceive('guard')->with('customer')->andThrow(new \RuntimeException('unavailable'));
        $this->assertTrue(IranSalesVisibility::restricted());
        $this->assertFalse(IranSalesVisibility::allowsCountry('IR'));
        $this->assertTrue(IranSalesVisibility::allowsCountry('DE'));
    }
}
