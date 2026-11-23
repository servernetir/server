<?php

namespace Tests\Feature;

use App\Models\{CloudImage, CloudLocation, CloudPlan, Customer, Setting};
use App\Services\Cloud\CloudStoreDraft;
use App\Services\Finance\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\SeedsCatalogPricing;
use Tests\TestCase;

class PublicCloudStoreTest extends TestCase
{
    use RefreshDatabase, SeedsCatalogPricing;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.gtm.enabled' => false]);
        Setting::put('pricing_rate_override', '100000');
        $this->seedCatalogPricing();
        CloudLocation::create(['code' => 'de-frankfurt', 'country' => 'DE', 'city' => 'Frankfurt', 'is_active' => true]);
        CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'fixture-cx', 'provider_location' => 'fsn1',
            'location_code' => 'de-frankfurt', 'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de-frankfurt',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme', 'traffic_gb' => 1000,
            'cpu_kind' => 'shared', 'arch' => 'x86', 'cost_eur_cents' => 200, 'price_irt' => 500000,
            'price_eur_cents' => 500, 'is_active' => true, 'in_stock' => true,
        ]);
        CloudImage::create(['provider' => 'hetzner', 'provider_ref' => 'fixture-os', 'key' => 'ubuntu-24.04',
            'kind' => 'os', 'family' => 'ubuntu', 'label' => 'Ubuntu 24.04', 'arch' => 'x86',
            'min_disk_gb' => 5, 'min_ram_mb' => 0, 'is_active' => true]);
    }

    private function selection(): array
    {
        return ['location' => 'de-frankfurt', 'plan' => 'cv-2c-4g-40d-de-frankfurt', 'image' => 'ubuntu-24.04',
            'cycle' => 'monthly', 'billing_mode' => 'hourly', 'label' => 'my-project', 'extra_ipv4' => 0, 'on_credit_out' => 'suspend'];
    }

    private function customer(array $attributes = []): Customer
    {
        return Customer::create($attributes + ['email' => 'cloud-preview@example.test', 'password' => 'local-test-only',
            'status' => 'active', 'locale' => 'fa']);
    }

    public function test_regional_image_catalog_reuses_checks_and_rechecks_the_next_request(): void
    {
        config(['lightnode.sales_enabled' => true, 'lightnode.allow_fixture_rates' => true]);
        $plan = CloudPlan::firstOrFail();
        $plan->update(['provider' => 'lightnode', 'provider_ref' => '12|de|fixture-size', 'provider_location' => '12|de']);
        for ($i = 0; $i < 60; $i++) {
            CloudImage::create(['provider' => 'lightnode', 'provider_ref' => '12|image-'.$i,
                'key' => 'regional-os-'.$i, 'kind' => 'os', 'family' => 'ubuntu', 'label' => 'Regional OS '.$i,
                'arch' => 'x86', 'min_disk_gb' => 5, 'min_ram_mb' => 0, 'is_active' => true]);
        }
        $this->seedCatalogPricing();
        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries) {
            if (str_starts_with(strtolower($query->sql), 'select exists')
                && in_array('lightnode', $query->bindings, true) && in_array('12|%', $query->bindings, true)) {
                $queries++;
            }
        });

        $response = $this->get('/account/cloud-store?location=de-frankfurt')->assertOk();
        $response->assertViewHas('imageMap', fn ($map) => count($map[$plan->slug]['os']) === 60);
        $this->assertSame(1, $queries, 'One eligibility query for all 60 images in the same region.');

        // The next render gets a new lookup; no stock result survives a request.
        $this->get('/account/cloud-store?location=de-frankfurt')->assertOk();
        $this->assertSame(2, $queries);
        $plan->update(['in_stock' => false]);
        $this->get('/account/cloud-store?location=de-frankfurt')->assertOk()
            ->assertViewHas('imageMap', fn ($map) => $map === []);
        $this->assertSame([], \App\Http\Controllers\Account\CloudStoreController::imageKeysFor($plan));
        $this->assertDatabaseCount('services', 0);
        Http::assertNothingSent();
    }

    public function test_regional_lookup_separates_missing_regions_and_slugs_and_refreshes_outside_render(): void
    {
        config(['lightnode.sales_enabled' => true, 'lightnode.allow_fixture_rates' => true]);
        $plan = CloudPlan::firstOrFail();
        $plan->update(['provider' => 'lightnode', 'provider_ref' => '12|de|fixture-size', 'provider_location' => '12|de']);
        $this->seedCatalogPricing();
        $lookup = new \App\Services\Cloud\CloudImageRegionLookup;
        $this->assertTrue($lookup->exists($plan, 'lightnode', '12'));
        $this->assertFalse($lookup->exists($plan, 'lightnode', '123'));
        $this->assertFalse($lookup->exists($plan, 'arvan', '12'));
        $other = $plan->replicate(); $other->slug = 'other-slug';
        $this->assertFalse($lookup->exists($other, 'lightnode', '12'));
        $plan->update(['in_stock' => false]);
        $this->assertFalse((new \App\Services\Cloud\CloudImageRegionLookup)->exists($plan, 'lightnode', '12'));
        Http::assertNothingSent();
    }

    public function test_guests_can_browse_the_real_configurator_in_all_three_locales(): void
    {
        $this->mock(Wallet::class, fn ($mock) => $mock->shouldNotReceive('availableOf'));
        foreach (['', '/en', '/tr'] as $prefix) {
            $html = $this->get($prefix.'/account/cloud-store?location=de-frankfurt')->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')->getContent();
            $this->assertStringContainsString('CV-2-4', $html);
            $this->assertStringContainsString('Ubuntu 24.04', $html);
            $this->assertStringContainsString($prefix.'/account/cloud-store/continue', $html);
            $this->assertStringContainsString('name="_token"', $html);
            $this->assertStringNotContainsString('id="pnl-side"', $html);
            $this->assertStringNotContainsString('name="ssh_key_id"', $html);
            $this->assertStringNotContainsString('cloud_store_guest.', $html);
        }
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('invoices', 0);
        Http::assertNothingSent();
    }

    public function test_public_browsing_does_not_open_order_or_private_account_routes(): void
    {
        $this->get('/account/services')->assertRedirect();
        $this->post('/account/cloud-store', $this->selection())->assertRedirect();
        $this->assertGuest('customer');
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('credit_ledger', 0);
        Http::assertNothingSent();
    }

    public function test_separate_managed_application_checkout_keeps_its_login_requirement(): void
    {
        $this->get('/account/cloud-store?application=n8n')->assertRedirect(lroute('login'));
        $this->assertDatabaseCount('services', 0);
    }

    public function test_continue_remembers_only_public_choices_and_asks_for_login(): void
    {
        $data = $this->selection();
        $this->post('/account/cloud-store/continue', $data + ['customer_id' => 77, 'price' => 1,
            'ssh_key_id' => 88, 'ssh_key_new' => 'must-not-retain', 'redirect' => 'https://example.invalid/'])
            ->assertRedirect(lroute('login'))->assertSessionHas('url.intended', lroute('account.cloud.store', ['resume' => 1]));
        $this->assertSame($data, session(CloudStoreDraft::SESSION.'.data'));
        $this->assertGreaterThan(now()->timestamp, session(CloudStoreDraft::SESSION.'.expires_at'));
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('credit_ledger', 0);
        Http::assertNothingSent();
    }

    public function test_authentication_restores_configuration_without_placing_an_order(): void
    {
        $data = $this->selection();
        $this->post('/account/cloud-store/continue', $data)->assertRedirect();
        $url = session('url.intended');
        $response = $this->actingAs($this->customer(), 'customer')->get($url)->assertRedirect();
        $target = $response->headers->get('Location');
        $this->assertStringContainsString('billing_mode=hourly', $target);
        $this->assertStringContainsString('plan='.$data['plan'], $target);
        $this->assertStringNotContainsString('my-project', $target);
        $html = $this->get($target)->assertOk()->getContent();
        $this->assertStringContainsString('value="my-project"', $html);
        $this->assertStringContainsString('id="pnl-side"', $html);
        $this->assertStringContainsString('name="ssh_key_id"', $html);
        $this->assertStringContainsString('action="'.lroute('account.cloud.store.place').'"', $html);
        $this->assertNull(session(CloudStoreDraft::SESSION));
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('invoices', 0);
        Http::assertNothingSent();
    }

    public function test_expired_drafts_are_discarded_and_a_guest_cannot_consume_a_draft(): void
    {
        $this->post('/account/cloud-store/continue', $this->selection())->assertRedirect();
        $this->get('/account/cloud-store?resume=1')->assertOk();
        $this->assertNotNull(session(CloudStoreDraft::SESSION));
        $this->travel(31)->minutes();
        $this->actingAs($this->customer(), 'customer')->get('/account/cloud-store?resume=1')->assertOk();
        $this->assertNull(session(CloudStoreDraft::SESSION));
        $this->assertDatabaseCount('services', 0);
    }

    public function test_explicit_authenticated_confirmation_uses_server_prices_and_stays_unpaid(): void
    {
        $data = array_replace($this->selection(), ['billing_mode' => 'cycle']);
        $this->post('/account/cloud-store/continue', $data)->assertRedirect();
        $this->assertDatabaseCount('services', 0);
        $customer = $this->customer();
        $this->actingAs($customer, 'customer')->get(session('url.intended'))->assertRedirect();
        $this->post('/account/cloud-store', $data + ['price' => 1, 'customer_id' => 999])
            ->assertRedirect()->assertSessionHasNoErrors();
        $service = \App\Models\Service::firstOrFail();
        $this->assertSame($customer->id, $service->customer_id);
        $this->assertSame(500000, (int) $service->price);
        $this->assertSame('unpaid', \App\Models\Invoice::firstOrFail()->status);
        $this->assertDatabaseCount('services', 1);
        Http::assertNothingSent();
    }

    public function test_foreign_locale_continuation_returns_to_the_same_locale(): void
    {
        foreach (['en', 'tr'] as $locale) {
            $this->post('/'.$locale.'/account/cloud-store/continue', $this->selection())
                ->assertRedirect(url('/'.$locale.'/login'))
                ->assertSessionHas('url.intended', url('/'.$locale.'/account/cloud-store?resume=1'));
        }
    }

    public function test_malformed_configuration_does_not_create_a_draft_or_an_order(): void
    {
        $this->post('/account/cloud-store/continue', ['location' => ['malformed'], 'cycle' => 'free'])
            ->assertSessionHasErrors(['location', 'plan', 'image', 'cycle']);
        $this->assertNull(session(CloudStoreDraft::SESSION));
        $this->assertDatabaseCount('services', 0);
    }

    public function test_guest_continuation_keeps_csrf_protection(): void
    {
        $this->app->instance('env', 'local');
        $this->withSession(['_token' => str_repeat('t', 40)])
            ->post('/account/cloud-store/continue', $this->selection())->assertStatus(419);
        $this->assertNull(session(CloudStoreDraft::SESSION));
    }

    public function test_existing_account_status_checks_still_apply_to_authenticated_browsers(): void
    {
        $this->actingAs($this->customer(['status' => 'suspended']), 'customer')
            ->get('/account/cloud-store')->assertRedirect(lroute('login'));
        $this->assertGuest('customer');
    }
}
