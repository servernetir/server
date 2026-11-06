<?php
/** CLI-only, read-only aggregate preflight/acceptance. No provider or lifecycle commands. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = rtrim($argv[1] ?? '', '/');
$stage = rtrim($argv[2] ?? '', '/');
if (! is_file($root.'/vendor/autoload.php')) { throw new RuntimeException('Application root required'); }
require $root.'/vendor/autoload.php';
if ($stage !== '') {
    $map = [
        App\Services\Billing\OrderPaymentWindow::class => '/app/Services/Billing/OrderPaymentWindow.php',
        App\Services\Cloud\GpuCapacityOrders::class => '/app/Services/Cloud/GpuCapacityOrders.php',
    ];
    foreach ($map as $path) {
        if (! is_file($stage.$path)) { throw new RuntimeException('Missing staged dependency'); }
    }
    spl_autoload_register(static function ($class) use ($stage, $map) {
        if (isset($map[$class])) { require $stage.$map[$class]; }
    }, true, true);
}
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$orders = app(App\Services\Cloud\GpuCapacityOrders::class);
$eligibility = new ReflectionMethod($orders, 'isUnallocatedHourlyIntent');
$limit = 500;
$rows = App\Models\Service::query()->where('billing_mode', 'hourly')
    ->where('status', 'awaiting_provision')
    ->whereIn('provision_status', [App\Services\Cloud\GpuCapacityOrders::WAIT, 'pending', 'running'])
    ->where('provision_meta->gpu_capacity_order->version', 1)
    ->orderBy('id')->limit($limit + 1)->get();
$result = ['order_expiry_hours' => App\Services\Billing\OrderPaymentWindow::hours(),
    'invoice_hold_hours' => config('billing.invoice_hold_hours'),
    'sampled' => min($rows->count(), $limit), 'truncated' => $rows->count() > $limit,
    'protected' => 0, 'eligible_funded' => 0, 'eligible_unfunded' => 0,
    'past_deadline_unfunded' => 0, 'missing_deadline' => 0];
$wallet = app(App\Services\Finance\Wallet::class);
$credit = app(App\Services\Cloud\HourlyStartCredit::class);
foreach ($rows->take($limit) as $service) {
    if (! $eligibility->invoke($eligibility->isStatic() ? null : $orders, $service)) {
        $result['protected']++; continue;
    }
    $deadline = App\Services\Cloud\GpuCapacityOrders::requestDeadline($service);
    if (! $deadline) { $result['missing_deadline']++; continue; }
    $minimum = $credit->minimum($service->customer_id, (int) $service->hourly_rate_irt, $service->id);
    if ($wallet->availableOf($service->customer_id) >= $minimum) {
        $result['eligible_funded']++; continue;
    }
    $result['eligible_unfunded']++;
    if (now()->gte($deadline)) { $result['past_deadline_unfunded']++; }
}
$result['payment_expired_total'] = App\Models\Service::query()
    ->where('provision_meta->gpu_capacity_order->closed_reason', 'payment_expired')->count();
$result['checked_at_utc'] = now()->utc()->toIso8601String();
$result['live_mutations'] = 0;
echo json_encode($result, JSON_UNESCAPED_SLASHES).PHP_EOL;
