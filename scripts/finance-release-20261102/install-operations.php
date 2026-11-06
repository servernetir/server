<?php
declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed release operations. The caller authenticates, pins this file and the
 * private plan, and holds BOTH release locks for the entire invocation.
 * This file never registers accounts, posts journals or rolls back schema.
 */
function financeReleasePrivatePath(string $stage, string $relative): string
{
    if (!preg_match('~\A[a-zA-Z0-9._/-]+\z~D', $relative)
        || str_starts_with($relative, '/') || str_contains($relative, '..')
        || str_contains($relative, '//')) {
        throw new RuntimeException('private_path');
    }
    $path = $stage;
    foreach (explode('/', $relative) as $part) {
        $path .= '/' . $part;
        if (is_link($path)) {
            throw new RuntimeException('private_symlink');
        }
    }
    return $path;
}

function financeReleaseDirectory(string $stage, string $relative): string
{
    $path = financeReleasePrivatePath($stage, $relative);
    if (!is_dir($path) && !mkdir($path, 0700)) {
        throw new RuntimeException('private_directory');
    }
    if (realpath($path) !== $path || !chmod($path, 0700)) {
        throw new RuntimeException('private_directory_mode');
    }
    return $path;
}

function financeReleaseWrite(string $stage, string $relative, string $data): void
{
    $path = financeReleasePrivatePath($stage, $relative);
    if (realpath(dirname($path)) !== dirname($path)) {
        throw new RuntimeException('private_parent');
    }
    $temporary = $path . '.new-' . bin2hex(random_bytes(8));
    $handle = fopen($temporary, 'x+b');
    if ($handle === false) {
        throw new RuntimeException('private_open');
    }
    try {
        if (!chmod($temporary, 0600)) {
            throw new RuntimeException('private_mode');
        }
        $offset = 0;
        while ($offset < strlen($data)) {
            $written = fwrite($handle, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('private_write');
            }
            $offset += $written;
        }
        if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
            throw new RuntimeException('private_flush');
        }
        fclose($handle);
        $handle = null;
        if (hash_file('sha256', $temporary) !== hash('sha256', $data)
            || is_link($path) || !rename($temporary, $path)) {
            throw new RuntimeException('private_activate');
        }
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
        if (is_file($temporary) && !is_link($temporary)) {
            unlink($temporary);
        }
    }
}

