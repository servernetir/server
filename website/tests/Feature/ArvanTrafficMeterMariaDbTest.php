<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Finance\ArvanTrafficMeter;
use App\Services\Finance\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** Two real connections to the opted-in disposable CI MariaDB; no production source or provider calls. */
class ArvanTrafficMeterMariaDbTest extends TestCase
{
    private bool $disposableReady = false;

    /** No migration trait: validate the disposable target before ANY migration. */
    protected function setUp(): void
    {
        parent::setUp();
        $cfg = config('database.connections.'.config('database.default'));
        if (($cfg['driver'] ?? null) === 'sqlite') {
            $this->markTestSkipped('This committed two-connection test requires the opted-in disposable MariaDB job.');
        }
        $this->assertSame('testing', app()->environment());
        $this->assertContains($cfg['driver'], ['mysql', 'mariadb']);
        $this->assertSame('127.0.0.1', $cfg['host']);
        $this->assertSame('incident_testing', $cfg['database']);
        $this->assertSame('1', getenv('ARVAN_TRAFFIC_MARIADB'), 'Explicit disposable MariaDB test opt-in required.');
        $this->disposableReady = true;
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
        RefreshDatabaseState::$migrated = false;
    }

    protected function tearDown(): void
    {
        try {
            if ($this->disposableReady && $this->app) {
                // Legacy unrelated migration down() paths are not reliable on
                // MariaDB. Reset only this explicitly validated disposable DB
                // using the same fresh-schema path as the rest of CI.
                $cfg = config('database.connections.'.config('database.default'));
                $this->assertSame('127.0.0.1', $cfg['host']);
                $this->assertSame('incident_testing', $cfg['database']);
                $this->assertSame('testing', app()->environment());
                $this->assertSame('1', getenv('ARVAN_TRAFFIC_MARIADB'));
                $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
            }
        } finally {
            RefreshDatabaseState::$migrated = false;
            parent::tearDown();
        }
    }

