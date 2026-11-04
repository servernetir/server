<?php

namespace Tests\Feature;

use App\Models\CreditEntry;
use App\Models\CryptoPayment;
use App\Models\CryptoWallet;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Payment\BscWatcher;
use App\Services\Payment\CryptoReconciler;
use App\Services\Payment\PaymentService;
use App\Services\Payment\TronWatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class CryptoReconciliationResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        if (getenv('CRYPTO_EXPECTED_DB')) {
            $this->assertSame(getenv('CRYPTO_EXPECTED_DB'), DB::getDefaultConnection());
        }
    }

    private function checkout(): CryptoPayment
    {
        $customer = Customer::create(['code' => uniqid('SN-'), 'email' => uniqid().'@example.test',
            'password' => bcrypt('test-only'), 'status' => 'active']);
        $invoice = Invoice::create(['customer_id' => $customer->id, 'number' => uniqid('INV-'),
            'kind' => 'topup', 'currency_code' => 'IRT', 'subtotal' => 100000,
            'tax' => 0, 'total' => 100000, 'paid' => 0, 'status' => 'unpaid',
            'issued_at' => now(), 'due_at' => now()->addDay()]);
        $wallet = CryptoWallet::create(['chain' => 'bsc',
            'address' => '0x'.str_pad(dechex($invoice->id), 40, '0', STR_PAD_LEFT), 'is_active' => true]);
        $cp = CryptoPayment::create(['invoice_id' => $invoice->id, 'customer_id' => $customer->id,
            'crypto_wallet_id' => $wallet->id, 'chain' => 'bsc', 'asset' => 'USDT', 'network' => 'BEP20',
            'address' => $wallet->address, 'amount_atomic' => 1000000, 'decimals' => 6,
            'invoice_amount' => 100000, 'invoice_currency' => 'IRT', 'rate_micro' => 1000000,
            'status' => 'pending', 'expires_at' => now()->addHour()]);
        $wallet->update(['busy_payment_id' => $cp->id]);
        return $cp;
    }

    private function deposit(int $id, array $overrides = []): array
    {
        return array_replace(['txid' => '0x'.str_pad(dechex($id), 64, 'a', STR_PAD_LEFT),
            'amount' => 1000000, 'asset' => 'USDT', 'decimals' => 6,
            'confirmed' => true, 'confirmations' => 20, 'timestamp' => now()->timestamp], $overrides);
    }

    private function watcher(array $deposits): void
    {
        $this->mock(BscWatcher::class)->shouldReceive('deposits')
            ->andReturnUsing(fn ($address) => $deposits[$address] ?? []);
        $this->mock(TronWatcher::class)->shouldReceive('deposits')->andReturn([]);
    }

    public function test_full_bsc_reference_settles_and_credits_exactly_once(): void
    {
        $cp = $this->checkout();
        $deposit = $this->deposit(1);
        $this->watcher([$cp->address => [$deposit]]);
        $rec = app(CryptoReconciler::class);
        $this->assertSame(1, $rec->sweep()['confirmed']);
        $rec->sweep();
        $this->assertSame($deposit['txid'], Payment::sole()->ref_id);
        $this->assertSame(66, strlen(Payment::sole()->ref_id));
        $this->assertSame('paid', $cp->invoice->fresh()->status);
        $this->assertSame(100000, (int) CreditEntry::where('customer_id', $cp->customer_id)->sum('amount'));
        $this->assertSame(1, CreditEntry::where('customer_id', $cp->customer_id)->count());
        $this->assertSame('confirmed', $cp->fresh()->status);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_a_failed_settlement_rolls_back_and_does_not_starve_the_next_customer(): void
    {
        $first = $this->checkout();
        $second = $this->checkout();
        $this->watcher([$first->address => [$this->deposit(2)], $second->address => [$this->deposit(3)]]);
        $real = app(PaymentService::class);
        $fail = true;
        $mock = Mockery::mock(PaymentService::class);
        $mock->shouldReceive('settleConfirmed')->andReturnUsing(function ($payment, $txid) use ($first, $real, &$fail) {
            $out = $real->settleConfirmed($payment, $txid);
            if ($payment->invoice_id === $first->invoice_id && $fail) {
                throw new \RuntimeException('synthetic failure after wallet write');
            }
            return $out;
        });
        $this->app->instance(PaymentService::class, $mock);
        $rec = app(CryptoReconciler::class);
        $stat = $rec->sweep();
        $this->assertSame(1, $stat['errors']);
        $this->assertSame(1, $stat['confirmed']);
        $this->assertSame('pending', $first->fresh()->status);
        $this->assertNull($first->fresh()->txid);
        $this->assertSame(0, CreditEntry::where('customer_id', $first->customer_id)->count());
        $this->assertSame('confirmed', $second->fresh()->status);
        $this->assertSame(1, Payment::count());
        $fail = false;
        $this->assertSame(1, $rec->sweep()['confirmed']);
        $this->assertSame(2, Payment::count());
        $this->assertSame(100000, (int) CreditEntry::where('customer_id', $first->customer_id)->sum('amount'));
    }

    public function test_expired_checkout_with_funds_goes_to_review_without_automatic_credit(): void
    {
        $cp = $this->checkout();
        $cp->forceFill(['status' => 'expired', 'created_at' => now()->subHour(), 'expires_at' => now()->subMinutes(5)])->save();
        $deposit = $this->deposit(4);
        $this->watcher([$cp->address => [$deposit]]);
        $rec = app(CryptoReconciler::class);
        $this->assertSame(1, $rec->sweep()['unmatched']);
        $rec->sweep();
        $this->assertSame('manual', $cp->fresh()->status);
        $this->assertSame($deposit['txid'], $cp->fresh()->txid);
        $this->assertSame(1000000, $cp->fresh()->received_atomic);
        $this->assertSame('unpaid', $cp->invoice->fresh()->status);
        $this->assertSame(0, CreditEntry::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_legacy_manual_credit_with_the_same_hash_cannot_be_credited_again(): void
    {
        $cp = $this->checkout();
        $deposit = $this->deposit(5);
        CreditEntry::create(['customer_id' => $cp->customer_id, 'currency_code' => 'IRT',
            'amount' => 100000, 'balance_after' => 100000, 'reason' => 'adjustment',
            'note' => 'Owner-confirmed manual crypto credit '.$deposit['txid']]);
        $this->watcher([$cp->address => [$deposit]]);
        app(CryptoReconciler::class)->sweep();
        $this->assertSame('manual', $cp->fresh()->status);
        $this->assertSame('unpaid', $cp->invoice->fresh()->status);
        $this->assertSame(100000, (int) CreditEntry::sum('amount'));
        $this->assertSame(0, Payment::where('status', 'paid')->count());
    }

    public function test_changed_invoice_quote_requires_review_instead_of_underfunded_settlement(): void
    {
        $cp = $this->checkout();
        $cp->invoice->update(['total' => 200000]);
        $this->watcher([$cp->address => [$this->deposit(6)]]);
        app(CryptoReconciler::class)->sweep();
        $this->assertSame('manual', $cp->fresh()->status);
        $this->assertSame(0, CreditEntry::count());
        $this->assertSame(0, Payment::where('status', 'paid')->count());
    }

    public function test_late_deposit_does_not_release_a_wallet_reserved_by_a_new_checkout(): void
    {
        $old = $this->checkout();
        $old->forceFill(['status' => 'expired', 'created_at' => now()->subHour(), 'expires_at' => now()->subMinutes(15)])->save();
        $new = $this->checkout();
        $new->update(['crypto_wallet_id' => $old->crypto_wallet_id, 'address' => $old->address]);
        $old->wallet->update(['busy_payment_id' => $new->id]);
        $this->watcher([$old->address => [$this->deposit(7, ['timestamp' => now()->subMinutes(10)->timestamp])]]);
        app(CryptoReconciler::class)->sweep();
        $this->assertSame('manual', $old->fresh()->status);
        $this->assertSame('pending', $new->fresh()->status);
        $this->assertSame($new->id, $old->wallet->fresh()->busy_payment_id);
        $this->assertSame(0, CreditEntry::count());
    }

    public function test_a_canceled_manually_resolved_invoice_is_never_automatically_reopened(): void
    {
        $cp = $this->checkout();
        $cp->invoice->update(['status' => 'canceled']);
        $this->watcher([$cp->address => [$this->deposit(8)]]);
        app(CryptoReconciler::class)->sweep();
        $this->assertSame('manual', $cp->fresh()->status);
        $this->assertSame('canceled', $cp->invoice->fresh()->status);
        $this->assertSame(0, CreditEntry::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_reference_migration_preserves_existing_values_and_refuses_lossy_rollback(): void
    {
        $cp = $this->checkout();
        $payment = Payment::create(['invoice_id' => $cp->invoice_id, 'customer_id' => $cp->customer_id,
            'gateway' => 'crypto', 'currency_code' => 'IRT', 'amount' => 100000, 'status' => 'paid',
            'ref_id' => str_repeat('f', 64)]);
        $migration = require database_path('migrations/2026_11_04_000100_widen_payment_reference_for_crypto.php');
        $migration->up();
        $this->assertSame(str_repeat('f', 64), $payment->fresh()->ref_id);
        DB::table('payments')->where('id', $payment->id)->update(['ref_id' => '0x'.str_repeat('f', 64)]);
        try {
            $migration->down();
            $this->fail('A lossy reference rollback was accepted');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('exceed 64', $error->getMessage());
        }
        $this->assertSame('0x'.str_repeat('f', 64), $payment->fresh()->ref_id);
    }

    public function test_one_transaction_cannot_pay_two_invoices(): void
    {
        $first = $this->checkout();
        $second = $this->checkout();
        $deposit = $this->deposit(9);
        $this->watcher([$first->address => [$deposit], $second->address => [$deposit]]);
        $rec = app(CryptoReconciler::class);
        $rec->sweep();
        $rec->sweep();
        $this->assertSame('paid', $first->invoice->fresh()->status);
        $this->assertSame('unpaid', $second->invoice->fresh()->status);
        $this->assertSame(100000, (int) CreditEntry::sum('amount'));
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, CryptoPayment::where('txid', $deposit['txid'])->count());
    }
}
