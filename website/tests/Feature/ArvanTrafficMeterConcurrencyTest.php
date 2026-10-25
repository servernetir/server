<?php

namespace Tests\Feature;

use App\Models\BusinessEntry;
use App\Models\CreditEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Finance\ArvanTrafficMeter;
use App\Services\Finance\Wallet;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\Support\ArvanTrafficMeterFixture;
use Tests\TestCase;

class ArvanTrafficMeterConcurrencyTest extends TestCase
{
    use ArvanTrafficMeterFixture;

    public function test_eight_separate_processes_only_settle_the_increment_once(): void
    {
        if (! getenv('TRAFFIC_METER_CONCURRENCY')) {
            $this->markTestSkipped('Opt-in disposable loopback MariaDB only');
        }
        $cfg = config('database.connections.'.config('database.default'));
        $this->assertContains($cfg['driver'], ['mariadb', 'mysql']);
        $this->assertSame('127.0.0.1', $cfg['host']);
        $this->assertSame('servernet_traffic_meter_testing', $cfg['database']);
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
        Wallet::flushSchemaCache();
        [$customer, , , $snapshot] = $this->fixture();
        $snapshot = $this->growth($snapshot);
        $code = 'require '.var_export(base_path('vendor/autoload.php'), true).';$app=require '.var_export(base_path('bootstrap/app.php'), true).';'
            .'$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
            .'$s=json_decode(base64_decode("'.base64_encode(json_encode($snapshot)).'"),true);'
            .'echo json_encode(app(App\\Services\\Finance\\ArvanTrafficMeter::class)->run($s,true));';
        $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mariadb', 'DB_HOST' => '127.0.0.1',
            'DB_PORT' => (string) $cfg['port'], 'DB_DATABASE' => $cfg['database'], 'DB_USERNAME' => $cfg['username'],
            'DB_PASSWORD' => $cfg['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'EXCHANGE_ENABLED' => 'false'];
        $args = [PHP_BINARY, '-n', '-d', 'extension_dir='.ini_get('extension_dir'), '-d', 'memory_limit=512M'];
        foreach (['curl', 'fileinfo', 'intl', 'mbstring', 'pdo_mysql', 'sodium', 'openssl'] as $ext) {
            array_push($args, '-d', 'extension='.$ext);
        }
        array_push($args, '-r', $code);
        $workers = [];
        for ($i = 0; $i < 8; $i++) {
            $p = new Process($args, base_path(), $env, null, 60);
            $p->start();
            $workers[] = $p;
        }
        $charged = 0;
        foreach ($workers as $p) {
            $p->wait();
            $this->assertTrue($p->isSuccessful(), $p->getErrorOutput().$p->getOutput());
            $result = json_decode($p->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $charged += $result['total_irt'];
        }
        $this->assertSame(2079, $charged);
        $this->assertSame(2, Invoice::count());
        $this->assertSame(2, Payment::count());
        $this->assertSame(2, CreditEntry::count());
        $this->assertSame(4, BusinessEntry::count());
        $this->assertSame(-4158, app(Wallet::class)->balanceOf($customer->id));
        $this->assertSame(36000, array_values(app(ArvanTrafficMeter::class)->state()['checkpoints'])[0]['cost_irr']);
    }
}
