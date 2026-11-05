<?php
// Run only from a private staged bundle after the owner approves this release.
// No money command, scheduler execution, global cache clear or provider call.
declare(strict_types=1);

$stage = __DIR__;
$live = '/home/servernetcloud/servernet_app';
$home = dirname($live);
$php = '/opt/cpanel/ea-php84/root/usr/bin/php';
$manifest = json_decode(file_get_contents($stage.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$locks = [];
$event = null;
$held = false;
$installed = [];
$migration = 'database/migrations/2026_11_04_000100_widen_payment_reference_for_crypto.php';

function digest(string $path): ?string {
    if (is_link($path)) throw new RuntimeException('Symlink rejected');
    return is_file($path) ? hash_file('sha256', $path) : null;
}
function putAtomic(string $path, string $data, int $mode): void {
    $tmp = $path.'.crypto-release-stage';
    $fd = fopen($tmp, 'x');
    if ($fd === false) throw new RuntimeException('Staging collision');
    try {
        if (fwrite($fd, $data) !== strlen($data)) throw new RuntimeException('Short write');
        fflush($fd);
        fsync($fd);
        chmod($tmp, $mode);
    } finally { fclose($fd); }
    if (! rename($tmp, $path)) throw new RuntimeException('Rename failed');
}
function check(bool $ok, string $message): void {
    if (! $ok) throw new RuntimeException($message);
}

try {
    set_time_limit(90);
    foreach (['.servernet-web-release.lock', 'servernet-release.lock'] as $name) {
        check(! is_link($home.'/'.$name), 'Lock symlink');
        $handle = fopen($home.'/'.$name, 'c');
        check($handle !== false && flock($handle, LOCK_EX | LOCK_NB), 'Release busy');
        $locks[] = $handle;
    }
    check(! file_exists($stage.'/started.json'), 'Release already started; inspect receipt first');
    check(PHP_VERSION_ID >= 80400 && PHP_VERSION_ID < 80500, 'Unexpected PHP');
    require $live.'/vendor/autoload.php';
    $app = require $live.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    foreach ($app->make(Illuminate\Console\Scheduling\Schedule::class)->events() as $candidate) {
        if (str_contains($candidate->command ?? '', 'crypto:watch')) {
            check($event === null, 'Multiple crypto events');
            $event = $candidate;
        }
    }
    check($event !== null && $event->withoutOverlapping && $event->expiresAt === 5, 'Scheduler contract changed');
    check($event->mutex->create($event), 'Crypto job busy; no files changed');
    $held = true;
    $db = Illuminate\Support\Facades\DB::connection();
    check($db->getDriverName() === 'mariadb' || $db->getDriverName() === 'mysql', 'Unexpected DB');
    $db->statement('SET SESSION lock_wait_timeout=10');
    $column = $db->selectOne("SHOW COLUMNS FROM payments WHERE Field='ref_id'");
    check(strtolower($column->Type) === 'varchar(64)' && $column->Null === 'YES', 'Column drift');
    $migrationName = basename($migration, '.php');
    check(! $db->table('migrations')->where('migration', $migrationName)->exists(), 'Migration already applied');
    foreach ($manifest['files'] as $path => $entry) {
        check(digest($live.'/'.$path) === $entry['before'], 'Live drift: '.$path);
        check(digest($stage.'/source/'.$path) === $entry['after'], 'Bundle drift: '.$path);
        exec(escapeshellarg($php).' -l '.escapeshellarg($stage.'/source/'.$path).' 2>/dev/null', $lint, $status);
        check($status === 0, 'Lint failure');
    }
    // Targeted schema backup and hashed old references; no customer/payment dump.
    mkdir($stage.'/before', 0700);
    file_put_contents($stage.'/before/schema.json', json_encode($db->select('SHOW CREATE TABLE payments'), JSON_THROW_ON_ERROR));
    chmod($stage.'/before/schema.json', 0600);
    $refs = $db->table('payments')->selectRaw('id, SHA2(COALESCE(ref_id, "<NULL>"), 256) AS digest')->orderBy('id')->get()->keyBy('id')->map(fn ($r) => $r->digest)->all();
    file_put_contents($stage.'/before/reference-digests.json', json_encode($refs, JSON_THROW_ON_ERROR));
    chmod($stage.'/before/reference-digests.json', 0600);
    foreach ($manifest['files'] as $path => $entry) {
        if ($entry['before'] === null) continue;
        $backup = $stage.'/before/'.$path;
        if (! is_dir(dirname($backup))) mkdir(dirname($backup), 0700, true);
        check(copy($live.'/'.$path, $backup), 'Backup failure');
        chmod($backup, 0600);
        check(digest($backup) === $entry['before'], 'Backup drift');
    }
    file_put_contents($stage.'/started.json', json_encode(['utc'=>gmdate('c'), 'commit'=>$manifest['commit']], JSON_THROW_ON_ERROR));
    chmod($stage.'/started.json', 0600);
    // Additive schema first; it remains compatible with the original code.
    check(digest($live.'/'.$migration) === null, 'Migration appeared concurrently');
    putAtomic($live.'/'.$migration, file_get_contents($stage.'/source/'.$migration), 0644);
    $installed[] = $migration;
    $result = Illuminate\Support\Facades\Artisan::call('migrate', ['--path'=>$migration, '--force'=>true]);
    check($result === 0, 'Targeted migration failed');
    $column = $db->selectOne("SHOW COLUMNS FROM payments WHERE Field='ref_id'");
    check(strtolower($column->Type) === 'varchar(128)' && $column->Null === 'YES', 'Expanded schema not verified');
    check($db->table('migrations')->where('migration', $migrationName)->count() === 1, 'Migration receipt missing');
    foreach ($refs as $id => $hash) {
        $row = $db->table('payments')->where('id', $id)->selectRaw('SHA2(COALESCE(ref_id, "<NULL>"), 256) AS digest')->first();
        check($row !== null && $row->digest === $hash, 'Existing reference changed');
    }
    foreach ($manifest['files'] as $path => $entry) {
        if ($path === $migration) continue;
        check(digest($live.'/'.$path) === $entry['before'], 'Concurrent edit: '.$path);
        putAtomic($live.'/'.$path, file_get_contents($stage.'/source/'.$path), 0644);
        $installed[] = $path;
    }
    foreach ($manifest['files'] as $path => $entry) check(digest($live.'/'.$path) === $entry['after'], 'Installed drift');
    $receipt = ['status'=>'installed','commit'=>$manifest['commit'],'utc'=>gmdate('c'),'source_files'=>3,'ref_type'=>$column->Type,'old_references_preserved'=>count($refs),'migration_applied'=>true,'crypto_mutex_held'=>true,'global_cache_clear'=>false,'financial_commands'=>0,'provider_calls'=>0];
    file_put_contents($stage.'/installed.json', json_encode($receipt, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    chmod($stage.'/installed.json', 0600);
    echo json_encode($receipt, JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    // Retain additive schema and migration history. Restore only our own runtime writes.
    foreach (array_reverse($installed) as $path) {
        if ($path === $migration) continue;
        if (digest($live.'/'.$path) === $manifest['files'][$path]['after']) {
            putAtomic($live.'/'.$path, file_get_contents($stage.'/before/'.$path), 0644);
        }
    }
    echo json_encode(['status'=>'stopped','class'=>$error::class,'reason'=>$error instanceof RuntimeException ? $error->getMessage() : 'Inspect privately; no raw SQL printed','schema_rollback'=>false]), PHP_EOL;
    $exitCode = 1;
} finally {
    if ($held) $event->mutex->forget($event);
    foreach (array_reverse($locks) as $handle) fclose($handle);
}
exit($exitCode ?? 0);
