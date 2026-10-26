<?php

namespace Tests\Feature;

use App\Models\CloudPlan;
use App\Models\CloudInstance;
use App\Models\CloudImage;
use App\Models\CloudProviderOperation;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\VpsTcCatalog;
use App\Services\Cloud\VpsTcClient;
use App\Services\Cloud\CloudProvisioner;
use App\Services\Cloud\CloudFraudGuard;
use App\Http\Controllers\Account\CloudServerController;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class VpsTcLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private array $quote;
    private array $spec;
    private string $vmStatus = 'running';
    private string $password = 'fixture-root-password';
    private string $username = 'root';
    private string $serviceStatus = 'Active';
    private string $invoiceStatus = 'Paid';
    private string $supplierDue = '2026-11-26';
    private bool $journalObserved = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.vpstc' => ['base_url' => 'https://portal.vps.tc', 'username' => 'fixture-user', 'password' => 'fixture-secret']]);
        foreach (['vpstc_currency' => 'USD', 'cloud_margin_pct_vpstc' => '20', 'vpstc_supplier_tax_pct' => '0',
            'pricing_fx_fee_pct_vpstc' => '0', 'vpstc_sales_enabled' => '1'] as $key => $value) { Setting::put($key, $value); }
        CloudPlan::create(['provider' => 'vpstc', 'provider_ref' => '12', 'provider_location' => 'tr-ist',
            'location_code' => 'tr-istanbul', 'public_name' => 'CV-2-4', 'slug' => 'fixture-vpstc', 'vcpu' => 2,
            'ram_mb' => 4096, 'disk_gb' => 50, 'cost_eur_cents' => 1000, 'setup_eur_cents' => 100,
            'price_eur_cents' => 1200, 'price_irt' => 120000, 'in_stock' => true]);
        $this->spec = ['name' => 'sn-svc-7', 'labels' => ['snet-service' => '7'], 'plan_ref' => '12',
            'location_ref' => 'tr-ist', 'image_ref' => '12|ubuntu', 'vcpu' => 2, 'ram_mb' => 4096,
            'disk_gb' => 50, 'cpu_kind' => 'shared', 'term' => 'month'];
        $this->quote = ['product_id' => '12', 'currency' => 'USD', 'monthly_amount' => '10.00',
            'setup_amount' => '1.00', 'total_amount' => '11.00', 'payment_method' => '120', 'in_stock' => true,
            'cost_eur_cents' => 1000, 'setup_eur_cents' => 100, 'location_ref' => 'tr-ist',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 50, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'custom' => [], 'fingerprint' => str_repeat('a', 64),
            'images' => ['12|ubuntu' => ['custom' => ['4' => ['5' => 1]], 'template' => null]]];
        $this->mock(VpsTcCatalog::class, fn ($mock) => $mock->shouldReceive('quote')->with('12')
            ->andReturnUsing(fn () => $this->quote));
    }

    private function client(): VpsTcClient { return app(VpsTcClient::class); }

    private function fake(array $overrides = []): void
    {
        Http::fake($overrides + [
            '*/api/login' => Http::response(['token' => 'fixture-token']),
            '*/api/balance' => Http::response(['details' => ['currency' => 'USD', 'acc_credit' => '100.00']]),
            '*/api/service' => Http::response(['services' => []]),
            '*/api/order/12' => function () {
                $this->journalObserved = CloudProviderOperation::where('provider', 'vpstc')->where('action', 'create')->exists();
                return Http::response(['order_num' => '500', 'invoice_id' => '91', 'total' => '11.00',
                    'items' => ['id' => '83', 'type' => 'Hosting', 'product_id' => '12', 'name' => 'sn-svc-7.servernet.cloud']]);
            },
            '*/api/invoice/91' => fn () => Http::response(['invoice' => ['id' => '91', 'status' => $this->invoiceStatus,
                'currency' => 'USD', 'total' => '11.00', 'items' => [['item_id' => '83', 'type' => 'Hosting', 'qty' => '1.00']]]]),
            '*/api/service/83' => fn () => Http::response(['service' => ['id' => '83', 'status' => $this->serviceStatus,
                'domain' => 'sn-svc-7.servernet.cloud', 'billingcycle' => 'Monthly', 'next_due' => $this->supplierDue]]),
            '*/api/service/83/vms' => Http::response(['vms' => [['vmid' => 'vm-1056']]]),
            '*/api/service/83/vms/vm-1056' => fn () => Http::response(['vm' => ['vmid' => 'vm-1056',
                'built' => true, 'locked' => false, 'status' => $this->vmStatus, 'ipv4' => ['203.0.113.5'],
                'username' => $this->username, 'password' => $this->password, 'cores' => 2, 'memory' => 4096, 'disk' => 50]]),
        ]);
    }

    private function orderCount(): int
    {
        return Http::recorded(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/api/order/12'))->count();
    }

    public function test_monthly_order_is_journaled_before_purchase_and_retries_adopt_same_receipt(): void
    {
        $this->fake();
        $first = $this->client()->createServer($this->spec);
        $this->assertTrue($first['ok']);
        $this->assertSame('83', $first['ref']);
        $this->assertSame('running', $first['status']);
        $this->assertSame('fixture-root-password', $first['root_password']);
        $this->assertTrue($this->journalObserved);
        $again = $this->client()->createServer($this->spec);
        $this->assertSame('83', $again['ref']);
        $this->assertSame(1, $this->orderCount());
        $this->assertSame(1, CloudProviderOperation::count());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/order/12')
            && $request['cycle'] === 'm' && $request['pay_method'] === 120 && $request['custom']['4']['5'] === 1);
    }

    public function test_lost_order_response_is_never_resubmitted_even_when_service_list_is_empty(): void
    {
        $attempts = 0;
        $this->fake(['*/api/order/12' => function () use (&$attempts) {
            $attempts++; throw new ConnectionException('do-not-expose-private-secret');
        }]);
        foreach ([1, 2, 3] as $_) {
            $r = $this->client()->createServer($this->spec);
            $this->assertFalse($r['ok']);
            $this->assertStringNotContainsString('do-not-expose', json_encode($r));
        }
        $this->assertSame(1, $attempts);
        $this->assertSame('outcome_unknown', CloudProviderOperation::sole()->state);
        $inventory = $this->client()->listServers();
        $this->assertTrue($inventory['ok']);
        $this->assertNotSame('', $inventory['message']);
    }

    public function test_explicit_provider_rejection_also_requires_resolution_before_a_new_order(): void
    {
        $this->fake(['*/api/order/12' => Http::response(['error' => 'private-provider-error'], 422)]);
        $this->assertFalse($this->client()->createServer($this->spec)['ok']);
        $this->assertFalse($this->client()->createServer($this->spec)['ok']);
        $this->assertSame(1, $this->orderCount());
        $this->assertSame('rejected', CloudProviderOperation::sole()->state);
    }

    public function test_hourly_and_nondeterministic_orders_cannot_authenticate_or_buy(): void
    {
        $this->fake();
        foreach ([['term' => 'hour'], ['name' => 'user-host'], ['labels' => ['snet-service' => '8']]] as $change) {
            $this->assertFalse($this->client()->createServer(array_replace($this->spec, $change))['ok']);
        }
        Http::assertNothingSent();
        $this->assertSame(0, CloudProviderOperation::count());
    }

    public function test_sale_switch_blocks_only_new_purchases_and_keeps_existing_reconciliation(): void
    {
        $this->fake();
        Setting::put('vpstc_sales_enabled', '0');
        $this->assertFalse($this->client()->createServer($this->spec)['ok']);
        Http::assertNothingSent();
        Setting::put('vpstc_sales_enabled', '1');
        $this->assertTrue($this->client()->createServer($this->spec)['ok']);
        Setting::put('vpstc_sales_enabled', '0');
        $this->assertTrue($this->client()->createServer($this->spec)['ok']);
        $this->assertSame(1, $this->orderCount());
    }

    public function test_inadequate_credit_and_price_increase_never_create_order_journals(): void
    {
        $this->fake(['*/api/balance' => Http::response(['details' => ['currency' => 'USD', 'acc_credit' => '10.99']])]);
        $this->assertFalse($this->client()->createServer($this->spec)['ok']);
        $this->assertSame(0, $this->orderCount());
        $this->assertSame(0, CloudProviderOperation::count());
        $this->quote['cost_eur_cents'] = 1001;
        $this->assertFalse($this->client()->createServer($this->spec)['ok']);
        $this->assertSame(0, CloudProviderOperation::count());
    }

    public function test_out_of_stock_and_changed_resources_do_not_buy(): void
    {
        $this->fake();
        $this->quote['in_stock'] = false;
        $this->assertFalse($this->client()->createServer($this->spec)['ok']);
        $this->quote['in_stock'] = true; $this->quote['ram_mb'] = 2048;
        $this->assertFalse($this->client()->createServer($this->spec)['ok']);
        Http::assertNothingSent();
    }

    public function test_existing_unjournaled_named_supplier_service_requires_review(): void
    {
        $this->fake(['*/api/service' => Http::response(['services' => [['id' => '82', 'status' => 'Active',
            'domain' => 'sn-svc-7.servernet.cloud']]])]);
        $this->assertFalse($this->client()->createServer($this->spec)['ok']);
        $this->assertSame(0, $this->orderCount());
        $this->assertSame(0, CloudProviderOperation::count());
    }

    public function test_credentials_change_cannot_reuse_an_existing_order_or_buy_again(): void
    {
        $this->fake();
        $this->assertTrue($this->client()->createServer($this->spec)['ok']);
        config(['services.vpstc.username' => 'different-reseller']);
        $this->assertFalse($this->client()->createServer($this->spec)['ok']);
        $this->assertSame(1, $this->orderCount());
    }

    public function test_unpaid_or_unrelated_invoice_cannot_mark_delivery_ready_or_pay_unrelated_credit(): void
    {
        $this->fake(['*/api/invoice/91' => Http::response(['invoice' => ['id' => '91', 'status' => 'Paid',
            'currency' => 'USD', 'total' => '11.00', 'items' => [['item_id' => '999']]]])]);
        $r = $this->client()->createServer($this->spec);
        $this->assertTrue($r['ok']);
        $this->assertSame('building', $r['status']);
        $this->assertNull($r['root_password']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/credit'));
    }

    public function test_running_vm_without_credentials_is_still_building(): void
    {
        $this->fake(); $this->password = '';
        $r = $this->client()->createServer($this->spec);
        $this->assertTrue($r['ok']);
        $this->assertSame('building', $r['status']);
    }

    public function test_verified_encrypted_access_survives_one_time_supplier_password_field(): void
    {
        $this->fake();
        $this->assertSame('running', $this->client()->createServer($this->spec)['status']);
        $this->password = '';
        $again = $this->client()->createServer($this->spec);
        $this->assertSame('running', $again['status']);
        $this->assertSame('fixture-root-password', $again['root_password']);
        $this->assertSame(1, $this->orderCount());
    }

    public function test_terminated_supplier_order_is_not_finalized_as_new_success_or_rebought(): void
    {
        $this->fake();
        $this->assertTrue($this->client()->createServer($this->spec)['ok']);
        $this->serviceStatus = 'Terminated';
        $r = $this->client()->createServer($this->spec);
        $this->assertFalse($r['ok']);
        $this->assertSame('deleted', $r['status']);
        $this->assertSame(1, $this->orderCount());
    }

    public function test_numeric_keyed_vm_without_duplicate_id_field_is_supported(): void
    {
        $this->fake(['*/api/service/83/vms' => Http::response(['vms' => ['1056' => ['built' => true]]]),
            '*/api/service/83/vms/1056' => Http::response(['vm' => ['built' => true, 'locked' => false,
                'status' => 'running', 'ipv4' => '203.0.113.5', 'password' => 'fixture', 'username' => 'root']])]);
        $this->assertSame('running', $this->client()->serverStatus('83')['status']);
    }

    public function test_status_persists_access_and_initial_supplier_due_without_erasing_renewal_anchor(): void
    {
        $customer = Customer::create(['email' => 'vpstc-fixture@example.test', 'password' => 'fixture-password']);
        $service = new Service(['customer_id' => $customer->id, 'name' => 'Fixture VPS', 'cycle' => 'monthly', 'price' => 100]);
        $service->id = 7; $service->save();
        $instance = CloudInstance::create(['service_id' => 7, 'provider' => 'vpstc', 'provider_ref' => '83', 'status' => 'building']);
        $this->fake();
        $this->assertSame('running', $this->client()->createServer($this->spec)['status']);
        $this->assertTrue($instance->fresh()->hasPassword());
        $this->assertSame('root', $service->fresh()->username);
        $this->assertSame('fixture-root-password', $service->fresh()->password);
        $this->assertSame('2026-11-26', $instance->fresh()->meta['vpstc_supplier_due_at']);
        $instance->update(['password_seen' => true]);
        $this->supplierDue = '2026-12-26';
        $status = $this->client()->serverStatus('83');
        $this->assertTrue($status['ok']);
        $this->assertStringNotContainsString('fixture-root-password', json_encode($status));
        $this->assertTrue($instance->fresh()->password_seen);
        $this->assertSame('2026-11-26', $instance->fresh()->meta['vpstc_supplier_due_at']);
        $this->assertSame('2026-11-26', CloudProviderOperation::where('action', 'create')->sole()->payload['_supplier_due_at']);
    }

    public function test_account_poll_and_scheduler_preserve_metadata_written_during_reconciliation(): void
    {
        Mail::fake();
        $customer = Customer::create(['email' => 'vpstc-poll@example.test', 'password' => 'fixture-password', 'status' => 'active']);
        $service = new Service(['customer_id' => $customer->id, 'name' => 'Fixture VPS', 'cycle' => 'monthly',
            'price' => 100, 'status' => 'active', 'provision_status' => 'done']);
        $service->id = 7; $service->save();
        $instance = CloudInstance::create(['service_id' => 7, 'provider' => 'vpstc', 'status' => 'building', 'meta' => ['keep' => true]]);
        $this->fake();
        $this->client()->createServer($this->spec);
        $instance->update(['provider_ref' => '83']);
        $this->actingAs($customer, 'customer');
        $response = app(CloudServerController::class)->status($service->fresh());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('2026-11-26', $instance->fresh()->meta['vpstc_supplier_due_at']);
        $this->assertTrue($instance->fresh()->meta['keep']);
        $this->assertStringNotContainsString('fixture-root-password', json_encode(Cache::get('cloud-st:'.$instance->id)));
        $instance->refresh()->update(['status' => 'building', 'meta' => ['keep' => true], 'ready_notified_at' => now()]);
        $out = app(CloudProvisioner::class)->syncInstances();
        $this->assertSame(1, $out['refreshed']);
        $this->assertSame('2026-11-26', $instance->fresh()->meta['vpstc_supplier_due_at']);
        $this->assertTrue($instance->fresh()->meta['keep']);
    }

    public function test_real_provisioner_preserves_verified_windows_username_in_final_delivery(): void
    {
        Mail::fake();
        $this->mock(CloudFraudGuard::class, fn ($mock) => $mock->shouldReceive('check')->andReturn(['hold' => false, 'reason' => '']));
        app(VpsTcCatalog::class)->shouldReceive('manifest')->andReturn(['products' => []]);
        CloudImage::create(['provider' => 'vpstc', 'provider_ref' => '12|ubuntu', 'key' => 'windows-2022',
            'kind' => 'os', 'family' => 'windows', 'version' => '2022', 'label' => 'Windows Server 2022', 'arch' => 'x86', 'is_active' => true]);
        $customer = Customer::create(['email' => 'vpstc-windows@example.test', 'password' => 'fixture-password', 'status' => 'active']);
        $service = new Service(['customer_id' => $customer->id, 'name' => 'Fixture Windows', 'cycle' => 'monthly',
            'price' => 100000, 'status' => 'awaiting_provision', 'provision_status' => 'pending',
            'cloud_plan_id' => CloudPlan::where('provider', 'vpstc')->sole()->id, 'cloud_image_key' => 'windows-2022']);
        $service->id = 7; $service->save();
        $this->username = 'Administrator'; $this->fake();
        $this->assertTrue(app(CloudProvisioner::class)->provision($service));
        $this->assertSame('Administrator', $service->fresh()->username);
        $this->assertSame('fixture-root-password', $service->fresh()->password);
        $this->assertSame('running', $service->fresh()->cloudInstance->status);
        $this->assertSame('2026-11-26', $service->fresh()->cloudInstance->meta['vpstc_supplier_due_at']);
        $this->assertSame(1, $this->orderCount());
    }

    public function test_linked_unpaid_invoice_is_funded_once_after_durable_claim_and_paid_readback(): void
    {
        $claimSeen = false; $this->invoiceStatus = 'Unpaid';
        $this->fake(['*/api/invoice/91/credit' => function () use (&$claimSeen) {
            $claimSeen = isset(CloudProviderOperation::where('action', 'create')->sole()->payload['_credit_attempted']);
            $this->invoiceStatus = 'Paid';
            return Http::response(['success' => true, 'invoice_status' => 'Paid', 'applied' => '11.00']);
        }]);
        $r = $this->client()->createServer($this->spec);
        $this->assertTrue($r['ok']); $this->assertSame('running', $r['status']);
        $this->assertTrue($claimSeen);
        $this->assertTrue($this->client()->createServer($this->spec)['ok']);
        $this->assertSame(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/credit'))->count());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/credit') && $request['amount'] === '11.00');
    }

    public function test_lost_credit_response_is_not_repeated_and_later_paid_read_resolves_it(): void
    {
        $attempts = 0; $this->invoiceStatus = 'Unpaid';
        $this->fake(['*/api/invoice/91/credit' => function () use (&$attempts) {
            $attempts++; throw new ConnectionException('lost credit receipt');
        }]);
        $this->assertSame('building', $this->client()->createServer($this->spec)['status']);
        $this->assertSame('building', $this->client()->createServer($this->spec)['status']);
        $this->assertSame(1, $attempts);
        $this->invoiceStatus = 'Paid';
        $this->assertSame('running', $this->client()->createServer($this->spec)['status']);
    }

    public function test_mixed_invoice_cannot_apply_credit_even_when_below_quote_ceiling(): void
    {
        $this->fake(['*/api/invoice/91' => Http::response(['invoice' => ['id' => '91', 'status' => 'Unpaid',
            'currency' => 'USD', 'total' => '10.00', 'items' => [
                ['item_id' => '83', 'type' => 'Hosting', 'qty' => '1.00'],
                ['item_id' => '84', 'type' => 'Hosting', 'qty' => '1.00'],
            ]]])]);
        $this->assertSame('building', $this->client()->createServer($this->spec)['status']);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/credit'));
    }

    public function test_cancel_waits_for_parent_termination_and_never_deletes_vm(): void
    {
        $this->fake(['*/api/service/83/cancel' => Http::response(['info' => ['cancell_sent']])]);
        $first = $this->client()->deleteServer('83');
        $this->assertFalse($first['ok']);
        $this->assertTrue($first['pending']);
        $this->assertFalse($this->client()->deleteServer('83')['ok']);
        $this->serviceStatus = 'Terminated';
        $this->assertTrue($this->client()->deleteServer('83')['ok']);
        $this->assertSame(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/cancel'))->count());
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }

    public function test_lost_power_stop_can_reconcile_observed_off_without_resubmission(): void
    {
        $attempts = 0;
        $this->fake(['*/api/service/83/vms/vm-1056/stop' => function () use (&$attempts) {
            $attempts++; throw new ConnectionException('lost');
        }]);
        $this->assertFalse($this->client()->power('83', 'off')['ok']);
        $this->vmStatus = 'stopped';
        $this->assertTrue($this->client()->power('83', 'off')['ok']);
        $this->assertSame(1, $attempts);
    }

    public function test_lost_reboot_is_not_completed_merely_because_vm_is_running(): void
    {
        $attempts = 0;
        $this->fake(['*/api/service/83/vms/vm-1056/reboot' => function () use (&$attempts) {
            $attempts++; throw new ConnectionException('lost');
        }]);
        $this->assertFalse($this->client()->power('83', 'reboot')['ok']);
        $this->assertFalse($this->client()->power('83', 'reboot')['ok']);
        $this->assertSame(1, $attempts);
    }

    public function test_inventory_rejects_declared_partial_or_duplicate_lists(): void
    {
        $this->fake(['*/api/service' => Http::sequence()->push(['services' => [], 'total' => 50])
            ->push(['services' => [['id' => '83'], ['id' => '83']]])]);
        $this->assertFalse($this->client()->listServers()['ok']);
        $this->assertFalse($this->client()->listServers()['ok']);
    }

    public function test_unsupported_features_do_not_make_provider_requests(): void
    {
        $this->fake();
        foreach (['rebuild', 'console', 'reset_password', 'snapshot', 'resize', 'ssh_key', 'extra_ip'] as $key) {
            $this->assertFalse($this->client()->capabilities()[$key]);
        }
        $this->assertFalse($this->client()->console('83')['ok']);
        $this->assertFalse($this->client()->resetPassword('83')['ok']);
        $this->assertFalse($this->client()->rebuild('83', '12|ubuntu')['ok']);
        Http::assertNothingSent();
    }
}
