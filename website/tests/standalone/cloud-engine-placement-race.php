<?php

/** Real two-process reservation races on disposable file SQLite, never production. */
use App\Models\{CloudPlan, EngineAddress, EngineImage, EngineNode, EnginePlanNode, EngineVm, Service};
use App\Services\Cloud\Engine\{Ipam, Placement};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

require dirname(__DIR__, 2).'/vendor/autoload.php';

function bootRace(string $directory): void
{
    $real = realpath($directory);
    if ($real === false || dirname($real) !== realpath(sys_get_temp_dir())
        || ! str_starts_with(basename($real), 'servernet-engine-race-')) {
        throw new RuntimeException('Only a disposable engine race directory is accepted.');
    }
    foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $real.'/race.sqlite', 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'EXCHANGE_ENABLED' => 'false'] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    set_exception_handler(static function (Throwable $e): void {
        fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
        exit(1);
    });
    config(['database.connections.sqlite.busy_timeout' => 5000]);
    DB::purge('sqlite');
    DB::connection()->statement('PRAGMA journal_mode=WAL');
}

function awaitFile(string $file, int $seconds = 15): void
{
    $deadline = microtime(true) + $seconds;
    while (! is_file($file)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Race barrier timed out: '.basename($file));
        }
        usleep(10000);
    }
}

