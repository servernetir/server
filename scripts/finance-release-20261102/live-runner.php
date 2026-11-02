<?php
declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A fixed, reviewed operation. Paths and actions never come from the HTTP body.
$home = '/home/servernetcloud';
$appRoot = $home . '/servernet_app';
$locks = [];
try {
    $releaseStep = 'private_stage';
    if (!preg_match('~\A/home/servernetcloud/finance-release-20261102-[a-f0-9]{12}\z~D', $stage)
        || is_link($stage) || realpath($stage) !== $stage || !chmod($stage, 0700)) {
        throw new RuntimeException('private_stage');
    }
    if (!in_array($input['action'] ?? null, ['inspect', 'install', 'activate', 'verify', 'register', 'render'], true)) {
        throw new RuntimeException('action');
    }
    $releaseStep = 'locks';
    foreach (['.servernet-web-release.lock', 'servernet-release.lock'] as $name) {
        $path = $home . '/' . $name;
        if (is_link($path)) {
            throw new RuntimeException('lock_symlink');
        }
        $handle = fopen($path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('release_busy');
        }
        $locks[] = $handle;
        clearstatcache(true, $path);
        $opened = fstat($handle);
        $current = lstat($path);
        if (($opened['mode'] & 0170000) !== 0100000 || $opened['ino'] !== $current['ino']
            || $opened['dev'] !== $current['dev']) {
            throw new RuntimeException('lock_changed');
        }
    }
    $releaseStep = 'scope';
    if (is_link($stage . '/scope.json') || hash_file('sha256', $stage . '/scope.json')
        !== '6c745280707364772150a5984c9a13800efbf929496cd733e3df373f724e8f40') {
        throw new RuntimeException('scope_hash');
    }
    $scope = json_decode(file_get_contents($stage . '/scope.json'), true, 32, JSON_THROW_ON_ERROR);
    if ($scope['source_commit'] !== '190ff59cc1531399c7cdbd8b928b54d3c7727ae6'
        || count($scope['files']) !== 26) {
        throw new RuntimeException('scope');
    }
    $files = [];
    $fileMetadata = [];
    foreach (array_merge($scope['files'], $scope['required_shared_dependencies']) as $row) {
        $source = $row['source'];
        if (!str_starts_with($source, 'website/') || str_contains($source, '..')) {
            throw new RuntimeException('source_path');
        }
        $relative = substr($source, 8);
        $destinations = ['servernet_app/' . $relative];
        if (str_starts_with($relative, 'public/')) {
            $destinations[] = 'public_html/' . substr($relative, 7);
        }
        if ($row['destinations'] !== $destinations) {
            throw new RuntimeException('destination');
        }
        foreach ($destinations as $relativePath) {
            $path = $home;
            foreach (explode('/', $relativePath) as $part) {
                $path .= '/' . $part;
                if (is_link($path)) {
                    throw new RuntimeException('target_symlink');
                }
            }
            $files[$relativePath] = is_file($path) ? hash_file('sha256', $path) : null;
            if (is_file($path)) {
                $data = file_get_contents($path);
                $fileMetadata[$relativePath] = ['bytes' => strlen($data), 'crlf_count' => substr_count($data, "\r\n"),
                    'normalized_sha256' => hash('sha256', str_replace("\r\n", "\n", $data))];
            }
        }
    }
    $releaseStep = 'bootstrap';
    require $appRoot . '/vendor/autoload.php';
    $app = require $appRoot . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (!is_string(config('app.key')) || config('app.key') === '') {
        throw new RuntimeException('app_key_missing');
    }
    if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
        throw new RuntimeException('database_driver');
    }
    if ($input['action'] !== 'inspect') {
        $releaseStep = $input['action'];
        $operations = $stage . '/install-operations.php';
        $planFile = $stage . '/install-plan.json';
        if (is_link($operations) || !is_file($operations)
            || hash_file('sha256', $operations) !== '__INSTALL_OPERATIONS_SHA256__'
            || is_link($planFile) || !is_file($planFile)
            || hash_file('sha256', $planFile) !== '__INSTALL_PLAN_SHA256__') {
            throw new RuntimeException('release_private_input_mismatch');
        }
        token_get_all(file_get_contents($operations), TOKEN_PARSE);
        require $operations;
        $plan = json_decode(file_get_contents($planFile), true, 32, JSON_THROW_ON_ERROR);
        if ($input['action'] === 'install') {
            $result = financeInstall($app, $home, $stage, $scope, $plan);
        } elseif ($input['action'] === 'activate') {
            $result = financeActivate($app, $home, $stage, $scope, $plan);
        } elseif ($input['action'] === 'register') {
            $identityOperations = $stage . '/identity-operations.php';
            $identityPlanFile = $stage . '/identity-plan.json';
            if (is_link($identityOperations) || !is_file($identityOperations)
                || hash_file('sha256', $identityOperations) !== '__IDENTITY_OPERATIONS_SHA256__'
                || is_link($identityPlanFile) || !is_file($identityPlanFile)
                || hash_file('sha256', $identityPlanFile) !== '__IDENTITY_PLAN_SHA256__') {
                throw new RuntimeException('identity_private_input_mismatch');
            }
            token_get_all(file_get_contents($identityOperations), TOKEN_PARSE);
            require $identityOperations;
            $identityPlan = json_decode(file_get_contents($identityPlanFile), true, 32, JSON_THROW_ON_ERROR);
            $result = financeRegisterIdentities($app, $home, $stage, $scope, $plan, $identityPlan, $input);
        } elseif ($input['action'] === 'render') {
            $renderOperations = $stage . '/render-operations.php';
            if (is_link($renderOperations) || !is_file($renderOperations)
                || hash_file('sha256', $renderOperations) !== '__RENDER_OPERATIONS_SHA256__') {
                throw new RuntimeException('render_private_input_mismatch');
            }
            token_get_all(file_get_contents($renderOperations), TOKEN_PARSE);
            require $renderOperations;
            $result = financeRender($app, $home, $stage, $scope, $plan);
        } else {
            $context = financeReleaseContext($app, $home, $stage, $scope, $plan);
            $state = json_decode(file_get_contents(financeReleasePrivatePath($stage, 'install-state.json')), true, 32, JSON_THROW_ON_ERROR);
            financeReleaseAfter($app, $home, $stage, $scope, $plan, $context, $state);
            $result = ['ok' => true, 'action' => 'verify', 'destinations' => 28,
                'compiled_views' => 3, 'migrations_applied' => 2, 'source_commit' => $plan['source_commit']];
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return;
    }
    $releaseStep = 'schema';
    $tables = [];
    foreach (['finance_accounts', 'finance_vouchers', 'finance_controls', 'finance_lines',
              'finance_audit_events', 'wallet_refund_requests', 'wallet_refund_events', 'business_entries'] as $table) {
        $tables[$table] = Schema::hasTable($table)
            ? ['columns' => Schema::getColumnListing($table), 'count' => DB::table($table)->count()]
            : null;
    }
    $migrations = [];
    foreach ($scope['migrations'] as $migration) {
        $name = basename($migration, '.php');
        $migrations[$name] = Schema::hasTable('migrations') ? DB::table('migrations')->where('migration', $name)->count() : null;
    }
    $releaseStep = 'accounts';
    $accountColumns = ['id', 'name', 'type', 'currency_code', 'owner_name'];
    if (Schema::hasColumn('finance_accounts', 'partner_key')) {
        $accountColumns[] = 'partner_key';
    }
    $accounts = Schema::hasTable('finance_accounts') ? DB::table('finance_accounts')->whereIn('type', ['bank', 'partner'])
        ->orderBy('id')->get($accountColumns)->map(fn ($row) => (array) $row)->all() : [];
    $accountRowHashes = Schema::hasTable('finance_accounts') ? DB::table('finance_accounts')->orderBy('id')->get()
        ->mapWithKeys(fn ($row) => [$row->id => hash('sha256', json_encode((array) $row, JSON_THROW_ON_ERROR))])->all() : [];
    $maskedIdentities = method_exists(App\Models\FinanceAccount::class, 'maskedBankIdentity')
        ? App\Models\FinanceAccount::whereIn('type', ['bank', 'partner'])->orderBy('id')->get()
            ->map(fn ($account) => $account->maskedBankIdentity())->all() : [];
    $refundIntegrity = [];
    foreach (['wallet_refund_requests', 'wallet_refund_events'] as $table) {
        if (Schema::hasTable($table)) {
            $rows = DB::table($table)->orderBy('id')->get()->all();
            $refundIntegrity[$table] = ['count' => count($rows),
                'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
        }
    }
    $bankSettings = Schema::hasTable('settings') ? DB::table('settings')
        ->whereIn('key', ['bank_holder', 'bank_name', 'bank_account', 'bank_sheba', 'bank_card'])
        ->orderBy('key')->pluck('value', 'key')->all() : [];
    $companyBankSummary = [
        'bank_name' => $bankSettings['bank_name'] ?? null,
        'owner_name' => $bankSettings['bank_holder'] ?? null,
        'iban_last4' => filled($bankSettings['bank_sheba'] ?? null) ? substr(preg_replace('/\s+/u', '', $bankSettings['bank_sheba']), -4) : null,
        'card_last4' => filled($bankSettings['bank_card'] ?? null) ? substr(preg_replace('/\s+/u', '', $bankSettings['bank_card']), -4) : null,
        'fingerprint' => hash('sha256', json_encode($bankSettings, JSON_THROW_ON_ERROR)),
    ];
    unset($bankSettings);
    // Metadata read only; never serialize a model which may contain bank details.
    $releaseStep = 'admins';
    $admins = Schema::hasTable('users') ? DB::table('users')->where('role', 'admin')->orderBy('id')->get(['id', 'name'])
        ->map(fn ($row) => (array) $row)->all() : [];
    $result = [
        'ok' => true, 'action' => 'inspect', 'utc' => gmdate('c'),
        'php_version' => PHP_VERSION, 'sapi' => PHP_SAPI,
        'server_name' => $_SERVER['SERVER_NAME'] ?? null,
        'files' => $files, 'file_metadata' => $fileMetadata, 'tables' => $tables, 'migrations' => $migrations,
        'accounting_migrations' => Schema::hasTable('migrations') ? DB::table('migrations')
            ->where(fn ($query) => $query->where('migration', 'like', '%accounting%')->orWhere('migration', 'like', '%finance%'))
            ->orderBy('id')->pluck('migration')->all() : [],
        'accounts' => $accounts, 'admins' => $admins, 'account_row_hashes' => $accountRowHashes,
        'masked_identities' => $maskedIdentities, 'refund_integrity' => $refundIntegrity,
        'company_bank_settings' => $companyBankSummary,
        'control_row_exists' => Schema::hasTable('finance_controls') && DB::table('finance_controls')->where('id', 1)->exists(),
        'opcache_enabled' => (bool) ini_get('opcache.enable'),
        'opcache_validate_timestamps' => ini_get('opcache.validate_timestamps'),
        'opcache_revalidate_freq' => (int) ini_get('opcache.revalidate_freq'),
        'opcache_api_accessible' => !ini_get('opcache.enable') || (function_exists('opcache_get_status') && is_array(opcache_get_status(false))),
        'dump_binaries' => array_values(array_filter(['/usr/bin/mariadb-dump', '/usr/bin/mysqldump'], 'is_executable')),
        'proc_open_available' => function_exists('proc_open'),
        'free_bytes' => disk_free_space($home),
        'app_key_fingerprint' => hash('sha256', (string) config('app.key')),
    ];
    $privateReceipt = $stage . '/inspect-' . preg_replace('/[^a-z0-9.-]/i', '_', $result['server_name'] ?? 'web') . '.json';
    $releaseStep = 'receipt';
    $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (is_link($privateReceipt)) {
        throw new RuntimeException('receipt_symlink');
    }
    $temp = $privateReceipt . '.new-' . bin2hex(random_bytes(8));
    $handle = fopen($temp, 'x');
    if ($handle === false || !chmod($temp, 0600) || fwrite($handle, $json) !== strlen($json)
        || !fflush($handle)) {
        throw new RuntimeException('receipt_write');
    }
    fclose($handle);
    if (!rename($temp, $privateReceipt)) {
        throw new RuntimeException('receipt_activate');
    }
    echo $json;
} finally {
    foreach (array_reverse($locks) as $lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