function financeReleaseJson(string $stage, string $relative, array $value): void
{
    financeReleaseWrite($stage, $relative, json_encode($value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}

function financeReleaseTarget(string $home, string $relative): string
{
    if (!preg_match('~\A(?:servernet_app|public_html)/[a-zA-Z0-9._/-]+\z~D', $relative)
        || str_contains($relative, '..') || str_contains($relative, '//')) {
        throw new RuntimeException('target_path');
    }
    $path = $home;
    foreach (explode('/', $relative) as $part) {
        $path .= '/' . $part;
        if (is_link($path)) {
            throw new RuntimeException('target_symlink');
        }
    }
    if (realpath(dirname($path)) !== dirname($path)
        || (file_exists($path) && !is_file($path))) {
        throw new RuntimeException('target_parent_or_type');
    }
    return $path;
}

function financeReleaseHash(string $path): ?string
{
    clearstatcache(true, $path);
    if (is_link($path)) {
        throw new RuntimeException('hash_symlink');
    }
    if (!file_exists($path)) {
        return null;
    }
    if (!is_file($path) || ($hash = hash_file('sha256', $path)) === false) {
        throw new RuntimeException('hash_file');
    }
    return $hash;
}

function financeReleaseSameKeys(array $actual, array $expected): bool
{
    $keys = array_keys($actual);
    sort($keys);
    sort($expected);
    return $keys === $expected;
}

function financeReleaseContext($app, string $home, string $stage, array $scope, array $plan): array
{
    if ($home !== '/home/servernetcloud' || realpath($home) !== $home
        || !preg_match('~\A/home/servernetcloud/finance-release-20261102-[a-f0-9]{12}\z~D', $stage)
        || is_link($stage) || realpath($stage) !== $stage
        || (fileperms($stage) & 0777) !== 0700
        || realpath($app->basePath()) !== $home . '/servernet_app') {
        throw new RuntimeException('release_context');
    }
    if (hash_file('sha256', financeReleasePrivatePath($stage, 'scope.json'))
        !== '6c745280707364772150a5984c9a13800efbf929496cd733e3df373f724e8f40'
        || $scope !== json_decode(file_get_contents($stage . '/scope.json'), true, 32, JSON_THROW_ON_ERROR)
        || !financeReleaseSameKeys($plan, ['source_commit', 'files', 'dependencies', 'app_key_fingerprint', 'opcache_revalidate_wait_seconds'])
        || $plan['source_commit'] !== '190ff59cc1531399c7cdbd8b928b54d3c7727ae6'
        || $scope['source_commit'] !== $plan['source_commit']
        || !is_array($plan['files']) || !is_array($plan['dependencies'])
        || !is_string($plan['app_key_fingerprint'])
        || !preg_match('/\A[a-f0-9]{64}\z/D', $plan['app_key_fingerprint'])
        || !is_int($plan['opcache_revalidate_wait_seconds'])
        || $plan['opcache_revalidate_wait_seconds'] < 1 || $plan['opcache_revalidate_wait_seconds'] > 30) {
        throw new RuntimeException('release_plan');
    }
    if (!is_string(config('app.key')) || config('app.key') === ''
        || !hash_equals($plan['app_key_fingerprint'], hash('sha256', config('app.key')))
        || !in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        throw new RuntimeException('release_runtime');
    }
    // The pinned plan is prepared from observations of both production pools.
    if (filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN)
        && ((int) ini_get('opcache.validate_timestamps') !== 1
            || (int) ini_get('opcache.revalidate_freq') >= $plan['opcache_revalidate_wait_seconds'])) {
        throw new RuntimeException('release_opcache_preflight');
    }
    $expected = $dependencies = [];
    foreach ($scope['files'] as $row) {
        foreach ($row['destinations'] as $destination) {
            $expected[$destination] = $row['source'];
        }
    }
    foreach ($scope['required_shared_dependencies'] as $row) {
        foreach ($row['destinations'] as $destination) {
            $dependencies[$destination] = $row['source'];
        }
    }
    if (count($expected) !== 28 || count($dependencies) !== 15
        || !financeReleaseSameKeys($plan['files'], array_keys($expected))
        || !financeReleaseSameKeys($plan['dependencies'], array_keys($dependencies))) {
        throw new RuntimeException('release_allowlist');
    }
    $decoded = $sourceHashes = [];
    foreach ($expected as $destination => $source) {
        $row = $plan['files'][$destination];
        if (!is_array($row) || !financeReleaseSameKeys($row, ['source', 'before', 'after', 'data_b64'])
            || $row['source'] !== $source || !is_string($row['after'])
            || !preg_match('/\A[a-f0-9]{64}\z/D', $row['after'])
            || ($row['before'] !== null && (!is_string($row['before'])
                || !preg_match('/\A[a-f0-9]{64}\z/D', $row['before'])))
            || !is_string($row['data_b64'])
            || ($bytes = base64_decode($row['data_b64'], true)) === false
            || $bytes === '' || strlen($bytes) > 2097152
            || hash('sha256', $bytes) !== $row['after']) {
            throw new RuntimeException('release_payload');
        }
        if (isset($sourceHashes[$source]) && $sourceHashes[$source] !== $row['after']) {
            throw new RuntimeException('release_duplicate_source');
        }
        $sourceHashes[$source] = $row['after'];
        financeReleaseTarget($home, $destination);
        if (str_ends_with($source, '.php') && !str_ends_with($source, '.blade.php')) {
            token_get_all($bytes, TOKEN_PARSE);
        }
        $decoded[$destination] = $bytes;
    }
    foreach ($dependencies as $destination => $source) {
        $hash = $plan['dependencies'][$destination];
        if (!is_string($hash) || !preg_match('/\A[a-f0-9]{64}\z/D', $hash)
            || financeReleaseHash(financeReleaseTarget($home, $destination)) !== $hash) {
            throw new RuntimeException('release_dependency_changed');
        }
    }
    return ['files' => $expected, 'bytes' => $decoded,
        'plan_hash' => hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR)),
        'source_commit' => $plan['source_commit']];
}

