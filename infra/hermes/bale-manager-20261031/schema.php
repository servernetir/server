<?php
/** Private additive evidence migration. The release caller holds worker locks. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('HOME') !== '/home/servernetcloud'
    || getenv('BALE_MANAGER_LOCKS_HELD') !== '1') { exit(1); }
umask(0077);
$base = '/home/servernetcloud/servernet_app';
$private = '/home/servernetcloud/deploy-backups/bale-manager-20261031';
$handles = [];
try {
    foreach (['.servernet-web-release.lock', 'servernet-release.lock'] as $name) {
        $path = '/home/servernetcloud/'.$name;
        if (is_link($path)) { throw new RuntimeException('release_lock_invalid'); }
        $handle = fopen($path, 'c+b');
        if (! $handle || ! flock($handle, LOCK_EX | LOCK_NB)) { throw new RuntimeException('release_busy'); }
        $handles[] = $handle;
    }
    require $base.'/vendor/autoload.php';
    $app = require $base.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (App\Models\Setting::get('bale_manager_policy_v2_enabled') === '1') { throw new RuntimeException('disable_policy_before_schema'); }
    if (is_link($private) || ! is_dir($private) || (fileperms($private) & 0777) !== 0700) {
        throw new RuntimeException('private_release_required');
    }
    $schema = Illuminate\Support\Facades\Schema::getColumnListing('bank_transfer_receipts');
    if ($schema === []) { throw new RuntimeException('receipt_table_unavailable'); }
    $backup = $private.'/bank-receipts-before.enc';
    if (is_link($backup)) { throw new RuntimeException('backup_path_invalid'); }
    if (! is_file($backup)) {
        $snapshot = Illuminate\Support\Facades\DB::transaction(static fn () => [
            'version' => 1, 'at' => gmdate('c'), 'columns' => $schema,
            'rows' => Illuminate\Support\Facades\DB::table('bank_transfer_receipts')->orderBy('id')->get()->all(),
        ]);
        $encoded = Illuminate\Support\Facades\Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR));
        $handle = fopen($backup, 'x+b');
        if (! $handle || fwrite($handle, $encoded) !== strlen($encoded)) { throw new RuntimeException('backup_write_failed'); }
        fflush($handle); fclose($handle); chmod($backup, 0600);
    }
    // Validate that the durable backup can actually be decrypted before DDL.
    $snapshot = json_decode(Illuminate\Support\Facades\Crypt::decryptString(file_get_contents($backup)), true, 64, JSON_THROW_ON_ERROR);
    if (($snapshot['version'] ?? null) !== 1 || ! is_array($snapshot['rows'] ?? null)) { throw new RuntimeException('backup_unverified'); }
    $migration = 'database/migrations/2026_10_31_110000_add_bank_receipt_evidence.php';
    if (! is_file($base.'/'.$migration) || is_link($base.'/'.$migration)) { throw new RuntimeException('migration_unavailable'); }
    $exit = Illuminate\Support\Facades\Artisan::call('migrate', ['--path' => $migration, '--force' => true]);
    if ($exit !== 0) { throw new RuntimeException('migration_failed'); }
    $columns = Illuminate\Support\Facades\Schema::getColumnListing('bank_transfer_receipts');
    $expected = ['evidence_path', 'evidence_name', 'evidence_mime', 'evidence_sha256', 'evidence_size'];
    if (array_diff($expected, $columns) !== []) { throw new RuntimeException('schema_incomplete'); }
    $metadata = Illuminate\Support\Facades\Schema::getColumns('bank_transfer_receipts');
    foreach ($metadata as $column) {
        if (in_array($column['name'], $expected, true) && ! $column['nullable']) { throw new RuntimeException('schema_not_nullable'); }
    }
    // Only this new editable acknowledgement; never run the global seeder.
    App\Models\NotificationTemplate::firstOrCreate(['key' => 'lead_reviewed'], [
        'title' => 'بررسی درخواست متقاضی توسط مدیر', 'group' => 'account', 'audience' => 'customer',
        'sms_event' => 'lead_reviewed', 'email_subject' => 'بررسی درخواست شما در سرورنت',
        'email_body' => '<p>درخواست شما با کد <b>{code}</b> توسط تیم سرورنت بررسی شد.</p><p>ادامه پیگیری: servernet.cloud</p>',
        'bale_body' => null, 'is_active' => true,
        'variables' => [['name' => 'code', 'desc' => 'کد پیگیری درخواست']],
    ]);
    $receipt = ['status' => 'schema_ready', 'at' => gmdate('c'), 'columns_added_or_verified' => $expected,
        'migration' => basename($migration, '.php'), 'backup_sha256' => hash_file('sha256', $backup),
        'backup_rows' => count($snapshot['rows']), 'current_rows' => Illuminate\Support\Facades\DB::table('bank_transfer_receipts')->count(),
        'customer_messages_sent' => 0, 'other_migrations_run' => false];
    $path = $private.'/schema-receipt.json';
    if (is_link($path)) { throw new RuntimeException('receipt_path_invalid'); }
    file_put_contents($path, json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($path, 0600);
    echo json_encode($receipt, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['status' => 'schema_stopped', 'exception_class' => $error::class,
        'reason' => $error instanceof RuntimeException && preg_match('/^[a-z_]+$/D', $error->getMessage())
            ? $error->getMessage() : 'inspect_private_runtime'], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
} finally {
    foreach (array_reverse($handles) as $handle) { flock($handle, LOCK_UN); fclose($handle); }
}
