<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Payment\GatewayRegistry;
use App\Services\Payment\PaymentGateway;
use App\Services\Payment\PaymentService;
use App\Services\Payment\StartResult;
use App\Services\Payment\VerifyResult;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

// Only synthetic fixtures in the explicitly named local disposable database.
if (getenv('APP_ENV') !== 'testing' || getenv('PAYMENT_CONCURRENCY') !== '1'
    || getenv('DB_DATABASE') !== 'servernet_payment_testing' || getenv('DB_HOST') !== '127.0.0.1') {
    throw new RuntimeException('Payment worker requires an opted-in local test database.');
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.'.config('database.default').'.database') !== 'servernet_payment_testing') {
    throw new RuntimeException('Unexpected payment worker connection.');
}
Http::fake();
Mail::fake();
DB::statement('SET SESSION innodb_lock_wait_timeout=5');
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$gate = realpath($input['gate']);
if (! $gate || ! str_starts_with($gate, realpath(storage_path('framework/testing')).'/payment-state-')) {
    throw new RuntimeException('Unexpected test barrier.');
}
$ready = function (Payment $payment) use ($input, $gate): void {
    $path = $gate.'/ready-'.$input['index'];
    $temporary = $gate.'/waiting-'.$input['index'].'.tmp';
    file_put_contents($temporary, (string) $payment->id);
    rename($temporary, $path);
    $deadline = microtime(true) + 20;
    while (! is_file($gate.'/go')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Payment test barrier timed out.');
        }
        usleep(10000);
    }
};
$registry = new GatewayRegistry;
$registry->register(new class($ready, $input['mode']) implements PaymentGateway
{
    public function __construct(private Closure $ready, private string $mode) {}

    public function key(): string
    {
        return 'zarinpal';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function currency(): string
    {
        return 'IRT';
    }

    public function minimum(): int
    {
        return 1;
    }

    public function start(Payment $payment, string $callbackUrl): StartResult
    {
        ($this->ready)($payment);

        return $this->mode === 'start-fail'
            ? StartResult::fail('Synthetic delayed failure')
            : StartResult::redirect('https://gateway.invalid/pay', 'synthetic-start');
    }

    public function verify(Payment $payment, array $callback): VerifyResult
    {
        ($this->ready)($payment);

        return $this->mode === 'verify-cancel'
            ? VerifyResult::canceled()
            : VerifyResult::fail('Synthetic delayed failure');
    }
});
$service = new PaymentService($registry);
if (str_starts_with($input['mode'], 'start-')) {
    $outcome = $service->begin(Invoice::findOrFail($input['invoice']), 'zarinpal', Request::create('/test', 'POST'));
} else {
    $payment = Payment::findOrFail($input['payment']);
    if ($input['mode'] === 'paid') {
        $ready($payment);
        $outcome = $service->settleConfirmed($payment, 'synthetic-reference');
    } else {
        $outcome = $service->settle($payment, []);
    }
}
echo json_encode(['ok' => $outcome->ok, 'status' => $outcome->payment?->status,
    'redirect' => $outcome->redirectUrl ?? null], JSON_THROW_ON_ERROR);