    public function test_committed_manual_debit_between_snapshot_and_customer_lock_is_seen_by_current_read(): void
    {
        $cfg = config('database.connections.'.config('database.default'));
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-11-02T12:00:00Z'));
        Setting::put('cloud_margin_pct', '5');
        config(['billing.tax_percent' => 10]);
        Wallet::flushSchemaCache();
        $customer = Customer::create(['email' => 'committed-manual@example.test', 'status' => 'active']);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'Synthetic committed manual race',
            'currency_code' => 'IRT', 'cycle' => 'monthly', 'billing_mode' => 'cycle', 'status' => 'active', 'price' => 1000,
            'created_at' => Carbon::parse('2026-10-24T00:00:00Z')]);
        $cycle = '12345678-1234-1234-1234-123456789abc';
        $native = '00000000-0000-0000-0000-000000000001';
        $resource = 'ir-test:'.$native;
        CloudInstance::create(['service_id' => $service->id, 'provider' => 'arvan', 'provider_ref' => $resource, 'status' => 'running']);
        $checkpoint = ['service_id' => $service->id, 'customer_id' => $customer->id, 'resource' => $resource,
            'item_id' => 'synthetic-internet', 'from' => '2026-10-28T00:00:00Z', 'to' => '2026-11-01T00:00:00Z',
            'gb_display' => '1.000000', 'cost_irr' => 10000, 'sell_irt' => 1050, 'tax_irt' => 105];
        $state = ['version' => 1, 'enabled' => true, 'authorization' => 'Synthetic committed fixture baseline',
            'authorized_at' => '2026-10-25T00:00:00Z', 'baseline_paid_at' => '2026-10-25T00:00:00Z',
            'baseline_hash' => str_repeat('a', 64), 'margin_bp' => 500, 'tax_bp' => 1000, 'excluded_customers' => [],
            'sequence' => 0, 'cycles' => [$cycle => ['account_hash' => hash('sha256', 'synthetic-account'),
                'started_at' => '2026-10-23T00:00:00Z', 'expired_at' => '2026-11-22T00:00:00Z']],
            'checkpoints' => [$cycle.'|'.$resource => $checkpoint], 'last_success' => null];
        Setting::putSecret(ArvanTrafficMeter::KEY, json_encode($state, JSON_THROW_ON_ERROR));
        $item = ['itemId' => 'synthetic-internet', 'metric' => 'cc_internet', 'amount' => 2,
            'price' => '70000', 'discount' => '0', 'usedGift' => '0',
            'usageFrom' => '2026-10-28T00:00:00Z', 'usageTo' => '2026-11-02T11:00:00Z'];
        $row = ['instanceId' => $native, 'region' => 'ir-test', 'scope' => 'instance', 'productName' => 'iaas',
            'totalPrice' => '70000', 'totalDiscount' => '0', 'totalUsedGift' => '0', 'netPrice' => '70000', 'items' => [$item]];
        $cycleData = ['id' => $cycle, 'accountId' => 'synthetic-account', 'currency' => 'irr',
            'startedAt' => '2026-10-23T00:00:00Z', 'expiredAt' => '2026-11-22T00:00:00Z',
            'totalItemsPrice' => '70000', 'totalItemsDiscount' => '0', 'totalUsedGift' => '0', 'totalVatPrice' => '0', 'totalPrice' => '70000'];
        $snapshot = ['observed_at' => '2026-11-02T12:00:00Z', 'current_cycle' => $cycle,
            'reports' => [['cycle' => $cycleData, 'rows' => [$row], 'total_items' => 1]]];

        $connection = DB::connection();
        // Explicitly reproduce the stronger isolation that makes a plain
        // recheck stale. Session-only setting on this disposable connection.
        $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        config(['database.connections.traffic_manual_writer' => $cfg]);
        DB::purge('traffic_manual_writer');
        $writer = DB::connection('traffic_manual_writer');
        $fired = false;
        $connection->beforeExecuting(function (string $sql, array $bindings) use (&$fired, $writer, $customer): void {
            if (!$fired && str_contains(strtolower($sql), 'for update')
                && preg_match('/\Aselect \* from [`"]customers[`"] where [`"]customers[`"]\.[`"]id[`"] = \?/i', $sql)
                && in_array($customer->id, $bindings, true)) {
                $fired = true;
                $writer->transaction(function () use ($writer, $customer): void {
                    $writer->table('customers')->where('id', $customer->id)->lockForUpdate()->first();
                    $writer->table('credit_ledger')->insert(['customer_id' => $customer->id, 'currency_code' => 'IRT',
                        'amount' => -900, 'balance_after' => -900, 'reason' => 'manual_traffic',
                        'source_type' => Customer::class, 'source_id' => $customer->id, 'note' => 'Synthetic independently committed traffic debit',
                        'created_at' => now(), 'updated_at' => now()]);
                });
            }
        });
        try {
            app(ArvanTrafficMeter::class)->run($snapshot, true);
            $this->fail('Committed manual traffic debit must be seen despite the old repeatable-read snapshot');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('changed during lock acquisition', $error->getMessage());
        } finally {
            DB::purge('traffic_manual_writer');
        }
        $this->assertTrue($fired, 'The second connection must commit at the intended lock boundary');
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(1, CreditEntry::count(), 'The independent manual debit must survive the meter rollback');
        $this->assertSame(-900, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame($state, app(ArvanTrafficMeter::class)->state());
        Http::assertNothingSent();
    }
    public function test_current_wallet_sees_topup_and_live_hourly_reserve_committed_after_old_snapshot(): void
    {
        Http::preventStrayRequests();
        Wallet::flushSchemaCache();
        $cfg = config('database.connections.'.config('database.default'));
        $customer = Customer::create(['email' => 'current-wallet@example.test', 'status' => 'active']);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'Synthetic reserve race',
            'currency_code' => 'IRT', 'billing_mode' => 'hourly', 'status' => 'active', 'hold_reserve_irt' => 100]);
        app(Wallet::class)->credit($customer->id, 'IRT', 100, 'topup', null);
        config(['database.connections.traffic_funds_writer' => $cfg]);
        DB::purge('traffic_funds_writer');
        $writer = DB::connection('traffic_funds_writer');
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        try {
            DB::transaction(function () use ($customer, $service, $writer): void {
                $wallet = app(Wallet::class);
                $this->assertSame(0, $wallet->availableOf($customer->id), 'Establish the old consistent snapshot');
                $writer->transaction(function () use ($writer, $customer, $service): void {
                    $writer->table('customers')->where('id', $customer->id)->lockForUpdate()->first();
                    $writer->table('services')->where('id', $service->id)->update(['hold_reserve_irt' => 200]);
                    $writer->table('credit_ledger')->insert(['customer_id' => $customer->id, 'currency_code' => 'IRT',
                        'amount' => 101, 'balance_after' => 201, 'reason' => 'topup', 'created_at' => now(), 'updated_at' => now()]);
                });
                Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
                $this->assertSame(0, $wallet->availableOf($customer->id), 'Plain reread retains the old snapshot');
                $this->assertSame(1, $wallet->availableOf($customer->id, currentRead: true),
                    'Current spendable must include both independently committed topup and live reserve');
            });
        } finally {
            DB::purge('traffic_funds_writer');
        }
        Http::assertNothingSent();
    }

    public function test_current_wallet_sees_independently_committed_nontraffic_debit_after_old_snapshot(): void
    {
        Http::preventStrayRequests();
        Wallet::flushSchemaCache();
        $cfg = config('database.connections.'.config('database.default'));
        $customer = Customer::create(['email' => 'current-debit@example.test', 'status' => 'active']);
        app(Wallet::class)->credit($customer->id, 'IRT', 1, 'topup', null);
        config(['database.connections.traffic_funds_writer' => $cfg]);
        DB::purge('traffic_funds_writer');
        $writer = DB::connection('traffic_funds_writer');
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        try {
            DB::transaction(function () use ($customer, $writer): void {
                $wallet = app(Wallet::class);
                $this->assertSame(1, $wallet->availableOf($customer->id));
                $writer->transaction(function () use ($writer, $customer): void {
                    $writer->table('customers')->where('id', $customer->id)->lockForUpdate()->first();
                    $writer->table('credit_ledger')->insert(['customer_id' => $customer->id, 'currency_code' => 'IRT',
                        'amount' => -1, 'balance_after' => 0, 'reason' => 'synthetic_usage', 'created_at' => now(), 'updated_at' => now()]);
                });
                Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
                $this->assertSame(1, $wallet->availableOf($customer->id));
                $this->assertSame(0, $wallet->availableOf($customer->id, currentRead: true),
                    'Committed ordinary debit must not be hidden by the planning snapshot');
            });
        } finally {
            DB::purge('traffic_funds_writer');
        }
        Http::assertNothingSent();
    }

    public function test_meter_uses_current_spendable_after_committed_topup_and_reserve_race(): void
    {
        $cfg = config('database.connections.'.config('database.default'));
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-11-02T12:00:00Z'));
        Setting::put('cloud_margin_pct', '5');
        config(['billing.tax_percent' => 10]);
        Wallet::flushSchemaCache();
        $customer = Customer::create(['email' => 'committed-meter-funding@example.test', 'status' => 'active']);
        $service = Service::create(['customer_id' => $customer->id, 'name' => 'Synthetic committed manual race',
            'currency_code' => 'IRT', 'cycle' => 'monthly', 'billing_mode' => 'cycle', 'status' => 'active', 'price' => 1000,
            'created_at' => Carbon::parse('2026-10-24T00:00:00Z')]);
        $cycle = '12345678-1234-1234-1234-123456789abc';
        $native = '00000000-0000-0000-0000-000000000001';
        $resource = 'ir-test:'.$native;
        CloudInstance::create(['service_id' => $service->id, 'provider' => 'arvan', 'provider_ref' => $resource, 'status' => 'running']);
        $checkpoint = ['service_id' => $service->id, 'customer_id' => $customer->id, 'resource' => $resource,
            'item_id' => 'synthetic-internet', 'from' => '2026-10-28T00:00:00Z', 'to' => '2026-11-01T00:00:00Z',
            'gb_display' => '1.000000', 'cost_irr' => 10000, 'sell_irt' => 1050, 'tax_irt' => 105];
        $state = ['version' => 1, 'enabled' => true, 'authorization' => 'Synthetic committed fixture baseline',
            'authorized_at' => '2026-10-25T00:00:00Z', 'baseline_paid_at' => '2026-10-25T00:00:00Z',
            'baseline_hash' => str_repeat('a', 64), 'margin_bp' => 500, 'tax_bp' => 1000, 'excluded_customers' => [],
            'sequence' => 0, 'cycles' => [$cycle => ['account_hash' => hash('sha256', 'synthetic-account'),
                'started_at' => '2026-10-23T00:00:00Z', 'expired_at' => '2026-11-22T00:00:00Z']],
            'checkpoints' => [$cycle.'|'.$resource => $checkpoint], 'last_success' => null];
        Setting::putSecret(ArvanTrafficMeter::KEY, json_encode($state, JSON_THROW_ON_ERROR));
        $item = ['itemId' => 'synthetic-internet', 'metric' => 'cc_internet', 'amount' => 2,
            'price' => '70000', 'discount' => '0', 'usedGift' => '0',
            'usageFrom' => '2026-10-28T00:00:00Z', 'usageTo' => '2026-11-02T11:00:00Z'];
        $row = ['instanceId' => $native, 'region' => 'ir-test', 'scope' => 'instance', 'productName' => 'iaas',
            'totalPrice' => '70000', 'totalDiscount' => '0', 'totalUsedGift' => '0', 'netPrice' => '70000', 'items' => [$item]];
        $cycleData = ['id' => $cycle, 'accountId' => 'synthetic-account', 'currency' => 'irr',
            'startedAt' => '2026-10-23T00:00:00Z', 'expiredAt' => '2026-11-22T00:00:00Z',
            'totalItemsPrice' => '70000', 'totalItemsDiscount' => '0', 'totalUsedGift' => '0', 'totalVatPrice' => '0', 'totalPrice' => '70000'];
        $snapshot = ['observed_at' => '2026-11-02T12:00:00Z', 'current_cycle' => $cycle,
            'reports' => [['cycle' => $cycleData, 'rows' => [$row], 'total_items' => 1]]];


        $service->update(['billing_mode' => 'hourly', 'hold_reserve_irt' => 0]);
        $connection = DB::connection();
        $connection->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        config(['database.connections.traffic_funds_writer' => $cfg]);
        DB::purge('traffic_funds_writer');
        $writer = DB::connection('traffic_funds_writer');
        $fired = false;
        $connection->beforeExecuting(function (string $sql, array $bindings) use (&$fired, $writer, $customer, $service): void {
            if (! $fired && str_contains(strtolower($sql), 'for update')
                && preg_match('/\Aselect \* from [`"]customers[`"] where [`"]customers[`"]\.[`"]id[`"] = \?/i', $sql)
                && in_array($customer->id, $bindings, true)) {
                $fired = true;
                $writer->transaction(function () use ($writer, $customer, $service): void {
                    $writer->table('customers')->where('id', $customer->id)->lockForUpdate()->first();
                    $writer->table('services')->where('id', $service->id)->update(['hold_reserve_irt' => 700]);
                    $writer->table('credit_ledger')->insert(['customer_id' => $customer->id, 'currency_code' => 'IRT',
                        'amount' => 7631, 'balance_after' => 7631, 'reason' => 'topup', 'created_at' => now(), 'updated_at' => now()]);
                });
            }
        });
        try {
            app(ArvanTrafficMeter::class)->run($snapshot, true);
        } finally {
            DB::purge('traffic_funds_writer');
        }
        $this->assertTrue($fired, 'Writer must commit after the supplier planning snapshot and before the customer lock');
        $this->assertSame(1, app(Wallet::class)->availableOf($customer->id));
        $this->assertFalse(\App\Services\Finance\ArvanTrafficCreditGuard::active($service->fresh()),
            'Meter must not create a traffic hold when current spendable remains positive');
        $this->assertSame(1, Invoice::count());
        $this->assertSame(2, CreditEntry::count());
        Http::assertNothingSent();
    }


    public function test_ordinary_debit_sees_new_exposure_and_ledger_commit_after_old_snapshot(): void
    {
        Http::preventStrayRequests();
        Wallet::flushSchemaCache();
        $cfg = config('database.connections.'.config('database.default'));
        $customer = Customer::create(['email' => 'pending-exposure-race@example.test', 'status' => 'active']);
        app(Wallet::class)->credit($customer->id, 'IRT', 10000, 'topup', null);
        config(['database.connections.traffic_exposure_writer' => $cfg]);
        DB::purge('traffic_exposure_writer');
        $writer = DB::connection('traffic_exposure_writer');
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $key = \App\Services\Finance\ArvanTrafficExposure::PREFIX.$customer->id;
        $payload = ['version' => 1, 'customer_id' => $customer->id, 'currency' => 'IRT',
            'components' => ['synthetic-cycle|synthetic-resource' => [
                'base_sha256' => str_repeat('a', 64), 'source_hash' => str_repeat('b', 64),
                'amount_irt' => 1155, 'next' => ['customer_id' => $customer->id, 'service_id' => 1,
                    'cost_irr' => 20000, 'sell_irt' => 2100, 'tax_irt' => 210]]]];
        $cipher = \Illuminate\Support\Facades\Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
        try {
            DB::transaction(function () use ($customer, $writer, $key, $cipher): void {
                $wallet = app(Wallet::class);
                $this->assertSame(10000, $wallet->availableOf($customer->id), 'Establish an old snapshot with no exposure row');
                $writer->transaction(function () use ($writer, $customer, $key, $cipher): void {
                    $writer->table('customers')->where('id', $customer->id)->lockForUpdate()->first();
                    $writer->table('settings')->insert(['key' => $key, 'value' => $cipher, 'created_at' => now(), 'updated_at' => now()]);
                    $writer->table('credit_ledger')->insert(['customer_id' => $customer->id, 'currency_code' => 'IRT',
                        'amount' => -500, 'balance_after' => 9500, 'reason' => 'synthetic_other_usage',
                        'created_at' => now(), 'updated_at' => now()]);
                });
                $this->assertSame(10000, $wallet->availableOf($customer->id), 'Plain reads still see the old snapshot');
                try {
                    $wallet->debit($customer->id, 'IRT', 9000, 'synthetic_purchase', null);
                    $this->fail('Ordinary debit must protect newly committed pending traffic');
                } catch (\App\Services\Finance\WalletException $error) {
                    $this->assertSame('insufficient_funds', $error->errorCode);
                }
                $this->assertSame(8345, $wallet->availableOf($customer->id, currentRead: true));
                $wallet->debit($customer->id, 'IRT', 8345, 'synthetic_affordable_purchase', null);
                $this->assertSame(0, $wallet->availableOf($customer->id, currentRead: true));
            });
        } finally {
            DB::purge('traffic_exposure_writer');
        }
        $this->assertSame(1155, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame(1155, app(\App\Services\Finance\ArvanTrafficExposure::class)->reservedOf($customer->id));
        $this->assertSame(0, CreditEntry::where('reason', 'synthetic_purchase')->count());
        $this->assertSame(3, CreditEntry::count());
        Http::assertNothingSent();
    }

}
