<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Cloud\VpsTcApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class VpsTcAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.vpstc' => ['base_url' => '', 'username' => '', 'password' => '']]);
    }

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'web');
    }

    private function saved(): void
    {
        Setting::put('cloud_vpstc_api_origin', 'https://api.vps.tc');
        Setting::putSecret('cloud_vpstc_username', 'private-api-user');
        Setting::putSecret('cloud_vpstc_password', 'private-api-password');
    }

    public function test_guest_and_nonadministrators_cannot_read_or_change_the_connection(): void
    {
        $this->get('/admin/cloud/vpstc')->assertRedirect();
        foreach (['support', 'author'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'web');
            $this->get('/admin/cloud/vpstc')->assertForbidden();
            $this->post('/admin/cloud/vpstc', ['api_origin' => 'https://api.vps.tc'])->assertForbidden();
            $this->post('/admin/cloud/vpstc/preflight')->assertForbidden();
        }
        $this->assertNull(Setting::get('cloud_vpstc_api_origin'));
        Http::assertNothingSent();
    }

    public function test_credentials_are_encrypted_and_never_rendered_or_flashed(): void
    {
        $this->admin();
        $this->post('/admin/cloud/vpstc', [
            'api_origin' => 'https://api.vps.tc',
            'username' => 'private-api-user',
            'password' => 'private-api-password',
        ])->assertRedirect('/admin/settings?tab=infra#infra-vpstc')->assertSessionHasNoErrors();
        $this->assertSame('private-api-user', Setting::getSecret('cloud_vpstc_username'));
        $this->assertSame('private-api-password', Setting::getSecret('cloud_vpstc_password'));
        $this->assertNotSame('private-api-user', Setting::get('cloud_vpstc_username'));
        $this->assertNotSame('private-api-password', Setting::get('cloud_vpstc_password'));
        $this->get('/admin/settings?tab=infra')->assertOk()->assertDontSee('private-api-user')->assertDontSee('private-api-password');
        $this->assertSame([], session()->getOldInput());
        Http::assertNothingSent();
    }

    public function test_old_settings_url_redirects_to_the_only_infrastructure_settings_page(): void
    {
        $this->admin();
        $this->get('/admin/cloud/vpstc')->assertRedirect('/admin/settings?tab=infra#infra-vpstc');
        Http::assertNothingSent();
    }

    public function test_admin_can_verify_billing_fixes_without_running_catalog_or_enabling_sales(): void
    {
        $this->admin();
        $this->saved();
        Setting::put('vpstc_currency', 'TRY');
        Http::fake([
            '*/api/login' => Http::response(['token' => 'private-token']),
            '*/api/balance' => Http::response(['details' => ['currency' => 'TRY', 'acc_credit' => 5, 'acc_balance' => 0]]),
            '*/api/payment' => Http::response(['payments' => ['120' => 'Credit Balance']]),
            '*/api/payment/fees' => Http::response(['payments' => [['id' => 120, 'fixed_fee' => 0, 'percent_fee' => 0]]]),
        ]);
        $this->post('/admin/cloud/vpstc/preflight', ['billing_only' => '1'])
            ->assertRedirect('/admin/settings?tab=infra#infra-vpstc')
            ->assertSessionHas('vpstc_report.ok', true)->assertSessionHas('vpstc_report.scope', 'billing_only');
        $this->assertNotSame('1', Setting::get('vpstc_sales_enabled'));
        $this->get('/admin/settings?tab=infra')->assertOk()
            ->assertSee('ارز پاسخ اصلی')->assertSee('Service Auto Renewal')
            ->assertDontSee('private-token')->assertDontSee('private-api-password');
        Http::assertSentCount(4);
    }

    public function test_blank_edit_preserves_both_existing_credentials(): void
    {
        $this->admin();
        $this->saved();
        $this->post('/admin/cloud/vpstc', ['api_origin' => 'https://api.vps.tc', 'username' => '', 'password' => ''])->assertSessionHasNoErrors();
        $this->assertSame('private-api-user', Setting::getSecret('cloud_vpstc_username'));
        $this->assertSame('private-api-password', Setting::getSecret('cloud_vpstc_password'));
        Http::assertNothingSent();
    }

    public function test_origin_change_requires_both_credentials_and_preserves_original_on_error(): void
    {
        $this->admin();
        $this->saved();
        foreach ([['username' => 'new-user'], ['password' => 'new-password'], []] as $partial) {
            $this->post('/admin/cloud/vpstc', ['api_origin' => 'https://whitelabelservices.us'] + $partial)->assertSessionHasErrors('credentials');
            $this->assertSame('https://api.vps.tc', Setting::get('cloud_vpstc_api_origin'));
            $this->assertSame('private-api-user', Setting::getSecret('cloud_vpstc_username'));
            $this->assertSame('private-api-password', Setting::getSecret('cloud_vpstc_password'));
            $this->assertSame([], session()->getOldInput());
        }
        Http::assertNothingSent();
    }

    public function test_complete_origin_change_updates_the_credential_pair_together(): void
    {
        $this->admin();
        $this->saved();
        $this->post('/admin/cloud/vpstc', ['api_origin' => 'https://whitelabelservices.us', 'username' => 'new-user', 'password' => 'new-password'])->assertSessionHasNoErrors();
        $this->assertSame('https://whitelabelservices.us', Setting::get('cloud_vpstc_api_origin'));
        $this->assertSame('new-user', Setting::getSecret('cloud_vpstc_username'));
        $this->assertSame('new-password', Setting::getSecret('cloud_vpstc_password'));
        Http::assertNothingSent();
    }

    public function test_invalid_origin_and_invalid_input_do_not_flash_or_store_credentials(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->admin();
        foreach (['http://api.vps.tc', 'https://api.vps.tc/api', 'https://user@api.vps.tc', 'https://api.vps.tc?token=x', 'https://api.vps.tc#x', 'https://127.0.0.1', 'https://localhost', 'https://api.vps.tc:443'] as $origin) {
            $this->post('/admin/cloud/vpstc', ['api_origin' => $origin, 'username' => 'private-api-user', 'password' => 'private-api-password'])->assertSessionHasErrors('api_origin');
            $this->assertSame([], session()->getOldInput());
            $this->assertNull(Setting::get('cloud_vpstc_api_origin'));
        }
        $this->post('/admin/cloud/vpstc', ['api_origin' => 'https://api.vps.tc', 'username' => ['private-api-user'], 'password' => 'private-api-password'])->assertSessionHasErrors('username');
        $this->assertSame([], session()->getOldInput());
        Http::assertNothingSent();
    }

    public function test_preflight_page_reads_configuration_without_an_outbound_request(): void
    {
        $this->admin();
        $this->saved();
        $this->get('/admin/settings?tab=infra')->assertOk()->assertSee('VPS.TC')->assertSee('name="password"', false)->assertDontSee('private-api-password');
        Http::assertNothingSent();
    }

    public function test_preflight_is_rate_limited_without_making_orders(): void
    {
        $this->admin();
        for ($i = 0; $i < 3; $i++) {
            $this->post('/admin/cloud/vpstc/preflight')->assertRedirect('/admin/settings?tab=infra#infra-vpstc');
        }
        $this->post('/admin/cloud/vpstc/preflight')->assertStatus(429);
        Http::assertNothingSent();
    }

    public function test_saved_origin_never_inherits_an_environment_credential_pair(): void
    {
        config(['services.vpstc' => ['base_url' => 'https://other.vps.tc', 'username' => 'environment-user', 'password' => 'environment-password']]);
        Setting::put('cloud_vpstc_api_origin', 'https://api.vps.tc');
        Setting::putSecret('cloud_vpstc_username', 'saved-user');
        $api = app(VpsTcApi::class);
        $this->assertFalse($api->isConfigured());
        $this->assertSame('not_configured', $api->balance()['code']);
        Http::assertNothingSent();
    }

    public function test_all_new_routes_have_admin_auth_and_post_operations_have_rate_limits(): void
    {
        foreach (['admin.cloud.vpstc', 'admin.cloud.vpstc.save', 'admin.cloud.vpstc.preflight'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('auth:web', $route->gatherMiddleware());
            $this->assertContains('admin', $route->gatherMiddleware());
        }
        $this->assertContains('throttle:3,1', Route::getRoutes()->getByName('admin.cloud.vpstc.preflight')->gatherMiddleware());
        $this->assertContains('throttle:6,1', Route::getRoutes()->getByName('admin.cloud.vpstc.save')->gatherMiddleware());
    }
}
