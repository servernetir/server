<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use App\Services\Cloud\CloudOrderIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CloudOrderIssueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
    }

    private function order(string $locale = 'fa'): Service
    {
        $customer = Customer::create([
            'code' => 'SN-TEST-001', 'email' => 'capacity@example.test',
            'password' => bcrypt('fixture-only'), 'status' => 'active', 'locale' => $locale,
        ]);
        CloudLocation::firstOrCreate(['code' => 'de-falkenstein'],
            ['country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true]);
        CloudImage::create([
            'provider' => 'hetzner', 'provider_ref' => 'ubuntu-24.04', 'key' => 'ubuntu-24.04',
            'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04', 'label' => 'Ubuntu 24.04',
            'arch' => 'x86', 'is_active' => true,
        ]);
        $plan = CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cx22', 'provider_location' => 'fsn1',
            'location_code' => 'de-falkenstein', 'public_name' => 'CV-2-4',
            'slug' => 'cv-2c-4g-40d-de-falkenstein', 'vcpu' => 2, 'ram_mb' => 4096,
            'disk_gb' => 40, 'disk_type' => 'nvme', 'traffic_gb' => 20480,
            'cpu_kind' => 'shared', 'arch' => 'x86', 'cost_eur_cents' => 379,
            'price_eur_cents' => 570, 'price_irt' => 570000, 'is_active' => true, 'in_stock' => true,
        ]);
        $service = Service::create([
            'customer_id' => $customer->id, 'name' => 'VPS capacity example',
            'currency_code' => 'IRT', 'price' => 570000, 'cycle' => 'monthly',
            'billing_mode' => 'hourly', 'hourly_rate_irt' => 4000,
            'status' => 'awaiting_provision', 'provision_status' => 'failed',
            'provision_error' => 'تحویلِ سرور ناموفق: [resource_unavailable] error during placement api-secret-test',
            'cloud_plan_id' => $plan->id, 'cloud_image_key' => 'ubuntu-24.04',
        ]);
        CloudInstance::create([
            'service_id' => $service->id, 'provider' => 'hetzner', 'provider_ref' => null,
            'location_code' => 'de-falkenstein', 'image_key' => 'ubuntu-24.04',
            'status' => 'error', 'last_error' => $service->provision_error,
            'specs' => ['vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme'],
        ]);

        return $service;
    }

    public static function locales(): array
    {
        return [['fa', ''], ['en', '/en'], ['tr', '/tr']];
    }

    #[DataProvider('locales')]
    public function test_capacity_is_actionable_in_details_list_and_live_status(string $locale, string $prefix): void
    {
        $service = $this->order($locale);
        $before = $service->fresh()->getRawOriginal();
        $this->actingAs($service->customer, 'customer');

        $html = $this->get($prefix.'/account/cloud/'.$service->id)->assertOk()->getContent();
        $this->assertStringContainsString('data-cloud-order-issue="capacity"', $html);
        $this->assertStringContainsString(__('cloud-order.choose', [], $locale), $html);
        $this->assertStringContainsString('billing_mode=hourly', $html);
        $this->assertStringNotContainsString('id="cb-steps"', $html);
        $this->assertStringNotContainsString('resource_unavailable', $html);
        $this->assertStringNotContainsString('api-secret-test', $html);
        $this->assertStringNotContainsString('cloud-order.capacity.title', $html);
        $this->assertStringContainsString($prefix.'/account/cloud', $html);

        $this->get($prefix.'/account/servers')->assertOk()
            ->assertSee('data-cloud-order-issue="capacity"', false)
            ->assertSee(__('cloud-order.capacity.label', [], $locale));

        $this->getJson($prefix.'/account/cloud/'.$service->id.'/status')->assertOk()
            ->assertJsonPath('delivery_issue', 'capacity')->assertJsonPath('ready', false)
            ->assertJsonPath('stage_index', null)->assertJsonMissingPath('message')
            ->assertDontSee('resource_unavailable')->assertDontSee('api-secret-test');

        $this->assertSame($before, $service->fresh()->getRawOriginal(), 'Presentation must not mutate the order');
        Http::assertNothingSent();

        // Optional CI-only visual fixture. The database and customer are synthetic.
        if ($dir = getenv('CLOUD_ORDER_PREVIEWS')) {
            if (! is_dir($dir)) { mkdir($dir, 0700, true); }
            file_put_contents($dir.'/'.$locale.'.html', $html);
        }
    }

    public function test_an_existing_receipt_is_reviewed_instead_of_inviting_another_purchase(): void
    {
        $service = $this->order();
        $service->cloudInstance->update(['provider_ref' => 'receipt-test-only']);
        $this->actingAs($service->customer, 'customer')->get('/account/cloud/'.$service->id)
            ->assertOk()->assertSee('data-cloud-order-issue="review"', false)
            ->assertDontSee('data-cloud-choose-another', false);
        $this->getJson('/account/cloud/'.$service->id.'/status')->assertOk()
            ->assertJsonPath('delivery_issue', 'review');
        Http::assertNothingSent();
    }

    public function test_timeout_quota_and_unknown_failures_do_not_claim_capacity_or_encourage_reordering(): void
    {
        $service = $this->order();
        foreach (['timeout', '[resource_limit_exceeded] Primary IP limit exceeded', 'unknown error', ''] as $error) {
            $service->update(['provision_error' => $error]);
            $this->assertSame('review', CloudOrderIssue::kind($service, $service->cloudInstance));
        }
        $this->actingAs($service->customer, 'customer')->get('/account/cloud/'.$service->id)
            ->assertOk()->assertSee('data-cloud-order-issue="review"', false)
            ->assertDontSee('data-cloud-choose-another', false);
    }

    public function test_pending_and_delivered_orders_are_not_presented_as_capacity_failures(): void
    {
        $service = $this->order();
        $service->update(['provision_status' => 'pending']);
        $this->assertNull(CloudOrderIssue::kind($service, $service->cloudInstance));
        $this->actingAs($service->customer, 'customer')->getJson('/account/cloud/'.$service->id.'/status')
            ->assertOk()->assertJsonPath('status', 'building')->assertJsonMissingPath('delivery_issue');

        $service->update(['provision_status' => 'failed']);
        $service->cloudInstance->update(['provider_ref' => 'delivered-test', 'status' => 'running', 'ipv4' => '203.0.113.10']);
        $this->assertNull(CloudOrderIssue::kind($service, $service->cloudInstance->fresh()));
    }

    public function test_cancelled_orders_remain_closed_and_cannot_be_read_by_another_customer(): void
    {
        $service = $this->order();
        $service->update(['status' => 'cancelled']);
        $this->actingAs($service->customer, 'customer')->getJson('/account/cloud/'.$service->id.'/status')
            ->assertOk()->assertJsonPath('closed', true)->assertJsonMissingPath('delivery_issue');
        $this->get('/account/cloud/'.$service->id)->assertOk()->assertDontSee('data-cloud-order-issue=', false);

        $other = Customer::create(['email' => 'other@example.test', 'password' => bcrypt('fixture'), 'status' => 'active']);
        $this->actingAs($other, 'customer')->get('/account/cloud/'.$service->id)->assertNotFound();
        $this->getJson('/account/cloud/'.$service->id.'/status')->assertNotFound();
    }

    public function test_failure_is_observable_after_the_initial_building_response(): void
    {
        $service = $this->order();
        $service->update(['provision_status' => 'pending']);
        $this->actingAs($service->customer, 'customer')->getJson('/account/cloud/'.$service->id.'/status')
            ->assertOk()->assertJsonMissingPath('delivery_issue');
        $service->update(['provision_status' => 'failed']);
        $this->getJson('/account/cloud/'.$service->id.'/status')->assertOk()->assertJsonPath('delivery_issue', 'capacity');
        $service->update(['provision_status' => 'pending']);
        $this->getJson('/account/cloud/'.$service->id.'/status')->assertOk()->assertJsonMissingPath('delivery_issue');
        Http::assertNothingSent();
    }
}
