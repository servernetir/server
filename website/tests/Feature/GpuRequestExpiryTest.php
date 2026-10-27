<?php

namespace Tests\Feature;

use App\Models\{ActivityLog, AiReservation, CloudImage, CloudInstance, CloudLocation, CloudPlan, CreditEntry, Customer, Invoice, Service, Setting, User};
use App\Services\Billing\OrderPaymentWindow;
use App\Services\Cloud\{GpuCapacityOrders, HourlyStartCredit, SaladClient};
use App\Services\Finance\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Expiry is solely for unfunded, never-allocated customer intents; no provider/financial actions. */
class GpuRequestExpiryTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;
    private CloudPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 27)->setTime(12, 0));
        config(['billing.order_expiry_hours' => 24, 'billing.invoice_hold_hours' => 48]);
        Wallet::flushSchemaCache();
        Http::preventStrayRequests();
        Http::fake();
        Setting::put('pricing_rate_override', '100000');
        Setting::put('pricing_usd_rate_override', '90000');
        Setting::put('salad_jupyter_sales_paused', '0');
        CloudLocation::create(['code' => 'global-gpu', 'country' => 'XX', 'is_active' => true]);
        $this->plan = CloudPlan::create([
            'provider' => 'salad', 'provider_ref' => 'expiry-test', 'provider_location' => 'global',
            'location_code' => 'global-gpu', 'slug' => 'expiry-test', 'public_name' => 'Test GPU',
            'vcpu' => 8, 'ram_mb' => 30720, 'disk_gb' => 150, 'arch' => 'x86', 'disk_type' => 'ssd',
            'gpu_model' => 'Test GPU', 'gpu_vram_mb' => 4096, 'gpu_count' => 1, 'is_interruptible' => true,
            'cost_eur_cents' => 1000, 'price_eur_cents' => 2000, 'price_irt' => 2000000,
            'cost_hour_eur_micro' => 15000, 'is_active' => true, 'in_stock' => true,
        ]);
        CloudImage::create(['provider' => 'salad', 'key' => 'gpu-jupyter',
            'provider_ref' => SaladClient::APPS['gpu-jupyter']['ref'], 'kind' => 'app',
            'family' => 'gpu-jupyter', 'label' => 'Jupyter PyTorch', 'arch' => 'x86', 'is_active' => true]);
        $this->customer = $this->customer('expiry');
    }

    private function customer(string $tag): Customer
    {
        return Customer::create(['email' => $tag.'@example.com', 'phone' => '0912'.random_int(1000000, 9999999),
            'password' => 'test-only-password', 'status' => 'active', 'locale' => 'fa']);
    }

    private function intent(int $ageSeconds = 86400, array $attributes = [], array $metadata = []): Service
    {
        $requested = now()->subSeconds($ageSeconds);
        $service = Service::create(array_replace([
            'customer_id' => $this->customer->id, 'name' => 'Test GPU hourly', 'currency_code' => 'IRT',
            'price' => 2000000, 'tax_percent' => 0, 'cycle' => 'monthly', 'billing_mode' => 'hourly',
            'hourly_rate_irt' => 1000, 'status' => 'awaiting_provision', 'provision_status' => GpuCapacityOrders::WAIT,
            'cloud_plan_id' => $this->plan->id, 'cloud_image_key' => 'gpu-jupyter', 'cloud_addons' => [],
            'provision_meta' => ['gpu_capacity_order' => array_replace([
                'version' => 1, 'request_key' => 'expiry-request', 'requested_at' => $requested->toIso8601String(),
                'state' => 'waiting_capacity', 'charge_pending' => true,
            ], $metadata)],
        ], $attributes));
        $service->forceFill(['created_at' => $requested])->save();
        return $service->fresh();
    }

    private function credit(int $amount): void
    {
        CreditEntry::create(['customer_id' => $this->customer->id, 'currency_code' => 'IRT',
            'amount' => $amount, 'balance_after' => $amount, 'reason' => 'topup']);
    }

    private function expire(Service $service): bool
    {
        return app(GpuCapacityOrders::class)->expireUnfunded($service);
    }

    private function assertExpired(Service $service): void
    {
        $service->refresh();
        $this->assertSame('cancelled', $service->status);
        $this->assertSame('none', $service->provision_status);
        $this->assertNotNull($service->cancelled_at);
        $this->assertSame('expired', data_get($service->provision_meta, 'gpu_capacity_order.state'));
        $this->assertSame('payment_expired', data_get($service->provision_meta, 'gpu_capacity_order.closed_reason'));
        $this->assertFalse(data_get($service->provision_meta, 'gpu_capacity_order.charge_pending'));
        $this->assertFalse(data_get($service->provision_meta, 'gpu_capacity_order.stop_pending'));
    }

    public static function boundary(): array
    {
        return [[86399, false], [86400, true], [86401, true]];
    }

    #[DataProvider('boundary')]
    public function test_the_exact_initial_order_payment_window_boundary(int $age, bool $expired): void
    {
        $service = $this->intent($age);
        $this->assertSame($expired, $this->expire($service));
        if ($expired) {
            $this->assertExpired($service);
            $this->assertSame(now()->subSeconds($age)->addHours(24)->toIso8601String(),
                data_get($service->provision_meta, 'gpu_capacity_order.expires_at'));
        } else {
            $this->assertSame('awaiting_provision', $service->fresh()->status);
        }
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('credit_ledger', 0);
        $this->assertDatabaseCount('cloud_instances', 0);
        Http::assertNothingSent();
    }

    public function test_shared_policy_uses_configured_order_window_not_generic_invoice_hold(): void
    {
        config(['billing.order_expiry_hours' => 6, 'billing.invoice_hold_hours' => 48]);
        $service = $this->intent(6 * 3600);
        $this->assertSame(6, OrderPaymentWindow::hours());
        $this->assertTrue($this->expire($service));

        $initial = Service::create(['customer_id' => $this->customer->id, 'name' => 'Initial order',
            'currency_code' => 'IRT', 'price' => 100000, 'tax_percent' => 0, 'cycle' => 'monthly', 'status' => 'pending']);
        $invoice = Invoice::create(['customer_id' => $this->customer->id, 'service_id' => $initial->id,
            'kind' => 'service', 'currency_code' => 'IRT', 'subtotal' => 100000, 'tax' => 0,
            'total' => 100000, 'paid' => 0, 'status' => 'unpaid', 'issued_at' => now()]);
        $invoice->forceFill(['created_at' => now()->subHours(7)])->save();
        $this->artisan('invoices:expire-orders')->assertSuccessful();
        $this->assertSame('canceled', $invoice->fresh()->status);
    }

    public function test_expiry_is_idempotent_and_preserves_wallet_and_other_metadata(): void
    {
        $service = $this->intent();
        $service->update(['provision_meta' => array_replace($service->provision_meta, ['unrelated' => ['value' => 'preserved']])]);
        $this->credit(23999);
        $before = CreditEntry::get()->toArray();
        $this->assertTrue($this->expire($service));
        $updated = $service->updated_at;
        $this->travel(2)->minutes();
        $this->assertFalse($this->expire($service));
        $this->assertEquals($updated, $service->updated_at);
        $this->assertSame($before, CreditEntry::get()->toArray());
        $this->assertSame('preserved', data_get($service->provision_meta, 'unrelated.value'));
        $this->assertSame(1, ActivityLog::where('action', 'gpu_request_expired')->where('service_id', $service->id)->count());
        $this->assertSame('system', ActivityLog::where('action', 'gpu_request_expired')->value('actor'));
        Http::assertNothingSent();
    }

    public function test_fresh_updated_at_and_capacity_checks_do_not_extend_original_deadline(): void
    {
        $service = $this->intent(25 * 3600, [], ['checked_at' => now()->toIso8601String(), 'state' => 'price_changed']);
        $service->touch();
        $this->assertTrue($this->expire($service));
        $this->assertExpired($service);
    }

    public static function invalidDates(): array
    {
        return [[null], [''], ['not-a-date'], ['now'], ['tomorrow'], ['+48 hours'], ['2026-99-99T99:99:99Z'], ['2026-02-31T10:00:00Z']];
    }

    #[DataProvider('invalidDates')]
    public function test_bad_or_relative_request_dates_fall_back_to_creation(?string $requested): void
    {
        $service = $this->intent(90000, [], ['requested_at' => $requested]);
        $this->assertEquals($service->created_at->copy()->addHours(24), GpuCapacityOrders::requestDeadline($service));
        $this->assertTrue($this->expire($service));
    }

    public function test_future_request_timestamp_cannot_extend_the_creation_deadline(): void
    {
        $service = $this->intent(90000, [], ['requested_at' => now()->addDays(2)->toIso8601String()]);
        $this->assertEquals($service->created_at->copy()->addHours(24), GpuCapacityOrders::requestDeadline($service));
        $this->assertTrue($this->expire($service));
    }

    public function test_an_older_original_request_date_wins_over_row_creation(): void
    {
        $service = $this->intent(100, [], ['requested_at' => now()->subHours(25)->toIso8601String()]);
        $this->assertTrue($this->expire($service));
    }

    public function test_funded_capacity_wait_remains_open_at_and_after_deadline(): void
    {
        $service = $this->intent(3 * 86400);
        $this->credit(24000);
        $before = $service->getRawOriginal();
        $this->assertFalse($this->expire($service));
        $this->assertSame($before, $service->getRawOriginal());
        $this->assertTrue(GpuCapacityOrders::fundingWindow($service)['funded']);
        $this->assertSame(24000, GpuCapacityOrders::fundingWindow($service)['required_credit']);
        Http::assertNothingSent();
    }

    public function test_reserved_credit_is_not_available_funding(): void
    {
        $service = $this->intent();
        $this->credit(24000);
        AiReservation::create(['customer_id' => $this->customer->id, 'currency_code' => 'IRT', 'amount_irt' => 1,
            'status' => 'pending', 'idempotency_key' => 'expiry-test-hold', 'expires_at' => now()->addHour()]);
        $this->assertSame(23999, app(Wallet::class)->availableOf($this->customer->id));
        $this->assertTrue($this->expire($service));
        $this->assertDatabaseCount('credit_ledger', 1);
        $this->assertSame('pending', AiReservation::sole()->status);
    }

    public function test_other_running_hourly_commitments_are_included_in_required_credit(): void
    {
        $service = $this->intent();
        $this->intent(20, ['status' => 'active', 'provision_status' => 'done', 'hourly_rate_irt' => 500], ['charge_pending' => false]);
        $this->credit(24000);
        $this->assertSame(36000, GpuCapacityOrders::fundingWindow($service)['required_credit']);
        $this->assertTrue($this->expire($service));
    }

    public function test_unallocated_waiting_siblings_do_not_consume_the_same_minimum_twice(): void
    {
        $service = $this->intent();
        $this->intent(100);
        $this->credit(24000);
        $this->assertSame(24000, GpuCapacityOrders::fundingWindow($service)['required_credit']);
        $this->assertFalse($this->expire($service));
    }

    public static function protectedAttributes(): array
    {
        return [
            'active' => [['status' => 'active']],
            'cancelled' => [['status' => 'cancelled']],
            'suspended' => [['status' => 'suspended']],
            'cycle' => [['billing_mode' => 'cycle']],
            'activated' => [['activated_at' => '2026-10-20 12:00:00']],
            'zero-rate' => [['hourly_rate_irt' => 0]],
            'null-rate' => [['hourly_rate_irt' => null]],
            'failed-provision' => [['provision_status' => 'failed']],
            'done-provision' => [['provision_status' => 'done']],
        ];
    }

    #[DataProvider('protectedAttributes')]
    public function test_non_intent_service_states_are_never_expired(array $attributes): void
    {
        $service = $this->intent(90000, $attributes);
        $before = $service->getRawOriginal();
        $this->assertFalse($this->expire($service));
        $this->assertSame($before, $service->getRawOriginal());
        $this->assertNull(GpuCapacityOrders::fundingWindow($service));
        Http::assertNothingSent();
    }

    public static function protectedMetadata(): array
    {
        return [[['version' => 2]], [['version' => '1']], [['charge_pending' => false]],
            [['charge_pending' => 1]], [['allocation_started_at' => '2026-10-25T12:00:00Z']]];
    }

    #[DataProvider('protectedMetadata')]
    public function test_allocated_or_unmanaged_metadata_is_never_expired(array $metadata): void
    {
        $service = $this->intent(90000, [], $metadata);
        $before = $service->getRawOriginal();
        $this->assertFalse($this->expire($service));
        $this->assertSame($before, $service->getRawOriginal());
        $this->assertNull(GpuCapacityOrders::fundingWindow($service));
        Http::assertNothingSent();
    }

    public function test_staff_created_request_is_protected(): void
    {
        $admin = User::create(['name' => 'Test admin', 'email' => 'expiry-admin@example.com', 'password' => 'test', 'role' => 'admin']);
        $service = $this->intent(90000, ['created_by' => $admin->id]);
        $this->assertFalse($this->expire($service));
        $this->assertNull(GpuCapacityOrders::fundingWindow($service));
    }

    public function test_stale_managed_metadata_on_another_provider_is_protected(): void
    {
        $this->plan->update(['provider' => 'arvan']);
        $service = $this->intent();
        $this->assertFalse($this->expire($service));
        $this->assertNull(GpuCapacityOrders::fundingWindow($service));
    }

    public function test_any_cloud_instance_even_without_a_reference_protects_the_request(): void
    {
        $service = $this->intent();
        CloudInstance::create(['service_id' => $service->id, 'provider' => 'salad', 'provider_ref' => '',
            'image_key' => 'gpu-jupyter', 'status' => 'building']);
        $this->assertFalse($this->expire($service));
        $this->assertNull(GpuCapacityOrders::fundingWindow($service));
        $this->assertDatabaseCount('cloud_instances', 1);
        Http::assertNothingSent();
    }

    public static function invoiceStates(): array
    {
        return [['unpaid', 0], ['unpaid', 100], ['paid', 1000], ['canceled', 0]];
    }

    #[DataProvider('invoiceStates')]
    public function test_invoice_backed_requests_follow_invoice_policy_instead(string $status, int $paid): void
    {
        $service = $this->intent();
        Invoice::create(['customer_id' => $this->customer->id, 'service_id' => $service->id,
            'kind' => 'service', 'currency_code' => 'IRT', 'subtotal' => 1000, 'tax' => 0,
            'total' => 1000, 'paid' => $paid, 'status' => $status, 'issued_at' => now()]);
        $this->assertFalse($this->expire($service));
        $this->assertNull(GpuCapacityOrders::fundingWindow($service));
        $this->assertSame($status, Invoice::sole()->status);
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_previous_hourly_charge_protects_against_stale_unpaid_metadata(): void
    {
        $service = $this->intent();
        CreditEntry::create(['customer_id' => $this->customer->id, 'currency_code' => 'IRT', 'amount' => -1000,
            'balance_after' => 0, 'reason' => 'cloud_hourly', 'source_type' => Service::class, 'source_id' => $service->id]);
        $this->assertFalse($this->expire($service));
        $this->assertNull(GpuCapacityOrders::fundingWindow($service));
        $this->assertDatabaseCount('credit_ledger', 1);
    }

    public function test_stale_owner_model_cannot_cancel_the_new_owners_request(): void
    {
        $service = $this->intent();
        $owner = $this->customer('new-owner');
        Service::whereKey($service->id)->update(['customer_id' => $owner->id]);
        $this->assertFalse($this->expire($service));
        $this->assertSame('awaiting_provision', $service->status);
        $this->assertSame($owner->id, $service->customer_id);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public static function queueStates(): array
    {
        return [[GpuCapacityOrders::WAIT], ['pending'], ['running']];
    }

    #[DataProvider('queueStates')]
    public function test_advance_expires_before_any_provider_work_even_if_already_queued(string $state): void
    {
        $service = $this->intent(90000, ['provision_status' => $state]);
        $this->assertSame('expired', app(GpuCapacityOrders::class)->advance($service));
        $this->assertExpired($service);
        $this->assertSame('skip', app(GpuCapacityOrders::class)->advance($service));
        Http::assertNothingSent();
        $this->assertDatabaseCount('cloud_instances', 0);
        $this->assertDatabaseCount('credit_ledger', 0);
    }

    public function test_price_confirmation_reports_expiry_after_committing_cancellation(): void
    {
        $service = $this->intent();
        try {
            app(GpuCapacityOrders::class)->confirmPrice($service, 1000);
            $this->fail('Expired request accepted a price confirmation');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('price', $e->errors());
        }
        $this->assertExpired($service);
        $this->assertFalse(app(GpuCapacityOrders::class)->allowAllocation($service, $this->plan));
        Http::assertNothingSent();
    }

    public function test_expired_allocation_is_rejected_even_after_charge_pending_was_cleared(): void
    {
        $service = $this->intent();
        $orders = app(GpuCapacityOrders::class);
        $this->assertFalse($orders->allowAllocation($service, $this->plan));
        $this->assertExpired($service);
        $this->assertFalse($orders->allowAllocation($service, $this->plan));
        Http::assertNothingSent();
    }

    public function test_funding_window_is_readonly_and_uses_original_timestamp(): void
    {
        $service = $this->intent(90000);
        $before = $service->getRawOriginal();
        $window = GpuCapacityOrders::fundingWindow($service);
        $this->assertFalse($window['funded']);
        $this->assertSame(24, $window['hours']);
        $this->assertSame(24000, $window['required_credit']);
        $this->assertEquals($service->created_at->copy()->addHours(24), $window['deadline']);
        $this->assertSame($before, $service->fresh()->getRawOriginal());
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('credit_ledger', 0);
        Http::assertNothingSent();
    }

    /** Simulate expiry occurring while the capacity provider response is in flight. */
    public function test_capacity_fallback_cannot_resurrect_a_request_expired_during_provider_call(): void
    {
        $service = $this->intent(86399, ['provision_status' => 'running']);
        $provider = \Mockery::mock(SaladClient::class);
        $provider->shouldReceive('capacityFor')->once()->andReturnUsing(function () use ($service) {
            $this->travel(2)->seconds();
            $this->assertTrue($this->expire($service->fresh()));
            return ['ok' => true, 'available' => false];
        });
        $orders = \Mockery::mock(GpuCapacityOrders::class, [$provider, app(Wallet::class), app(HourlyStartCredit::class)])->makePartial();
        $orders->shouldReceive('currentRate')->once()->andReturn(1000);
        $this->assertFalse($orders->allowAllocation($service, $this->plan));
        $this->assertExpired($service);
        $this->assertDatabaseCount('credit_ledger', 0);
        $this->assertDatabaseCount('cloud_instances', 0);
        Http::assertNothingSent();
    }

    public function test_late_advance_state_callback_does_not_overwrite_expired_metadata(): void
    {
        $service = $this->intent(86399);
        $provider = \Mockery::mock(SaladClient::class);
        $provider->shouldReceive('capacityFor')->once()->andReturnUsing(function () use ($service) {
            $this->travel(2)->seconds();
            $this->assertTrue($this->expire($service->fresh()));
            return ['ok' => true, 'available' => false];
        });
        $orders = new GpuCapacityOrders($provider, app(Wallet::class), app(HourlyStartCredit::class));
        $this->assertSame('waiting', $orders->advance($service));
        $this->assertExpired($service);
        $this->assertDatabaseCount('credit_ledger', 0);
        Http::assertNothingSent();
    }

    public function test_final_price_confirmation_check_commits_expiry_reached_during_quote(): void
    {
        $service = $this->intent(86399);
        $orders = \Mockery::mock(GpuCapacityOrders::class, [app(SaladClient::class), app(Wallet::class), app(HourlyStartCredit::class)])->makePartial();
        $orders->shouldReceive('currentRate')->once()->andReturnUsing(function () {
            $this->travel(2)->seconds();
            return 1000;
        });
        try {
            $orders->confirmPrice($service, 1000);
            $this->fail('Expired quote was accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('price', $e->errors());
        }
        $this->assertExpired($service);
        Http::assertNothingSent();
    }

    public function test_successful_allocation_marks_started_before_expiry_can_cancel_it(): void
    {
        $service = $this->intent(90000, ['provision_status' => 'running']);
        $this->credit(24000);
        $provider = \Mockery::mock(SaladClient::class);
        $provider->shouldReceive('capacityFor')->once()->andReturn(['ok' => true, 'available' => true]);
        $orders = \Mockery::mock(GpuCapacityOrders::class, [$provider, app(Wallet::class), app(HourlyStartCredit::class)])->makePartial();
        $orders->shouldReceive('currentRate')->once()->andReturn(1000);
        $this->assertTrue($orders->allowAllocation($service, $this->plan));
        $this->assertNotEmpty(data_get($service->fresh()->provision_meta, 'gpu_capacity_order.allocation_started_at'));
        // Credit subsequently spent elsewhere cannot turn an already started request into an unfunded intent.
        CreditEntry::where('customer_id', $this->customer->id)->delete();
        $this->assertFalse($this->expire($service));
        $this->assertSame('awaiting_provision', $service->fresh()->status);
        $this->assertDatabaseCount('cloud_instances', 0);
        Http::assertNothingSent();
    }

    public function test_successful_price_confirmation_does_not_restart_funding_window(): void
    {
        $service = $this->intent(23 * 3600);
        $original = data_get($service->provision_meta, 'gpu_capacity_order.requested_at');
        $deadline = GpuCapacityOrders::requestDeadline($service);
        $orders = \Mockery::mock(GpuCapacityOrders::class, [app(SaladClient::class), app(Wallet::class), app(HourlyStartCredit::class)])->makePartial();
        $orders->shouldReceive('currentRate')->once()->andReturn(1200);
        $orders->confirmPrice($service, 1200);
        $service->refresh();
        $this->assertSame(1200, (int) $service->hourly_rate_irt);
        $this->assertSame($original, data_get($service->provision_meta, 'gpu_capacity_order.requested_at'));
        $this->assertEquals($deadline, GpuCapacityOrders::requestDeadline($service));
        $this->travel(1)->hours();
        $this->assertTrue($this->expire($service));
        Http::assertNothingSent();
    }

    public function test_replaying_a_closed_key_does_not_restore_it_and_a_new_key_starts_fresh(): void
    {
        $service = $this->intent();
        $this->assertTrue($this->expire($service));
        $orders = app(GpuCapacityOrders::class);
        $data = ['image' => 'gpu-jupyter', 'billing_mode' => 'hourly', 'cycle' => 'monthly', 'gpu_request_key' => 'expiry-request'];
        $capacity = ['ok' => true, 'available' => false, 'checked_at' => now()->toIso8601String()];
        $replay = $orders->register($this->customer, $this->plan, $data, 'test', 'Test request', $capacity);
        $this->assertSame($service->id, $replay->id);
        $this->assertExpired($replay);
        $data['gpu_request_key'] = 'fresh-request';
        $fresh = $orders->register($this->customer, $this->plan, $data, 'test', 'Test request', $capacity);
        $this->assertNotSame($service->id, $fresh->id);
        $this->assertSame('awaiting_provision', $fresh->status);
        $this->assertEquals(now()->addHours(24), GpuCapacityOrders::requestDeadline($fresh));
        $this->assertDatabaseCount('services', 2);
        $this->assertDatabaseCount('credit_ledger', 0);
        Http::assertNothingSent();
    }

    public static function locales(): array
    {
        return [['fa'], ['en'], ['tr']];
    }

    #[DataProvider('locales')]
    public function test_unfunded_notice_shows_original_deadline_and_actual_credit_readonly(string $locale): void
    {
        $service = $this->intent(3600, [], ['state' => 'insufficient_credit', 'minimum_credit_irt' => 99]);
        $before = $service->getRawOriginal();
        $prefix = $locale === 'fa' ? '' : '/'.$locale;
        $html = $this->actingAs($this->customer, 'customer')->get($prefix.'/account/cloud/'.$service->id)->assertOk()->getContent();
        $this->assertStringContainsString('data-gpu-request-expiry', $html);
        $this->assertStringContainsString('data-deadline-ms="'.GpuCapacityOrders::requestDeadline($service)->getTimestampMs().'"', $html);
        $this->assertStringContainsString('data-locale="'.$locale.'"', $html);
        $this->assertStringContainsString(__('ui.gpu_request_deadline_heading'), $html);
        $this->assertStringContainsString(cloud_price(24000), $html);
        $this->assertStringNotContainsString('ui.gpu_request_deadline_', $html);
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $notice = (new \DOMXPath($dom))->query('//*[@data-gpu-request-expiry]')->item(0);
        $this->assertNotNull($notice);
        $this->assertStringNotContainsString(':hours', $notice->textContent);
        $this->assertStringContainsString('assets/js/gpu-request-expiry.js', $html);
        $this->assertSame($before, $service->fresh()->getRawOriginal());
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('credit_ledger', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('cloud_instances', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('locales')]
    public function test_funded_wait_has_no_payment_expiry_notice_and_expired_request_has_clear_closed_state(string $locale): void
    {
        $service = $this->intent(3600);
        $this->credit(24000);
        $prefix = $locale === 'fa' ? '' : '/'.$locale;
        $this->actingAs($this->customer, 'customer')->get($prefix.'/account/cloud/'.$service->id)
            ->assertOk()->assertDontSee('data-gpu-request-expiry', false);
        CreditEntry::where('customer_id', $this->customer->id)->delete();
        $this->travel(24)->hours();
        $this->assertTrue($this->expire($service));
        $this->actingAs($this->customer, 'customer')->get($prefix.'/account/cloud/'.$service->id)
            ->assertOk()->assertSee(__('ui.gpu_request_expired_h'), false)
            ->assertSee(__('ui.gpu_request_expired_p'), false)->assertSee(__('ui.gpu_order_expired'), false)
            ->assertDontSee('data-gpu-request-expiry', false)->assertDontSee('data-gpu-capacity-wait', false);
        $this->assertExpired($service);
        Http::assertNothingSent();
    }
}
