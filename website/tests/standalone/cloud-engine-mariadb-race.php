<?php

/**
 * Real InnoDB placement races against a disposable local, socket-only server.
 *
 * ENGINE_MARIADB_TEST=1 ENGINE_MARIADB_SOCKET=/absolute/test.sock php this-file.php
 * Optional ENGINE_MARIADB_USER selects a local unix_socket-authenticated account.
 * No password, host, existing database, .env database or production URL is used.
 */
use App\Models\{CloudPlan, EngineAddress, EngineImage, EngineNode, EnginePlanNode, EngineVm, Service};
use App\Services\Cloud\Engine\{Ipam, Placement};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Http, Schema};

require dirname(__DIR__, 2).'/vendor/autoload.php';

function mysqlTestConnection(?string $database = null): PDO
{
    $socket = (string) getenv('ENGINE_MARIADB_SOCKET');
    $user = (string) (getenv('ENGINE_MARIADB_USER') ?: get_current_user());
    if (getenv('ENGINE_MARIADB_TEST') !== '1' || ! extension_loaded('pdo_mysql')
        || ! str_starts_with($socket, '/') || filetype($socket) !== 'socket'
        || ! preg_match('/\A[A-Za-z0-9_-]+\z/D', $user)
        || ($database !== null && ! preg_match('/\Aservernet_engine_test_[a-f0-9]{16}\z/D', $database))) {
        throw new RuntimeException('Explicit disposable MariaDB test socket and pdo_mysql are required.');
    }
    $connection = new PDO('mysql:unix_socket='.$socket.($database ? ';dbname='.$database : '').';charset=utf8mb4',
        $user, '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ((int) $connection->query('SELECT @@skip_networking')->fetchColumn() !== 1) {
        throw new RuntimeException('This test only accepts an isolated socket-only database server.');
    }
    return $connection;
}

function bootMysqlRace(string $directory, string $database): void
{
    $real = realpath($directory);
    if ($real === false || dirname($real) !== realpath(sys_get_temp_dir())
        || ! str_starts_with(basename($real), 'servernet-engine-mariadb-')) {
        throw new RuntimeException('Only a disposable engine race directory is accepted.');
    }
    mysqlTestConnection($database);
    foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database, 'DB_URL' => '',
        'DB_SOCKET' => (string) getenv('ENGINE_MARIADB_SOCKET'),
        'DB_USERNAME' => (string) (getenv('ENGINE_MARIADB_USER') ?: get_current_user()), 'DB_PASSWORD' => '',
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
    DB::purge('mysql');
    DB::connection()->statement('SET SESSION innodb_lock_wait_timeout = 20');
    Http::fake([]);
    Http::preventStrayRequests();
}

function mysqlAwait(string $file, int $seconds = 15): void
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
    [$script, $mode, $directory, $database, $slot, $serviceId] = $argv;
    bootMysqlRace($directory, $database);
    $synchronized = false;
    DB::listen(function ($query) use ($directory, $slot, &$synchronized) {
        if (! $synchronized && str_contains($query->sql, 'COALESCE(SUM(vcpu)')) {
            $synchronized = true;
            touch($directory.'/read-'.$slot);
            // Hold the actual node lock while the competing connection blocks.
            // Forcing both capacity reads would deadlock a correct InnoDB lock.
            mysqlAwait($directory.'/write');
        }
    });
    touch($directory.'/ready-'.$slot);
    mysqlAwait($directory.'/start');
    touch($directory.'/attempt-'.$slot);
    try {
        $vm = app(Placement::class)->reserve(Service::findOrFail((int) $serviceId), CloudPlan::firstOrFail(), 'ubuntu-24.04');
        echo json_encode(['outcome' => 'reserved', 'id' => $vm->id, 'uuid' => $vm->uuid,
            'vmid' => $vm->vmid, 'address' => $vm->address->address], JSON_THROW_ON_ERROR);
    } catch (DomainException $e) {
        echo json_encode(['outcome' => 'exhausted', 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
    }
    Http::assertNothingSent();
    exit(0);
}

function setupMysqlRace(string $directory, string $database, int $capacity, bool $checkRollback): int
{
    bootMysqlRace($directory, $database);
    Schema::create('customers', fn (Blueprint $t) => $t->id());
    Schema::create('services', function (Blueprint $t) { $t->id(); $t->string('status'); $t->timestamps(); });
    Schema::create('cloud_plans', function (Blueprint $t) {
        $t->id(); $t->string('provider'); $t->string('location_code'); $t->string('arch');
        $t->integer('vcpu'); $t->integer('ram_mb'); $t->integer('disk_gb'); $t->timestamps();
    });
    $paths = glob(dirname(__DIR__, 2).'/database/migrations/2026_11_03_100*.php') ?: [];
    sort($paths);
    if (count($paths) < 6) throw new RuntimeException('Expected the complete new engine migration set.');
    $migrations = array_map(static fn ($path) => require $path, $paths);
    foreach ($migrations as $migration) $migration->up();
    $nonTransactional = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'cloud_engine_%' AND engine <> 'InnoDB'");
    if ((int) $nonTransactional->n !== 0) {
        throw new RuntimeException('All Cloud Engine tables must use the transactional InnoDB engine.');
    }
    if ($checkRollback) {
        foreach (array_reverse($migrations) as $migration) $migration->down();
        if (DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'cloud_engine_%'")->n !== 0) {
            throw new RuntimeException('Engine migration rollback left tables behind.');
        }
        if (! Schema::hasTable('services') || ! Schema::hasTable('cloud_plans') || ! Schema::hasTable('customers')) {
            throw new RuntimeException('Engine rollback removed existing business tables.');
        }
        foreach ($migrations as $migration) $migration->up();
    }
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
        'accepted_at' => now(), 'initialization_verified_at' => now(),
        'metadata' => ['guest_agent_verified' => true]]);
    DB::disconnect();
    return count($paths);
}