function financeReleaseMigrations(array $scope): array
{
    $fixed = [
        'website/database/migrations/2026_10_31_180000_add_bank_identity_to_finance_accounts.php',
        'website/database/migrations/2026_10_31_230100_add_related_voucher_to_finance_vouchers.php',
    ];
    if ($scope['migrations'] !== $fixed) {
        throw new RuntimeException('release_migration_allowlist');
    }
    foreach (['finance_accounts', 'finance_vouchers', 'finance_lines', 'finance_controls', 'finance_audit_events', 'migrations'] as $table) {
        if (!Schema::hasTable($table)) {
            throw new RuntimeException('release_base_schema');
        }
    }
    if (!DB::table('finance_controls')->where('id', 1)->exists()) {
        throw new RuntimeException('release_control_missing');
    }
    // A populated repository also prevents migrate from loading a schema dump.
    if (DB::table('migrations')->where('migration', '2026_10_25_120000_create_documented_accounting_tables')->count() !== 1) {
        throw new RuntimeException('release_base_migration_missing');
    }
    $states = [];
    foreach ($fixed as $number => $source) {
        $table = $number === 0 ? 'finance_accounts' : 'finance_vouchers';
        $columns = $number === 0 ? ['bank_details', 'bank_iban_fingerprint', 'partner_key'] : ['related_voucher_id'];
        $present = array_intersect($columns, Schema::getColumnListing($table));
        $records = DB::table('migrations')->where('migration', basename($source, '.php'))->count();
        if ($records === 0 && $present === []) {
            $states[$source] = 'pending';
        } elseif ($records === 1 && count($present) === count($columns)) {
            $definitions = array_column(Schema::getColumns($table), null, 'name');
            foreach ($columns as $column) {
                if (!$definitions[$column]['nullable'] || $definitions[$column]['generation'] !== null) {
                    throw new RuntimeException('release_column_definition');
                }
            }
            if (($number === 0 && ($definitions['bank_details']['type_name'] !== 'text'
                    || $definitions['bank_iban_fingerprint']['type'] !== 'char(64)'
                    || $definitions['partner_key']['type'] !== 'varchar(80)'))
                || ($number === 1 && !preg_match('/\Abigint(?:\([0-9]+\))? unsigned\z/D', $definitions['related_voucher_id']['type']))) {
                throw new RuntimeException('release_column_type');
            }
            $indexes = Schema::getIndexes($table);
            $hasIndex = static function (array $names, bool $unique = false) use ($indexes): bool {
                foreach ($indexes as $index) {
                    if ($index['columns'] === $names && (!$unique || $index['unique'])) {
                        return true;
                    }
                }
                return false;
            };
            if ($number === 0 && (!$hasIndex(['bank_iban_fingerprint'], true) || !$hasIndex(['partner_key']))) {
                throw new RuntimeException('release_identity_indexes');
            }
            if ($number === 1) {
                $foreign = array_filter(Schema::getForeignKeys($table), static fn (array $key): bool =>
                    $key['columns'] === ['related_voucher_id'] && $key['foreign_table'] === 'finance_vouchers'
                    && $key['foreign_columns'] === ['id'] && in_array(strtolower($key['on_delete']), ['restrict', 'no action'], true));
                if (!$hasIndex(['related_voucher_id']) || $foreign === []) {
                    throw new RuntimeException('release_related_foreign_key');
                }
            }
            $states[$source] = 'applied';
        } else {
            throw new RuntimeException('release_partial_schema');
        }
    }
    return $states;
}

function financeReleaseOrder(array $scope): array
{
    $relative = [
        'database/migrations/2026_10_31_180000_add_bank_identity_to_finance_accounts.php',
        'database/migrations/2026_10_31_230100_add_related_voucher_to_finance_vouchers.php',
        'app/Models/FinanceAccount.php', 'app/Models/FinanceVoucher.php',
        'app/Services/Finance/FinanceAccountRegistry.php', 'app/Services/Finance/DocumentedAccounting.php',
        'app/Services/Finance/SupplierCreditReconciliation.php', 'app/Services/Finance/AccountingReport.php',
        'app/Services/Finance/FinanceAudit.php', 'app/Services/Finance/BankReconciliation.php',
        'app/Services/Finance/BusinessLedger.php', 'app/Services/Finance/WalletRefunds.php',
        'app/Services/Finance/TaxEvidenceBundle.php',
        'lang/fa/finance.php', 'lang/fa/refund.php', 'lang/en/finance.php', 'lang/en/refund.php',
        'lang/tr/finance.php', 'lang/tr/refund.php',
        'public/assets/css/admin-wallet-refund.css', 'public/assets/css/finance-funding.css',
        'app/Http/Controllers/Admin/AccountingController.php', 'app/Http/Controllers/Admin/WalletRefundController.php',
        'resources/views/admin/accounting-voucher.blade.php', 'resources/views/admin/accounting.blade.php',
        'resources/views/admin/wallet-refund.blade.php',
    ];
    $rows = array_column($scope['files'], null, 'source');
    $expected = array_map(static fn (string $path): string => 'website/' . $path, $relative);
    if (!financeReleaseSameKeys($rows, $expected)) {
        throw new RuntimeException('release_publish_order');
    }
    $ordered = [];
    foreach ($expected as $source) {
        foreach ($rows[$source]['destinations'] as $destination) {
            $ordered[$destination] = $source;
        }
    }
    if (count($ordered) !== 28) {
        throw new RuntimeException('release_publish_count');
    }
    return $ordered;
}

