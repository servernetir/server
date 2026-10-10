<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ServiceController;
use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\BillingRunMonitor;
use App\Services\Billing\OutstandingPayments;
use App\Services\Billing\RenewalTerms;
use App\Services\Calendar\Providers\PaymentDueProvider;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\CloudPricing;
use App\Services\Cloud\CloudProvider;
use App\Services\Cloud\CloudProvisioner;
use App\Services\Cloud\HourlyHold;
use App\Services\Domain\DomainRenewalInvoicer;
use App\Services\Payment\PaymentService;
use App\Services\Provisioning\ProvisioningService;
use App\Services\Provisioning\ProvisionResult;
use App\Services\Provisioning\WhmProvisioner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\TestCase;

class BillingRenewalSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 12:00:00');
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        HourlyHold::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        HourlyHold::flush();
        parent::tearDown();
    }

    private function service(array $attrs = []): Service
    {
        $customer = Customer::create([
            'email' => uniqid('billing').'@example.test', 'password' => 'x',
            'status' => 'active', 'locale' => 'fa',
        ]);

        $service = new Service($attrs + [
            'customer_id' => $customer->id, 'name' => 'Renewable service',
            'price' => 100000, 'tax_percent' => 0, 'currency_code' => 'IRT',
            'cycle' => 'monthly', 'billing_mode' => 'cycle', 'status' => 'active',
            'provision_status' => 'done', 'activated_at' => '2026-08-09', 'next_due_at' => '2026-10-09',
        ]);
        if (isset($attrs['id'])) {
            $service->id = $attrs['id'];
        }
        $service->save();

        return $service;
    }

    private function invoice(Service $service, array $attrs = []): Invoice
    {
        return Invoice::create($attrs + [
            'customer_id' => $service->customer_id, 'service_id' => $service->id,
            'kind' => 'service', 'currency_code' => 'IRT', 'subtotal' => 100000,
            'tax' => 0, 'total' => 100000, 'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
        ]);
    }

    private function provisioner(): MockInterface
    {
        $mock = \Mockery::mock(ProvisioningService::class);
        $this->app->instance(ProvisioningService::class, $mock);

        return $mock;
    }

    private function hourly(array $attrs = []): Service
    {
        $service = $this->service($attrs + [
            'billing_mode' => 'hourly', 'hourly_rate_irt' => 1000,
            'last_metered_at' => now()->subHour(), 'on_credit_out' => 'suspend',
        ]);
        CloudInstance::create([
            'service_id' => $service->id, 'provider' => 'aeza', 'provider_ref' => 'test-'.$service->id,
            'location_code' => 'de', 'image_key' => 'ubuntu-24.04', 'hostname' => 'test',
            'ipv4' => '192.0.2.10', 'status' => 'running', 'ready_notified_at' => now()->subDay(),
        ]);

        return $service;
    }

    private function topup(Service $service, int $amount): void
    {
        CreditEntry::create([
            'customer_id' => $service->customer_id, 'currency_code' => 'IRT', 'amount' => $amount,
            'balance_after' => $amount, 'reason' => 'topup', 'source_type' => Customer::class,
            'source_id' => $service->customer_id, 'note' => 'test',
        ]);
    }

    public function test_internal_service_is_excluded_from_all_billing_jobs_and_provider_actions(): void
    {
        $service = $this->service(['id' => 20]);
        $this->artisan('services:renew-due')->assertSuccessful();
        $this->artisan('services:lifecycle')->assertSuccessful();
        $this->assertSame(0, $service->invoices()->count());
        $this->assertSame('active', $service->fresh()->status);
        $this->assertFalse(app(ProvisioningService::class)->suspend($service)->ok);
        $this->assertFalse(app(ProvisioningService::class)->terminate($service)->ok);
        $this->assertFalse(app(CloudProvisioner::class)->suspend($service));
        $this->assertFalse(app(CloudProvisioner::class)->terminate($service));
        $service->update(['billing_mode' => 'hourly', 'hourly_rate_irt' => 1000, 'last_metered_at' => now()->subDays(2)]);
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame('active', $service->fresh()->status);
        $this->assertNull($service->fresh()->cancelled_at);
        Http::assertNothingSent();
    }

    public function test_failed_suspension_is_retried_without_false_success_or_duplicate_invoice(): void
    {
        $service = $this->service();
        $mock = $this->provisioner();
        $mock->shouldReceive('suspend')->once()->andReturn(ProvisionResult::fail('temporary outage'));
        $this->artisan('services:lifecycle')->assertFailed();
        $this->assertSame('active', $service->fresh()->status);
        $this->assertNull($service->fresh()->suspended_at);
        $this->assertSame(1, $service->invoices()->count());
        $this->assertSame(1, BillingRunMonitor::read('services:lifecycle')['errors']);
        $this->artisan('services:lifecycle')->assertSuccessful(); // Retry backoff, no second API call.
        $this->travel(16)->minutes();
        $mock = $this->provisioner();
        $mock->shouldReceive('suspend')->once()->andReturn(ProvisionResult::success(null, null, null));
        $this->artisan('services:lifecycle')->assertSuccessful();
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertNotNull($service->fresh()->suspended_at);
        $this->assertNotEmpty($service->fresh()->provision_meta['billing_suspend']['confirmed_at']);
        $this->assertSame(1, $service->invoices()->count());
    }

    public function test_failed_restoration_keeps_the_service_suspended(): void
    {
        $service = $this->service(['status' => 'suspended', 'suspended_at' => now()->subDay(), 'next_due_at' => '2026-11-09']);
        $this->provisioner()->shouldReceive('unsuspend')->once()->andReturn(ProvisionResult::fail('offline'));
        $this->artisan('services:lifecycle')->assertFailed();
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertNotNull($service->fresh()->suspended_at);
    }

    public function test_manual_suspension_is_pending_and_stays_in_the_work_queue(): void
    {
        $service = $this->service();
        $this->artisan('services:lifecycle')->assertSuccessful();
        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame('suspend', $service->fresh()->pendingManualAction()['kind']);
        $first = $service->fresh()->provision_meta;
        $this->artisan('services:lifecycle')->assertSuccessful();
        $this->assertEquals($first, $service->fresh()->provision_meta);
        $service->update(['status' => 'cancelled']);
        $this->assertTrue(Service::awaitingManualAction()->whereKey($service->id)->exists());
    }

    public function test_renewal_invoice_uses_tehran_service_deadline_while_initial_order_keeps_hold(): void
    {
        $service = $this->service(['next_due_at' => '2026-10-15']);
        $invoice = RenewalTerms::ensureInvoice($service);
        $this->assertSame('2026-10-15 20:29:59', $invoice->due_at->format('Y-m-d H:i:s'));
        $this->assertSame($invoice->id, RenewalTerms::ensureInvoice($service)->id);
        $new = $this->service(['status' => 'pending', 'next_due_at' => null]);
        $initial = app(ServiceController::class)->issueInvoice($new);
        $this->assertTrue($initial->due_at->equalTo(now()->addHours(48)));
    }

    public function test_due_day_is_not_cut_short_at_utc_midnight(): void
    {
        $service = $this->service(['next_due_at' => '2026-10-10']);
        $mock = $this->provisioner();
        $mock->shouldNotReceive('suspend');
        Carbon::setTestNow('2026-10-10 20:29:00');
        $this->artisan('services:lifecycle')->assertSuccessful();
        $this->assertSame('active', $service->fresh()->status);
        Carbon::setTestNow('2026-10-10 20:31:00');
        $this->provisioner()->shouldReceive('suspend')->once()->andReturn(ProvisionResult::success(null, null, null));
        $this->artisan('services:lifecycle')->assertSuccessful();
        $this->assertSame('suspended', $service->fresh()->status);
    }

    public function test_partial_invoice_blocks_duplicate_renewal(): void
    {
        $service = $this->service();
        $invoice = $this->invoice($service, ['status' => 'partial', 'paid' => 40000]);
        $this->artisan('services:renew-due')->assertSuccessful();
        $this->assertSame($invoice->id, RenewalTerms::openInvoice($service)->id);
        $this->assertSame(1, $service->invoices()->count());
    }

    public function test_invoice_failure_does_not_skip_other_services(): void
    {
        $bad = $this->service();
        $good = $this->service();
        $controller = \Mockery::mock(ServiceController::class)->makePartial();
        $controller->shouldReceive('issueInvoice')->withArgs(fn ($s) => $s->id === $bad->id)->andThrow(new \RuntimeException('test'));
        $this->app->instance(ServiceController::class, $controller);
        $this->artisan('services:renew-due')->assertFailed();
        $this->assertSame(1, $good->invoices()->count());
        $this->assertSame(0, $bad->invoices()->count());
        $this->assertSame(1, BillingRunMonitor::read('services:renew-due')['errors']);
    }

    public function test_missing_service_date_is_visible_as_a_job_error(): void
    {
        $this->service(['next_due_at' => null]);
        $this->artisan('services:lifecycle')->assertFailed();
        $this->assertSame(1, BillingRunMonitor::read('services:lifecycle')['errors']);
    }

    public function test_calendar_respects_local_month_boundary_and_partial_status(): void
    {
        $service = $this->service();
        $inside = $this->invoice($service, ['due_at' => '2026-09-30 20:30:00', 'status' => 'partial', 'paid' => 1]);
        $this->invoice($service, ['due_at' => '2026-09-30 20:29:59']);
        $this->invoice($service, ['due_at' => '2026-10-31 20:30:00']);
        $this->invoice($service, ['due_at' => '2026-10-10 10:00:00', 'status' => 'paid', 'paid' => 100000]);
        $items = app(PaymentDueProvider::class)->getEvents(
            Carbon::parse('2026-10-01', 'Asia/Tehran'), Carbon::parse('2026-10-31', 'Asia/Tehran')->endOfDay());
        $this->assertSame([$inside->id], $items->pluck('sourceId')->all());
    }

    public function test_missing_and_overdue_invoice_dates_remain_in_backlog(): void
    {
        $service = $this->service();
        $missing = $this->invoice($service);
        $missing->update(['due_at' => null]);
        $late = $this->invoice($service, ['due_at' => '2026-01-01']);
        $this->invoice($service, ['due_at' => '2026-11-01']);
        $summary = app(OutstandingPayments::class)->summary();
        $this->assertSame(2, $summary['count']);
        $this->assertSame(1, $summary['missing_due']);
        $this->assertEqualsCanonicalizing([$missing->id, $late->id], $summary['rows']->pluck('id')->all());
    }

    public function test_repair_is_preview_first_and_preserves_explicit_deadlines_paid_and_internal_invoices(): void
    {
        $service = $this->service();
        $legacy = $this->invoice($service);
        $missing = $this->invoice($service);
        $missing->update(['due_at' => null]);
        $manual = $this->invoice($service, ['due_at' => '2026-11-20']);
        $paid = $this->invoice($service, ['paid' => 100000, 'status' => 'paid']);
        $internal = $this->invoice($this->service(['id' => 20]));
        $this->artisan('billing:repair-dates')->assertSuccessful();
        $this->assertNull($missing->fresh()->due_at);
        $this->assertTrue($legacy->fresh()->due_at->equalTo(now()->addHours(48)));
        $this->artisan('billing:repair-dates --apply')->assertSuccessful();
        $this->assertSame('2026-10-09 20:29:59', $legacy->fresh()->due_at->format('Y-m-d H:i:s'));
        $this->assertEquals($legacy->fresh()->due_at, $missing->fresh()->due_at);
        $this->assertSame('2026-11-20', $manual->fresh()->due_at->toDateString());
        $this->assertEquals($paid->due_at, $paid->fresh()->due_at);
        $this->assertEquals($internal->due_at, $internal->fresh()->due_at);
        $this->artisan('billing:repair-dates --apply')->assertSuccessful();
        $this->assertSame(5, Invoice::count());
    }

    public function test_hourly_failed_shutdown_is_retried_and_retention_starts_only_when_confirmed(): void
    {
        $service = $this->hourly();
        $prov = \Mockery::mock(CloudProvisioner::class);
        $prov->shouldReceive('suspend')->once()->andReturnFalse();
        $prov->shouldReceive('recordSuspendFailure')->once();
        $this->app->instance(CloudProvisioner::class, $prov);
        $this->artisan('cloud:meter')->assertFailed();
        $this->assertNull($service->fresh()->suspended_at);
        $this->assertSame('suspended', $service->fresh()->status);
        $this->travel(16)->minutes();
        $prov = \Mockery::mock(CloudProvisioner::class);
        $prov->shouldReceive('suspend')->once()->andReturnTrue();
        $this->app->instance(CloudProvisioner::class, $prov);
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertTrue($service->fresh()->suspended_at->equalTo(now()));
        $this->assertSame(0, CreditEntry::count());
    }

    public function test_failed_hourly_resume_neither_charges_nor_deletes_a_funded_service(): void
    {
        $service = $this->hourly(['status' => 'suspended', 'suspended_at' => now()->subHours(30),
            'provision_meta' => ['hourly_suspend' => ['confirmed_at' => now()->subHours(30)->toIso8601String()]]]);
        $this->topup($service, 10000);
        $prov = \Mockery::mock(CloudProvisioner::class);
        $prov->shouldReceive('unsuspend')->once()->andReturnFalse();
        $prov->shouldNotReceive('terminate');
        $this->app->instance(CloudProvisioner::class, $prov);
        $this->artisan('cloud:meter')->assertFailed();
        $this->assertSame(10000, $service->customer->creditBalance('IRT'));
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertNull($service->fresh()->cancelled_at);
    }

    public function test_successful_hourly_resume_charges_one_hour_once(): void
    {
        $service = $this->hourly(['status' => 'suspended', 'suspended_at' => now()->subHour(),
            'provision_meta' => ['hourly_suspend' => ['confirmed_at' => now()->subHour()->toIso8601String()]]]);
        $this->topup($service, 10000);
        $prov = \Mockery::mock(CloudProvisioner::class);
        $prov->shouldReceive('unsuspend')->once()->andReturnTrue();
        $this->app->instance(CloudProvisioner::class, $prov);
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame(9000, $service->customer->creditBalance('IRT'));
        $this->assertSame('active', $service->fresh()->status);
        $this->assertArrayNotHasKey('hourly_suspend', $service->fresh()->provision_meta);
    }

    public function test_due_processing_is_scheduled_frequently(): void
    {
        $events = collect(app(Schedule::class)->events());
        $lifecycle = $events->first(fn ($e) => str_contains((string) $e->command, 'services:lifecycle'));
        $renewals = $events->first(fn ($e) => str_contains((string) $e->command, 'services:renew-due'));
        $meter = $events->first(fn ($e) => str_contains((string) $e->command, 'cloud:meter'));
        $this->assertSame('*/5 * * * *', $lifecycle->expression);
        $this->assertSame('*/15 * * * *', $renewals->expression);
        $this->assertSame('*/5 * * * *', $meter->expression);
    }

    public function test_delayed_hourly_resume_is_reconciled_at_next_tick_without_repeat_charge(): void
    {
        $service = $this->hourly(['status' => 'suspended', 'suspended_at' => now()->subHour(),
            'provision_meta' => ['hourly_suspend' => ['confirmed_at' => now()->subHour()->toIso8601String()]]]);
        $this->topup($service, 10000);
        $prov = \Mockery::mock(CloudProvisioner::class);
        $prov->shouldReceive('unsuspend')->twice()->andReturn(false, true);
        $prov->shouldNotReceive('terminate', 'suspend');
        $this->app->instance(CloudProvisioner::class, $prov);
        $this->artisan('cloud:meter')->assertFailed();
        $this->assertSame(10000, $service->customer->creditBalance('IRT'));
        $this->travel(5)->minutes();
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame(9000, $service->customer->creditBalance('IRT'));
        $this->travel(5)->minutes();
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame(9000, $service->customer->creditBalance('IRT'));
        $this->assertSame(1, CreditEntry::where('reason', 'cloud_hourly')->count());
    }

    public function test_domain_renewal_deadline_is_the_expiry_not_two_days_after_issue(): void
    {
        $service = $this->service();
        $domain = Domain::create([
            'customer_id' => $service->customer_id, 'domain' => 'renew.example', 'sld' => 'renew',
            'tld' => 'com', 'status' => 'active', 'period_years' => 1,
            'price_toman' => 100000, 'renew_toman' => 100000, 'expires_at' => now()->addDays(21),
        ]);
        $invoice = app(DomainRenewalInvoicer::class)->issue($domain);
        $this->assertEquals($domain->expires_at, $invoice->due_at);
        $this->travel(22)->days();
        $this->artisan('invoices:expire')->assertSuccessful();
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    public function test_provider_acceptance_does_not_fake_power_state_and_retry_observes_completion(): void
    {
        $service = $this->hourly();
        $driver = \Mockery::mock(CloudProvider::class);
        $driver->shouldReceive('serverStatus')->times(3)->andReturn(
            ['ok' => true, 'status' => 'running'], ['ok' => true, 'status' => 'running'], ['ok' => true, 'status' => 'off']);
        $driver->shouldReceive('power')->once()->andReturn(['ok' => true]);
        $manager = \Mockery::mock(CloudManager::class);
        $manager->shouldReceive('forInstance')->twice()->andReturn($driver);
        $this->app->instance(CloudManager::class, $manager);
        $provisioner = app(CloudProvisioner::class);
        $this->assertFalse($provisioner->suspend($service));
        $this->assertSame('running', $service->cloudInstance->fresh()->status);
        $this->assertTrue($provisioner->suspend($service));
        $this->assertSame('off', $service->cloudInstance->fresh()->status);
    }

    public function test_manual_acknowledgement_records_real_suspension_and_resume_clears_markers(): void
    {
        $service = $this->service();
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'role' => 'admin']);
        $this->artisan('services:lifecycle')->assertSuccessful();
        $this->actingAs($admin)->post('/admin/services/'.$service->id.'/ack-manual')->assertRedirect();
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertNotEmpty($service->fresh()->provision_meta['billing_suspend']['confirmed_at']);
        app(ProvisioningService::class)->unsuspend($service->fresh());
        $this->actingAs($admin)->post('/admin/services/'.$service->id.'/ack-manual')->assertRedirect();
        $this->assertSame('active', $service->fresh()->status);
        $this->assertNull($service->fresh()->suspended_at);
        $this->assertNull($service->fresh()->pendingManualAction());
    }

    public function test_payment_keeps_suspension_until_verified_and_does_not_bill_the_suspended_gap(): void
    {
        $service = $this->service(['next_due_at' => '2026-05-09', 'status' => 'suspended', 'suspended_at' => '2026-05-10',
            'provision_meta' => ['billing_suspend' => ['confirmed_at' => '2026-05-10T00:00:00Z']]]);
        $invoice = $this->invoice($service);
        $payment = Payment::create([
            'invoice_id' => $invoice->id, 'customer_id' => $service->customer_id, 'gateway' => 'bale',
            'currency_code' => 'IRT', 'amount' => 100000, 'status' => 'redirected', 'external_ref' => 'test',
        ]);
        app(PaymentService::class)->settleConfirmed($payment, 'test-paid');
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertSame('2026-11-09', $service->fresh()->next_due_at->toDateString());
        $this->assertSame('paid', $invoice->fresh()->status);
        app(PaymentService::class)->settleConfirmed($payment, 'test-paid');
        $this->assertSame('2026-11-09', $service->fresh()->next_due_at->toDateString());
    }

    public function test_existing_hourly_hold_reserve_is_charged_once_without_spending_other_credit(): void
    {
        $this->assertTrue(Schema::hasColumns('services', ['hold_rate_irt', 'hold_reserve_irt']));
        HourlyHold::flush();
        $service = $this->hourly(['status' => 'suspended', 'suspended_at' => now()->subHours(2),
            'last_metered_at' => now()->subHours(2), 'hold_rate_irt' => 100, 'hold_reserve_irt' => 2400,
            'provision_meta' => ['hourly_suspend' => ['confirmed_at' => now()->subHours(2)->toIso8601String()]]]);
        $this->topup($service, 3000);
        $plan = CloudPlan::create(['provider' => 'aeza', 'provider_ref' => 'hold-test',
            'location_code' => 'de', 'public_name' => 'Test', 'slug' => 'hold-test', 'cost_hour_eur_micro' => 1000]);
        $service->update(['cloud_plan_id' => $plan->id]);
        $pricing = \Mockery::mock(CloudPricing::class);
        $pricing->shouldReceive('eurToToman')->andReturn(100000);
        $this->app->instance(CloudPricing::class, $pricing);
        $prov = \Mockery::mock(CloudProvisioner::class);
        $prov->shouldNotReceive('unsuspend', 'terminate');
        $this->app->instance(CloudProvisioner::class, $prov);
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertSame(2800, $service->customer->creditBalance('IRT'));
        $this->assertSame(2200, (int) $service->fresh()->hold_reserve_irt);
        $this->assertSame('suspended', $service->fresh()->status);
    }

    public function test_zero_total_open_invoice_blocks_repeated_renewal_generation(): void
    {
        $service = $this->service(['price' => 0, 'next_due_at' => '2026-10-11']);
        $first = RenewalTerms::ensureInvoice($service);
        $this->assertSame(0, $first->total);
        foreach (range(1, 3) as $run) {
            $this->artisan('services:renew-due')->assertSuccessful();
            $this->artisan('services:lifecycle')->assertSuccessful();
        }
        $this->assertSame($first->id, RenewalTerms::openInvoice($service)->id);
        $this->assertSame(1, $service->invoices()->count());
        $first->update(['status' => 'paid']);
        $this->assertNull(RenewalTerms::openInvoice($service));
    }

    public function test_paid_manual_renewal_is_completed_before_restoration_is_queued(): void
    {
        $service = $this->service(['domain' => 'licence.example.test', 'status' => 'suspended',
            'suspended_at' => now()->subDay(),
            'provision_meta' => ['billing_suspend' => ['confirmed_at' => now()->subDay()->toIso8601String()]]]);
        $invoice = $this->invoice($service);
        $payment = Payment::create(['invoice_id' => $invoice->id, 'customer_id' => $service->customer_id,
            'gateway' => 'bale', 'currency_code' => 'IRT', 'amount' => 100000, 'status' => 'redirected']);
        app(PaymentService::class)->settleConfirmed($payment, 'manual-renewal-paid');
        $this->assertSame('renew', $service->fresh()->pendingManualAction()['kind']);
        $this->artisan('services:lifecycle')->assertSuccessful();
        $this->assertSame('renew', $service->fresh()->pendingManualAction()['kind']);
        $this->assertSame('suspended', $service->fresh()->status);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/services/'.$service->id.'/ack-manual')->assertRedirect();
        $this->artisan('services:lifecycle')->assertSuccessful();
        $this->assertSame('unsuspend', $service->fresh()->pendingManualAction()['kind']);
        $this->actingAs($admin)->post('/admin/services/'.$service->id.'/ack-manual')->assertRedirect();
        $this->assertSame('active', $service->fresh()->status);
    }

    public function test_staff_manual_suspension_is_not_automatically_restored_by_billing(): void
    {
        $service = $this->service(['next_due_at' => '2026-11-10']);
        app(ProvisioningService::class)->suspend($service);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post('/admin/services/'.$service->id.'/ack-manual')->assertRedirect();
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertEmpty($service->fresh()->provision_meta['billing_suspend'] ?? null);
        $this->artisan('services:lifecycle')->assertSuccessful();
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertNull($service->fresh()->pendingManualAction());
        Http::assertNothingSent();
    }

    public function test_staff_suspension_survives_a_later_unpaid_due_date(): void
    {
        $service = $this->service(['next_due_at' => '2026-10-11']);
        app(ProvisioningService::class)->suspend($service);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/services/'.$service->id.'/ack-manual')->assertRedirect();
        $this->travel(3)->days();
        $this->artisan('services:renew-due')->assertSuccessful();
        $this->artisan('services:lifecycle')->assertSuccessful();
        $this->assertSame('suspended', $service->fresh()->status);
        $this->assertNotEmpty($service->fresh()->provision_meta['staff_suspend']);
        $this->assertEmpty($service->fresh()->provision_meta['billing_suspend'] ?? null);
        app(ProvisioningService::class)->unsuspend($service->fresh());
        $this->actingAs($admin)->post('/admin/services/'.$service->id.'/ack-manual')->assertRedirect();
        $this->assertSame('active', $service->fresh()->status);
        $this->assertEmpty($service->fresh()->provision_meta['staff_suspend'] ?? null);
    }

    public function test_internal_service_cannot_be_refunded_through_cancellation(): void
    {
        $service = $this->service(['id' => 20]);
        $this->invoice($service, ['status' => 'paid', 'paid' => 100000]);
        $this->provisioner()->shouldNotReceive('terminate');
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post('/admin/services/20/cancel-refund', ['amount' => 100000])->assertSessionHasErrors();
        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame(0, CreditEntry::where('reason', 'refund')->count());
        $this->assertNull($service->fresh()->cancelled_at);
        Http::assertNothingSent();
    }

    public function test_status_selector_records_success_and_queued_manual_action_with_staff_identity(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Billing Operator']);
        foreach ([ProvisionResult::success(null, null, null), ProvisionResult::manual('Waiting for supplier')] as $result) {
            $service = $this->service();
            $this->provisioner()->shouldReceive('suspend')->once()->andReturn($result);
            $this->actingAs($admin)->post('/admin/services/'.$service->id.'/status', ['status' => 'suspended'])
                ->assertRedirect();
            $log = \App\Models\ActivityLog::where('service_id', $service->id)->where('action', 'suspend')->firstOrFail();
            $this->assertSame('staff', $log->actor);
            $this->assertStringContainsString('Billing Operator', $log->description);
        }
    }

    public function test_financial_only_renewal_does_not_create_supplier_work(): void
    {
        $service = $this->service(['domain' => null, 'server_id' => null, 'server_ip' => null]);
        $invoice = $this->invoice($service);
        $payment = Payment::create(['invoice_id' => $invoice->id, 'customer_id' => $service->customer_id,
            'gateway' => 'bale', 'currency_code' => 'IRT', 'amount' => 100000, 'status' => 'redirected']);
        app(PaymentService::class)->settleConfirmed($payment, 'support-renewal-paid');
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertNull($service->fresh()->pendingManualAction());
        $this->assertSame('2026-11-09', $service->fresh()->next_due_at->toDateString());
    }

    public function test_real_hourly_hold_migration_preserves_existing_reserves(): void
    {
        $this->assertTrue(HourlyHold::enabled());
        $service = $this->hourly(['hold_rate_irt' => 100, 'hold_reserve_irt' => 2400]);
        $migration = require database_path('migrations/2026_11_03_000100_add_hourly_hold_to_services.php');
        $migration->up();
        $this->assertSame(2400, (int) $service->fresh()->hold_reserve_irt);
        $this->assertSame(100, (int) $service->fresh()->hold_rate_irt);
        $this->assertSame(2400, HourlyHold::heldOf($service->customer_id));
    }

    public function test_rclone_driver_registration_and_pricing_configuration_are_complete(): void
    {
        $server = Server::create(['name' => 'Backup Gateway', 'type' => 'rclone_storage',
            'hostname' => 'backup.example.test', 'api_token' => 'test', 'monthly_cost' => 1000000, 'cost_currency' => 'IRT']);
        $this->assertTrue($server->isAutoProvisioned());
        $this->assertContains('rclone_storage', Server::TYPES);
        $this->assertInstanceOf(\App\Services\Provisioning\RcloneStorageProvisioner::class,
            app(ProvisioningService::class)->driverFor($server));
        $this->assertTrue(class_exists(\App\Services\Provisioning\RcloneStorageClient::class));
        config(['provisioning.rclone_storage.capacity_bytes' => 125 * 1024 ** 3]);
        $service = $this->service(['server_id' => $server->id, 'plan' => 'sn_backup_1']);
        $this->assertSame(1200000, app(\App\Services\Provisioning\RcloneStorageCosts::class)->floorForService($service, 1));
        Http::assertNothingSent();
    }

    public function test_already_suspended_whm_account_is_reconciled_without_repeating_the_action(): void
    {
        $server = Server::create(['name' => 'test', 'type' => 'whm', 'hostname' => 'whm.example.test',
            'username' => 'root', 'api_token' => 'test', 'is_active' => true]);
        $service = $this->service(['server_id' => $server->id, 'username' => 'customer1']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*accountsummary*' => Http::response([
            'metadata' => ['result' => 1], 'data' => ['acct' => [['user' => 'customer1', 'suspended' => 1]]],
        ])]);
        $result = app(WhmProvisioner::class)->suspend($service);
        $this->assertTrue($result->ok);
        $this->assertTrue($result->meta['already_in_requested_state']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'suspendacct'));
    }

    public function test_hourly_error_remains_visible_when_a_later_customer_has_no_credit(): void
    {
        $first = $this->hourly();
        $second = $this->hourly();
        $prov = \Mockery::mock(CloudProvisioner::class);
        $prov->shouldReceive('suspend')->withArgs(fn ($s) => $s->id === $first->id)->once()->andReturnFalse();
        $prov->shouldReceive('recordSuspendFailure')->once();
        $prov->shouldReceive('suspend')->withArgs(fn ($s) => $s->id === $second->id)->once()->andReturnTrue();
        $this->app->instance(CloudProvisioner::class, $prov);
        $this->artisan('cloud:meter')->assertFailed();
        $this->assertSame(1, BillingRunMonitor::read('cloud:meter')['errors']);
        $this->assertNull(BillingRunMonitor::read('cloud:meter')['last_success_at']);
        $this->artisan('cloud:meter')->assertSuccessful();
        $this->assertGreaterThan(0, BillingRunMonitor::read('cloud:meter')['counts']['pending']);
        $this->assertNull(BillingRunMonitor::read('cloud:meter')['last_success_at']);
    }
}