$admin = mysqlTestConnection();
$scenarios = ['same_service' => [2, [1, 1], 1], 'two_services' => [2, [1, 2], 2], 'capacity_one' => [1, [1, 2], 1]];
$assertions = 0;
$migrationCount = 0;
foreach ($scenarios as $name => [$capacity, $services, $expected]) {
    $database = 'servernet_engine_test_'.bin2hex(random_bytes(8));
    $directory = sys_get_temp_dir().'/servernet-engine-mariadb-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $children = [];
    $created = false;
    try {
        $admin->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $created = true;
        $migrationCount = setupMysqlRace($directory, $database, $capacity, $name === 'same_service');
        foreach ($services as $slot => $serviceId) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __FILE__, 'child', $directory, $database, (string) $slot, (string) $serviceId],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (! is_resource($process)) throw new RuntimeException('Could not start race worker.');
            fclose($pipes[0]);
            $children[] = [$process, $pipes];
        }
        foreach ([0, 1] as $slot) mysqlAwait($directory.'/ready-'.$slot);
        touch($directory.'/start');
        foreach ([0, 1] as $slot) mysqlAwait($directory.'/attempt-'.$slot);
        $waitObserved = false;
        $deadline = microtime(true) + 10;
        do {
            $waitObserved = (int) $admin->query('SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS')->fetchColumn() > 0;
            if (! $waitObserved && count(glob($directory.'/read-*') ?: []) === 1
                && (int) $admin->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB = '".$database."' AND COMMAND IN ('Query', 'Execute') AND INFO LIKE '%for update%'")->fetchColumn() > 0) {
                // Some MariaDB builds do not populate INNODB_LOCK_WAITS until
                // monitor instrumentation is enabled. Prove the held node lock
                // independently while the other worker executes FOR UPDATE.
                try {
                    $admin->query('SELECT id FROM `'.$database.'`.cloud_engine_nodes WHERE id = 1 FOR UPDATE NOWAIT');
                } catch (PDOException $e) {
                    if ((int) ($e->errorInfo[1] ?? 0) !== 1205) throw $e;
                    $waitObserved = true;
                }
            }
            if ($waitObserved) break;
            usleep(10000);
        } while (microtime(true) < $deadline);
        if (! $waitObserved || count(glob($directory.'/read-*') ?: []) !== 1) {
            $diagnostics = ['capacity_reads' => count(glob($directory.'/read-*') ?: []), 'workers' => [],
                'connections' => $admin->query('SHOW FULL PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC),
                'transactions' => $admin->query('SELECT trx_state, trx_wait_started, trx_query FROM information_schema.INNODB_TRX')->fetchAll(PDO::FETCH_ASSOC)];
            foreach ($children as [$process, $pipes]) {
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $diagnostics['workers'][] = ['state' => proc_get_status($process),
                    'stdout' => stream_get_contents($pipes[1]), 'stderr' => stream_get_contents($pipes[2])];
            }
            throw new RuntimeException('The competing transaction did not demonstrably wait on the InnoDB reservation lock: '
                .json_encode($diagnostics, JSON_THROW_ON_ERROR));
        }
        touch($directory.'/write');
        $outcomes = [];
        foreach ($children as $index => [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $exit = proc_close($process);
            $children[$index] = null;
            if ($exit !== 0) throw new RuntimeException('Race worker failed: '.$error.$output);
            $outcomes[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        }
        $children = [];
        DB::reconnect();
        $vms = EngineVm::all();
        foreach ([count($vms) === $expected, $vms->unique('service_id')->count() === $expected,
            $vms->unique('vmid')->count() === $expected, $vms->unique('address_id')->count() === $expected,
            EngineAddress::where('state', 'allocated')->count() === $expected,
            (int) $vms->sum('vcpu') <= $capacity] as $passed) {
            if (! $passed) throw new RuntimeException('Reservation invariant failed: '.$name);
            $assertions++;
        }
        if ($name === 'same_service' && ($outcomes[0]['id'] ?? null) !== ($outcomes[1]['id'] ?? null)) {
            throw new RuntimeException('Duplicate concurrent service did not return the same reservation.');
        }
        if ($name === 'capacity_one' && count(array_filter($outcomes, fn ($o) => $o['outcome'] === 'exhausted')) !== 1) {
            throw new RuntimeException('Capacity exhaustion was not reported exactly once.');
        }
        echo json_encode(['scenario' => $name, 'result' => 'passed', 'innodb_lock_wait_observed' => true,
            'reservations' => $expected, 'outcomes' => array_column($outcomes, 'outcome')], JSON_THROW_ON_ERROR).PHP_EOL;
    } finally {
        foreach ($children as $child) {
            if ($child === null) continue;
            [$process, $pipes] = $child;
            proc_terminate($process);
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_close($process);
        }
        DB::disconnect();
        if ($created) $admin->exec('DROP DATABASE `'.$database.'`');
        foreach (glob($directory.'/*') ?: [] as $file) unlink($file);
        rmdir($directory);
    }
}
echo json_encode(['result' => 'passed', 'scenarios' => count($scenarios), 'assertions' => $assertions,
    'backend' => 'mariadb_innodb', 'server_version' => $admin->query('SELECT VERSION()')->fetchColumn(),
    'migrations_up_down_up' => $migrationCount, 'processes_per_scenario' => 2, 'external_calls' => 0], JSON_THROW_ON_ERROR).PHP_EOL;
