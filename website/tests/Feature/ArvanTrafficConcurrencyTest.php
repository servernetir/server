<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\CloudInstance;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Services\Finance\ArvanTrafficSettlement;
use App\Services\Finance\Wallet;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Opt-in disposable local MariaDB only. Uses committed fixtures and separate real connections. */
class ArvanTrafficConcurrencyTest extends TestCase
{
    public function test_eight_concurrent_owner_settlements_create_one_invoice_and_one_debit(): void
    {
        $cfg = config('database.connections.'.config('database.default'));
        if (! getenv('TRAFFIC_CONCURRENCY')) {
            $this->markTestSkipped('Requires an explicitly opted-in disposable local MariaDB');
        }
        $this->assertContains($cfg['driver'], ['mariadb', 'mysql']);
        $this->assertSame('127.0.0.1', $cfg['host']);
        $this->assertSame('servernet_traffic_testing', $cfg['database']);
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
        Wallet::flushSchemaCache();
        $c = Customer::create(['email' => 'concurrent-traffic@example.com', 'status' => 'active']);
        $s = Service::create(['customer_id' => $c->id, 'name' => 'Synthetic concurrency', 'status' => 'active',
            'currency_code' => 'IRT', 'cycle' => 'monthly', 'price' => 1000]);
        $native = '00000000-0000-0000-0000-000000000001';
        CloudInstance::create(['service_id' => $s->id, 'provider' => 'arvan', 'provider_ref' => 'ir-test:'.$native, 'status' => 'running']);
        $item = ['itemId' => 'synthetic-item', 'metric' => 'cc_internet', 'amount' => 1, 'price' => '18000', 'discount' => '0', 'usedGift' => '0',
            'usageFrom' => '2026-10-24T00:00:00Z', 'usageTo' => '2026-10-25T00:00:00Z'];
        $json = json_encode(['observed_at' => '2026-10-25T01:00:00Z', 'billing' => ['usage_status' => 200, 'cycle_status' => 200,
            'cycle' => ['data' => ['id' => '12345678-1234-1234-1234-123456789abc', 'currency' => 'irr', 'startedAt' => '2026-10-23T00:00:00Z', 'expiredAt' => '2026-11-22T00:00:00Z']],
            'usage' => ['data' => [['instanceId' => $native, 'scope' => 'instance', 'region' => 'ir-test', 'productName' => 'iaas', 'items' => [$item]]],
                'meta' => ['pagination' => ['totalPages' => 1, 'totalItems' => 1]]]],
            'traffic_attribution' => [['service_id' => $s->id, 'customer_id' => $c->id, 'provider_instance_id' => $native, 'matched' => true,
                'price_irr' => '18000', 'discount_irr' => '0', 'usage_from' => $item['usageFrom'], 'usage_to' => $item['usageTo']]]], JSON_THROW_ON_ERROR);
        $p = app(ArvanTrafficSettlement::class)->plan($json, hash('sha256', $json), 500, 1000);
        $code = 'chdir('.var_export(base_path(), true).');require "vendor/autoload.php";$app=require "bootstrap/app.php";'
            .'$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
            .'$p=json_decode(base64_decode("'.base64_encode(json_encode($p)).'"),true);'
            .'$r=app(App\\Services\\Finance\\ArvanTrafficSettlement::class)->apply($p,$p["hash"],"Synthetic concurrent owner authorization");echo json_encode($r);';
        $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mariadb', 'DB_HOST' => $cfg['host'],
            'DB_PORT' => (string) $cfg['port'], 'DB_DATABASE' => $cfg['database'], 'DB_USERNAME' => $cfg['username'],
            'DB_PASSWORD' => $cfg['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'EXCHANGE_ENABLED' => 'false'];
        $args = [PHP_BINARY, '-n', '-d', 'extension_dir='.ini_get('extension_dir')];
        foreach (['curl', 'fileinfo', 'intl', 'mbstring', 'pdo_mysql', 'sodium', 'openssl'] as $ext) {
            array_push($args, '-d', 'extension='.$ext);
        }
        array_push($args, '-r', $code);
        $workers = [];
        for ($i = 0; $i < 8; $i++) {
            $worker = new Process($args, base_path(), $env, null, 60);
            $worker->start();
            $workers[] = $worker;
        }
        $new = 0;
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
            $result = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $new += $result[0]['replayed'] ? 0 : 1;
        }
        $this->assertSame(1, $new);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, CreditEntry::count());
        $this->assertSame(2, BusinessEntry::count());
        $this->assertSame(-2079, app(Wallet::class)->balanceOf($c->id));
    }
}
