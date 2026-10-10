<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ServiceController;
use App\Models\ActivityLog;
use App\Models\CloudInstance;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Billing\ComplimentaryServices;
use App\Services\Cloud\CloudProvisioner;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ComplimentaryServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 10)->startOfDay());
        Http::fake();
        Mail::fake();
    }

    private function owner(): Customer
    {
        $c = Customer::create(['email' => 'owner@example.test', 'password' => 'x', 'status' => 'active']);
        config(['billing.complimentary_services' => ['customer_id' => $c->id, 'customer_code' => $c->code]]);

        return $c;
    }

    private function service(Customer $c, array $overrides = []): Service
    {
        return Service::create(array_merge([
            'customer_id' => $c->id, 'name' => 'Owner server', 'price' => 50000, 'tax_percent' => 10,
            'currency_code' => 'IRT', 'cycle' => 'monthly', 'billing_mode' => 'cycle', 'status' => 'active',
            'next_due_at' => '2026-08-08', 'provision_status' => 'done',
        ], $overrides));
    }

    public function test_both_customer_identifiers_must_match(): void
    {
        $c = $this->owner();
        $policy = app(ComplimentaryServices::class);
        $this->assertTrue($policy->customerExempt($c));
        config(['billing.complimentary_services.customer_code' => 'another-account']);
        $this->assertFalse($policy->customerExempt($c));
        config(['billing.complimentary_services' => []]);
        $this->assertFalse($policy->customerExempt($c));
    }

    public function test_overdue_owner_renews_without_invoice_or_payment_and_repeat_is_idempotent(): void
    {
        $s = $this->service($this->owner());
        $this->artisan('services:renew-due')->assertSuccessful();
        $this->assertSame('2026-11-08', $s->fresh()->next_due_at->toDateString());
        $this->assertSame(0, $s->fresh()->price);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('credit_ledger', 0);
        $this->artisan('services:renew-due')->assertSuccessful();
        $this->assertSame('2026-11-08', $s->fresh()->next_due_at->toDateString());
        $this->assertSame(1, ActivityLog::where('service_id', $s->id)->where('action', 'renew')->count());
    }

    public function test_lifecycle_cannot_suspend_owner_with_old_unpaid_invoice(): void
    {
        $s = $this->service($this->owner());
        Invoice::create(['customer_id' => $s->customer_id, 'service_id' => $s->id, 'kind' => 'service',
            'currency_code' => 'IRT', 'subtotal' => 50000, 'tax' => 0, 'total' => 50000, 'paid' => 0, 'status' => 'unpaid']);
        $this->mock(ProvisioningService::class, fn ($m) => $m->shouldNotReceive('suspend'));
        $this->artisan('services:lifecycle')->assertSuccessful();
        $this->assertSame('active', $s->fresh()->status);
        $this->assertNull($s->fresh()->suspended_at);
        $this->assertSame('2026-11-08', $s->fresh()->next_due_at->toDateString());
    }

    public function test_dry_lifecycle_preserves_owner_dates_prices_and_activity(): void
    {
        $s = $this->service($this->owner());
        $this->artisan('services:lifecycle --dry')->assertSuccessful();
        $this->assertSame(50000, $s->fresh()->price);
        $this->assertSame('2026-08-08', $s->fresh()->next_due_at->toDateString());
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_other_customer_keeps_normal_paid_renewal(): void
    {
        $this->owner();
        $other = Customer::create(['email' => 'other@example.test', 'password' => 'x', 'status' => 'active']);
        $s = $this->service($other);
        $this->artisan('services:renew-due')->assertSuccessful();
        $this->assertDatabaseHas('invoices', ['service_id' => $s->id, 'status' => 'unpaid', 'total' => 55000]);
        $this->assertSame(50000, $s->fresh()->price);
    }

    public function test_closed_services_are_never_reactivated_or_renewed(): void
    {
        $c = $this->owner();
        foreach (['terminated', 'cancelled', 'suspended'] as $status) {
            $s = $this->service($c, ['status' => $status]);
            $this->assertFalse(app(ComplimentaryServices::class)->renew($s));
            $this->assertSame($status, $s->fresh()->status);
            $this->assertSame(50000, $s->fresh()->price);
        }
    }

    public function test_manual_owner_renewal_invoice_has_zero_total_and_no_payment(): void
    {
        $s = $this->service($this->owner(), ['plan' => 'hetzner-storage-10tb']);
        $i = app(ServiceController::class)->issueInvoice($s);
        $this->assertSame(0, $i->total);
        $this->assertSame(0, $i->paid);
        $this->assertSame('paid', $i->status);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_owner_hourly_meter_never_charges_stops_or_deletes_on_empty_wallet(): void
    {
        $c = $this->owner();
        $s = $this->service($c, ['billing_mode' => 'hourly', 'hourly_rate_irt' => 5000,
            'last_metered_at' => now()->subHours(40), 'on_credit_out' => 'terminate']);
        $old = $this->service($c, ['billing_mode' => 'hourly', 'hourly_rate_irt' => 5000,
            'status' => 'suspended', 'suspended_at' => now()->subHours(48)]);
        foreach ([$s, $old] as $svc) {
            CloudInstance::create(['service_id' => $svc->id, 'provider' => 'aeza', 'provider_ref' => 'srv-'.$svc->id,
                'location_code' => 'de', 'image_key' => 'ubuntu', 'hostname' => 'owner-'.$svc->id,
                'ipv4' => '10.0.0.1', 'status' => 'running', 'ready_notified_at' => now()->subDays(2)]);
        }
        $this->mock(CloudProvisioner::class, function ($m) {
            $m->shouldNotReceive('suspend');
            $m->shouldNotReceive('unsuspend');
            $m->shouldNotReceive('terminate');
        });
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame('active', $s->fresh()->status);
        $this->assertSame('suspended', $old->fresh()->status);
        $this->assertSame(0, CreditEntry::where('customer_id', $c->id)->count());
        $this->assertTrue($s->fresh()->last_metered_at->equalTo(now()));
    }

    public function test_owner_repricing_is_skipped(): void
    {
        $s = $this->service($this->owner(), ['billing_mode' => 'hourly', 'hourly_rate_irt' => 1]);
        $this->artisan('cloud:hourly-reprice --apply')->assertSuccessful();
        $this->assertSame(1, $s->fresh()->hourly_rate_irt);
    }

    public function test_month_end_and_missing_due_date_are_supported(): void
    {
        $c = $this->owner();
        $s = $this->service($c, ['next_due_at' => '2026-08-31']);
        app(ComplimentaryServices::class)->renew($s);
        $this->assertSame('2026-10-30', $s->fresh()->next_due_at->toDateString());
        $missing = $this->service($c, ['next_due_at' => null]);
        app(ComplimentaryServices::class)->renew($missing);
        $this->assertSame('2026-11-10', $missing->fresh()->next_due_at->toDateString());
    }
}
