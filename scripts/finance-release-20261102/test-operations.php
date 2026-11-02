<?php
declare(strict_types=1);

/**
 * Isolated checks for the temporary release operations, not product tests.
 * Run in a clean POSIX CLI process: php -n test-operations.php
 * No Composer bootstrap, database connection, live plan or credentials are read.
 */
namespace {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }
    if (class_exists('Illuminate\Foundation\Application', false)) {
        throw new RuntimeException('Run these checks in a clean CLI process.');
    }
}

namespace Illuminate\Support\Facades {
    final class DB
    {
        public static array $records = ['2026_10_25_120000_create_documented_accounting_tables' => 1];

        public static function table(string $table): SyntheticFinanceQuery
        {
            return new SyntheticFinanceQuery($table);
        }
    }

    final class SyntheticFinanceQuery
    {
        private mixed $value = null;

        public function __construct(private string $table) {}

        public function where(string $key, mixed $value): self
        {
            if (($this->table === 'migrations' && $key !== 'migration')
                || ($this->table === 'finance_controls' && $key !== 'id')) {
                throw new \LogicException('Unexpected synthetic query.');
            }
            $this->value = $value;
            return $this;
        }

        public function count(): int
        {
            if ($this->table !== 'migrations') {
                throw new \LogicException('Unexpected synthetic count.');
            }
            return DB::$records[$this->value] ?? 0;
        }

        public function exists(): bool
        {
            return $this->table === 'finance_controls' && $this->value === 1;
        }
    }

    final class Schema
    {
        public static array $columns = ['finance_accounts' => ['id'], 'finance_vouchers' => ['id']];
        public static bool $foreign = true;

        public static function hasTable(string $table): bool
        {
            return in_array($table, ['finance_accounts', 'finance_vouchers', 'finance_lines',
                'finance_controls', 'finance_audit_events', 'migrations'], true);
        }

        public static function getColumnListing(string $table): array
        {
            return self::$columns[$table];
        }

        public static function getColumns(string $table): array
        {
            $types = [
                'bank_details' => ['text', 'text'],
                'bank_iban_fingerprint' => ['char', 'char(64)'],
                'partner_key' => ['varchar', 'varchar(80)'],
                'related_voucher_id' => ['bigint', 'bigint(20) unsigned'],
            ];
            return array_map(static fn (string $column): array => [
                'name' => $column, 'type_name' => $types[$column][0] ?? 'bigint',
                'type' => $types[$column][1] ?? 'bigint unsigned',
                'nullable' => true, 'generation' => null,
            ], self::$columns[$table]);
        }

        public static function getIndexes(string $table): array
        {
            return $table === 'finance_accounts'
                ? [['columns' => ['bank_iban_fingerprint'], 'unique' => true], ['columns' => ['partner_key'], 'unique' => false]]
                : [['columns' => ['related_voucher_id'], 'unique' => false]];
        }

        public static function getForeignKeys(string $table): array
        {
            return self::$foreign ? [['columns' => ['related_voucher_id'],
                'foreign_table' => 'finance_vouchers', 'foreign_columns' => ['id'], 'on_delete' => 'restrict']] : [];
        }
    }
}

namespace {
    require __DIR__ . '/install-operations.php';

    function financeTestCheck(bool $condition, string $name): void
    {
        if (!$condition) {
            throw new RuntimeException('Check failed: ' . $name);
        }
    }

    function financeTestReject(callable $operation, string $expected): void
    {
        try {
            $operation();
        } catch (RuntimeException $error) {
            financeTestCheck($error->getMessage() === $expected, 'expected rejection ' . $expected);
            return;
        }
        throw new LogicException('Expected rejection: ' . $expected);
    }

    function financeTestOrderAndInputs(array $scope): void
    {
        $order = financeReleaseOrder($scope);
        financeTestCheck(count($order) === 28 && count(array_unique(array_keys($order))) === 28, '28 distinct destinations');
        $positions = array_flip(array_values($order));
        $lastService = $lastController = -1;
        $firstController = $firstView = PHP_INT_MAX;
        foreach (array_values($order) as $index => $source) {
            if (str_contains($source, '/Services/') || str_contains($source, '/Models/')) {
                $lastService = max($lastService, $index);
            }
            if (str_contains($source, '/Controllers/')) {
                $firstController = min($firstController, $index);
                $lastController = max($lastController, $index);
            }
            if (str_contains($source, '/views/')) {
                $firstView = min($firstView, $index);
            }
        }
        financeTestCheck($lastService < $firstController && $lastController < $firstView
            && $positions['website/app/Models/FinanceAccount.php'] < $positions['website/app/Services/Finance/FinanceAccountRegistry.php']
            && $positions['website/app/Services/Finance/WalletRefunds.php'] < $positions['website/app/Services/Finance/TaxEvidenceBundle.php'],
            'models/services precede their callers');
        $damaged = $scope;
        array_pop($damaged['files']);
        financeTestReject(static fn () => financeReleaseOrder($damaged), 'release_publish_order');
        financeTestCheck(financeReleaseOption("a\"b\\c\nd") === '"a\\"b\\\\c\\nd"', 'client option escaping');
        financeTestReject(static fn () => financeReleaseOption("bad\0value"), 'release_database_option');
        financeTestReject(static fn () => financeReleasePrivatePath('/synthetic-no-write', '../.env'), 'private_path');
    }