function financeReleaseCompiled($app, string $home, string $source, string $bytes): array
{
    $fixed = ['website/resources/views/admin/accounting-voucher.blade.php',
        'website/resources/views/admin/accounting.blade.php', 'website/resources/views/admin/wallet-refund.blade.php'];
    if (!in_array($source, $fixed, true)) {
        throw new RuntimeException('release_view_allowlist');
    }
    $sourcePath = financeReleaseTarget($home, 'servernet_app/' . substr($source, 8));
    $compiler = $app->make('blade.compiler');
    $compiled = $compiler->getCompiledPath($sourcePath);
    $directory = $home . '/servernet_app/storage/framework/views';
    if (dirname($compiled) !== $directory || realpath($directory) !== $directory
        || !preg_match('/\A[a-f0-9]+\.php\z/D', basename($compiled)) || is_link($compiled)) {
        throw new RuntimeException('release_compiled_path');
    }
    $previous = $compiler->getPath();
    try {
        $compiler->setPath($sourcePath);
        $data = $compiler->compileString($bytes);
        $last = null;
        foreach (token_get_all($data) as $token) {
            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG], true)) {
                $last = $token[0];
            }
        }
        if ($last !== null && $last !== T_CLOSE_TAG) {
            $data .= ' ?>';
        }
        $data .= "<?php /**PATH {$sourcePath} ENDPATH**/ ?>";
        token_get_all($data, TOKEN_PARSE);
    } finally {
        $compiler->setPath($previous);
    }
    return ['path' => $compiled, 'source_path' => $sourcePath,
        'data' => $data, 'sha256' => hash('sha256', $data)];
}

function financeReleaseSnapshot(string $stage, string $name, string $path, string $kind): array
{
    $before = financeReleaseHash($path);
    $mode = $before === null ? null : (fileperms($path) & 0777);
    $relative = $before === null ? null : 'before/' . hash('sha256', $name) . '.bin';
    if ($relative !== null) {
        $data = file_get_contents($path);
        if ($data === false || hash('sha256', $data) !== $before) {
            throw new RuntimeException('release_backup_drift');
        }
        financeReleaseWrite($stage, $relative, $data);
        if (financeReleaseHash($stage . '/' . $relative) !== $before) {
            throw new RuntimeException('release_backup_hash');
        }
    }
    return ['destination' => $name, 'kind' => $kind, 'before' => $before,
        'mode' => $mode, 'backup' => $relative];
}

function financeReleaseOption(string $value): string
{
    if (str_contains($value, "\0")) {
        throw new RuntimeException('release_database_option');
    }
    return '"' . str_replace(["\\", '"', "\n", "\r", "\t"], ["\\\\", '\\"', '\\n', '\\r', '\\t'], $value) . '"';
}

