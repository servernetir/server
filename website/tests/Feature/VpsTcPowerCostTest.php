<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\CloudProviderOperation;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\VpsTcApi;
use App\Services\Cloud\VpsTcCatalog;
use App\Services\Cloud\VpsTcClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VpsTcPowerCostTest extends TestCase
{
    use RefreshDatabase;

    private string $remoteStatus = 'running';
    private string $hostname = '';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.vpstc' => ['base_url' => 'https://portal.vps.tc',
            'username' => 'fixture-user', 'password' => 'fixture-secret']]);
        // Closing sales does not, by itself, establish any management operation's cost.
        Setting::put('vpstc_sales_enabled', '0');
    }

    private function machine(string $provider = 'vpstc'): array
    {
        $customer = Customer::create(['email' => uniqid('power').'@example.test', 'password' => 'x',
            'status' => 'active', 'locale' => 'fa']);
        CloudLocation::firstOrCreate(['code' => 'tr-istanbul'], ['country' => 'TR', 'city' => 'Istanbul', 'is_active' => true]);
        $plan = CloudPlan::create(['provider' => $provider, 'provider_ref' => $provider === 'vpstc' ? '12' : 'cx22',
            'provider_location' => 'istanbul', 'location_code' => 'tr-istanbul', 'public_name' => 'CV-2-2',
            'slug' => uniqid('power-plan'), 'vcpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 30,
            'cost_eur_cents' => 1000, 'price_eur_cents' => 1200, 'price_irt' => 1200000,
            'is_active' => true, 'in_stock' => true]);
        $service = Service::create(['customer_id' => $customer->id, 'cloud_plan_id' => $plan->id,
            'name' => 'Monthly server', 'price' => 1200000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'currency_code' => 'IRT', 'billing_mode' => 'cycle', 'status' => 'active', 'provision_status' => 'done',
            'activated_at' => now(), 'next_due_at' => now()->addMonth(), 'username' => 'root']);
        $instance = CloudInstance::create(['service_id' => $service->id, 'provider' => $provider, 'provider_ref' => '83',
            'location_code' => 'tr-istanbul', 'image_key' => 'ubuntu-24.04', 'hostname' => 'sn-svc-'.$service->id,
            'status' => 'running', 'ipv4' => '203.0.113.5', 'specs' => ['vcpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 30]]);
        $instance->setPassword('fixture-root-password');
        $instance->save();
        $this->hostname = 'sn-svc-'.$service->id;
        if ($provider === 'vpstc') {
            CloudProviderOperation::create(['operation_key' => hash('sha256', 'fixture-create:'.$service->id), 'provider' => 'vpstc',
                'action' => 'create', 'resource_ref' => '83', 'task_ref' => '91', 'state' => 'pending', 'payload' => [
                    '_account' => app(VpsTcApi::class)->accountFingerprint(),
                    '_spec' => ['plan_ref' => '12', 'location_ref' => 'istanbul', 'name' => $this->hostname,
                        'vcpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 30],
                    'request' => ['domain' => $this->hostname.'.servernet.cloud'],
                    '_quote' => ['currency' => 'USD', 'total_amount' => '10.00'],
                ]]);
        }
        return [$service, $instance, $plan];
    }

    private function manifest(mixed $mode = null, ?string $account = null, array $overrides = []): void
    {
        $mapping = array_replace([
            'product_id' => '12', 'category_id' => '56', 'kind' => 'vps', 'cycle' => 'm',
            'renewal_mode' => 'manual_invoice_deduplicated', 'parent_cancel_stops_billing' => true,
            'power_cost_mode' => $mode, 'forms_sha256' => VpsTcCatalog::formFingerprint([]),
            'country' => 'TR', 'city' => 'Istanbul', 'location_ref' => 'istanbul',
            'vcpu' => 2, 'ram_mb' => 2048, 'disk_gb' => 30, 'disk_type' => 'nvme', 'cpu_kind' => 'shared',
            'arch' => 'x86', 'traffic_gb' => 1000, 'network_terms' => ['charge_mode' => 'hard_cap', 'extra_charges' => false],
            'availability' => ['path' => 'in_stock', 'mode' => 'boolean'], 'payment_method' => '120', 'custom' => [],
            'images' => ['12|ubuntu-24.04' => ['key' => 'ubuntu-24.04', 'family' => 'ubuntu', 'version' => '24.04',
                'label' => 'Ubuntu 24.04', 'min_ram_mb' => 512, 'min_disk_gb' => 10, 'custom' => []]],
        ], $overrides);
        if ($mode === null) { unset($mapping['power_cost_mode']); }
        Setting::put('vpstc_catalog_manifest', json_encode(['account_fingerprint' => $account ?? app(VpsTcApi::class)->accountFingerprint(),
            'products' => [$mapping]], JSON_THROW_ON_ERROR));
    }

    private function fake(array $overrides = []): void
    {
        Http::fake($overrides + [
            '*/api/login' => Http::response(['token' => 'fixture-token']),
            '*/api/service/83' => Http::response(['service' => ['id' => '83', 'status' => 'Active', 'billingcycle' => 'Monthly',
                'domain' => $this->hostname.'.servernet.cloud']]),
            '*/api/invoice/91' => Http::response(['invoice' => ['id' => '91', 'status' => 'Paid', 'currency' => 'USD',
                'total' => '0.00', 'credit' => '10.00', 'subtotal' => '10.00', 'tax' => '0.00',
                'items' => [['type' => 'Hosting', 'item_id' => '83', 'qty' => '1.00']]]]),
            '*/api/service/83/vms' => Http::response(['vms' => [['vmid' => 'vm-1056']]]),
            '*/api/service/83/vms/vm-1056' => fn () => Http::response(['vm' => ['vmid' => 'vm-1056',
                'built' => true, 'locked' => false, 'status' => $this->remoteStatus, 'ipv4' => ['203.0.113.5'],
                'username' => 'root', 'password' => 'fixture-root-password', 'cores' => 2, 'memory' => 2048, 'disk' => 30]]),
            '*/api/service/83/vms/vm-1056/stop' => function () {
                $this->remoteStatus = 'stopped';
                return Http::response(['status' => true]);
            },
        ]);
    }

    public function test_unknown_or_nonincluded_cost_never_exposes_customer_actions_or_calls_supplier(): void
    {
        [$service, $instance] = $this->machine();
        foreach ([null, 'unknown', 'paid', 'free', '', true, false, 0] as $mode) {
            $this->manifest($mode);
            $this->assertFalse(app(VpsTcClient::class)->canPower($instance));
            $this->assertFalse(app(VpsTcClient::class)->power('83', 'off')['ok']);
            $this->actingAs($service->customer, 'customer')->get('/account/cloud/'.$service->id)
                ->assertOk()->assertViewHas('powerActions', []);
        }
        $this->actingAs($service->customer, 'customer')->post('/account/cloud/'.$service->id.'/power', ['action' => 'off'])
            ->assertRedirect()->assertSessionHasErrors();
        Http::assertNothingSent();
    }

    public function test_explicit_included_policy_exposes_actions_and_submits_once_without_customer_charge(): void
    {
        [$service, $instance] = $this->machine();
        $this->manifest('included');
        $this->assertTrue(app(VpsTcClient::class)->canPower($instance));
        $this->actingAs($service->customer, 'customer')->get('/account/cloud/'.$service->id)
            ->assertOk()->assertViewHas('powerActions', ['on', 'off', 'reboot']);
        Http::assertNothingSent();
        $this->fake();
        $this->assertTrue(app(VpsTcClient::class)->power('83', 'off')['ok']);
        $this->assertTrue(app(VpsTcClient::class)->power('83', 'off')['ok']);
        $this->assertSame(1, Http::recorded(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/stop'))->count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, CreditEntry::count());
        $this->assertSame(1200000, (int) $service->fresh()->price);
        $this->assertSame(0, CloudProviderOperation::where('action', 'power')->count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/order/') || str_contains($r->url(), '/credit'));
    }

    public function test_paid_invoice_without_gross_components_never_submits_power(): void
    {
        [, $instance] = $this->machine();
        $this->manifest('included');
        $this->fake([
            '*/api/invoice/91' => Http::response(['invoice' => ['id' => '91', 'status' => 'Paid', 'currency' => 'USD',
                'total' => '10.00', 'items' => [['type' => 'Hosting', 'item_id' => '83', 'qty' => '1.00']]]]),
        ]);

        $this->assertFalse(app(VpsTcClient::class)->power((string) $instance->provider_ref, 'off')['ok']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/stop'));
        $this->assertSame(0, CloudProviderOperation::where('action', 'power')->count());
    }

    public function test_policy_for_another_account_product_or_location_cannot_authorize_power(): void
    {
        [, $instance] = $this->machine();
        $this->manifest('included', str_repeat('a', 64));
        $this->assertFalse(app(VpsTcClient::class)->canPower($instance));
        $this->manifest('included', overrides: ['product_id' => '13', 'images' => ['13|ubuntu' => [
            'key' => 'ubuntu-24.04', 'family' => 'ubuntu', 'version' => '24.04', 'label' => 'Ubuntu',
            'min_ram_mb' => 512, 'min_disk_gb' => 10, 'custom' => [],
        ]]]);
        $this->assertFalse(app(VpsTcClient::class)->canPower($instance));
        $this->manifest('included', overrides: ['location_ref' => 'another-region']);
        $this->assertFalse(app(VpsTcClient::class)->power('83', 'off')['ok']);
        Http::assertNothingSent();
    }

    public function test_creation_receipt_must_match_the_exact_local_product_and_service(): void
    {
        [$service, $instance] = $this->machine();
        $this->manifest('included');
        $op = CloudProviderOperation::where('action', 'create')->sole();
        $payload = $op->payload;
        $payload['_spec']['plan_ref'] = '13';
        $op->update(['payload' => $payload]);
        $this->assertFalse(app(VpsTcClient::class)->power('83', 'off')['ok']);
        Http::assertNothingSent();
    }

    public function test_supplier_service_identifier_is_never_guessed_to_be_product_identifier(): void
    {
        [, $instance] = $this->machine();
        $this->manifest('included', overrides: ['product_id' => '83', 'images' => ['83|ubuntu' => [
            'key' => 'ubuntu-24.04', 'family' => 'ubuntu', 'version' => '24.04', 'label' => 'Ubuntu',
            'min_ram_mb' => 512, 'min_disk_gb' => 10, 'custom' => [],
        ]]]);
        $this->assertFalse(app(VpsTcClient::class)->canPower($instance));
        Http::assertNothingSent();
    }

    public function test_power_cost_policy_does_not_change_other_providers_actions(): void
    {
        [$service] = $this->machine('hetzner');
        $this->actingAs($service->customer, 'customer')->get('/account/cloud/'.$service->id)
            ->assertOk()->assertViewHas('powerActions', ['on', 'off', 'reboot']);
        Http::assertNothingSent();
    }

    public function test_manual_attachment_without_account_bound_purchase_is_never_assumed_free(): void
    {
        [, $instance] = $this->machine();
        CloudProviderOperation::where('action', 'create')->delete();
        $this->manifest('included');
        $this->assertFalse(app(VpsTcClient::class)->canPower($instance));
        $this->assertFalse(app(VpsTcClient::class)->power('83', 'off')['ok']);
        Http::assertNothingSent();
    }

    public function test_cost_policy_removed_during_read_back_blocks_the_final_power_request(): void
    {
        [, $instance] = $this->machine();
        $this->manifest('included');
        $this->fake(['*/api/service/83' => function () {
            $this->manifest('unknown');
            return Http::response(['service' => ['id' => '83', 'status' => 'Active', 'billingcycle' => 'Monthly',
                'domain' => $this->hostname.'.servernet.cloud']]);
        }]);
        $this->assertFalse(app(VpsTcClient::class)->power('83', 'off')['ok']);
        $this->assertSame(0, CloudProviderOperation::where('action', 'power')->count());
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/stop'));
    }

    public function test_another_process_revoking_policy_cannot_be_hidden_by_request_setting_memo(): void
    {
        [, $instance] = $this->machine();
        $this->manifest('included');
        $original = Setting::get('vpstc_catalog_manifest');
        $this->fake(['*/api/service/83' => function () {
            $revoked = json_decode((string) Setting::get('vpstc_catalog_manifest'), true, 64, JSON_THROW_ON_ERROR);
            $revoked['products'][0]['power_cost_mode'] = 'unknown';
            // Simulate a different process; this request's memo remains the original included policy.
            DB::table('settings')->where('key', 'vpstc_catalog_manifest')->update([
                'value' => json_encode($revoked, JSON_THROW_ON_ERROR),
            ]);
            return Http::response(['service' => ['id' => '83', 'status' => 'Active', 'billingcycle' => 'Monthly',
                'domain' => $this->hostname.'.servernet.cloud']]);
        }]);
        $this->assertFalse(app(VpsTcClient::class)->power('83', 'off')['ok']);
        $this->assertSame($original, Setting::get('vpstc_catalog_manifest'));
        $this->assertSame(0, CloudProviderOperation::where('action', 'power')->count());
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/stop'));
    }

    public function test_parent_cancel_still_runs_without_a_power_cost_policy(): void
    {
        $this->fake();
        Http::fake(['*/api/login' => Http::response(['token' => 'fixture-token']),
            '*/api/service/83' => Http::response(['service' => ['id' => '83', 'status' => 'Active']]),
            '*/api/service/83/cancel' => Http::response(['info' => ['cancell_sent']])]);
        $result = app(VpsTcClient::class)->deleteServer('83');
        $this->assertFalse($result['ok']);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/cancel'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/stop'));
    }
}
