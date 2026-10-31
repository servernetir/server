<?php
/**
 * One explicit CLI activation after the three-file release is verified.
 * Inserts only the audit's nonsecret public-root setting, never replaces a
 * different value, and invalidates only the settings snapshot. No web path,
 * page cache, environment, cron, worker, customer or finance data is changed.
 */
declare(strict_types=1);

final class LinkRootActivationError extends RuntimeException {}

const LINK_ROOT_APP = '/home/servernetcloud/servernet_app';
const LINK_ROOT_PUBLIC = '/home/servernetcloud/public_html';
const LINK_ROOT_AUDIT = '/home/servernetcloud/site-errors-audit-20261031';
const LINK_ROOT_KEY = 'link_audit_public_root';
const LINK_ROOT_RELEASE = 'site-errors-20261031';

function linkRootReceipt(string $path, array $receipt): void
{
    if (is_link($path)) { throw new LinkRootActivationError('receipt_path_invalid'); }
    $directory = dirname($path);
    $stage = tempnam($directory, '.link-root-');
    if ($stage === false || dirname($stage) !== $directory) {
        throw new LinkRootActivationError('receipt_stage_unavailable');
    }
    try {
        if (! chmod($stage, 0600)) { throw new LinkRootActivationError('receipt_mode_unavailable'); }
        $json = json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
        if (file_put_contents($stage, $json, LOCK_EX) !== strlen($json)
            || ! rename($stage, $path)) {
            throw new LinkRootActivationError('receipt_write_unavailable');
        }
    } finally {
        if (is_file($stage)) { unlink($stage); }
    }
}

