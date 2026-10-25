<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\CloudInstance;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Finance\ArvanTrafficSettlement;
use App\Services\Finance\BusinessLedger;
use App\Services\Finance\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ArvanTrafficSettlementTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->travelTo(now()->setDate(2026, 10, 25)->setTime(12, 0));
        $c = Customer::create(['email' => 'traffic-fixture@example.com', 'status' => 'active', 'locale' => 'fa']);
        $data = ['observed_at' => '2026-10-25T10:00:00Z', 'billing' => ['usage_status' => 200, 'cycle_status' => 200,
            'cycle' => ['data' => ['id' => '12345678-1234-1234-1234-123456789abc', 'currency' => 'irr',
                'startedAt' => '2026-10-23T00:00:00Z', 'expiredAt' => '2026-11-22T00:00:00Z']],
            'usage' => ['data' => [], 'meta' => ['pagination' => ['totalPages' => 1, 'totalItems' => 3]]]], 'traffic_attribution' => []];
        foreach (['123457', '30001'] as $n => $cost) {
            $s = Service::create(['customer_id' => $c->id, 'name' => 'Fixture server', 'currency_code' => 'IRT',
                'status' => 'active', 'cycle' => 'monthly', 'billing_mode' => $n ? 'cycle' : 'hourly',
                'price' => 1000, 'hourly_price_irt' => 100, 'hold_reserve_irt' => 1200,
                'next_due_at' => now()->addMonth(), 'on_credit_out' => 'suspend']);
            $native = '00000000-0000-0000-0000-00000000000'.$n;
            CloudInstance::create(['service_id' => $s->id, 'provider' => 'arvan', 'provider_ref' => 'ir-test:'.$native,
                'status' => 'running', 'ipv4' => '192.0.2.'.($n + 1)]);
            $data['billing']['usage']['data'][] = ['instanceId' => $native, 'scope' => 'instance', 'productName' => 'iaas', 'region' => 'ir-test',
                'items' => [['itemId' => 'item-'.$n, 'metric' => 'cc_internet', 'amount' => 6.2, 'price' => $cost, 'discount' => '0', 'usedGift' => '0',
                    'usageFrom' => '2026-10-25T06:00:00Z', 'usageTo' => '2026-10-25T08:00:00Z']]];
            $data['traffic_attribution'][] = ['service_id' => $s->id, 'customer_id' => $c->id, 'provider_instance_id' => $native,
                'matched' => true, 'price_irr' => $cost, 'discount_irr' => '0', 'net_irt' => 999999999.99,
                'usage_from' => '2026-10-25T06:00:00Z', 'usage_to' => '2026-10-25T08:00:00Z'];
        }
        // A much larger tenant cost has no owner and must never enter this settlement.
        $data['billing']['usage']['data'][] = ['instanceId' => 'tenant', 'scope' => 'tenant', 'productName' => 'iaas',
            'items' => [['metric' => 'cc_internet', 'amount' => 99999, 'price' => '900000000', 'discount' => '0']]];

        return [$c, $data];
    }

    private function plan(array $data, int $tax = 1000): array
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);

        return app(ArvanTrafficSettlement::class)->plan($json, hash('sha256', $json), 500, $tax);
    }

    private function apply(array $p): array
    {
        return app(ArvanTrafficSettlement::class)->apply($p, $p['hash'], 'Explicit fixture owner authorization');
    }

    public function test_source_irr_integer_rounding_and_tax_ignore_float_display_and_tenant_cost(): void
    {
        [, $data] = $this->fixture();
        $p = $this->plan($data);
        $this->assertSame([12963, 3151], array_column($p['lines'], 'sell_irt'));
        $this->assertSame([1296, 315], array_column($p['lines'], 'tax_irt'));
        $this->assertSame(153458, array_sum(array_column($p['lines'], 'cost_irr')));
    }

    public function test_owner_debt_settlement_has_invoice_tax_and_one_debit_without_touching_services(): void
    {
        [$c, $data] = $this->fixture();
        app(Wallet::class)->credit($c->id, 'IRT', 1000, 'topup', $c);
        $services = Service::all()->map->getRawOriginal()->all();
        $instances = CloudInstance::all()->map->getRawOriginal()->all();
        $out = $this->apply($this->plan($data));
        $this->assertSame(-16725, app(Wallet::class)->balanceOf($c->id));
        $this->assertSame(17725, $out[0]['total_irt']);
        $this->assertFalse($out[0]['replayed']);
        $invoice = Invoice::firstOrFail();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(16114, $invoice->subtotal);
        $this->assertSame(1611, $invoice->tax);
        $this->assertNull($invoice->service_id);
        $this->assertSame(2, $invoice->items()->count());
        $this->assertStringNotContainsString('arvan', $invoice->toJson().$invoice->items->toJson());
        $this->assertSame('credit', Payment::first()->gateway);
        $this->assertSame(16114, (int) BusinessEntry::where('kind', 'revenue')->sum('amount'));
        $this->assertSame(1611, (int) BusinessEntry::where('kind', 'tax_collected')->sum('amount'));
        $this->assertSame(2, BusinessEntry::count());
        $this->assertSame($services, Service::all()->map->getRawOriginal()->all());
        $this->assertSame($instances, CloudInstance::all()->map->getRawOriginal()->all());
        $this->assertSame(1, DB::table('finance_audit_events')->where('action', 'owner_traffic_settlement')->count());
    }

    public function test_positive_balance_is_deducted_and_not_overwritten_with_negative_total(): void
    {
        [$c, $data] = $this->fixture();
        app(Wallet::class)->credit($c->id, 'IRT', 50000, 'topup', $c);
        $this->apply($this->plan($data));
        $this->assertSame(32275, app(Wallet::class)->balanceOf($c->id));
    }

    public function test_exact_retry_does_not_double_charge_and_changed_snapshot_for_cycle_is_rejected(): void
    {
        [$c, $data] = $this->fixture();
        $p = $this->plan($data);
        $first = $this->apply($p);
        $second = $this->apply($p);
        $this->assertTrue($second[0]['replayed']);
        $this->assertSame($first[0]['invoice_id'], $second[0]['invoice_id']);
        $this->assertSame(1, CreditEntry::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(2, BusinessEntry::count());
        $this->assertSame(-17725, app(Wallet::class)->balanceOf($c->id));
        $data['observed_at'] = '2026-10-25T10:01:00Z';
        $this->expectExceptionMessage('another snapshot');
        $this->apply($this->plan($data));
    }

    public function test_incomplete_pagination_or_tenant_attribution_cannot_be_charged(): void
    {
        [, $data] = $this->fixture();
        $data['billing']['usage']['meta']['pagination']['totalPages'] = 2;
        try {
            $this->plan($data);
            $this->fail('Incomplete source was accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('pagination', $e->getMessage());
        }
        $data['billing']['usage']['meta']['pagination']['totalPages'] = 1;
        $data['billing']['usage']['data'][0]['scope'] = 'tenant';
        $this->expectExceptionMessage('unique instance');
        $this->plan($data);
    }

    public function test_changed_hash_wrong_owner_and_prior_manual_charge_are_refused(): void
    {
        [$c, $data] = $this->fixture();
        $p = $this->plan($data);
        try {
            app(ArvanTrafficSettlement::class)->apply($p, str_repeat('0', 64), 'Explicit owner authorization');
            $this->fail('Wrong hash accepted');
        } catch (RuntimeException $e) {
            $this->assertSame('Plan changed', $e->getMessage());
        }
        $other = Customer::create(['email' => 'other@example.com', 'status' => 'active']);
        Service::whereKey($p['lines'][0]['service_id'])->update(['customer_id' => $other->id]);
        try {
            $this->apply($p);
            $this->fail('Wrong owner accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ownership', $e->getMessage());
        }
        Service::whereKey($p['lines'][0]['service_id'])->update(['customer_id' => $c->id]);
        app(Wallet::class)->debit($c->id, 'IRT', 1, 'manual_traffic', $c, requireAvailable: false);
        $this->expectExceptionMessage('Prior traffic charge');
        $this->apply($p);
    }

    public function test_accounting_failure_rolls_back_every_invoice_payment_and_debit(): void
    {
        [$c, $data] = $this->fixture();
        // Force the failure AFTER the wallet write, at the accounting boundary.
        $ledger = \Mockery::mock(BusinessLedger::class)->makePartial();
        $ledger->shouldReceive('recordPayment')->once()->andThrow(new RuntimeException('accounting unavailable'));
        $this->app->instance(BusinessLedger::class, $ledger);
        try {
            $this->apply($this->plan($data));
            $this->fail('Accounting failure was swallowed');
        } catch (RuntimeException $e) {
            $this->assertSame('accounting unavailable', $e->getMessage());
        }
        $this->assertSame(0, app(Wallet::class)->balanceOf($c->id));
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, CreditEntry::count());
        $this->assertSame(0, BusinessEntry::count());
    }

    public function test_tampered_retry_stops_without_another_debit(): void
    {
        [, $data] = $this->fixture();
        $p = $this->plan($data);
        $this->apply($p);
        CreditEntry::query()->update(['amount' => -1]);
        $this->expectExceptionMessage('debit missing or changed');
        $this->apply($p);
    }

    public function test_zero_tax_has_one_revenue_record_and_no_fabricated_vat(): void
    {
        [, $data] = $this->fixture();
        $out = $this->apply($this->plan($data, 0));
        $this->assertSame(16114, $out[0]['total_irt']);
        $this->assertSame(0, $out[0]['tax_irt']);
        $this->assertSame(1, BusinessEntry::count());
    }

    public function test_failure_in_second_customer_rolls_back_the_already_written_first_customer(): void
    {
        [$first, $data] = $this->fixture();
        $second = Customer::create(['email' => 'second@example.com', 'status' => 'active']);
        $data['traffic_attribution'][1]['customer_id'] = $second->id;
        Service::whereKey($data['traffic_attribution'][1]['service_id'])->update(['customer_id' => $second->id]);
        $real = new BusinessLedger;
        $calls = 0;
        $ledger = \Mockery::mock(BusinessLedger::class)->makePartial();
        $ledger->shouldReceive('recordPayment')->twice()->andReturnUsing(function ($p) use ($real, &$calls) {
            if (++$calls === 2) {
                throw new RuntimeException('second customer failure');
            }
            $real->recordPayment($p);
        });
        $this->app->instance(BusinessLedger::class, $ledger);
        try {
            $this->apply($this->plan($data));
            $this->fail('Partial batch committed');
        } catch (RuntimeException $e) {
            $this->assertSame('second customer failure', $e->getMessage());
        }
        foreach ([$first, $second] as $c) {
            $this->assertSame(0, app(Wallet::class)->balanceOf($c->id));
        }
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, BusinessEntry::count());
        $this->assertSame(0, DB::table('finance_audit_events')->where('action', 'owner_traffic_settlement')->count());
    }

    public function test_snapshot_hash_and_negative_supplier_cost_are_rejected(): void
    {
        [, $data] = $this->fixture();
        try {
            app(ArvanTrafficSettlement::class)->plan(json_encode($data), str_repeat('0', 64), 500, 1000);
            $this->fail('Changed snapshot accepted');
        } catch (RuntimeException $e) {
            $this->assertSame('Snapshot hash mismatch', $e->getMessage());
        }
        $data['billing']['usage']['data'][0]['items'][0]['price'] = '-100';
        $this->expectExceptionMessage('Invalid integer IRR');
        $this->plan($data);
    }

    public function test_live_resource_reassignment_is_refused(): void
    {
        [, $data] = $this->fixture();
        $p = $this->plan($data);
        CloudInstance::query()->update(['provider_ref' => 'ir-test:replacement']);
        $this->expectExceptionMessage('ownership/resource mismatch');
        $this->apply($p);
    }

    public function test_cli_preview_is_read_only_and_apply_requires_the_reviewed_hash(): void
    {
        [, $data] = $this->fixture();
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $file = tempnam(sys_get_temp_dir(), 'traffic-test-');
        file_put_contents($file, $json);
        try {
            $args = ['snapshot' => $file, '--source-hash' => hash('sha256', $json), '--markup-bp' => 500, '--tax-bp' => 1000];
            $this->artisan('finance:settle-reviewed-traffic', $args)->assertSuccessful();
            $this->assertSame(0, Invoice::count());
            $this->assertSame(0, CreditEntry::count());
            Setting::put('cloud_margin_pct', '5');
            $this->artisan('finance:settle-reviewed-traffic', $args + ['--apply' => true, '--authorization' => 'Explicit fixture owner authorization'])->assertFailed();
            $this->assertSame(0, CreditEntry::count());
        } finally {
            unlink($file);
        }
    }
}
