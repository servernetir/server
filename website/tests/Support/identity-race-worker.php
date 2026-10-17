<?php

use App\Models\Customer;
use App\Models\CustomerApiToken;
use App\Models\IdentityApplication;
use App\Models\IdentityRequest;
use App\Services\Ai\AiReservations;
use App\Services\Finance\Wallet;
use App\Services\Finance\WalletException;
use App\Services\IdentityApi\Engine;
use App\Services\IdentityApi\IdentityError;
use App\Services\IdentityApi\Provider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Test-only worker. No production command or endpoint installs synthetic provider behavior.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! getenv('AI_RESERVATION_STRESS') || config('database.connections.'.config('database.default').'.database') !== 'servernet_stress'
    || config('database.connections.'.config('database.default').'.host') !== 'db') {
    throw new RuntimeException('Disposable database required');
}
Http::preventStrayRequests();
Wallet::flushSchemaCache();
config(['identity_api.token' => 'synthetic-provider-key']);
$customer = Customer::firstOrFail();
$a = IdentityApplication::firstOrFail();
$token = CustomerApiToken::firstOrFail();
$mode = $argv[1];
$id = (int) ($argv[2] ?? 0);
if ($mode === 'dispatch') {
    app()->instance(Provider::class, new class extends Provider
    {
        public function send(array $s, array $p): array
        {
            DB::table('identity_audits')->insert(['actor_id' => null, 'action' => 'synthetic_dispatch', 'detail' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            usleep(50000);

            return ['http' => 200, 'json' => ['result' => 1, 'response_body' => ['data' => ['matched' => false], 'error_code' => null]]];
        }
    });
    app(Engine::class)->dispatch(IdentityRequest::findOrFail($id));
} elseif ($mode === 'capture' || $mode === 'release') {
    $e = app(Engine::class);
    $r = IdentityRequest::findOrFail($id);
    $mode === 'capture' ? $e->capture($r) : $e->release($r, 'synthetic_race');
} else {
    for ($i = 0; $i < 10; $i++) {
        try {
            if (($i + getmypid()) % 3 === 0) {
                app(Wallet::class)->debit($customer->id, 'IRT', 1000, 'invoice_payment', $customer);
            } elseif (($i + getmypid()) % 3 === 1) {
                app(AiReservations::class)->reserve($customer->id, 1000, 'synthetic-ai-'.getmypid().'-'.$i, now()->addHour());
            } else {
                app(Engine::class)->accept($a, $token, 'shahkar-inquiry', ['mobile' => '09120000000', 'national_code' => '0000000019'], 'synthetic-race-'.getmypid().'-'.$i, 1000);
            }
        } catch (WalletException $e) {
            if ($e->errorCode !== 'insufficient_funds') {
                throw $e;
            }
        } catch (IdentityError $e) {
            if (! in_array($e->errorCode, ['insufficient_funds', 'concurrency_limit'], true)) {
                throw $e;
            }
        }
    }
}
echo "ok\n";
