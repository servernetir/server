<?php

use App\Models\Customer;
use App\Models\User;
use App\Models\WalletRefundRequest;
use App\Services\Finance\Wallet;
use App\Services\Finance\WalletException;
use App\Services\Finance\WalletRefunds;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

// Opt-in worker for a disposable local test database. Never installed in public/ or used on production.
require dirname(__DIR__, 2).'/tests/bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$conn = config('database.connections.'.config('database.default'));
if (! app()->environment('testing') || ! getenv('WALLET_REFUND_CONCURRENCY')
    || ! in_array($conn['driver'] ?? '', ['mysql', 'mariadb'], true)
    || ($conn['host'] ?? '') !== '127.0.0.1' || ($conn['database'] ?? '') !== 'servernet_wallet_refund_testing') {
    fwrite(STDERR, "Disposable local wallet-refund test database required.\n");
    exit(2);
}
$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$gate = realpath($data['gate']);
$allowed = realpath(storage_path('framework/testing'));
$diskRoot = realpath($data['storage_root']);
if (! $gate || ! $allowed || ! $diskRoot || ! str_starts_with($gate, $allowed.DIRECTORY_SEPARATOR)
    || ! str_starts_with($diskRoot, $allowed.DIRECTORY_SEPARATOR)) {
    exit(3);
}
Carbon::setTestNow(Carbon::parse('2026-10-25 08:00:00', 'UTC'));
Http::fake();
Mail::fake();
config(['filesystems.disks.local.root' => $diskRoot]);
Storage::forgetDisk('local');
file_put_contents($gate.'/ready-'.$data['index'], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($gate.'/go')) {
    if (microtime(true) > $deadline) {
        exit(4);
    }
    usleep(5000);
}
try {
    $service = app(WalletRefunds::class);
    $customer = Customer::findOrFail($data['customer']);
    $admin = User::findOrFail($data['admin']);
    $result = match ($data['mode']) {
        'request' => $service->request($customer, $data['key'], 70000, $data['bank'], 'Concurrent refund request'),
        'paid' => $service->paid(WalletRefundRequest::findOrFail($data['refund']), $admin, $data['funding'],
            '2026-10-25', 'CONCURRENT-TRANSFER-001', UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\nConcurrent transfer proof\n")),
        'reject' => $service->close(WalletRefundRequest::findOrFail($data['refund']), 'rejected', 'No bank transfer made', $admin),
        'debit' => app(Wallet::class)->debit($customer->id, 'IRT', 40000, 'adjustment', null, 'Concurrent ordinary debit'),
    };
    echo json_encode(['ok' => true, 'id' => $result->id], JSON_THROW_ON_ERROR);
} catch (ValidationException|WalletException $e) {
    echo json_encode(['ok' => false, 'rejected' => true], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage());
    exit(1);
}
