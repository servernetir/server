<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MarketingGrant;
use App\Services\Marketing\DiscountEngine;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[1],
    'database.connections.sqlite.busy_timeout' => 5000, 'cache.default' => 'array']);
DB::purge('sqlite');
Carbon::setTestNow($argv[6]);
while (! is_file($argv[5])) {
    usleep(1000);
}
try {
    $r = app(DiscountEngine::class)->reserve(
        Invoice::findOrFail($argv[2]),
        Customer::findOrFail($argv[3]),
        MarketingGrant::findOrFail($argv[4])->code);
    echo json_encode(['result' => 'reserved', 'id' => $r->id], JSON_THROW_ON_ERROR);
} catch (ValidationException) {
    echo json_encode(['result' => 'rejected'], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['result' => 'error', 'class' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
    exit(1);
}
