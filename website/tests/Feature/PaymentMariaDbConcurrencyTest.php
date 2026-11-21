<?php

namespace Tests\Feature;

use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Finance\Wallet;
use App\Services\Payment\PaymentService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Separate committed connections: no RefreshDatabase transaction around fixtures. */
class PaymentMariaDbConcurrencyTest extends TestCase
{
    private function fixture(): Invoice
    {
        $connection = config('database.connections.'.config('database.default'));
        if (getenv('PAYMENT_CONCURRENCY') !== '1' || ! in_array($connection['driver'] ?? '', ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires an opted-in disposable local MariaDB database.');
        }
        $this->assertSame('testing', app()->environment());
        $this->assertSame('127.0.0.1', $connection['host']);
        $this->assertSame('servernet_payment_testing', $connection['database']);
        $this->assertEmpty($connection['url']);
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
        Http::fake();
        Mail::fake();
        Wallet::flushSchemaCache();
        $customer = Customer::create(['email' => 'payment-race@example.test', 'status' => 'active', 'locale' => 'fa']);

        return Invoice::create(['customer_id' => $customer->id, 'kind' => 'topup', 'currency_code' => 'IRT',
            'subtotal' => 100000, 'tax' => 0, 'total' => 100000, 'paid' => 0,
            'status' => 'unpaid', 'issued_at' => now()]);
    }

    private function payment(Invoice $invoice): Payment
    {
        return Payment::create(['invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id,
            'gateway' => 'zarinpal', 'currency_code' => 'IRT', 'amount' => 100000,
            'status' => 'redirected', 'expires_at' => now()->addMinutes(10)]);
    }

    private function race(Invoice $invoice, ?Payment $payment, array $modes, ?\Closure $afterReady = null): array
    {
        $gate = storage_path('framework/testing/payment-state-'.Str::uuid());
        mkdir($gate, 0700, true);
        $jobs = [];
        try {
            foreach ($modes as $index => $mode) {
                $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/payment-state-worker.php')],
                    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
                $this->assertIsResource($process);
                fwrite($pipes[0], json_encode(['gate' => $gate, 'index' => $index, 'mode' => $mode,
                    'invoice' => $invoice->id, 'payment' => $payment?->id], JSON_THROW_ON_ERROR));
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $jobs[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 25;
            while (count(glob($gate.'/ready-*')) !== count($jobs)) {
                if (microtime(true) > $deadline) {
                    $errors = implode('\n', array_map(fn ($job) => stream_get_contents($job[1][2]), $jobs));
                    $this->fail('Payment worker readiness timed out: '.$errors);
                }
                usleep(10000);
            }
            $ids = array_map(fn ($path) => (int) file_get_contents($path), glob($gate.'/ready-*'));
            if ($afterReady) {
                $afterReady($ids);
            }
            file_put_contents($gate.'/go', 'go');
            $results = [];
            foreach ($jobs as [$process, $pipes]) {
                $output = '';
                $error = '';
                $deadline = microtime(true) + 30;
                do {
                    $output .= stream_get_contents($pipes[1]);
                    $error .= stream_get_contents($pipes[2]);
                    $status = proc_get_status($process);
                    if (microtime(true) > $deadline) {
                        $this->fail('Payment worker completion timed out.');
                    }
                    if ($status['running']) {
                        usleep(10000);
                    }
                } while ($status['running']);
                $output .= stream_get_contents($pipes[1]);
                $error .= stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                $this->assertSame(0, $status['exitcode'], $error.' / '.$output);
                $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($jobs as [$process, $pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    foreach ($pipes as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }
                    proc_close($process);
                }
            }
            foreach (glob($gate.'/*') as $file) {
                unlink($file);
            }
            rmdir($gate);
        }
    }

    public static function lateResults(): array
    {
        return array_combine(['verify-fail', 'verify-cancel', 'start-fail', 'start-redirect'],
            array_map(fn ($mode) => [$mode], ['verify-fail', 'verify-cancel', 'start-fail', 'start-redirect']));
    }

    #[DataProvider('lateResults')]
    public function test_an_inflight_request_cannot_downgrade_a_committed_payment(string $mode): void
    {
        $invoice = $this->fixture();
        $payment = str_starts_with($mode, 'start-') ? null : $this->payment($invoice);
        $results = $this->race($invoice, $payment, [$mode], function (array $ids): void {
            $this->assertTrue(app(PaymentService::class)->settleConfirmed(Payment::findOrFail($ids[0]), 'synthetic-reference')->ok);
        });
        $payment = Payment::firstOrFail();
        app(PaymentService::class)->settleConfirmed($payment->fresh(), 'synthetic-reference');

        $this->assertSame(100000, app(Wallet::class)->balanceOf($invoice->customer_id));
        $this->assertSame(1, CreditEntry::count());
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertTrue($results[0]['ok']);
        $this->assertSame('paid', $results[0]['status']);
        $this->assertNull($results[0]['redirect']);
    }

    public function test_eight_successful_callbacks_credit_the_wallet_exactly_once(): void
    {
        $invoice = $this->fixture();
        $payment = $this->payment($invoice);
        $results = $this->race($invoice, $payment, array_fill(0, 8, 'paid'));

        $this->assertSame(8, collect($results)->where('ok', true)->count());
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame(100000, (int) $invoice->fresh()->paid);
        $this->assertSame(100000, app(Wallet::class)->balanceOf($invoice->customer_id));
        $this->assertSame(1, CreditEntry::count());
    }

    public function test_a_superseded_start_is_not_reactivated_by_its_delayed_response(): void
    {
        $invoice = $this->fixture();
        $results = $this->race($invoice, null, ['start-redirect'], function (array $ids): void {
            Payment::whereKey($ids[0])->update(['status' => 'canceled']);
        });

        $this->assertFalse($results[0]['ok']);
        $this->assertSame('canceled', Payment::firstOrFail()->status);
        $this->assertSame(0, CreditEntry::count());
        $this->assertNull($results[0]['redirect']);
    }
}
