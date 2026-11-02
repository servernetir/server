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
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** Two real connections to the opted-in disposable CI MariaDB; no production source or provider calls. */
class ArvanTrafficMeterMariaDbTest extends TestCase
{
    use DatabaseMigrations;

    public function test_committed_manual_debit_between_snapshot_and_customer_lock_is_seen_by_current_read(): void
    {
        $cfg = config('database.connections.'.config('database.default'));
        $this->assertContains($cfg['driver'], ['mysql', 'mariadb']);
        $this->assertSame('127.0.0.1', $cfg['host']);
        $this->assertSame('incident_testing', $cfg['database']);
        $this->assertSame('testing', app()->environment());
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
            'price' => '20000', 'discount' => '0', 'usedGift' => '0',
            'usageFrom' => '2026-10-28T00:00:00Z', 'usageTo' => '2026-11-02T11:00:00Z'];
        $row = ['instanceId' => $native, 'region' => 'ir-test', 'scope' => 'instance', 'productName' => 'iaas',
            'totalPrice' => '20000', 'totalDiscount' => '0', 'totalUsedGift' => '0', 'netPrice' => '20000', 'items' => [$item]];
        $cycleData = ['id' => $cycle, 'accountId' => 'synthetic-account', 'currency' => 'irr',
            'startedAt' => '2026-10-23T00:00:00Z', 'expiredAt' => '2026-11-22T00:00:00Z',
            'totalItemsPrice' => '20000', 'totalItemsDiscount' => '0', 'totalUsedGift' => '0', 'totalVatPrice' => '0', 'totalPrice' => '20000'];
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
}
