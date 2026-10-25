<?php
// Two independent processes use the actual model CAS against one synthetic SQLite file.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.connections.ovh_lease_test' => ['driver' => 'sqlite', 'database' => $argv[1],
    'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000]]);
$order = App\Models\OvhOrder::on('ovh_lease_test')->findOrFail(1);
file_put_contents($argv[2].'/ready-'.$argv[3], 'ready');
$deadline = microtime(true) + 10;
while (! is_file($argv[2].'/go') && microtime(true) < $deadline) { usleep(1000); }
if (! is_file($argv[2].'/go')) { exit(2); }
$won = $order->claimLease((string) Illuminate\Support\Str::uuid());
echo $won ? 'won' : 'busy';
