<?php

namespace Tests\Feature;

use App\Console\Commands\MeterArvanTraffic;
use App\Models\BusinessEntry;
use App\Models\CloudInstance;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Finance\ArvanTrafficMeter;
use App\Services\Finance\ArvanTrafficExposure;
use App\Services\Finance\ArvanTrafficCreditGuard;
use App\Services\Finance\ArvanTrafficSource;
use App\Services\Finance\Wallet;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** Synthetic snapshots only; no real supplier, wallet, guest or provider calls. */
class ArvanTrafficMeterTest extends TestCase
{
    use RefreshDatabase;

    private const CYCLE = '12345678-1234-1234-1234-123456789abc';
    private const NATIVE = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-11-02T12:00:00Z'));
        Setting::put('cloud_margin_pct', '5');
        Setting::put('arvan_traffic_exposure_enabled_v1', '1');
        // MariaDB keeps auto-increment gaps across rolled-back fixtures. A
        // synthetic ID must never acquire production's ID-20 infrastructure exemption.
        config(['billing.tax_percent' => 10, 'billing.internal_service_ids' => []]);
        Wallet::flushSchemaCache();
    }

    private function fixture(bool $excluded = false): array
    {
        $customer = Customer::create(['email' => 'synthetic-meter@example.test', 'status' => 'active', 'locale' => 'fa']);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'Synthetic traffic fixture',
            'currency_code' => 'IRT', 'cycle' => 'monthly', 'billing_mode' => 'cycle', 'status' => 'active', 'price' => 1000,
            'created_at' => Carbon::parse('2026-10-24T00:00:00Z')]);
        $resource = 'ir-test:'.self::NATIVE;
        CloudInstance::create(['service_id' => $service->id, 'provider' => 'arvan', 'provider_ref' => $resource,
            'status' => 'running', 'ipv4' => '192.0.2.10']);
        $checkpoint = ['service_id' => $service->id, 'customer_id' => $customer->id, 'resource' => $resource,
            'item_id' => 'synthetic-internet', 'from' => '2026-10-28T00:00:00Z', 'to' => '2026-11-01T00:00:00Z',
            'gb_display' => '1.000000', 'cost_irr' => 10000, 'sell_irt' => 1050, 'tax_irt' => 105];
        $state = ['version' => 1, 'enabled' => true, 'authorization' => 'Synthetic pre-paid baseline fixture',
            'authorized_at' => '2026-10-25T00:00:00Z', 'baseline_paid_at' => '2026-10-25T00:00:00Z',
            'baseline_hash' => str_repeat('a', 64), 'margin_bp' => 500, 'tax_bp' => 1000,
            'excluded_customers' => $excluded ? [$customer->id] : [], 'sequence' => 0,
            'cycles' => [self::CYCLE => ['account_hash' => hash('sha256', 'synthetic-account'),
                'started_at' => '2026-10-23T00:00:00Z', 'expired_at' => '2026-11-22T00:00:00Z']],
            'checkpoints' => [self::CYCLE.'|'.$resource => $checkpoint], 'last_success' => null];
        Setting::putSecret(ArvanTrafficMeter::KEY, json_encode($state, JSON_THROW_ON_ERROR));

        return [$customer, $service, $state, $this->snapshot()];
    }

    private function snapshot(string $cost = '70000', float $quantity = 2): array
    {
        $row = ['instanceId' => self::NATIVE, 'region' => 'ir-test', 'scope' => 'instance', 'productName' => 'iaas',
            'totalPrice' => $cost, 'totalDiscount' => '0', 'totalUsedGift' => '0', 'netPrice' => $cost,
            'items' => [['itemId' => 'synthetic-internet', 'metric' => 'cc_internet', 'amount' => $quantity,
                'price' => $cost, 'discount' => '0', 'usedGift' => '0',
                'usageFrom' => '2026-10-28T00:00:00Z', 'usageTo' => match ($cost) { '20000' => '2026-11-02T10:00:00Z', '30000' => '2026-11-02T10:20:00Z', '53277' => '2026-11-02T10:40:00Z', default => '2026-11-02T11:00:00Z' }]]];
        $cycle = ['id' => self::CYCLE, 'accountId' => 'synthetic-account', 'currency' => 'irr',
            'startedAt' => '2026-10-23T00:00:00Z', 'expiredAt' => '2026-11-22T00:00:00Z',
            'totalItemsPrice' => $cost, 'totalItemsDiscount' => '0', 'totalUsedGift' => '0', 'totalVatPrice' => '0', 'totalPrice' => $cost];

        return ['observed_at' => '2026-11-02T12:00:00Z', 'current_cycle' => self::CYCLE,
            'reports' => [['cycle' => $cycle, 'rows' => [$row], 'total_items' => 1]]];
    }

    public function test_preview_preserves_encrypted_checkpoint_and_all_financial_rows(): void
    {
        [, , $state, $snapshot] = $this->fixture();
        $result = app(ArvanTrafficMeter::class)->run($snapshot);
        $this->assertSame('preview', $result['mode']);
        $this->assertSame(6930, $result['total_irt']);
        $this->assertSame($state, app(ArvanTrafficMeter::class)->state());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, CreditEntry::count());
        Http::assertNothingSent();
    }

    public function test_incremental_apply_posts_once_and_retry_does_not_charge_again_or_suspend(): void
    {
        [$customer, , , $snapshot] = $this->fixture();
        $services = Service::all()->map->getRawOriginal()->all();
        $instances = CloudInstance::all()->map->getRawOriginal()->all();
        $first = app(ArvanTrafficMeter::class)->run($snapshot, true);
        $second = app(ArvanTrafficMeter::class)->run($snapshot, true);
        $this->assertSame(6930, $first['total_irt']);
        $this->assertSame(0, $second['total_irt']);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, CreditEntry::count());
        $this->assertSame(2, BusinessEntry::count());
        $this->assertSame(-6930, app(Wallet::class)->balanceOf($customer->id));
        $afterService = Service::first();
        $this->assertTrue(ArvanTrafficCreditGuard::active($afterService));
        $this->assertSame('pending', data_get(ArvanTrafficCreditGuard::state($afterService), 'stop.state'));
        $afterRows = Service::all()->map->getRawOriginal()->all();
        foreach ($afterRows as &$serviceRow) {
            unset($serviceRow['updated_at']);
        }
        foreach ($services as &$serviceRow) {
            unset($serviceRow['updated_at']);
        }
        $this->assertSame($services, $afterRows);
        $this->assertSame($instances, CloudInstance::all()->map->getRawOriginal()->all());
        Http::assertNothingSent();
    }

    public function test_new_debit_preserves_one_positive_spendable_toman_after_initial_reserve(): void
    {
        [$customer, $service, , $snapshot] = $this->fixture();
        $service->update(['billing_mode' => 'hourly', 'hold_reserve_irt' => 700, 'hourly_rate_irt' => 10000]);
        app(Wallet::class)->credit($customer->id, 'IRT', 7631, 'topup', null);
        app(ArvanTrafficMeter::class)->run($snapshot, true);
        $this->assertSame(1, app(Wallet::class)->availableOf($customer->id));
        $this->assertFalse(ArvanTrafficCreditGuard::active($service->fresh()), '24-hour start minimum must not replace the ongoing positive-credit policy');
        Http::assertNothingSent();
    }

    public function test_new_debit_exhausting_spendable_credit_creates_only_its_own_intent_atomically(): void
    {
        [$customer, $service, , $snapshot] = $this->fixture();
        $service->update(['billing_mode' => 'hourly', 'hold_reserve_irt' => 700,
            'provision_meta' => ['hourly_billing_hold' => ['active' => true], 'staff_marker' => 'keep']]);
        app(Wallet::class)->credit($customer->id, 'IRT', 7630, 'topup', null);
        app(ArvanTrafficMeter::class)->run($snapshot, true);
        $fresh = $service->fresh();
        $hold = ArvanTrafficCreditGuard::state($fresh);
        $this->assertSame(0, app(Wallet::class)->availableOf($customer->id));
        $this->assertTrue($hold['active']);
        $this->assertSame('pending', $hold['stop']['state']);
        $this->assertSame(Invoice::first()->id, $hold['trigger_invoice_id']);
        $this->assertSame('active', $fresh->status);
        $this->assertNull($fresh->suspended_at);
        $this->assertTrue($fresh->hourlyBillingHeld());
        $this->assertSame('keep', $fresh->provision_meta['staff_marker']);
        $this->assertArrayNotHasKey('hourly_suspend', $fresh->provision_meta);
        Http::assertNothingSent();
    }

    public function test_unchanged_supplier_cost_never_enrolls_a_zero_balance_monthly_service(): void
    {
        [, $service] = $this->fixture();
        app(ArvanTrafficMeter::class)->run($this->snapshot('10000', 1), true);
        $this->assertFalse(ArvanTrafficCreditGuard::active($service->fresh()));
        $this->assertSame(0, CreditEntry::count());
        Http::assertNothingSent();
    }

    public function test_failure_after_saving_stop_intent_rolls_back_all_money_and_checkpoint_together(): void
    {
        [, $service, $state, $snapshot] = $this->fixture();
        $saved = false;
        DB::listen(function (QueryExecuted $query) use (&$saved): void {
            if (! $saved && (str_starts_with(strtolower($query->sql), 'insert') || str_starts_with(strtolower($query->sql), 'update'))
                && str_contains($query->sql, 'settings')
                && collect($query->bindings)->contains(fn ($v) => is_string($v) && str_contains($v, 'traffic_credit_hold'))) {
                $saved = true;
                throw new RuntimeException('Synthetic failure after durable stop intent write');
            }
        });
        try {
            app(ArvanTrafficMeter::class)->run($snapshot, true);
            $this->fail('The injected post-intent failure must roll back the financial transaction');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('after durable stop intent', $error->getMessage());
        }
        $this->assertTrue($saved);
        $this->assertFalse(ArvanTrafficCreditGuard::active($service->fresh()));
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, CreditEntry::count());
        $this->assertSame(0, BusinessEntry::count());
        $this->assertSame($state, app(ArvanTrafficMeter::class)->state());
        Http::assertNothingSent();
    }

    public function test_owner_exclusion_is_preserved_without_posting_or_advancing_its_checkpoint(): void
    {
        [, $service, $state, $snapshot] = $this->fixture(true);
        $result = app(ArvanTrafficMeter::class)->run($snapshot, true);
        $this->assertSame([['service_id' => $service->id, 'reason' => 'owner_exclusion']], $result['held']);
        $after = app(ArvanTrafficMeter::class)->state();
        $this->assertSame($state['excluded_customers'], $after['excluded_customers']);
        $this->assertSame($state['checkpoints'], $after['checkpoints']);
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, CreditEntry::count());
    }

    public function test_manual_charge_visible_before_plan_is_held(): void
    {
        [$customer, $service, , $snapshot] = $this->fixture();
        $entry = $this->manualDebit($customer);
        $result = app(ArvanTrafficMeter::class)->run($snapshot, true);
        $this->assertSame([['service_id' => $service->id, 'reason' => 'manual_traffic_charge_requires_reconciliation']], $result['held']);
        $this->assertSame(0, Invoice::count());
        $this->assertSame([$entry->id], CreditEntry::pluck('id')->all());
    }

    private function manualDebit(Customer $customer): CreditEntry
    {
        return CreditEntry::create(['customer_id' => $customer->id, 'currency_code' => 'IRT', 'amount' => -900,
            'balance_after' => -900, 'reason' => 'manual_traffic', 'source_type' => Customer::class,
            'source_id' => $customer->id, 'note' => 'Synthetic manual traffic reconciliation']);
    }

    public function test_manual_charge_becoming_visible_at_customer_lock_refuses_the_batch_before_posting(): void
    {
        [$customer, , $state, $snapshot] = $this->fixture();
        $inserted = false;
        // A deterministic visibility interleaving: the first unlocked legacy
        // check sees nothing; the new debit appears exactly when Customer's
        // locking SELECT returns. This is not a multi-process concurrency claim.
        DB::listen(function (QueryExecuted $query) use ($customer, &$inserted): void {
            if (!$inserted && preg_match('/\Aselect \* from [`"]customers[`"] where [`"]customers[`"]\.[`"]id[`"] = \?/i', $query->sql)
                && in_array($customer->id, $query->bindings, true)) {
                $inserted = true;
                $this->manualDebit($customer);
            }
        });
        try {
            app(ArvanTrafficMeter::class)->run($snapshot, true);
            $this->fail('A new unreviewed charge must stop the entire batch');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('changed during lock acquisition', $error->getMessage());
        }
        $this->assertTrue($inserted, 'Fixture must reach the lock-acquisition interleaving');
        $this->assertSame($state, app(ArvanTrafficMeter::class)->state());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, CreditEntry::count(), 'Synthetic injection shares the rolled-back transaction');
    }

    public function test_changed_owner_is_held(): void
    {
        [, $service, , $snapshot] = $this->fixture();
        $other = Customer::create(['email' => 'other-fixture@example.test', 'status' => 'active']);
        $service->update(['customer_id' => $other->id]);
        $result = app(ArvanTrafficMeter::class)->run($snapshot, true);
        $this->assertSame('changed_owner_window_counter_discount_or_gift', $result['held'][0]['reason']);
        $this->assertSame(0, Invoice::count());
    }

    public function test_decreased_supplier_counter_is_held_without_rewriting_paid_baseline(): void
    {
        [, , $state] = $this->fixture();
        $result = app(ArvanTrafficMeter::class)->run($this->snapshot('9000', 0.9), true);
        $this->assertSame('changed_owner_window_counter_discount_or_gift', $result['held'][0]['reason']);
        $this->assertSame($state['checkpoints'], app(ArvanTrafficMeter::class)->state()['checkpoints']);
        $this->assertSame(0, Invoice::count());
    }

    private function fakeSupplier(array $snapshot): void
    {
        Setting::putSecret('arvan_api_token', 'synthetic-read-only-token');
        $report = $snapshot['reports'][0];
        Http::fake([
            'https://napi.arvancloud.ir/resid/v1/current-billing-cycle' => Http::response(['data' => $report['cycle']]),
            'https://napi.arvancloud.ir/resid/v1/billing-cycles/'.self::CYCLE => Http::response(['data' => $report['cycle']]),
            'https://napi.arvancloud.ir/resid/v1/billing-cycles/'.self::CYCLE.'/usage-items*' => Http::response([
                'data' => $report['rows'], 'meta' => ['pagination' => ['totalItems' => 1, 'totalPages' => 1, 'current' => 1]],
            ]),
        ]);
    }

    public function test_restored_source_fetches_complete_fake_finance_report_without_provider_mutations(): void
    {
        $this->fakeSupplier($this->snapshot());
        $source = app(ArvanTrafficSource::class);
        $snapshot = $source->validate($source->fetch());
        $this->assertSame(self::CYCLE, $snapshot['current_cycle']);
        $this->assertCount(1, $snapshot['reports'][0]['rows']);
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET'
            || !str_starts_with($request->url(), 'https://napi.arvancloud.ir/resid/v1/'));
    }

    public function test_registered_command_defaults_to_preview_and_preserves_financial_state(): void
    {
        [, , $state, $snapshot] = $this->fixture();
        $this->fakeSupplier($snapshot);
        // Use a per-test private directory so fixture report files do not mix
        // with another test process or any real application storage.
        $storage = storage_path('framework/testing/arvan-meter-'.bin2hex(random_bytes(6)));
        mkdir($storage, 0700, true);
        $this->app->useStoragePath($storage);
        try {
            $this->assertSame(0, Artisan::call('finance:meter-arvan-traffic'));
            $this->assertStringContainsString('"mode":"preview"', Artisan::output());
            $this->assertSame($state, app(ArvanTrafficMeter::class)->state());
            $this->assertSame(0, Invoice::count());
            $this->assertSame(0, CreditEntry::count());
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($storage);
        }
    }

    public function test_invalid_supplier_cycle_totals_fail_before_writes(): void
    {
        [, , $state, $snapshot] = $this->fixture();
        $snapshot['reports'][0]['cycle']['totalPrice'] = '999999';
        try {
            app(ArvanTrafficMeter::class)->run($snapshot, true);
            $this->fail('An invalid supplier snapshot must be rejected');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('cycle totals differ', $error->getMessage());
        }
        $this->assertSame($state, app(ArvanTrafficMeter::class)->state());
        $this->assertSame(0, Invoice::count());
    }

    public function test_actual_registered_command_and_single_existing_schedule_remain_integrated(): void
    {
        $this->assertInstanceOf(MeterArvanTraffic::class, Artisan::all()['finance:meter-arvan-traffic']);
        $events = array_values(array_filter(app(Schedule::class)->events(),
            fn ($event) => str_contains((string) $event->command, 'finance:meter-arvan-traffic')));
        $this->assertCount(1, $events, 'Restoration must not register a second scheduled meter');
        $this->assertSame('*/5 * * * *', $events[0]->expression);
        $this->assertSame('Asia/Tehran', $events[0]->timezone);
        $this->assertStringContainsString('--apply', $events[0]->command);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertTrue($events[0]->runInBackground);
        Http::assertNothingSent();
    }

    public function test_repeated_reconciliation_does_not_evict_other_incidents_but_changed_issues_alert_immediately(): void
    {
        [$customer, $service, $state, $snapshot] = $this->fixture(true);
        $this->fakeSupplier($snapshot);
        $originalStorage = storage_path();
        $storage = storage_path('framework/testing/arvan-meter-notices-'.bin2hex(random_bytes(6)));
        mkdir($storage.'/logs', 0700, true);
        mkdir($storage.'/app', 0700, true);
        $this->app->useStoragePath($storage);
        \App\Support\ErrorTracker::useDirectory($storage);
        try {
            foreach (range(1, 3) as $run) {
                $this->assertSame(0, Artisan::call('finance:meter-arvan-traffic'));
                $this->assertStringContainsString('owner_exclusion', Artisan::output());
            }
            $notices = fn () => array_values(array_filter(\App\Support\ErrorTracker::recent(50, 'error'),
                fn ($row) => ($row['message'] ?? '') === 'Traffic items require reconciliation'));
            $this->assertCount(1, $notices());
            $other = Customer::create(['email' => 'changed-notice-owner@example.test', 'status' => 'active']);
            $service->update(['customer_id' => $other->id]);
            $this->assertSame(0, Artisan::call('finance:meter-arvan-traffic'));
            $this->assertCount(2, $notices(), 'Changed ownership/reason must bypass the stable-incident delay');
            foreach (glob($storage.'/app/throttle-arvan-traffic-reconciliation-*') as $path) {
                touch($path, time() - 1801);
                clearstatcache(true, $path);
            }
            $this->assertSame(0, Artisan::call('finance:meter-arvan-traffic'));
            $this->assertCount(3, $notices(), 'An unresolved hold must be reminded after thirty minutes');
            $this->assertSame($state, app(ArvanTrafficMeter::class)->state());
            $this->assertSame(0, Invoice::count());
            $this->assertSame(0, CreditEntry::count());
            Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        } finally {
            \App\Support\ErrorTracker::useDirectory(null);
            $this->app->useStoragePath($originalStorage);
            \Illuminate\Support\Facades\File::deleteDirectory($storage);
        }
    }

    public function test_small_amount_is_carried_without_invoice_payment_debit_or_paid_checkpoint_advance(): void
    {
        [$customer, , $state] = $this->fixture();
        foreach (['20000' => 2, '30000' => 3] as $cost => $qty) {
            $result = app(ArvanTrafficMeter::class)->run($this->snapshot((string) $cost, $qty), true);
            $this->assertSame(0, $result['total_irt']);
            $this->assertGreaterThan(0, $result['deferred_total_irt']);
            $this->assertSame($state['checkpoints'], app(ArvanTrafficMeter::class)->state()['checkpoints']);
        }
        $this->assertTrue(app(ArvanTrafficMeter::class)->state()['cycles'][self::CYCLE]['pending']);
        $this->assertSame(0, Invoice::count()); $this->assertSame(0, Payment::count());
        $this->assertSame(0, CreditEntry::count()); $this->assertSame(0, BusinessEntry::count());
        $this->assertSame(0, app(Wallet::class)->balanceOf($customer->id));
        $result = app(ArvanTrafficMeter::class)->run($this->snapshot('53277', 5), true);
        $this->assertSame(5000, $result['total_irt']);
        $this->assertFalse(app(ArvanTrafficMeter::class)->state()['cycles'][self::CYCLE]['pending']);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(-5000, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame(0, app(ArvanTrafficMeter::class)->run($this->snapshot('53277', 5), true)['total_irt']);
        $this->assertSame(1, Payment::count());
        Http::assertNothingSent();
    }

    public function test_preview_below_minimum_has_no_state_or_financial_writes(): void
    {
        [, , $state] = $this->fixture();
        $result = app(ArvanTrafficMeter::class)->run($this->snapshot('20000', 2));
        $this->assertSame(0, $result['total_irt']);
        $this->assertSame(1155, $result['deferred_total_irt']);
        $this->assertSame($state, app(ArvanTrafficMeter::class)->state());
        $this->assertSame(0, Invoice::count());
    }

    public function test_source_keeps_a_deferred_expired_cycle_beyond_seven_days(): void
    {
        $this->fakeSupplier($this->snapshot());
        $otherCycle = '22345678-1234-1234-1234-123456789abc';
        $report = $this->snapshot()['reports'][0];
        $report['cycle']['id'] = $otherCycle;
        $report['cycle']['expiredAt'] = '2026-10-01T00:00:00Z';
        Http::fake([
            'https://napi.arvancloud.ir/resid/v1/billing-cycles/'.$otherCycle => Http::response(['data' => $report['cycle']]),
            'https://napi.arvancloud.ir/resid/v1/billing-cycles/'.$otherCycle.'/usage-items*' => Http::response([
                'data' => $report['rows'], 'meta' => ['pagination' => ['totalItems' => 1, 'totalPages' => 1, 'current' => 1]],
            ]),
        ]);
        $snapshot = app(ArvanTrafficSource::class)->fetch([
            $otherCycle => ['expired_at' => '2026-10-01T00:00:00Z', 'pending' => true],
        ]);
        $this->assertCount(2, $snapshot['reports']);
        Http::assertSent(fn ($request) => str_contains($request->url(), $otherCycle.'/usage-items'));
    }

    public function test_two_individually_small_cycles_settle_in_one_customer_invoice(): void
    {
        [$customer] = $this->fixture();
        $this->travelTo(Carbon::parse('2026-12-02T12:00:00Z'));
        $snapshot = $this->snapshot('30000', 3);
        $next = $this->snapshot('30000', 3)['reports'][0];
        $nextId = '22345678-1234-1234-1234-123456789abc';
        $next['cycle']['id'] = $nextId;
        $next['cycle']['startedAt'] = '2026-11-22T00:00:00Z';
        $next['cycle']['expiredAt'] = '2026-12-22T00:00:00Z';
        $next['rows'][0]['items'][0]['usageFrom'] = '2026-11-22T00:00:00Z';
        $next['rows'][0]['items'][0]['usageTo'] = '2026-12-02T11:00:00Z';
        $snapshot['reports'][] = $next;
        $snapshot['current_cycle'] = $nextId;
        $snapshot['observed_at'] = '2026-12-02T12:00:00Z';
        $result = app(ArvanTrafficMeter::class)->run($snapshot, true);
        $this->assertSame(5775, $result['total_irt']);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(2, Invoice::first()->items()->count());
        $this->assertSame(-5775, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame(0, app(ArvanTrafficMeter::class)->run($snapshot, true)['total_irt']);
        $this->assertSame(1, Payment::count());
        Http::assertNothingSent();
    }

    private function repeatedFreeCdn(): array
    {
        $snapshot = $this->snapshot();
        $item = ['itemId' => 'synthetic-free-cdn', 'metric' => 'cdn_plan_basic', 'amount' => 1,
            'price' => '0', 'discount' => '0', 'usedGift' => '0',
            'usageFrom' => '2026-10-28T00:00:00Z', 'usageTo' => '2026-11-02T11:00:00Z'];
        foreach (['synthetic-cdn-a', 'synthetic-cdn-b'] as $parent) {
            $snapshot['reports'][0]['rows'][] = ['instanceId' => $parent, 'scope' => 'instance', 'region' => 'synthetic-cdn',
                'productName' => 'cdn', 'totalPrice' => '0', 'totalDiscount' => '0', 'totalUsedGift' => '0', 'netPrice' => '0', 'items' => [$item]];
        }
        $snapshot['reports'][0]['total_items'] = 3;
        return $snapshot;
    }

    public function test_identical_free_cdn_components_under_distinct_zero_parents_do_not_block_iaas_settlement(): void
    {
        $this->fixture();
        $result = app(ArvanTrafficMeter::class)->run($this->repeatedFreeCdn(), true);
        $this->assertSame(6930, $result['total_irt']);
        $this->assertSame(1, Invoice::first()->items()->count());
        $this->assertSame(0, app(ArvanTrafficMeter::class)->run($this->repeatedFreeCdn(), true)['total_irt']);
        $this->assertSame(1, Payment::count());
        Http::assertNothingSent();
    }

    public function test_changed_free_cdn_payload_and_paid_duplicate_still_stop_before_any_write(): void
    {
        [, , $state] = $this->fixture();
        foreach (['amount', 'paid_component', 'paid_parent'] as $change) {
            $snapshot = $this->repeatedFreeCdn();
            $parent = &$snapshot['reports'][0]['rows'][2];
            if ($change === 'amount') { $parent['items'][0]['amount'] = 2; }
            elseif ($change === 'paid_component') { $parent['items'][0]['price'] = '1'; }
            else { $parent['items'][] = array_replace($parent['items'][0], ['itemId' => 'other-paid-item', 'price' => '1']); }
            if ($change !== 'amount') {
                $parent['totalPrice'] = $parent['netPrice'] = '1';
                $snapshot['reports'][0]['cycle']['totalItemsPrice'] = $snapshot['reports'][0]['cycle']['totalPrice'] = '70001';
            }
            unset($parent);
            try { app(ArvanTrafficMeter::class)->run($snapshot, true); $this->fail('Changed or paid duplicate must be rejected'); }
            catch (RuntimeException $error) { $this->assertStringContainsString('Duplicate or missing supplier component', $error->getMessage()); }
            $this->assertSame($state, app(ArvanTrafficMeter::class)->state());
            $this->assertSame(0, Invoice::count()); $this->assertSame(0, CreditEntry::count());
        }
        Http::assertNothingSent();
    }

    public function test_pending_traffic_reserves_credit_and_stops_before_invoice_minimum_without_a_small_debit(): void
    {
        [$customer, $service, $state] = $this->fixture();
        app(Wallet::class)->credit($customer->id, 'IRT', 1155, 'topup', null);
        app(ArvanTrafficMeter::class)->run($this->snapshot('20000', 2), true);
        $this->assertSame(1155, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame(1155, app(ArvanTrafficExposure::class)->reservedOf($customer->id));
        $this->assertSame(0, app(Wallet::class)->availableOf($customer->id));
        $hold = ArvanTrafficCreditGuard::state($service->fresh());
        $this->assertTrue($hold['active']);
        $this->assertSame('pending_traffic_credit_exhausted', $hold['reason']);
        $this->assertSame('pending_traffic', $hold['trigger']['kind']);
        $this->assertArrayNotHasKey('trigger_invoice_id', $hold);
        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame($state['checkpoints'], app(ArvanTrafficMeter::class)->state()['checkpoints']);
        $this->assertSame(0, Invoice::count()); $this->assertSame(0, Payment::count());
        $this->assertSame(1, CreditEntry::count());
        $token = $hold['token'];
        app(ArvanTrafficMeter::class)->run($this->snapshot('20000', 2), true);
        $this->assertSame($token, ArvanTrafficCreditGuard::state($service->fresh())['token']);
        $this->assertSame(1155, app(ArvanTrafficExposure::class)->reservedOf($customer->id));
        Http::assertNothingSent();
    }

    public function test_pending_credit_cannot_be_spent_elsewhere_and_settlement_clears_only_its_own_reserve(): void
    {
        [$customer] = $this->fixture();
        app(Wallet::class)->credit($customer->id, 'IRT', 8000, 'topup', null);
        app(ArvanTrafficMeter::class)->run($this->snapshot('20000', 2), true);
        try { app(Wallet::class)->debit($customer->id, 'IRT', 7000, 'synthetic_other', null); $this->fail('Pending traffic credit cannot be spent twice'); }
        catch (\App\Services\Finance\WalletException $error) { $this->assertSame('insufficient_funds', $error->errorCode); }
        $this->assertSame(6845, app(Wallet::class)->availableOf($customer->id));
        app(ArvanTrafficMeter::class)->run($this->snapshot('53277', 5), true);
        $this->assertSame(0, app(ArvanTrafficExposure::class)->reservedOf($customer->id));
        $this->assertSame(3000, app(Wallet::class)->availableOf($customer->id));
        $this->assertSame(1, Invoice::count()); $this->assertSame(2, CreditEntry::count());
        $this->assertSame(0, app(ArvanTrafficMeter::class)->run($this->snapshot('53277', 5), true)['total_irt']);
        $this->assertSame(0, app(ArvanTrafficExposure::class)->reservedOf($customer->id));
        Http::assertNothingSent();
    }

    public function test_missing_held_excluded_and_invalid_reports_preserve_existing_pending_credit(): void
    {
        [$customer, , , $snapshot] = $this->fixture();
        app(ArvanTrafficMeter::class)->run($this->snapshot('20000', 2), true);
        $snapshot['reports'][0]['rows'] = []; $snapshot['reports'][0]['total_items'] = 0;
        foreach (['totalItemsPrice', 'totalPrice'] as $field) { $snapshot['reports'][0]['cycle'][$field] = '0'; }
        app(ArvanTrafficMeter::class)->run($snapshot, true);
        $this->assertSame(1155, app(ArvanTrafficExposure::class)->reservedOf($customer->id));
        $this->assertTrue(app(ArvanTrafficMeter::class)->state()['cycles'][self::CYCLE]['pending']);
        $state = app(ArvanTrafficMeter::class)->state(); $state['excluded_customers'][] = $customer->id;
        Setting::putSecret(ArvanTrafficMeter::KEY, json_encode($state, JSON_THROW_ON_ERROR));
        app(ArvanTrafficMeter::class)->run($this->snapshot('30000', 3), true);
        $this->assertSame(1155, app(ArvanTrafficExposure::class)->reservedOf($customer->id));
        $state['excluded_customers'] = []; Setting::putSecret(ArvanTrafficMeter::KEY, json_encode($state, JSON_THROW_ON_ERROR));
        app(ArvanTrafficMeter::class)->run($this->snapshot('9000', 0.9), true);
        $this->assertSame(1155, app(ArvanTrafficExposure::class)->reservedOf($customer->id));
        $snapshot['reports'][0]['cycle']['totalPrice'] = '999';
        try { app(ArvanTrafficMeter::class)->run($snapshot, true); $this->fail('Invalid snapshot must fail'); }
        catch (RuntimeException) {}
        $this->assertSame(1155, app(ArvanTrafficExposure::class)->reservedOf($customer->id));
        $this->assertSame(0, Invoice::count()); Http::assertNothingSent();
    }

    public function test_decreasing_unpaid_proposal_cannot_silently_release_reserved_credit(): void
    {
        [$customer] = $this->fixture();
        app(ArvanTrafficMeter::class)->run($this->snapshot('30000', 3), true);
        try { app(ArvanTrafficMeter::class)->run($this->snapshot('20000', 2), true); $this->fail('Decreasing pending evidence must be reconciled'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('exposure requires reconciliation', $error->getMessage()); }
        $this->assertSame(2310, app(ArvanTrafficExposure::class)->reservedOf($customer->id));
        $this->assertSame(0, Invoice::count()); Http::assertNothingSent();
    }

    public function test_new_resource_pending_owner_is_anchored_before_its_first_paid_checkpoint(): void
    {
        [$owner, $service, $state] = $this->fixture();
        $service->forceFill(['created_at' => Carbon::parse('2026-10-26T00:00:00Z')])->save();
        $state['checkpoints'] = [];
        Setting::putSecret(ArvanTrafficMeter::KEY, json_encode($state, JSON_THROW_ON_ERROR));
        app(ArvanTrafficMeter::class)->run($this->snapshot('20000', 2), true);
        $nextOwner = Customer::create(['email' => 'pending-new-owner@example.test', 'status' => 'active']);
        $service->update(['customer_id' => $nextOwner->id]);
        $result = app(ArvanTrafficMeter::class)->run($this->snapshot('70000', 7), true);
        $this->assertSame('pending_traffic_owner_changed_requires_reconciliation', $result['held'][0]['reason']);
        $this->assertSame(2310, app(ArvanTrafficExposure::class)->reservedOf($owner->id));
        $this->assertSame(0, app(ArvanTrafficExposure::class)->reservedOf($nextOwner->id));
        $this->assertSame(0, Invoice::count());
        $this->assertTrue(app(ArvanTrafficMeter::class)->state()['cycles'][self::CYCLE]['pending']);
        Http::assertNothingSent();
    }

    public function test_higher_cost_with_retracted_pending_usage_window_or_quantity_is_rejected(): void
    {
        [$customer] = $this->fixture();
        app(ArvanTrafficMeter::class)->run($this->snapshot('30000', 3), true);
        foreach (['time', 'quantity', 'repricing'] as $change) {
            $snapshot = $this->snapshot('70000', 7);
            if ($change === 'time') { $snapshot['reports'][0]['rows'][0]['items'][0]['usageTo'] = '2026-11-02T10:00:00Z'; }
            if ($change === 'quantity') { $snapshot['reports'][0]['rows'][0]['items'][0]['amount'] = 2; }
            if ($change === 'repricing') { $snapshot['reports'][0]['rows'][0]['items'][0]['usageTo'] = '2026-11-02T10:20:00Z'; }
            try { app(ArvanTrafficMeter::class)->run($snapshot, true); $this->fail('Retracted pending evidence cannot settle'); }
            catch (RuntimeException $error) { $this->assertStringContainsString('exposure requires reconciliation', $error->getMessage()); }
            $this->assertSame(2310, app(ArvanTrafficExposure::class)->reservedOf($customer->id));
            $this->assertSame(0, Invoice::count());
        }
        Http::assertNothingSent();
    }

    public function test_paying_one_cycle_does_not_unpin_a_missing_unsettled_cycle(): void
    {
        [$customer] = $this->fixture();
        app(ArvanTrafficMeter::class)->run($this->snapshot('20000', 2), true);
        $this->travelTo(Carbon::parse('2026-12-02T12:00:00Z'));
        $snapshot = $this->snapshot();
        $next = $snapshot['reports'][0];
        $nextId = '22345678-1234-1234-1234-123456789abc';
        $next['cycle']['id'] = $nextId;
        $next['cycle']['startedAt'] = '2026-11-22T00:00:00Z';
        $next['cycle']['expiredAt'] = '2026-12-22T00:00:00Z';
        $next['rows'][0]['items'][0]['usageFrom'] = '2026-11-22T00:00:00Z';
        $next['rows'][0]['items'][0]['usageTo'] = '2026-12-02T11:00:00Z';
        $snapshot['reports'][0]['rows'] = []; $snapshot['reports'][0]['total_items'] = 0;
        foreach (['totalItemsPrice', 'totalPrice'] as $field) { $snapshot['reports'][0]['cycle'][$field] = '0'; }
        $snapshot['reports'][] = $next; $snapshot['current_cycle'] = $nextId;
        $snapshot['observed_at'] = '2026-12-02T12:00:00Z';
        app(ArvanTrafficMeter::class)->run($snapshot, true);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1155, app(ArvanTrafficExposure::class)->reservedOf($customer->id));
        $this->assertTrue(app(ArvanTrafficMeter::class)->state()['cycles'][self::CYCLE]['pending']);
        $this->assertFalse(app(ArvanTrafficMeter::class)->state()['cycles'][$nextId]['pending']);
        Http::assertNothingSent();
    }

    public function test_failure_after_exposure_clear_rolls_back_invoice_debit_and_reserve_together(): void
    {
        [$customer] = $this->fixture();
        app(ArvanTrafficMeter::class)->run($this->snapshot('20000', 2), true);
        $state = app(ArvanTrafficMeter::class)->state(); $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired): void {
            if ($fired || !str_contains($query->sql, 'settings') || !preg_match('/^(insert|update)/i', $query->sql)) { return; }
            if (!collect($query->bindings)->contains(fn ($v) => is_string($v) && str_starts_with($v, ArvanTrafficExposure::PREFIX))) { return; }
            foreach ($query->bindings as $value) {
                if (!is_string($value)) { continue; }
                try { $payload = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($value), true); }
                catch (\Throwable) { continue; }
                if (isset($payload['components']) && $payload['components'] === []) {
                    $fired = true; throw new RuntimeException('Synthetic failure after exposure clear');
                }
            }
        });
        try { app(ArvanTrafficMeter::class)->run($this->snapshot('53277', 5), true); $this->fail('Must roll back after reserve clear'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('after exposure clear', $error->getMessage()); }
        $this->assertTrue($fired);
        $this->assertSame(1155, app(ArvanTrafficExposure::class)->reservedOf($customer->id));
        $this->assertSame($state, app(ArvanTrafficMeter::class)->state());
        $this->assertSame(0, Invoice::count()); $this->assertSame(0, Payment::count()); $this->assertSame(0, CreditEntry::count());
        Http::assertNothingSent();
    }

    public function test_4999_is_reserved_without_debit_and_5000_settles_exactly_once(): void
    {
        [$customer, , $state] = $this->fixture();
        $key = array_key_first($state['checkpoints']);
        $state['checkpoints'][$key]['cost_irr'] = 9950;
        $state['checkpoints'][$key]['sell_irt'] = 1045;
        $state['checkpoints'][$key]['tax_irt'] = 105;
        Setting::putSecret(ArvanTrafficMeter::KEY, json_encode($state, JSON_THROW_ON_ERROR));
        $snapshot = $this->snapshot('53229', 5);
        $snapshot['reports'][0]['rows'][0]['items'][0]['usageTo'] = '2026-11-02T10:00:00Z';
        app(ArvanTrafficMeter::class)->run($snapshot, true);
        $this->assertSame(4999, app(ArvanTrafficExposure::class)->reservedOf($customer->id));
        $this->assertSame(0, Invoice::count()); $this->assertSame(0, CreditEntry::count());
        $snapshot = $this->snapshot('53239', 6);
        app(ArvanTrafficMeter::class)->run($snapshot, true);
        $this->assertSame(5000, Invoice::first()->total);
        $this->assertSame(0, app(ArvanTrafficExposure::class)->reservedOf($customer->id));
        $this->assertSame(-5000, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame(0, app(ArvanTrafficMeter::class)->run($snapshot, true)['total_irt']);
        $this->assertSame(1, CreditEntry::count()); Http::assertNothingSent();
    }

    public function test_pausing_new_exposure_writes_keeps_existing_reserves_and_can_settle_them(): void
    {
        [$customer] = $this->fixture();
        app(ArvanTrafficMeter::class)->run($this->snapshot('20000', 2), true);
        Setting::put('arvan_traffic_exposure_enabled_v1', '0');
        app(ArvanTrafficMeter::class)->run($this->snapshot('30000', 3), true);
        $this->assertSame(1155, app(ArvanTrafficExposure::class)->reservedOf($customer->id));
        app(ArvanTrafficMeter::class)->run($this->snapshot('53277', 5), true);
        $this->assertSame(0, app(ArvanTrafficExposure::class)->reservedOf($customer->id));
        $this->assertSame(1, Invoice::count()); Http::assertNothingSent();
    }

    public function test_pending_reserve_is_enforced_when_ai_reservation_schema_is_absent(): void
    {
        [$customer] = $this->fixture();
        app(ArvanTrafficMeter::class)->run($this->snapshot('20000', 2), true);
        Wallet::flushSchemaCache();
        $schema = \Illuminate\Support\Facades\Schema::getFacadeRoot();
        \Illuminate\Support\Facades\Schema::shouldReceive('hasTable')->andReturnUsing(
            fn (string $table): bool => $table !== 'ai_reservations' && $schema->hasTable($table)
        );
        $this->assertSame(1155, app(Wallet::class)->reservedOf($customer->id));
        $this->assertSame(0, app(Wallet::class)->reservedOf($customer->id, 'EUR'));
        Http::assertNothingSent();
    }
}