function financeReleaseDatabaseBackup(string $stage): array
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('release_backup_process_unavailable');
    }
    $binary = null;
    foreach (['/usr/bin/mariadb-dump', '/usr/bin/mysqldump'] as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            $binary = $candidate;
            break;
        }
    }
    if ($binary === null) {
        throw new RuntimeException('release_backup_binary_unavailable');
    }
    $configuration = DB::connection()->getConfig();
    $database = $configuration['database'] ?? '';
    if (!is_string($database) || !preg_match('/\A[a-zA-Z0-9_][a-zA-Z0-9_$.-]{0,63}\z/D', $database)) {
        throw new RuntimeException('release_database_name');
    }
    if (DB::selectOne('SELECT DATABASE() AS current_database')->current_database !== $database) {
        throw new RuntimeException('release_database_selection');
    }
    $options = "[client]\n";
    foreach (['host', 'username', 'password'] as $key) {
        $name = $key === 'username' ? 'user' : $key;
        $value = $configuration[$key] ?? '';
        if (!is_scalar($value)) {
            throw new RuntimeException('release_database_configuration');
        }
        $options .= $name . '=' . financeReleaseOption((string) $value) . "\n";
    }
    $port = (string) ($configuration['port'] ?? '3306');
    if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('release_database_port');
    }
    $options .= 'port=' . $port . "\ndefault-character-set=utf8mb4\n";
    if (!empty($configuration['unix_socket'])) {
        $options .= 'socket=' . financeReleaseOption((string) $configuration['unix_socket']) . "\n";
    }
    $cnf = financeReleasePrivatePath($stage, 'database-client.cnf');
    $sql = financeReleasePrivatePath($stage, 'before/accounting-before.sql');
    $errors = financeReleasePrivatePath($stage, 'database-dump.stderr');
    foreach ([$cnf, $sql, $errors] as $path) {
        if (file_exists($path)) {
            throw new RuntimeException('release_backup_already_exists');
        }
    }
    $tables = ['finance_accounts', 'finance_vouchers', 'finance_lines', 'finance_controls', 'finance_audit_events', 'migrations'];
    $engines = DB::select('SELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?, ?, ?, ?, ?)', $tables);
    if (count($engines) !== count($tables)) {
        throw new RuntimeException('release_backup_table_inventory');
    }
    foreach ($engines as $table) {
        if (!in_array($table->table_name, $tables, true) || strtolower((string) $table->engine) !== 'innodb') {
            throw new RuntimeException('release_backup_transactional_tables_required');
        }
    }
    $output = $errorOutput = $process = null;
    try {
        financeReleaseWrite($stage, 'database-client.cnf', $options);
        $output = fopen($sql, 'x+b');
        $errorOutput = fopen($errors, 'x+b');
        if ($output === false || $errorOutput === false || !chmod($sql, 0600) || !chmod($errors, 0600)) {
            throw new RuntimeException('release_backup_open');
        }
        $arguments = [$binary, '--defaults-file=' . $cnf, '--single-transaction', '--skip-lock-tables',
            '--no-tablespaces', '--hex-blob', '--skip-extended-insert', '--', $database, ...$tables];
        $process = proc_open($arguments, [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $errorOutput],
            $pipes, $stage, ['PATH' => '/usr/bin:/bin', 'HOME' => $stage, 'LANG' => 'C'], ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('release_backup_start');
        }
        $deadline = microtime(true) + 60;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('release_backup_timeout');
            }
            usleep(100000);
        } while (true);
        $closed = proc_close($process);
        $process = null;
        $exit = $status['exitcode'] >= 0 ? $status['exitcode'] : $closed;
        if ($exit !== 0 || !fflush($output) || (function_exists('fsync') && !fsync($output))) {
            throw new RuntimeException('release_backup_failed');
        }
        fclose($output);
        $output = null;
        fclose($errorOutput);
        $errorOutput = null;
        $stream = fopen($sql, 'rb');
        if ($stream === false) {
            throw new RuntimeException('release_backup_read');
        }
        $created = $dropped = $rows = array_fill_keys($tables, 0);
        $foreignOff = false;
        try {
            while (($line = fgets($stream)) !== false) {
                $foreignOff = $foreignOff || str_contains($line, 'FOREIGN_KEY_CHECKS=0');
                foreach ($tables as $table) {
                    $created[$table] += str_starts_with($line, 'CREATE TABLE `' . $table . '`') ? 1 : 0;
                    $dropped[$table] += str_starts_with($line, 'DROP TABLE IF EXISTS `' . $table . '`') ? 1 : 0;
                    $rows[$table] += str_starts_with($line, 'INSERT INTO `' . $table . '`') ? 1 : 0;
                }
            }
            if (!feof($stream) || fseek($stream, max(0, filesize($sql) - 8192)) !== 0) {
                throw new RuntimeException('release_backup_scan');
            }
            $tail = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
        if (!$foreignOff || !str_contains($tail, '-- Dump completed on ')
            || !str_contains($tail, 'FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS')
            || array_filter($created, static fn (int $count): bool => $count !== 1)
            || array_filter($dropped, static fn (int $count): bool => $count !== 1)) {
            throw new RuntimeException('release_backup_incomplete');
        }
        return ['path' => 'before/accounting-before.sql', 'sha256' => financeReleaseHash($sql),
            'bytes' => filesize($sql), 'tables' => $tables, 'dumped_rows' => $rows,
            'process_exit' => 0, 'structure_verified' => true, 'restore_executed' => false];
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            usleep(100000);
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
        }
        foreach ([$output, $errorOutput] as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
        foreach ([$cnf, $errors] as $temporary) {
            if (is_file($temporary) && !is_link($temporary) && !unlink($temporary)) {
                throw new RuntimeException('release_database_temporary_cleanup');
            }
        }
    }
}

