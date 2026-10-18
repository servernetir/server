<?php

namespace Tests\Feature;

use App\Models\{CloudImage, CloudInstance, CloudLocation, CloudPlan, Customer, GiftCoupon, Invoice, Payment, Server, Service, Setting};
use App\Services\Cloud\{CloudFraudGuard, CloudProvisioner};
use App\Services\Customer\{IranPurchaseReadiness, IranSalesGate};
use App\Services\Finance\Wallet;
use App\Services\Payment\PaymentService;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Http, Mail};
use Illuminate\Validation\ValidationException;
use Tests\Support\{NavasanRates, ShahkarFixture};
use Tests\TestCase;

class IranPurchaseReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        NavasanRates::cloudSnapshot();
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
    }

    private function customer(bool $verified = false): Customer
    {
        $customer = Customer::create(['email' => 'purchase@example.test', 'phone' => '09120000000',
            'password' => 'synthetic', 'status' => 'active', 'locale' => 'fa']);
        if ($verified) ShahkarFixture::verify($customer);
        app(Wallet::class)->credit($customer->id, 'IRT', 5000000, 'synthetic', $customer);

        return $customer;
    }

    private function service(Customer $customer, string $country = 'IR', array $attributes = []): Service
    {
        $server = Server::create(['name' => 'Synthetic delivery', 'type' => 'whm', 'country' => $country,
            'hostname' => 'delivery.test', 'username' => 'root', 'api_token' => 'synthetic', 'verify_tls' => false, 'status' => 'active']);

        return Service::create($attributes + ['customer_id' => $customer->id, 'server_id' => $server->id,
            'name' => 'Synthetic service', 'currency_code' => 'IRT', 'price' => 100000,
            'tax_percent' => 0, 'cycle' => 'monthly', 'status' => 'pending', 'provision_status' => 'pending', 'plan' => 'synthetic-plan']);
    }

    private function invoice(Service $service): Invoice
    {
        return Invoice::create(['customer_id' => $service->customer_id, 'service_id' => $service->id,
            'kind' => 'service', 'currency_code' => 'IRT', 'subtotal' => 100000, 'tax' => 0,
            'total' => 100000, 'paid' => 0, 'status' => 'unpaid', 'issued_at' => now()]);
    }

    public function test_every_payment_initiation_rejects_an_existing_unpaid_iran_order_before_any_mutation(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($this->service($customer));
        $coupon = GiftCoupon::create(['customer_id' => $customer->id, 'code' => GiftCoupon::freshCode(),
            'currency_code' => 'IRT', 'amount' => 100000, 'min_invoice' => 0, 'reason' => 'synthetic', 'expires_at' => now()->addDay()]);
        $this->actingAs($customer, 'customer');
        foreach (['pay-credit', 'coupon', 'pay', 'bank-transfer', 'crypto'] as $action) {
            $this->post('/account/invoices/'.$invoice->id.'/'.$action, ['code' => $coupon->code, 'gateway' => 'bale'])
                ->assertSessionHasErrors('iran');
        }
        $this->assertSame(5000000, $customer->creditBalance('IRT'));
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->fresh()->paid);
        $this->assertNull($coupon->fresh()->used_at);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('bank_transfer_receipts', 0);
        $this->assertDatabaseCount('credit_ledger', 1);
        Http::assertNothingSent();
    }

    public function test_payment_service_alternate_callers_are_guarded_before_resolving_a_gateway(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($this->service($customer));
        $outcome = app(PaymentService::class)->begin($invoice, 'unconfigured', Request::create('/synthetic', 'POST'));
        $this->assertFalse($outcome->ok);
        $this->assertSame(IranSalesGate::message(), $outcome->error);
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    public function test_paid_callback_retains_confirmed_funds_then_new_hosting_delivery_is_held_on_each_retry(): void
    {
        $customer = $this->customer(true);
        $service = $this->service($customer);
        $invoice = $this->invoice($service);
        $payment = Payment::create(['invoice_id' => $invoice->id, 'customer_id' => $customer->id,
            'gateway' => 'bale', 'currency_code' => 'IRT', 'amount' => 100000, 'status' => 'redirected', 'external_ref' => 'synthetic-inflight']);
        $customer->identityVerification()->update(['shahkar_matched' => false]);

        $outcome = app(PaymentService::class)->settleConfirmed($payment, 'synthetic-confirmed');
        $this->assertTrue($outcome->ok);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(100000, $invoice->fresh()->paid);
        $this->assertNotNull($service->fresh()->activated_at, 'First payment stamps activation before actual delivery');
        foreach ([1, 2] as $attempt) {
            $this->assertFalse(app(ProvisioningService::class)->provision($service->fresh()));
            $this->assertSame('manual', $service->fresh()->provision_status);
            $this->assertSame('awaiting_provision', $service->fresh()->status);
            $this->assertSame(IranSalesGate::message(), $service->fresh()->provision_error);
            $this->assertNull($service->fresh()->username);
        }
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(5000000, $customer->creditBalance('IRT'));
        Http::assertNothingSent();
        ShahkarFixture::verify($customer);
        $this->assertFalse(app(IranPurchaseReadiness::class)->holdDeliveryIfBlocked($service->fresh()));
    }

    public function test_delivered_iran_service_renewal_can_still_use_credit_without_shahkar(): void
    {
        $customer = $this->customer();
        $service = $this->service($customer, 'IR', ['status' => 'active', 'provision_status' => 'done',
            'provisioned_at' => now()->subMonth(), 'activated_at' => now()->subMonth()]);
        $invoice = $this->invoice($service);
        $this->actingAs($customer, 'customer')->post('/account/invoices/'.$invoice->id.'/pay-credit')->assertSessionHasNoErrors();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(4900000, $customer->creditBalance('IRT'));
        $this->assertSame('done', $service->fresh()->provision_status);
        Http::assertNothingSent();
    }

    public function test_new_foreign_delivery_and_new_iran_delivery_with_shahkar_can_pay(): void
    {
        $customer = $this->customer();
        $foreign = $this->invoice($this->service($customer, 'DE'));
        $this->actingAs($customer, 'customer')->post('/account/invoices/'.$foreign->id.'/pay-credit')->assertSessionHasNoErrors();
        $this->assertSame('paid', $foreign->fresh()->status);
        ShahkarFixture::verify($customer);
        $iran = $this->invoice($this->service($customer));
        $this->post('/account/invoices/'.$iran->id.'/pay-credit')->assertSessionHasNoErrors();
        $this->assertSame('paid', $iran->fresh()->status);
        $this->assertSame(4800000, $customer->creditBalance('IRT'));
        Http::assertNothingSent();
    }

    public function test_legacy_delivered_hosting_with_credentials_and_prior_settled_invoice_keeps_renewal_access(): void
    {
        $customer = $this->customer();
        $service = $this->service($customer, 'IR', ['status' => 'active', 'provision_status' => null,
            'activated_at' => now()->subYear(), 'username' => 'legacyaccount']);
        $past = $this->invoice($service);
        $past->update(['status' => 'paid', 'paid' => $past->total, 'paid_at' => now()->subMonth()]);
        $renewal = $this->invoice($service);
        $this->actingAs($customer, 'customer')->post('/account/invoices/'.$renewal->id.'/pay-credit')->assertSessionHasNoErrors();
        $this->assertSame('paid', $renewal->fresh()->status);
        $this->assertSame(4900000, $customer->creditBalance('IRT'));
        $this->assertSame('active', $service->fresh()->status);
        $this->assertNull($service->fresh()->provision_status);
        $this->assertSame('legacyaccount', $service->fresh()->username);
        Http::assertNothingSent();
    }

    public function test_unknown_destination_of_a_mixed_manual_product_is_not_guessed_to_be_iran(): void
    {
        $customer = $this->customer();
        \App\Models\Product::create(['name' => 'Mixed locations', 'slug' => 'mixed-manual', 'category' => 'dedicated',
            'locations' => ['IR', 'DE'], 'plan' => 'mixed-plan', 'is_active' => true]);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'Manual service',
            'price' => 100000, 'currency_code' => 'IRT', 'cycle' => 'monthly', 'status' => 'pending', 'plan' => 'mixed-plan']);
        $this->assertFalse(app(IranPurchaseReadiness::class)->targetsIran($service));
        app(IranPurchaseReadiness::class)->assertInvoice($this->invoice($service));
        Http::assertNothingSent();
    }

    public function test_legacy_suspended_hosting_renewal_keeps_its_existing_lifecycle_state(): void
    {
        $customer = $this->customer();
        $service = $this->service($customer, 'IR', ['status' => 'suspended', 'provision_status' => null,
            'activated_at' => now()->subYear(), 'username' => 'legacyaccount', 'suspended_at' => now()->subDay()]);
        $past = $this->invoice($service);
        $past->update(['status' => 'paid', 'paid' => $past->total, 'paid_at' => now()->subMonth()]);
        $renewal = $this->invoice($service);
        $this->actingAs($customer, 'customer')->post('/account/invoices/'.$renewal->id.'/pay-credit')->assertSessionHasNoErrors();
        $this->assertSame('paid', $renewal->fresh()->status);
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertNull($service->fresh()->provision_status);
        $this->assertSame(4900000, $customer->creditBalance('IRT'));
        Http::assertNothingSent();
    }

    public function test_wallet_and_coupon_recheck_shahkar_inside_the_locked_payment_attempt(): void
    {
        $customer = $this->customer(true);
        $invoice = $this->invoice($this->service($customer));
        $coupon = GiftCoupon::create(['customer_id' => $customer->id, 'code' => GiftCoupon::freshCode(),
            'currency_code' => 'IRT', 'amount' => 100000, 'min_invoice' => 0, 'reason' => 'synthetic', 'expires_at' => now()->addDay()]);
        foreach (['pay-credit', 'coupon'] as $action) {
            ShahkarFixture::verify($customer);
            $guard = new class($customer) extends IranPurchaseReadiness {
                private int $calls = 0;
                public function __construct(private Customer $buyer) {}
                public function assertInvoice(Invoice $invoice): void
                {
                    if (++$this->calls === 2) $this->buyer->identityVerification()->update(['shahkar_matched' => false]);
                    parent::assertInvoice($invoice);
                }
            };
            app()->instance(IranPurchaseReadiness::class, $guard);
            $this->actingAs($customer, 'customer')->post('/account/invoices/'.$invoice->id.'/'.$action, ['code' => $coupon->code])
                ->assertSessionHasErrors();
            $this->assertSame(5000000, $customer->creditBalance('IRT'));
            $this->assertNull($coupon->fresh()->used_at);
            $this->assertSame(0, $invoice->fresh()->paid);
            $this->assertDatabaseCount('payments', 0);
        }
        Http::assertNothingSent();
    }

    public function test_cloud_country_metadata_and_empty_placeholder_do_not_bypass_payment_or_provider_hold(): void
    {
        $customer = $this->customer();
        Setting::putSecret('hetzner_api_token', 'synthetic-token');
        CloudLocation::create(['code' => 'domestic-alias', 'country' => 'IR', 'city' => 'Tehran', 'is_active' => true]);
        $plan = CloudPlan::create(['provider' => 'hetzner', 'provider_ref' => 'cx22', 'provider_location' => 'synthetic',
            'location_code' => 'domestic-alias', 'public_name' => 'CV-2-4', 'slug' => 'synthetic-iran-plan',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'arch' => 'x86', 'cost_eur_cents' => 300,
            'price_eur_cents' => 570, 'price_irt' => 570000, 'is_active' => true, 'in_stock' => true]);
        CloudImage::create(['provider' => 'hetzner', 'provider_ref' => 'ubuntu24', 'key' => 'ubuntu-24.04',
            'family' => 'ubuntu', 'kind' => 'os', 'arch' => 'x86', 'label' => 'Ubuntu 24.04', 'is_active' => true]);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'Cloud', 'price' => 570000, 'currency_code' => 'IRT',
            'cycle' => 'monthly', 'cloud_plan_id' => $plan->id, 'cloud_image_key' => 'ubuntu-24.04',
            'status' => 'awaiting_provision', 'provision_status' => 'pending', 'activated_at' => now()]);
        $instance = CloudInstance::create(['service_id' => $service->id, 'provider' => 'hetzner', 'location_code' => 'domestic-alias',
            'hostname' => 'synthetic-host', 'status' => 'building']);
        $invoice = $this->invoice($service);
        $this->actingAs($customer, 'customer')->post('/account/invoices/'.$invoice->id.'/pay-credit')->assertSessionHasErrors('iran');
        $this->assertFalse(app(IranPurchaseReadiness::class)->hasExistingDelivery($service));
        $invoice->update(['status' => 'paid', 'paid' => $invoice->total]);
        $this->mock(CloudFraudGuard::class, function ($mock) { $mock->shouldReceive('check')->andReturn(['hold' => false, 'reason' => '']); });
        $this->assertFalse(app(CloudProvisioner::class)->provision($service->fresh()));
        $this->assertSame('manual', $service->fresh()->provision_status);
        $this->assertSame(IranSalesGate::message(), $service->fresh()->provision_error);
        $this->assertNull($instance->fresh()->provider_ref);
        $this->assertSame('paid', $invoice->fresh()->status);
        Http::assertNothingSent();
        $instance->update(['provider_ref' => 'already-owned-resource']);
        $this->assertTrue(app(IranPurchaseReadiness::class)->hasExistingDelivery($service->fresh()));
    }
}
