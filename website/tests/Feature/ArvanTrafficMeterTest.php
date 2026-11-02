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
        config(['billing.tax_percent' => 10]);
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

    private function snapshot(string $cost = '20000', float $quantity = 2): array
    {
        $row = ['instanceId' => self::NATIVE, 'region' => 'ir-test', 'scope' => 'instance', 'productName' => 'iaas',
            'totalPrice' => $cost, 'totalDiscount' => '0', 'totalUsedGift' => '0', 'netPrice' => $cost,
            'items' => [['itemId' => 'synthetic-internet', 'metric' => 'cc_internet', 'amount' => $quantity,
                'price' => $cost, 'discount' => '0', 'usedGift' => '0',
                'usageFrom' => '2026-10-28T00:00:00Z', 'usageTo' => '2026-11-02T11:00:00Z']]];
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
        $this->assertSame(1155, $result['total_irt']);
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
        $this->assertSame(1155, $first['total_irt']);
        $this->assertSame(0, $second['total_irt']);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, CreditEntry::count());
        $this->assertSame(2, BusinessEntry::count());
        $this->assertSame(-1155, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame($services, Service::all()->map->getRawOriginal()->all());
        $this->assertSame($instances, CloudInstance::all()->map->getRawOriginal()->all());
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
        $this->assertSame('0 */2 * * *', $events[0]->expression);
        $this->assertSame('Asia/Tehran', $events[0]->timezone);
        $this->assertStringContainsString('--apply', $events[0]->command);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertTrue($events[0]->runInBackground);
        Http::assertNothingSent();
    }
}
