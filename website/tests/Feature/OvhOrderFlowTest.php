<?php

namespace Tests\Feature;

use App\Models\{CloudImage, CloudInstance, CloudPlan, Customer, Invoice, InvoiceItem, OvhOrder, Service, Setting, User};
use App\Services\Billing\UndeliveredRefund;
use App\Services\Cloud\{CloudProvisioner, OvhClient, OvhOrderHold, OvhOrders};
use App\Services\Notify\AdminNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\{Http, Mail};
use Tests\Support\SeedsCatalogPricing;
use Tests\TestCase;

class OvhOrderFlowTest extends TestCase
{
    use RefreshDatabase, SeedsCatalogPricing;

    private Service $service;
    private array $items = [];
    private array $configurations = [];
    private int $checkouts = 0;
    private int $payments = 0;
    private int $installs = 0;
    private string $status = 'notPaid';
    private bool $loseCheckout = false;
    private bool $recoverOrder = true;
    private bool $losePayment = false;
    private bool $rejectPayment = false;
    private bool $rejectCheckout = false;
    private bool $noPaymentMethod = false;
    private bool $badHardware = false;
    private bool $badCurrency = false;
    private bool $transientCatalog = false;
    private bool $nestedWorker = false;
    private ?array $nestedResult = null;
    private bool $taskDone = false;
    private bool $loseInstall = false;
    private float $actualGross = 12;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogPricing();
        config(['ovh.purchase_enabled' => true]);
        Setting::put('ovh_purchase_enabled', '1');
        Setting::put('ovh_previous_order_checked', '1');
        Setting::put('ovh_max_order_minor', '5000');
        Setting::put('cloud_margin_pct_ovh', '20');
        Setting::put('pricing_fx_fee_pct_ovh', '0');
        Setting::putSecret('ovh_app_key', 'fixture-app');
        Setting::putSecret('ovh_app_secret', 'fixture-secret');
        Setting::putSecret('ovh_consumer_key', 'fixture-consumer');
        $this->mock(AdminNotifier::class)->shouldReceive('event')->zeroOrMoreTimes();
        Mail::fake();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(fn ($r) => $this->api($r));
        $customer = Customer::create(['email' => 'ovh-fixture@example.com', 'password' => 'fixture', 'status' => 'active', 'locale' => 'en', 'created_at' => now()->subDays(10)]);
        $plan = CloudPlan::create(['provider' => 'ovh', 'provider_ref' => 'vps-2025-model1', 'provider_location' => 'GRA',
            'location_code' => 'fr-gravelines', 'public_name' => 'CV-2-2', 'slug' => 'ovh-fixture', 'vcpu' => 2,
            'ram_mb' => 2048, 'disk_gb' => 40, 'disk_type' => 'nvme', 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 1200, 'price_eur_cents' => 2000, 'price_irt' => 2000000, 'is_active' => true, 'in_stock' => true]);
        CloudImage::create(['provider' => 'ovh', 'provider_ref' => 'Ubuntu 24.04', 'key' => 'ubuntu-24.04',
            'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04', 'label' => 'Ubuntu 24.04', 'is_active' => true]);
        $this->service = Service::create(['customer_id' => $customer->id, 'name' => 'Fixture VPS', 'currency_code' => 'IRT',
            'price' => 2000000, 'cycle' => 'monthly', 'status' => 'awaiting_provision', 'provision_status' => 'pending',
            'cloud_plan_id' => $plan->id, 'cloud_image_key' => 'ubuntu-24.04', 'cloud_addons' => ['extra_ipv4' => 0]]);
        $invoice = Invoice::create(['customer_id' => $customer->id, 'service_id' => $this->service->id, 'kind' => 'service',
            'status' => 'paid', 'currency_code' => 'IRT', 'subtotal' => 2000000, 'total' => 2000000, 'paid' => 2000000, 'paid_at' => now()]);
        InvoiceItem::create(['invoice_id' => $invoice->id, 'title' => 'Fixture VPS', 'quantity' => 1,
            'unit_price' => 2000000, 'line_total' => 2000000, 'tax_rate_bp' => 0, 'tax_amount' => 0]);
    }

    private function price(float $value, string $currency = 'EUR'): array { return ['value' => $value, 'currencyCode' => $currency]; }
    private function workflow(bool $readOnly = false, bool $dry = false): array
    { return app(OvhOrders::class)->provision(app(OvhClient::class), ['service_id' => $this->service->id], $dry, $readOnly); }
    private function order(): OvhOrder { return OvhOrder::where('service_id', $this->service->id)->firstOrFail(); }
    private function due(): void { $this->travel(16)->minutes(); }

    private function api($r)
    {
        $path = preg_replace('#^/1.0#', '', parse_url($r->url(), PHP_URL_PATH));
        $method = $r->method();
        $reply = static fn ($v, $status = 200) => Http::response($v, $status);
        if ($path === '/auth/time') { return $reply((string) time()); }
        if ($path === '/me') { return $reply(['ovhSubsidiary' => 'FR', 'currency' => ['code' => 'EUR']]); }
        if ($path === '/order/catalog/public/vps') {
            if ($this->transientCatalog) { return $reply([], 503); }
            return $reply(['plans' => [['planCode' => 'vps-2025-model1', 'product' => 'base',
                'configurations' => [['name' => 'vps_datacenter', 'values' => ['GRA']], ['name' => 'vps_os', 'values' => ['Ubuntu 24.04']], ['name' => 'region', 'values' => ['france']]],
                'pricings' => [['capacities' => ['renew'], 'intervalUnit' => 'month', 'commitment' => 0, 'price' => 1000000000]]]],
                'products' => [['name' => 'base', 'blobs' => ['technical' => ['cpu' => ['cores' => $this->badHardware ? 4 : 2], 'memory' => ['size' => 2], 'storage' => ['disks' => [['capacity' => 40]]]]]]]]);
        }
        if ($path === '/order/cart' && $method === 'POST') { return $reply(['cartId' => 'cart-fixture']); }
        if ($path === '/order/cart' && $method === 'GET') { return $reply(['cart-fixture']); }
        if ($path === '/order/cart/cart-fixture') { return $reply(['cartId' => 'cart-fixture', 'description' => 'snet-'.$this->order()->idempotency_key, 'items' => $this->items, 'readOnly' => false]); }
        if ($path === '/order/cart/cart-fixture/assign') { return $reply([]); }
        if ($path === '/order/cart/cart-fixture/vps' && $method === 'GET') { return $reply([['planCode' => 'vps-2025-model1', 'orderable' => true, 'prices' => [['duration' => 'P1M', 'pricingMode' => 'default', 'commitment' => 0]]]]); }
        if ($path === '/order/cart/cart-fixture/vps' && $method === 'POST') { $this->items[] = 11; return $reply(['itemId' => 11]); }
        if ($path === '/order/cart/cart-fixture/item/11') { return $reply(['planCode' => 'vps-2025-model1', 'quantity' => 1, 'duration' => 'P1M']); }
        if ($path === '/order/cart/cart-fixture/item/11/requiredConfiguration') { return $reply(array_map(fn ($label) => ['label' => $label, 'required' => true, 'type' => 'String'], ['vps_datacenter', 'vps_os', 'region'])); }
        if ($path === '/order/cart/cart-fixture/item/11/configuration' && $method === 'GET') { return $reply(array_keys($this->configurations)); }
        if ($path === '/order/cart/cart-fixture/item/11/configuration' && $method === 'POST') {
            $id = count($this->configurations) + 1; $this->configurations[$id] = ['label' => $r['label'], 'value' => $r['value']]; return $reply(['configurationId' => $id]);
        }
        if (preg_match('#^/order/cart/cart-fixture/item/11/configuration/(\d+)$#', $path, $m)) { return $reply($this->configurations[(int) $m[1]]); }
        if ($path === '/order/cart/cart-fixture/checkout' && $method === 'GET') {
            if ($this->checkouts > 0 && $this->recoverOrder) { return $reply(['orderId' => 101]); }
            return $reply(['prices' => ['withTax' => $this->price(12, $this->badCurrency ? 'USD' : 'EUR'), 'withoutTax' => $this->price(10), 'tax' => $this->price(2)]]);
        }
        if ($path === '/order/cart/cart-fixture/checkout' && $method === 'POST') {
            $this->checkouts++;
            if ($this->nestedWorker) { $this->nestedResult = $this->workflow(); }
            if ($this->loseCheckout) { throw new ConnectionException('Fixture lost checkout response'); }
            return $this->rejectCheckout ? $reply(['message' => 'Rejected'], 403) : $reply(['orderId' => 101]);
        }
        if ($path === '/me/payment/method') { return $reply($this->noPaymentMethod ? [] : [77]); }
        if ($path === '/me/payment/method/77') { return $reply(['status' => 'VALID']); }
        if ($path === '/me/order/101') { return $reply(['orderId' => 101, 'priceWithTax' => $this->price($this->actualGross), 'priceWithoutTax' => $this->price(10)]); }
        if ($path === '/me/order/101/status') { return $reply(json_encode($this->status)); }
        if ($path === '/me/order/101/pay') {
            $this->payments++;
            if ($this->rejectPayment) { return $reply(['message' => 'Insufficient funds'], 400); }
            $this->status = 'checking';
            if ($this->losePayment) { throw new ConnectionException('Fixture lost payment response'); }
            return $reply([]);
        }
        if ($path === '/me/order/101/details') { return $reply([201]); }
        if ($path === '/me/order/101/details/201/extension') { return $reply(['order' => ['plan' => ['code' => 'vps-2025-model1'], 'configurations' => [['label' => 'vps_datacenter', 'value' => 'GRA'], ['label' => 'vps_os', 'value' => 'Ubuntu 24.04']]]]); }
        if ($path === '/me/order/101/details/201') { return $reply(['domain' => $this->status === 'delivered' ? 'vps-fixture' : '', 'quantity' => '1']); }
        if ($path === '/me/order/101/details/201/operations') { return $reply($this->status === 'delivered' ? [301] : []); }
        if ($path === '/me/order/101/details/201/operations/301') { return $reply(['resource' => ['name' => 'vps-fixture'], 'type' => 'creation']); }
        if ($path === '/vps/vps-fixture/images/available') { return $reply(['image-fixture']); }
        if ($path === '/vps/vps-fixture/images/available/image-fixture' || $path === '/vps/vps-fixture/images/current') { return $reply(['id' => 'image-fixture', 'name' => 'Ubuntu 24.04']); }
        if ($path === '/vps/vps-fixture/rebuild') { $this->installs++; if ($this->loseInstall) { throw new ConnectionException('Fixture lost install response'); } return $reply(['id' => 401, 'state' => 'todo']); }
        if ($path === '/vps/vps-fixture/tasks/401') { return $reply(['id' => 401, 'state' => $this->taskDone ? 'done' : 'doing']); }
        if ($path === '/vps/vps-fixture') { return $reply(['state' => 'running', 'model' => ['name' => 'VPS-1'], 'zone' => 'GRA']); }
        if ($path === '/vps/vps-fixture/ips') { return $reply(['1.1.1.1']); }
        throw new \RuntimeException('Unfaked API: '.$method.' '.$path);
    }

    public function test_paid_order_is_not_active_until_delivery_image_task_and_ip_are_verified(): void
    {
        $p = app(CloudProvisioner::class);
        $this->assertFalse($p->provision($this->service));
        $this->assertSame('awaiting_provision', $this->service->fresh()->status);
        $this->assertSame(1, $this->checkouts, json_encode($this->order()->only(['phase', 'error_code', 'error_message'])));
        $this->assertSame(1, $this->payments);
        $this->status = 'delivered'; $this->due();
        $this->assertFalse($p->provision($this->service->fresh()));
        $this->assertSame(1, $this->installs);
        $this->assertSame('awaiting_provision', $this->service->fresh()->status);
        $this->taskDone = true; $this->due();
        $this->assertTrue($p->provision($this->service->fresh()), $this->order()->error_code ?? '');
        $this->assertSame('active', $this->service->fresh()->status);
        $this->assertSame('ubuntu', $this->service->fresh()->username);
        $this->assertSame('1.1.1.1', $this->service->fresh()->cloudInstance->ipv4);
        $this->assertTrue($p->provision($this->service->fresh()));
        $this->assertSame([1, 1, 1], [$this->checkouts, $this->payments, $this->installs]);
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_default_off_deployment_gate_sends_no_provider_request(): void
    {
        config(['ovh.purchase_enabled' => false]);
        $r = $this->workflow();
        $this->assertTrue($r['manual']);
        $this->assertSame('purchase_disabled', $this->order()->error_code);
        Http::assertNothingSent();
        $this->assertContains('ovh', CloudPlan::disabledProviders());
    }

    public function test_unpaid_or_refunded_invoice_never_reaches_checkout(): void
    {
        $this->service->invoices()->update(['status' => 'unpaid', 'paid' => 0]);
        $this->assertTrue($this->workflow()['manual']);
        $this->assertSame('customer_unpaid', $this->order()->error_code);
        $this->assertSame(0, $this->checkouts); Http::assertNothingSent();
    }

    public function test_margin_includes_supplier_tax_and_blocks_loss_making_purchase(): void
    {
        $this->service->invoices()->update(['subtotal' => 1000000, 'total' => 1000000, 'paid' => 1000000]);
        $this->assertTrue($this->workflow()['manual']);
        $this->assertSame('margin_shortfall', $this->order()->error_code);
        $this->assertSame(0, $this->checkouts);
    }

    public function test_currency_mismatch_is_not_assumed_to_be_a_conversion(): void
    {
        $this->badCurrency = true;
        $this->assertTrue($this->workflow()['manual']);
        $this->assertSame('currency_mismatch', $this->order()->error_code);
        $this->assertSame(0, $this->checkouts);
    }

    public function test_missing_default_payment_method_does_not_generate_order(): void
    {
        $this->noPaymentMethod = true;
        $this->assertTrue($this->workflow()['manual']);
        $this->assertSame('payment_method_missing', $this->order()->error_code);
        $this->assertSame(0, $this->checkouts);
    }

    public function test_dry_run_verifies_final_cost_but_never_checks_out_or_pays(): void
    {
        $r = $this->workflow(false, true);
        $this->assertSame('dry_run', $this->order()->phase, $r['message']);
        $this->assertSame(1200, (int) $this->order()->cost_minor);
        $this->assertSame(800000, (int) $this->order()->profit_irt);
        $this->assertSame([0, 0, 0], [$this->checkouts, $this->payments, $this->installs]);
    }

    public function test_rejected_checkout_is_reviewed_without_an_automatic_refund_or_new_order(): void
    {
        $this->rejectCheckout = true;
        $this->assertTrue($this->workflow()['manual']);
        $this->assertSame('checkout_unknown', $this->order()->phase);
        $this->assertSame(1, $this->checkouts);
        $this->assertDatabaseCount('credit_ledger', 0);
        $this->assertFalse(app(UndeliveredRefund::class)->eligible($this->service));
    }

    public function test_checkout_timeout_reconciles_exact_cart_and_never_buys_twice(): void
    {
        $this->loseCheckout = true;
        $this->assertTrue($this->workflow()['manual']);
        $this->assertNull($this->order()->order_id);
        $this->loseCheckout = false;
        $this->workflow(true);
        $this->assertSame(101, (int) $this->order()->order_id);
        $this->assertSame(0, $this->payments);
        $this->due(); $this->workflow();
        $this->assertSame([1, 1], [$this->checkouts, $this->payments]);
    }

    public function test_unresolved_checkout_stays_held_across_new_driver_and_restart(): void
    {
        $this->loseCheckout = true; $this->recoverOrder = false;
        $this->workflow(); $this->due();
        app()->forgetInstance(OvhClient::class);
        $this->workflow(true); $this->workflow(true);
        $this->assertSame('checkout_unknown', $this->order()->phase);
        $this->assertSame([1, 0], [$this->checkouts, $this->payments]);
    }

    public function test_payment_timeout_is_reconciled_without_resubmitting_payment(): void
    {
        $this->losePayment = true;
        $this->assertTrue($this->workflow()['manual']);
        $this->assertSame('payment_unknown', $this->order()->phase);
        $this->workflow(true);
        $this->assertSame('checking', $this->order()->provider_status);
        $this->assertSame(1, $this->payments);
    }

    public function test_insufficient_supplier_credit_is_held_without_a_second_charge(): void
    {
        $this->rejectPayment = true;
        $this->workflow(); $this->workflow(true); $this->workflow(true);
        $this->assertSame('payment_unknown', $this->order()->phase);
        $this->assertSame([1, 1], [$this->checkouts, $this->payments]);
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_second_worker_during_checkout_cannot_acquire_journal_or_create_an_order(): void
    {
        $this->nestedWorker = true; $this->workflow();
        $this->assertSame('busy', $this->nestedResult['code']);
        $this->assertSame([1, 1], [$this->checkouts, $this->payments]);
        $this->assertDatabaseCount('ovh_orders', 1);
    }

    public function test_changed_hardware_never_reaches_a_cart_or_purchase(): void
    {
        $this->badHardware = true;
        $this->assertTrue($this->workflow()['manual']);
        $this->assertSame('hardware_mismatch', $this->order()->error_code);
        $this->assertNull($this->order()->cart_id); $this->assertSame(0, $this->checkouts);
    }

    public function test_bounded_backoff_and_retry_exhaustion_are_persisted(): void
    {
        $this->transientCatalog = true;
        $this->assertTrue($this->workflow()['pending']);
        $first = $this->order()->attempts;
        $this->workflow(); $this->assertSame($first, $this->order()->attempts);
        $this->assertTrue($this->order()->next_attempt_at->isFuture());
        $this->order()->update(['attempts' => 12, 'next_attempt_at' => now()->subSecond()]);
        $this->assertTrue($this->workflow()['manual']);
        $this->assertSame('retry_exhausted', $this->order()->error_code);
    }

    public function test_api_key_rotation_cannot_move_an_existing_order_to_another_account(): void
    {
        $this->workflow(); Setting::putSecret('ovh_consumer_key', 'another-account'); $this->due();
        $this->assertTrue($this->workflow()['manual']);
        $this->assertSame('identity_changed', $this->order()->error_code);
        $this->assertSame(1, $this->checkouts);
    }

    public function test_us_order_resolution_uses_operations_instead_of_extension(): void
    {
        Setting::put('ovh_region', 'us'); $this->workflow(); $this->status = 'delivered'; $this->due(); $this->workflow();
        $this->assertSame('vps-fixture', $this->order()->server_ref);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/extension'));
    }

    public function test_retry_button_cannot_reopen_an_unknown_paid_checkout(): void
    {
        $this->loseCheckout = true; $this->workflow();
        $this->assertFalse($this->order()->canRetry());
        $this->expectException(OvhOrderHold::class);
        app(OvhOrders::class)->adminAction(app(OvhClient::class), $this->order(), 'retry', 'fixture audit reason', 1);
    }

    public function test_private_key_is_encrypted_and_excluded_from_model_serialization(): void
    {
        $this->workflow(); $o = $this->order();
        $this->assertStringContainsString('PRIVATE KEY', $o->private_key);
        $this->assertStringNotContainsString('PRIVATE KEY', $o->getRawOriginal('private_key'));
        $this->assertStringNotContainsString('PRIVATE KEY', $o->toJson());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/checkout') && $r->method() === 'POST'
            && $r['autoPayWithPreferredPaymentMethod'] === false);
    }

    public function test_customer_status_is_read_only_and_contains_no_supplier_data(): void
    {
        $this->workflow();
        $this->actingAs($this->service->customer, 'customer')->get('/account/cloud/'.$this->service->id.'/status')
            ->assertOk()->assertJsonPath('order_state', 'preparing')->assertJsonPath('ready', false)
            ->assertDontSee('OVH')->assertDontSee('order:101')->assertDontSee('cart-fixture');
        $this->assertSame([1, 1], [$this->checkouts, $this->payments]);
    }

    public function test_access_key_download_is_owner_only_single_use_and_never_cached(): void
    {
        $this->workflow(); $o = $this->order(); $o->update(['phase' => 'ready']);
        $other = Customer::create(['email' => 'other-ovh@example.com', 'password' => 'fixture', 'status' => 'active']);
        $path = '/account/cloud/'.$this->service->id.'/download-access-key';
        $this->actingAs($other, 'customer')->post($path)->assertNotFound();
        $response = $this->actingAs($this->service->customer, 'customer')->post($path)->assertOk();
        $response->assertHeader('Content-Type', 'application/octet-stream')->assertSee('PRIVATE KEY');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->post($path)->assertNotFound();
        $this->assertNull($o->fresh()->getRawOriginal('private_key'));
    }

    public function test_admin_dashboard_is_protected_and_does_not_render_private_keys(): void
    {
        $this->workflow();
        $this->actingAs(User::factory()->create(['role' => 'staff']))->get('/admin/cloud/ovh')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin/cloud/ovh?order='.$this->order()->id)
            ->assertOk()->assertSee('101')->assertDontSee('PRIVATE KEY')->assertSee('فقط خواندنی');
    }
    public function test_supplier_tax_customer_vat_and_transfer_fee_are_accounted_separately(): void
    {
        $this->service->invoices()->update(['subtotal' => 2000000, 'tax' => 200000, 'total' => 2200000, 'paid' => 2200000]);
        Setting::put('pricing_fx_fee_pct_ovh', '10');
        $this->workflow(false, true);
        $this->assertSame(1320000, (int) $this->order()->cost_irt);
        $this->assertSame(2000000, (int) $this->order()->revenue_irt);
        $this->assertSame(680000, (int) $this->order()->profit_irt);
    }

    public function test_audited_no_order_refund_is_single_use_and_cannot_retry_purchase(): void
    {
        config(['ovh.purchase_enabled' => false]); $this->workflow();
        $orders = app(OvhOrders::class); $client = app(OvhClient::class);
        $orders->adminAction($client, $this->order(), 'reject_refund', 'Independent fixture evidence of no external order.', 1, null, true);
        $orders->adminAction($client, $this->order(), 'reject_refund', 'Repeated request must never credit twice.', 1, null, true);
        $this->assertSame('refunded', $this->order()->phase);
        $this->assertSame('cancelled', $this->service->fresh()->status);
        $this->assertDatabaseCount('credit_ledger', 1);
        $this->assertSame(2000000, (int) \App\Models\CreditEntry::sum('amount'));
        $this->assertFalse($this->order()->canRetry());
        $this->assertSame(0, $this->checkouts);
    }

    public function test_customer_refund_is_blocked_when_a_supplier_order_or_payment_exists(): void
    {
        $this->workflow();
        $this->expectException(OvhOrderHold::class);
        app(OvhOrders::class)->adminAction(app(OvhClient::class), $this->order(), 'reject_refund', 'Fixture must not refund an existing purchase.', 1, null, true);
    }

    public function test_pending_and_ready_customer_pages_render_in_three_languages(): void
    {
        $p = app(CloudProvisioner::class); $p->provision($this->service);
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->actingAs($this->service->customer, 'customer')->get($prefix.'/account/cloud/'.$this->service->id)
                ->assertOk()->assertDontSee('PRIVATE KEY')->assertDontSee('ovh_order');
        }
        $this->status = 'delivered'; $this->due(); $p->provision($this->service->fresh());
        $this->taskDone = true; $this->due(); $p->provision($this->service->fresh());
        foreach (['', '/en', '/tr'] as $prefix) {
            $this->actingAs($this->service->customer, 'customer')->get($prefix.'/account/cloud/'.$this->service->id)
                ->assertOk()->assertSee('download-access-key')->assertSee('ubuntu')->assertDontSee('PRIVATE KEY');
        }
    }

    public function test_diagnostic_command_does_not_make_a_provider_request_or_alter_financial_state(): void
    {
        $this->artisan('ovh:orders', ['service' => $this->service->id, '--audit-existing' => true])->assertSuccessful();
        Http::assertNothingSent();
        $this->assertDatabaseCount('credit_ledger', 0);
        $this->assertSame('paid', $this->service->invoices()->first()->status);
    }

    public function test_monthly_only_and_image_access_gates_reject_unsupported_sales(): void
    {
        $image = new CloudImage(['kind' => 'os', 'family' => 'windows']);
        $this->assertFalse(CloudImage::providerAccessIsAutomated('ovh', $image));
        $this->service->update(['cycle' => 'annually']);
        $this->assertTrue($this->workflow()['manual']);
        $this->assertSame(0, $this->checkouts);
    }

    public function test_dry_run_on_existing_unpaid_supplier_order_never_pays_it(): void
    {
        $this->loseCheckout = true; $this->workflow(); $this->loseCheckout = false;
        $this->workflow(true);
        $this->due(); $this->workflow(false, true);
        $this->assertSame([1, 0, 0], [$this->checkouts, $this->payments, $this->installs]);
    }

    public function test_supplier_order_amount_is_rechecked_before_payment(): void
    {
        $this->actualGross = 13; $this->workflow();
        $this->assertSame('order_price_changed', $this->order()->error_code);
        $this->assertSame([1, 0], [$this->checkouts, $this->payments]);
    }

    public function test_unknown_install_result_never_rebuilds_again_or_activates_customer(): void
    {
        $this->workflow(); $this->status = 'delivered'; $this->loseInstall = true; $this->due();
        $this->workflow(); $this->workflow(true); $this->workflow(true);
        $this->assertSame('install_unknown', $this->order()->phase);
        $this->assertSame([1, 1, 1], [$this->checkouts, $this->payments, $this->installs]);
        $this->assertSame('awaiting_provision', $this->service->fresh()->status);
    }

    public function test_expired_worker_lease_recovers_persisted_checkout_intent_without_rebuying(): void
    {
        $this->loseCheckout = true; $this->workflow();
        $this->order()->update(['phase' => 'checkout_submitting', 'lease_token' => 'dead-worker', 'locked_until' => now()->subMinute()]);
        $this->loseCheckout = false; $this->workflow(true);
        $this->assertSame(101, (int) $this->order()->order_id);
        $this->assertSame([1, 0], [$this->checkouts, $this->payments]);
    }

    public function test_null_addons_and_retired_catalogue_image_do_not_lose_an_existing_paid_order(): void
    {
        $this->service->update(['cloud_addons' => null]); $this->workflow();
        CloudImage::where('provider', 'ovh')->update(['is_active' => false]);
        $this->status = 'delivered'; $this->due(); $this->workflow();
        $this->assertSame('vps-fixture', $this->order()->server_ref);
        $this->assertSame([1, 1, 1], [$this->checkouts, $this->payments, $this->installs]);
    }

}