function financeReleasePublish(string $path, string $bytes, ?string $before, int $mode, bool $acceptExactAfter = false): void
{
    $after = hash('sha256', $bytes);
    $actual = financeReleaseHash($path);
    if ($actual !== $before && !($acceptExactAfter && $actual === $after)) {
        throw new RuntimeException('release_before_changed');
    }
    if ($actual === $after) {
        return;
    }
    $temporary = dirname($path) . '/.finance-release-' . bin2hex(random_bytes(12));
    $handle = fopen($temporary, 'x+b');
    if ($handle === false) {
        throw new RuntimeException('release_target_open');
    }
    try {
        if (!chmod($temporary, $mode)) {
            throw new RuntimeException('release_target_mode');
        }
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('release_target_write');
            }
            $offset += $written;
        }
        if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
            throw new RuntimeException('release_target_flush');
        }
        fclose($handle);
        $handle = null;
        $actual = financeReleaseHash($path);
        if ($actual !== $before && !($acceptExactAfter && $actual === $after)) {
            throw new RuntimeException('release_before_changed');
        }
        if (financeReleaseHash($temporary) !== $after || !rename($temporary, $path)
            || financeReleaseHash($path) !== $after) {
            throw new RuntimeException('release_target_activate');
        }
        if (str_ends_with($path, '.php') && !str_ends_with($path, '.blade.php')
            && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN)) {
            if (!function_exists('opcache_invalidate') || !function_exists('opcache_is_script_cached')) {
                throw new RuntimeException('release_opcache_functions_unavailable');
            }
            $cached = opcache_is_script_cached($path);
            if (!opcache_invalidate($path, true) && $cached) {
                throw new RuntimeException('release_opcache_invalidation_failed');
            }
        }
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
        if (is_file($temporary) && !is_link($temporary)) {
            unlink($temporary);
        }
    }
}

