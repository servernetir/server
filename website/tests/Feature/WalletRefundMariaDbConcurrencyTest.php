<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\FinanceAccount;
use App\Models\FinanceDocument;
use App\Models\FinanceVoucher;
use App\Models\User;
use App\Models\WalletRefundRequest;
use App\Services\Finance\DocumentedAccounting;
use App\Services\Finance\Wallet;
use App\Services\Finance\WalletRefunds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** No RefreshDatabase transaction: child connections must see committed fixtures. */
class WalletRefundMariaDbConcurrencyTest extends TestCase
{
    private function fixture(): array
    {
        $conn = config('database.connections.'.config('database.default'));
        if (! getenv('WALLET_REFUND_CONCURRENCY') || ! in_array($conn['driver'] ?? '', ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires an explicitly opted-in disposable local MariaDB database.');
        }
        $this->assertSame('testing', app()->environment());
        $this->assertSame('127.0.0.1', $conn['host']);
        $this->assertSame('servernet_wallet_refund_testing', $conn['database']);
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
        $this->travelTo(Carbon::parse('2026-10-25 08:00:00', 'UTC'));
        Http::fake();
        Mail::fake();
        Storage::fake('local');
        Wallet::flushSchemaCache();
        $admin = User::create(['name' => 'Concurrent admin', 'email' => 'race-admin@example.test', 'password' => bcrypt('test'), 'role' => 'admin']);
        $c = Customer::create(['email' => 'race-customer@example.test', 'password' => bcrypt('test'), 'status' => 'active']);
        $bban = '0100000000000000000001';
        $iban = 'IR'.str_pad((string) (98 - (int) bcmod($bban.'182700', '97')), 2, '0', STR_PAD_LEFT).$bban;
        $bank = BankAccount::create(['customer_id' => $c->id, 'iban' => $iban, 'card_bin' => '603799', 'card_last4' => '1234',
            'owner_name' => 'Concurrent customer', 'name_matched' => true, 'status' => 'verified', 'verified_at' => now()]);
        app(Wallet::class)->credit($c->id, 'IRT', 100000, 'topup', null);
        app(WalletRefunds::class)->permission($c, true, 'Opt-in concurrency test', $admin);
        $funding = FinanceAccount::create(['name' => 'Test bank', 'type' => 'bank', 'currency_code' => 'IRT', 'created_by' => $admin->id]);
        app(DocumentedAccounting::class)->post(['idempotency_key' => (string) Str::uuid(), 'type' => 'opening_balance',
            'occurred_at' => '2026-10-25', 'service_line' => 'other', 'category' => 'opening', 'amount_irt' => 500000,
            'original_currency' => 'IRT', 'original_minor' => 500000, 'target_account_id' => $funding->id,
            'bank_reference' => 'TEST-OPENING', 'note' => 'Documented test opening balance', 'tax_status' => 'pending', 'vat_status' => 'none'], $admin->id);

        return compact('c', 'admin', 'bank', 'funding');
    }

    private function approved(array $f): WalletRefundRequest
    {
        $service = app(WalletRefunds::class);
        $r = $service->request($f['c'], (string) Str::uuid(), 40000, $f['bank']->id, 'Concurrent payout request');

        return $service->approve($r, $f['admin'], 'Approved for test', null, 'Unused test credit with supporting evidence');
    }

    private function race(array $f, array $modes, ?WalletRefundRequest $refund = null): array
    {
        $gate = storage_path('framework/testing/refund-race-'.Str::uuid());
        mkdir($gate, 0700, true);
        $cmd = [PHP_BINARY, '-n', '-d', 'extension_dir='.ini_get('extension_dir')];
        foreach (['curl', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_mysql', 'pdo_sqlite', 'sodium', 'zip'] as $extension) {
            $cmd[] = '-d';
            $cmd[] = 'extension='.$extension;
        }
        $cmd[] = base_path('tests/Fixtures/wallet-refund-worker.php');
        $jobs = [];
        try {
            foreach ($modes as $index => $mode) {
                $proc = proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
                $this->assertIsResource($proc);
                fwrite($pipes[0], json_encode(['gate' => $gate, 'index' => $index, 'mode' => $mode,
                    'customer' => $f['c']->id, 'admin' => $f['admin']->id, 'bank' => $f['bank']->id, 'funding' => $f['funding']->id,
                    'key' => (string) Str::uuid(), 'refund' => $refund?->id, 'storage_root' => Storage::disk('local')->path('')], JSON_THROW_ON_ERROR));
                fclose($pipes[0]);
                $jobs[] = [$proc, $pipes];
            }
            $deadline = microtime(true) + 20;
            while (count(glob($gate.'/ready-*')) !== count($jobs)) {
                if (microtime(true) > $deadline) {
                    $this->fail('Refund worker readiness timed out');
                }
                usleep(10000);
            }
            file_put_contents($gate.'/go', 'go');
            $results = [];
            foreach ($jobs as [$proc, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($proc), $err.' / '.$output);
                $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($jobs as [$proc]) {
                if (is_resource($proc)) {
                    proc_terminate($proc);
                    proc_close($proc);
                }
            }
            foreach (glob($gate.'/*') as $file) {
                unlink($file);
            }
            rmdir($gate);
        }
    }

    public function test_concurrent_requests_create_exactly_one_hold(): void
    {
        $f = $this->fixture();
        $results = $this->race($f, array_fill(0, 8, 'request'));
        $this->assertSame(1, collect($results)->where('ok', true)->count());
        $this->assertSame(1, WalletRefundRequest::count());
        $this->assertSame(100000, app(Wallet::class)->balanceOf($f['c']->id));
        $this->assertSame(30000, app(Wallet::class)->availableOf($f['c']->id));
    }

    public function test_parallel_payout_retries_debit_and_document_exactly_once(): void
    {
        $f = $this->fixture();
        $r = $this->approved($f);
        $results = $this->race($f, array_fill(0, 8, 'paid'), $r);
        $this->assertSame(8, collect($results)->where('ok', true)->count());
        $this->assertSame('paid', $r->fresh()->status);
        $this->assertSame(1, CreditEntry::where('reason', 'wallet_refund_payout')->count());
        $this->assertSame(1, FinanceVoucher::where('type', 'wallet_refund_payout')->count());
        $this->assertSame(1, FinanceDocument::count());
        $this->assertSame(1, DB::table('wallet_refund_events')->where('action', 'paid')->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('finance/wallet-refunds'));
        $this->assertSame(60000, app(Wallet::class)->availableOf($f['c']->id));
    }

    public function test_payout_vs_rejection_has_one_consistent_terminal_result(): void
    {
        $f = $this->fixture();
        $r = $this->approved($f);
        $results = $this->race($f, ['paid', 'reject'], $r);
        $this->assertSame(1, collect($results)->where('ok', true)->count());
        $state = $r->fresh()->status;
        $this->assertContains($state, ['paid', 'rejected']);
        $this->assertSame($state === 'paid' ? 60000 : 100000, app(Wallet::class)->availableOf($f['c']->id));
        $this->assertSame($state === 'paid' ? 1 : 0, FinanceDocument::count());
        $this->assertSame(0, app(Wallet::class)->reservedOf($f['c']->id));
    }

    public function test_ordinary_spending_cannot_consume_concurrently_reserved_refund_funds(): void
    {
        $f = $this->fixture();
        $this->race($f, ['request', 'debit', 'request', 'debit', 'request', 'debit']);
        $wallet = app(Wallet::class);
        $spent = -(int) CreditEntry::where('reason', 'adjustment')->sum('amount');
        $this->assertGreaterThanOrEqual(0, $wallet->availableOf($f['c']->id));
        $this->assertLessThanOrEqual(100000, $spent + $wallet->reservedOf($f['c']->id));
        $this->assertSame(100000 - $spent, $wallet->balanceOf($f['c']->id));
        $this->assertLessThanOrEqual(1, WalletRefundRequest::count());
    }
}
