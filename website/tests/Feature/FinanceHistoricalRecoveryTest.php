<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Service;
use App\Services\Finance\BusinessLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class FinanceHistoricalRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function source(): array
    {
        $c = Customer::create(['email' => 'history@test.invalid', 'password' => 'test', 'status' => 'active']);
        $s = Service::create(['customer_id' => $c->id, 'name' => 'Hourly', 'currency_code' => 'IRT', 'price' => 100, 'cycle' => 'monthly', 'status' => 'cancelled', 'billing_mode' => 'hourly', 'hourly_rate_irt' => 100]);

        return [$c, $s];
    }

    public function test_direct_hourly_refund_writers_reverse_revenue_once_without_touching_wallet_twice(): void
    {
        [$c,$s] = $this->source();
        $debit = CreditEntry::create(['customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => -100, 'balance_after' => 0, 'reason' => 'cloud_hourly_hold', 'source_type' => Service::class, 'source_id' => $s->id]);
        app(BusinessLedger::class)->recordCreditSpend($debit);
        $credit = CreditEntry::create(['customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => 100, 'balance_after' => 100, 'reason' => 'cloud_hourly_refund', 'source_type' => Service::class, 'source_id' => $s->id]);
        app(BusinessLedger::class)->recordCreditSpend($credit);
        $this->assertSame(0, app(BusinessLedger::class)->summary()['revenue']);
        $this->assertSame(1, BusinessEntry::where('kind', 'hourly_refund')->count());
        $this->assertSame(2, CreditEntry::count());
    }

    public function test_historical_recovery_previews_then_requires_an_exact_plan_hash_and_never_changes_source_balances(): void
    {
        [$c,$s] = $this->source();
        CreditEntry::create(['customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => -100, 'balance_after' => 0, 'reason' => 'cloud_hourly_hold', 'source_type' => Service::class, 'source_id' => $s->id]);
        $d = now('Asia/Tehran')->toDateString();
        $options = ['--from' => $d, '--to' => $d];
        $this->assertSame(0, Artisan::call('finance:reconcile-ledger', $options));
        $plan = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $plan['events']);
        $this->assertSame(0, BusinessEntry::count());
        $this->assertSame(1, Artisan::call('finance:reconcile-ledger', $options + ['--apply' => true, '--plan-hash' => 'wrong']));
        $before = (int) CreditEntry::sum('amount');
        $this->assertSame(0, Artisan::call('finance:reconcile-ledger', $options + ['--apply' => true, '--plan-hash' => $plan['plan_hash']]));
        $this->assertSame(100, app(BusinessLedger::class)->summary()['revenue']);
        $this->assertSame($before, (int) CreditEntry::sum('amount'));
        $this->assertSame(1, CreditEntry::count());
    }
}