function financeReleaseAfter($app, string $home, string $stage, array $scope, array $plan, array $context, array $state): void
{
    if (($state['plan_hash'] ?? null) !== $context['plan_hash'] || ($state['phase'] ?? null) !== 'installed'
        || count($state['compiled'] ?? []) !== 3) {
        throw new RuntimeException('release_state_mismatch');
    }
    foreach ($plan['files'] as $destination => $row) {
        if (financeReleaseHash(financeReleaseTarget($home, $destination)) !== $row['after']) {
            throw new RuntimeException('release_after_changed');
        }
    }
    foreach (financeReleaseMigrations($scope) as $status) {
        if ($status !== 'applied') {
            throw new RuntimeException('release_schema_incomplete');
        }
    }
    if (array_keys($state['compiled']) !== $scope['blade_views']) {
        throw new RuntimeException('release_compiled_allowlist');
    }
    foreach ($state['compiled'] as $source => $row) {
        $path = $app->make('blade.compiler')->getCompiledPath($home . '/servernet_app/' . substr($source, 8));
        if ($row['path'] !== $path || financeReleaseHash($path) !== $row['sha256']) {
            throw new RuntimeException('release_compiled_changed');
        }
        token_get_all(file_get_contents($path), TOKEN_PARSE);
    }
    if (financeReleaseHash($stage . '/before/accounting-before.sql') !== ($state['database_backup']['sha256'] ?? null)
        || financeReleaseHash($stage . '/before/manifest.json') !== ($state['backup_manifest_sha256'] ?? null)) {
        throw new RuntimeException('release_backup_changed');
    }
    $manifest = json_decode(file_get_contents($stage . '/before/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
    $keys = array_merge(array_keys($context['files']), array_map(
        static fn (string $source): string => 'compiled:' . $source, $scope['blade_views']));
    if (!financeReleaseSameKeys($manifest['files'] ?? [], $keys)) {
        throw new RuntimeException('release_backup_manifest');
    }
    foreach ($manifest['files'] as $name => $row) {
        $expectedBackup = $row['before'] === null ? null : 'before/' . hash('sha256', $name) . '.bin';
        if ($row['destination'] !== $name || $row['backup'] !== $expectedBackup
            || ($expectedBackup !== null && financeReleaseHash(financeReleasePrivatePath($stage, $expectedBackup)) !== $row['before'])) {
            throw new RuntimeException('release_file_backup_changed');
        }
    }
}

function financeInstall($app, string $home, string $stage, array $scope, array $plan): array
{
    $mask = umask(0077);
    $abort = ignore_user_abort(true);
    $state = null;
    try {
        if (function_exists('set_time_limit')) {
            set_time_limit(180);
        }
        $statePath = financeReleasePrivatePath($stage, 'install-state.json');
        $context = financeReleaseContext($app, $home, $stage, $scope, $plan);
        $ordered = financeReleaseOrder($scope);
        if (is_file($statePath)) {
            $previous = json_decode(file_get_contents($statePath), true, 32, JSON_THROW_ON_ERROR);
            financeReleaseAfter($app, $home, $stage, $scope, $plan, $context, $previous);
            return ['ok' => true, 'action' => 'install', 'status' => 'installed',
                'idempotent' => true, 'source_commit' => $context['source_commit']];
        }
        foreach ($plan['files'] as $destination => $row) {
            if (financeReleaseHash(financeReleaseTarget($home, $destination)) !== $row['before']) {
                throw new RuntimeException('release_initial_hash_changed');
            }
        }
        $migrationStates = financeReleaseMigrations($scope);
        $fixedViews = ['website/resources/views/admin/accounting-voucher.blade.php',
            'website/resources/views/admin/accounting.blade.php', 'website/resources/views/admin/wallet-refund.blade.php'];
        if ($scope['blade_views'] !== $fixedViews || file_exists($stage . '/before') || file_exists($stage . '/prepared')) {
            throw new RuntimeException('release_staging_not_clean');
        }
        $compiled = [];
        foreach ($fixedViews as $source) {
            $destination = 'servernet_app/' . substr($source, 8);
            $compiled[$source] = financeReleaseCompiled($app, $home, $source, $context['bytes'][$destination]);
        }
        $state = ['source_commit' => $context['source_commit'], 'plan_hash' => $context['plan_hash'],
            'phase' => 'preparing', 'started_utc' => gmdate('c'), 'applied_files' => [], 'compiled' => []];
        financeReleaseJson($stage, 'install-state.json', $state);
        financeReleaseDirectory($stage, 'before');
        financeReleaseDirectory($stage, 'prepared');
        $backups = [];
        foreach ($context['files'] as $destination => $source) {
            $path = financeReleaseTarget($home, $destination);
            $backups[$destination] = financeReleaseSnapshot($stage, $destination, $path, 'runtime');
            if ($backups[$destination]['before'] !== $plan['files'][$destination]['before']) {
                throw new RuntimeException('release_snapshot_changed');
            }
            financeReleaseWrite($stage, 'prepared/' . hash('sha256', $destination) . '.bin', $context['bytes'][$destination]);
        }
        foreach ($compiled as $source => $row) {
            $key = 'compiled:' . $source;
            $backups[$key] = financeReleaseSnapshot($stage, $key, $row['path'], 'compiled');
            financeReleaseWrite($stage, 'prepared/' . hash('sha256', $key) . '.bin', $row['data']);
        }
        if (count($backups) !== 31) {
            throw new RuntimeException('release_backup_count');
        }
        financeReleaseJson($stage, 'before/manifest.json', ['source_commit' => $context['source_commit'], 'files' => $backups]);
        $state['backup_manifest_sha256'] = financeReleaseHash($stage . '/before/manifest.json');
        $state['phase'] = 'backing_up_database';
        financeReleaseJson($stage, 'install-state.json', $state);
        $state['database_backup'] = financeReleaseDatabaseBackup($stage);
        $state['phase'] = 'backups_verified';
        financeReleaseJson($stage, 'install-state.json', $state);
        // Recheck every runtime target after the potentially long database dump.
        foreach ($plan['files'] as $destination => $row) {
            if (financeReleaseHash(financeReleaseTarget($home, $destination)) !== $row['before']) {
                throw new RuntimeException('release_before_changed');
            }
        }
        financeReleaseContext($app, $home, $stage, $scope, $plan);
        foreach ($scope['migrations'] as $source) {
            $destination = 'servernet_app/' . substr($source, 8);
            $row = $plan['files'][$destination];
            financeReleasePublish(financeReleaseTarget($home, $destination), $context['bytes'][$destination],
                $row['before'], $backups[$destination]['mode'] ?? 0644);
            $state['applied_files'][$destination] = $row['after'];
        }
        $state['phase'] = 'migrations_staged';
        financeReleaseJson($stage, 'install-state.json', $state);
        foreach ($migrationStates as $source => $status) {
            if ($status === 'applied') {
                continue;
            }
            $current = financeReleaseMigrations($scope);
            if ($current[$source] !== 'pending') {
                throw new RuntimeException('release_migration_state_changed');
            }
            $state['phase'] = 'applying_migration';
            $state['migration_source'] = $source;
            financeReleaseJson($stage, 'install-state.json', $state);
            $output = new Symfony\Component\Console\Output\BufferedOutput();
            $exit = $app->make(Illuminate\Contracts\Console\Kernel::class)->call('migrate', [
                '--path' => [$home . '/servernet_app/' . substr($source, 8)], '--realpath' => true, '--force' => true,
            ], $output);
            // Buffered migration output can include SQL; it is never emitted or logged here.
            unset($output);
            if ($exit !== 0 || financeReleaseMigrations($scope)[$source] !== 'applied') {
                throw new RuntimeException('release_migration_failed');
            }
            $state['phase'] = 'migration_applied';
            financeReleaseJson($stage, 'install-state.json', $state);
        }
        unset($state['migration_source']);
        $state['phase'] = 'writing_files';
        financeReleaseJson($stage, 'install-state.json', $state);
        $waitedForPools = false;
        foreach ($ordered as $destination => $source) {
            if (isset($state['applied_files'][$destination])) {
                if (financeReleaseHash(financeReleaseTarget($home, $destination)) !== $plan['files'][$destination]['after']) {
                    throw new RuntimeException('release_migration_file_changed');
                }
                continue;
            }
            if (!$waitedForPools && str_starts_with($source, 'website/app/Http/Controllers/')) {
                $state['phase'] = 'waiting_for_pool_revalidation';
                financeReleaseJson($stage, 'install-state.json', $state);
                $deadline = microtime(true) + $plan['opcache_revalidate_wait_seconds'];
                while (microtime(true) < $deadline) {
                    usleep((int) min(250000, max(1, ($deadline - microtime(true)) * 1000000)));
                }
                $waitedForPools = true;
                $state['phase'] = 'writing_files';
                financeReleaseJson($stage, 'install-state.json', $state);
            }
            financeReleasePublish(financeReleaseTarget($home, $destination), $context['bytes'][$destination],
                $plan['files'][$destination]['before'], $backups[$destination]['mode'] ?? 0644);
            $state['applied_files'][$destination] = $plan['files'][$destination]['after'];
            financeReleaseJson($stage, 'install-state.json', $state);
        }
        foreach ($compiled as $source => $row) {
            financeReleasePublish($row['path'], $row['data'], $backups['compiled:' . $source]['before'],
                $backups['compiled:' . $source]['mode'] ?? 0644, true);
            if (!touch($row['path'], max(time(), filemtime($row['source_path']) + 1))) {
                throw new RuntimeException('release_compiled_timestamp');
            }
            $state['compiled'][$source] = ['path' => $row['path'], 'sha256' => $row['sha256']];
        }
        $state['phase'] = 'installed';
        $state['finished_utc'] = gmdate('c');
        financeReleaseAfter($app, $home, $stage, $scope, $plan, $context, $state);
        financeReleaseJson($stage, 'install-state.json', $state);
        return ['ok' => true, 'action' => 'install', 'status' => 'installed',
            'idempotent' => false, 'runtime_destinations' => 28, 'compiled_views' => 3,
            'source_commit' => $context['source_commit'], 'database_backup_sha256' => $state['database_backup']['sha256']];
    } catch (Throwable $error) {
        if ($state !== null) {
            $state['failed_after_phase'] = $state['phase'];
            $state['phase'] = 'failed_review_required';
            $state['error_class'] = get_class($error);
            financeReleaseJson($stage, 'install-state.json', $state);
        }
        throw $error;
    } finally {
        umask($mask);
        ignore_user_abort((bool) $abort);
    }
}

function financeActivate($app, string $home, string $stage, array $scope, array $plan): array
{
    if (in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
        throw new RuntimeException('release_web_pool_required');
    }
    $context = financeReleaseContext($app, $home, $stage, $scope, $plan);
    $statePath = financeReleasePrivatePath($stage, 'install-state.json');
    if (!is_file($statePath)) {
        throw new RuntimeException('release_not_installed');
    }
    $state = json_decode(file_get_contents($statePath), true, 32, JSON_THROW_ON_ERROR);
    financeReleaseAfter($app, $home, $stage, $scope, $plan, $context, $state);
    $targets = [];
    foreach ($context['files'] as $destination => $source) {
        if (str_ends_with($source, '.php') && !str_ends_with($source, '.blade.php')) {
            $targets[] = financeReleaseTarget($home, $destination);
        }
    }
    foreach ($state['compiled'] as $row) {
        $targets[] = $row['path'];
    }
    $enabled = filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);
    if ($enabled && (!function_exists('opcache_invalidate') || !function_exists('opcache_is_script_cached'))) {
        throw new RuntimeException('release_opcache_functions_unavailable');
    }
    $results = [];
    foreach (array_values(array_unique($targets)) as $path) {
        $cached = $enabled && opcache_is_script_cached($path);
        $invalidated = $enabled && opcache_invalidate($path, true);
        if ($cached && !$invalidated) {
            throw new RuntimeException('release_opcache_invalidation_failed');
        }
        $results[] = ['path' => $path, 'was_cached' => $cached, 'invalidated' => $invalidated];
    }
    $domain = (string) ($_SERVER['SERVER_NAME'] ?? '');
    if (!preg_match('/\A[a-z0-9.-]+\z/iD', $domain)) {
        throw new RuntimeException('release_web_domain');
    }
    $receipt = ['ok' => true, 'action' => 'activate', 'source_commit' => $context['source_commit'],
        'domain' => $domain, 'sapi' => PHP_SAPI, 'utc' => gmdate('c'), 'opcache_enabled' => $enabled,
        'opcache_validate_timestamps' => ini_get('opcache.validate_timestamps'), 'targets' => $results];
    financeReleaseJson($stage, 'activation-' . $domain . '.json', $receipt);
    return $receipt;
}