if (($argv[1] ?? '') === 'child') {
    [$script, $mode, $directory, $slot, $serviceId] = $argv;
    bootRace($directory);
    $synchronized = false;
    DB::listen(function ($query) use ($directory, $slot, &$synchronized) {
        if (! $synchronized && str_contains($query->sql, 'COALESCE(SUM(vcpu)')) {
            $synchronized = true;
            touch($directory.'/read-'.$slot);
            // Both actual database transactions read capacity before either writes.
            awaitFile($directory.'/write');
        }
    });
    touch($directory.'/ready-'.$slot);
    awaitFile($directory.'/start');
    try {
        $vm = app(Placement::class)->reserve(Service::findOrFail((int) $serviceId), CloudPlan::firstOrFail(), 'ubuntu-24.04');
        echo json_encode(['outcome' => 'reserved', 'id' => $vm->id, 'uuid' => $vm->uuid,
            'vmid' => $vm->vmid, 'address' => $vm->address->address], JSON_THROW_ON_ERROR);
    } catch (DomainException $e) {
        echo json_encode(['outcome' => 'exhausted', 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit(0);
}

function setupRace(string $directory, int $capacity): void
{
    touch($directory.'/race.sqlite');
    bootRace($directory);
    Schema::create('services', function (Blueprint $t) { $t->id(); $t->string('status'); $t->timestamps(); });
    Schema::create('cloud_plans', function (Blueprint $t) {
        $t->id(); $t->string('provider'); $t->string('location_code'); $t->string('arch');
        $t->integer('vcpu'); $t->integer('ram_mb'); $t->integer('disk_gb'); $t->timestamps();
    });
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_03_100100_create_cloud_engine_inventory.php';
    $migration->up();
    (require dirname(__DIR__, 2).'/database/migrations/2026_11_03_100150_guard_cloud_engine_node_identity.php')->up();
    DB::table('services')->insert([['id' => 1, 'status' => 'awaiting_provision'], ['id' => 2, 'status' => 'awaiting_provision']]);
    $plan = CloudPlan::create(['provider' => 'cloud-engine', 'location_code' => 'de-fsn', 'arch' => 'x86',
        'vcpu' => 1, 'ram_mb' => 1024, 'disk_gb' => 10]);
    $node = EngineNode::create(['name' => 'race-node', 'location_code' => 'de-fsn',
        'api_url' => 'https://never-contacted.example.test/api2/json', 'pve_node' => 'race',
        'token_id' => 'race@pve!test', 'token_secret' => 'fixture-only', 'storage' => 'local',
        'bridge' => 'vmbr0', 'gateway' => '8.8.8.1', 'sales_enabled' => true,
        'health' => 'healthy', 'health_checked_at' => now(),
        'vcpu_capacity' => $capacity, 'ram_mb_capacity' => 8192, 'disk_gb_capacity' => 100]);
    app(Ipam::class)->import($node, '8.8.8.0/29', '8.8.8.1')->update(['enabled' => true]);
    EnginePlanNode::create(['node_id' => $node->id, 'cloud_plan_id' => $plan->id, 'enabled' => true]);
    EngineImage::create(['node_id' => $node->id, 'key' => 'ubuntu-24.04', 'family' => 'ubuntu', 'version' => '24.04',
        'template_vmid' => 9000, 'checksum_sha256' => str_repeat('a', 64),
        'source_url' => 'https://cloud-images.ubuntu.com/fixture.img',
        'accepted_at' => now(), 'initialization_verified_at' => now(), 'metadata' => ['guest_agent_verified' => true]]);
    DB::disconnect();
}

$scenarios = ['same_service' => [2, [1, 1], 1], 'two_services' => [2, [1, 2], 2], 'capacity_one' => [1, [1, 2], 1]];
$assertions = 0;
foreach ($scenarios as $name => [$capacity, $services, $expected]) {
    $directory = sys_get_temp_dir().'/servernet-engine-race-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $children = [];
    try {
        setupRace($directory, $capacity);
        foreach ($services as $slot => $serviceId) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __FILE__, 'child', $directory, (string) $slot, (string) $serviceId],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (! is_resource($process)) { throw new RuntimeException('Could not start race worker.'); }
            fclose($pipes[0]);
            $children[] = [$process, $pipes];
        }
        foreach ([0, 1] as $slot) { awaitFile($directory.'/ready-'.$slot); }
        touch($directory.'/start');
        foreach ([0, 1] as $slot) { awaitFile($directory.'/read-'.$slot); }
        touch($directory.'/write');
        $outcomes = [];
        foreach ($children as $index => [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $exit = proc_close($process);
            $children[$index] = null;
            if ($exit !== 0) { throw new RuntimeException('Race worker failed: '.$error.$output); }
            $outcomes[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        }
        $children = [];
        DB::reconnect();
        $vms = EngineVm::all();
        foreach ([count($vms) === $expected, $vms->unique('service_id')->count() === $expected,
            $vms->unique('vmid')->count() === $expected, $vms->unique('address_id')->count() === $expected,
            EngineAddress::where('state', 'allocated')->count() === $expected,
            (int) $vms->sum('vcpu') <= $capacity] as $passed) {
            if (! $passed) { throw new RuntimeException('Reservation invariant failed: '.$name); }
            $assertions++;
        }
        if ($name === 'same_service' && ($outcomes[0]['id'] ?? null) !== ($outcomes[1]['id'] ?? null)) {
            throw new RuntimeException('Duplicate concurrent service did not return the same reservation.');
        }
        if ($name === 'capacity_one' && count(array_filter($outcomes, fn ($o) => $o['outcome'] === 'exhausted')) !== 1) {
            throw new RuntimeException('Capacity exhaustion was not reported exactly once.');
        }
        echo json_encode(['scenario' => $name, 'result' => 'passed', 'synchronized_capacity_reads' => 2,
            'reservations' => $expected, 'outcomes' => array_column($outcomes, 'outcome')], JSON_THROW_ON_ERROR).PHP_EOL;
    } finally {
        foreach ($children as $child) {
            if ($child === null) continue;
            [$process, $pipes] = $child;
            proc_terminate($process);
            foreach ($pipes as $pipe) { if (is_resource($pipe)) fclose($pipe); }
            proc_close($process);
        }
        DB::disconnect();
        foreach (glob($directory.'/*') ?: [] as $file) { unlink($file); }
        rmdir($directory);
    }
}
echo json_encode(['result' => 'passed', 'scenarios' => count($scenarios), 'assertions' => $assertions,
    'backend' => 'file_sqlite', 'processes_per_scenario' => 2, 'external_calls' => 0], JSON_THROW_ON_ERROR).PHP_EOL;