    function financeTestSchemaStates(array $scope): void
    {
        financeTestCheck(array_values(financeReleaseMigrations($scope)) === ['pending', 'pending'], 'pending migration pair');
        \Illuminate\Support\Facades\Schema::$columns['finance_accounts'][] = 'bank_details';
        financeTestReject(static fn () => financeReleaseMigrations($scope), 'release_partial_schema');
        \Illuminate\Support\Facades\Schema::$columns['finance_accounts'] = ['id', 'bank_details', 'bank_iban_fingerprint', 'partner_key'];
        \Illuminate\Support\Facades\Schema::$columns['finance_vouchers'] = ['id', 'related_voucher_id'];
        financeTestReject(static fn () => financeReleaseMigrations($scope), 'release_partial_schema');
        foreach ($scope['migrations'] as $file) {
            \Illuminate\Support\Facades\DB::$records[basename($file, '.php')] = 1;
        }
        financeTestCheck(array_values(financeReleaseMigrations($scope)) === ['applied', 'applied'], 'complete schema including MariaDB integer display width');
        \Illuminate\Support\Facades\Schema::$foreign = false;
        financeTestReject(static fn () => financeReleaseMigrations($scope), 'release_related_foreign_key');
        \Illuminate\Support\Facades\Schema::$foreign = true;
        \Illuminate\Support\Facades\DB::$records['2026_10_25_120000_create_documented_accounting_tables'] = 0;
        financeTestReject(static fn () => financeReleaseMigrations($scope), 'release_base_migration_missing');
    }

    function financeTestFilesystem(): void
    {
        $base = realpath(sys_get_temp_dir());
        if ($base === false) {
            throw new RuntimeException('No canonical temporary directory.');
        }
        $root = $base . '/servernet-finance-ops-test-' . bin2hex(random_bytes(8));
        if (!mkdir($root, 0700) || realpath($root) !== $root) {
            throw new RuntimeException('Cannot create isolated fixture.');
        }
        $identity = lstat($root);
        $target = $root . '/target.php';
        $old = '<?php return "old";';
        $new = '<?php return "new";';
        $backup = 'before/' . hash('sha256', 'servernet_app/test.php') . '.bin';
        try {
            financeReleaseDirectory($root, 'before');
            financeTestCheck(file_put_contents($target, $old) === strlen($old) && chmod($target, 0640), 'fixture creation');
            $snapshot = financeReleaseSnapshot($root, 'servernet_app/test.php', $target, 'runtime');
            financeTestCheck($snapshot['before'] === hash('sha256', $old)
                && file_get_contents($root . '/' . $backup) === $old
                && (fileperms($root . '/' . $backup) & 0777) === 0600, 'checked private backup');
            financeReleasePublish($target, $new, hash('sha256', $old), 0640);
            financeTestCheck(file_get_contents($target) === $new && (fileperms($target) & 0777) === 0640, 'atomic publication retains mode');
            financeTestReject(static fn () => financeReleasePublish($target, 'unexpected', hash('sha256', $old), 0640), 'release_before_changed');
            financeTestCheck(symlink($target, $root . '/link.php'), 'synthetic symlink creation');
            financeTestReject(static fn () => financeReleaseHash($root . '/link.php'), 'hash_symlink');
            financeReleaseWrite($root, 'note.json', '{"synthetic":true}');
            financeTestCheck((fileperms($root . '/note.json') & 0777) === 0600, 'private receipt mode');
        } finally {
            clearstatcache(true, $root);
            $current = lstat($root);
            if (is_link($root) || realpath($root) !== $root
                || $current['ino'] !== $identity['ino'] || $current['dev'] !== $identity['dev']
                || !str_starts_with($root, $base . '/servernet-finance-ops-test-')) {
                throw new RuntimeException('Fixture changed; cleanup refused.');
            }
            if (is_link($root . '/before') || realpath($root . '/before') !== $root . '/before') {
                throw new RuntimeException('Fixture backup directory changed; cleanup refused.');
            }
            // Delete only the four exact fixture paths, never recurse or follow a link.
            foreach ([$root . '/link.php', $target, $root . '/note.json', $root . '/' . $backup] as $file) {
                if ((is_link($file) || is_file($file)) && !unlink($file)) {
                    throw new RuntimeException('Fixture file cleanup failed.');
                }
            }
            if (!rmdir($root . '/before') || !rmdir($root)) {
                throw new RuntimeException('Fixture cleanup failed; unknown files were preserved.');
            }
        }
    }

    $scope = json_decode(file_get_contents(__DIR__ . '/scope.json'), true, 32, JSON_THROW_ON_ERROR);
    financeTestOrderAndInputs($scope);
    echo "PASS: exact destinations, dependency ordering, client option escaping and input guards\n";
    financeTestSchemaStates($scope);
    echo "PASS: pending/complete schema, MariaDB width, partial/unrecorded schema and missing FK/base rejection\n";
    financeTestFilesystem();
    echo "PASS: isolated private backup, atomic publication, retained modes, stale-hash and symlink rejection\n";
    echo "PASS: 3 isolated release-operation check suites; no database network or production operation\n";
}