function configureLinkRoot(): array
{
    if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() === 0
        || (getenv('HOME') ?: '') !== '/home/servernetcloud'
        || realpath(LINK_ROOT_APP) !== LINK_ROOT_APP || is_link(LINK_ROOT_APP)) {
        throw new LinkRootActivationError('unexpected_account_or_app');
    }
    umask(0077);
    $releaseLocks = [];
    try {
        foreach (['.servernet-web-release.lock', 'servernet-release.lock'] as $name) {
            $path = '/home/servernetcloud/'.$name;
            if (is_link($path)) { throw new LinkRootActivationError('shared_release_lock_invalid'); }
            $handle = fopen($path, 'c+b');
            if ($handle === false) { throw new LinkRootActivationError('shared_release_lock_unavailable'); }
            $releaseLocks[] = $handle;
            $stat = fstat($handle);
            if (($stat['mode'] & 0170000) !== 0100000 || ! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new LinkRootActivationError('shared_release_lock_busy_or_invalid');
            }
        }
        return configureLinkRootLocked();
    } finally {
        foreach (array_reverse($releaseLocks) as $handle) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

/** Caller holds both cooperative release locks for preflight through readback. */
function configureLinkRootLocked(): array
{
    $manifestPath = __DIR__.'/release-manifest.json';
    if (is_link($manifestPath) || ! is_file($manifestPath)) {
        throw new LinkRootActivationError('release_manifest_unavailable');
    }
    $manifest = json_decode(file_get_contents($manifestPath), true, 16, JSON_THROW_ON_ERROR);
    if (($manifest['release'] ?? null) !== LINK_ROOT_RELEASE) {
        throw new LinkRootActivationError('release_manifest_invalid');
    }
    $rows = array_values(array_filter($manifest['files'] ?? [], static fn ($row) =>
        is_array($row) && ($row['target'] ?? null) === 'app/Console/Commands/CheckContentLinks.php'));
    if (count($rows) !== 1 || ($rows[0]['source'] ?? null) !== 'website/app/Console/Commands/CheckContentLinks.php'
        || ! preg_match('/\A[0-9a-f]{64}\z/D', (string) ($rows[0]['after'] ?? ''))) {
        throw new LinkRootActivationError('scanner_manifest_invalid');
    }
    $expected = $rows[0]['after'];
    $scanner = LINK_ROOT_APP.'/app/Console/Commands/CheckContentLinks.php';
    if (is_link($scanner) || ! is_file($scanner) || hash_file('sha256', $scanner) !== $expected) {
        throw new LinkRootActivationError('installed_scanner_hash_drift');
    }
    if (is_link(LINK_ROOT_PUBLIC) || realpath(LINK_ROOT_PUBLIC) !== LINK_ROOT_PUBLIC
        || ! is_dir(LINK_ROOT_PUBLIC) || ! is_readable(LINK_ROOT_PUBLIC)
        || ! is_file(LINK_ROOT_PUBLIC.'/index.php') || ! is_readable(LINK_ROOT_PUBLIC.'/index.php')) {
        throw new LinkRootActivationError('authoritative_public_root_invalid');
    }
    if (is_link(LINK_ROOT_AUDIT) || ! is_dir(LINK_ROOT_AUDIT)
        || realpath(LINK_ROOT_AUDIT) !== LINK_ROOT_AUDIT
        || (fileperms(LINK_ROOT_AUDIT) & 0077) !== 0) {
        throw new LinkRootActivationError('private_audit_directory_required');
    }
    $lockPath = LINK_ROOT_AUDIT.'/link-root-config.lock';
    $receiptPath = LINK_ROOT_AUDIT.'/link-root-config-receipt.json';
    if (is_link($lockPath) || is_link($receiptPath)) {
        throw new LinkRootActivationError('private_activation_path_invalid');
    }
    $lock = fopen($lockPath, 'c+b');
    if ($lock === false || ! chmod($lockPath, 0600)) {
        throw new LinkRootActivationError('activation_lock_unavailable');
    }
    try {
        $stat = fstat($lock);
        if (($stat['mode'] & 0170000) !== 0100000 || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new LinkRootActivationError('activation_lock_busy_or_invalid');
        }
        $prior = is_file($receiptPath)
            ? json_decode(file_get_contents($receiptPath), true, 16, JSON_THROW_ON_ERROR) : null;
        if ($prior !== null && (($prior['version'] ?? null) !== 1
            || ($prior['key'] ?? null) !== LINK_ROOT_KEY
            || ($prior['scanner_sha256'] ?? null) !== $expected
            || ($prior['public_root'] ?? null) !== LINK_ROOT_PUBLIC)) {
            throw new LinkRootActivationError('activation_receipt_contract_drift');
        }

        require LINK_ROOT_APP.'/vendor/autoload.php';
        $app = require LINK_ROOT_APP.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $receipt = $prior ?? ['version' => 1, 'release' => LINK_ROOT_RELEASE,
            'key' => LINK_ROOT_KEY, 'scanner_sha256' => $expected,
            'public_root' => LINK_ROOT_PUBLIC, 'claimed_at' => gmdate('c')];
        $inserted = Illuminate\Support\Facades\DB::transaction(static function () use (&$receipt, $receiptPath): bool {
            $row = Illuminate\Support\Facades\DB::table('settings')
                ->where('key', LINK_ROOT_KEY)->lockForUpdate()->first(['key', 'value']);
            if ($row !== null && $row->value !== LINK_ROOT_PUBLIC) {
                throw new LinkRootActivationError('existing_public_root_setting_differs');
            }
            if (! isset($receipt['before'])) {
                $receipt['before'] = ['exists' => $row !== null, 'value' => $row?->value];
            }
            $receipt['phase'] = 'prepared';
            $receipt['last_attempt_at'] = gmdate('c');
            linkRootReceipt($receiptPath, $receipt);
            if ($row !== null) { return false; }
            $now = now();
            // Query builder deliberately bypasses Setting's global PageCache observer.
            Illuminate\Support\Facades\DB::table('settings')->insert([
                'key' => LINK_ROOT_KEY, 'value' => LINK_ROOT_PUBLIC,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            return true;
        });
        $receipt['phase'] = 'configuration_committed_cache_pending';
        $receipt['after'] = ['exists' => true, 'value' => LINK_ROOT_PUBLIC];
        $receipt['inserted_this_attempt'] = $inserted;
        linkRootReceipt($receiptPath, $receipt);

        Illuminate\Support\Facades\Cache::forget('settings.all');
        $app->forgetInstance('settings.request-memo');
        if (App\Models\Setting::get(LINK_ROOT_KEY) !== LINK_ROOT_PUBLIC) {
            throw new LinkRootActivationError('configuration_readback_failed');
        }
        $receipt['phase'] = 'configured';
        $receipt['settings_snapshot_invalidated'] = true;
        $receipt['verified_at'] = gmdate('c');
        linkRootReceipt($receiptPath, $receipt);
        return ['status' => 'configured', 'key' => LINK_ROOT_KEY,
            'inserted' => $inserted, 'scanner_sha256' => $expected,
            'settings_snapshot_invalidated' => true, 'page_cache_purged' => false];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

try {
    echo json_encode(configureLinkRoot(), JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['status' => 'stopped', 'exception_class' => $error::class,
        'reason' => $error instanceof LinkRootActivationError ? $error->getMessage() : 'inspect_private_activation_status'], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
