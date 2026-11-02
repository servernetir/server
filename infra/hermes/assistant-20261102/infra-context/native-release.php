<?php
/** Private CLI launcher. Never serve over HTTP or schedule this release tool. */
declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

const HA_SOURCE = '/home/servernetcloud/.hermes-assistant-20261102/stage';
const HA_APP = '/home/servernetcloud/servernet_app';
const HA_PACKAGE = HA_SOURCE.'/infra/hermes/assistant-20261102/infra-context';
const HA_RELEASE = 'araz-infra-context-20261102';
const HA_BASE_COMMIT = '1d08a1d32821d047c34aae3c86ef369aa51e207c';
const HA_TARGETS = [
    'app/Services/Hermes/HermesInfrastructureContext.php',
    'app/Services/Hermes/HermesOperations.php',
    'app/Services/Hermes/HermesConversationMemory.php',
];

if (PHP_SAPI !== 'cli' || getenv('HOME') !== '/home/servernetcloud'
    || (function_exists('posix_getuid') && posix_getuid() === 0)
    || realpath(__FILE__) !== HA_PACKAGE.'/native-release.php') { exit(1); }
umask(0077);
$phase = $argv[1] ?? '';
if (count($argv) !== 2 || ! in_array($phase, ['prepare', 'apply', 'verify', 'rollback'], true)) { exit(1); }

$locks = []; $failed = false; $lockReleaseFailed = false; $deadline = microtime(true) + 220;
try {
    $manifestPath = HA_PACKAGE.'/release-manifest.json';
    if (is_link($manifestPath) || ! is_file($manifestPath) || filesize($manifestPath) > 65536) {
        throw new RuntimeException('release_manifest_invalid');
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 16, JSON_THROW_ON_ERROR);
    if (! is_array($manifest) || ($manifest['release'] ?? '') !== HA_RELEASE
        || ($manifest['base_commit'] ?? '') !== HA_BASE_COMMIT
        || ! is_array($manifest['files'] ?? null) || array_column($manifest['files'], 'target') !== HA_TARGETS) {
        throw new RuntimeException('release_manifest_scope_invalid');
    }
    // Refuse a stale deployment contract if either real command changed its lock.
    foreach ([
        'app/Console/Commands/HermesOperationsReview.php' => 'hermes:operations-review',
        'app/Console/Commands/HermesKnowledgeRefresh.php' => 'hermes:knowledge-refresh',
    ] as $relative => $key) {
        $path = HA_APP.'/'.$relative;
        if (is_link($path) || ! is_file($path)
            || ! preg_match('/Cache::lock\(\s*[\x27\x22]'.preg_quote($key, '/').'[\x27\x22]\s*,/', (string) file_get_contents($path))) {
            throw new RuntimeException('native_lock_contract_changed');
        }
    }
    require HA_APP.'/vendor/autoload.php';
    $app = require HA_APP.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    // These are native cache locks, not scheduler invocations. No worker runs here.
    foreach (['hermes:support-review', 'hermes:operations-review', 'hermes:business-review', 'hermes:knowledge-refresh'] as $key) {
        $lock = Cache::lock($key, 600);
        if (! $lock->get()) { throw new RuntimeException('native_worker_busy'); }
        $locks[] = $lock;
    }
    $remaining = (int) floor($deadline - microtime(true));
    if ($remaining < 5) { throw new RuntimeException('release_time_budget_exhausted'); }
    $process = new Process(['/usr/bin/python3', HA_PACKAGE.'/deploy.py', $phase, HA_SOURCE], HA_SOURCE,
        ['HERMES_ASSISTANT_LOCKS_HELD' => '1', 'PYTHONDONTWRITEBYTECODE' => '1'], null, $remaining);
    $process->run();
    if (! $process->isSuccessful()) {
        // Keep diagnostics private; surface only the deployment core's bounded guard reason.
        $stopped = json_decode(trim($process->getOutput()), true);
        $reason = is_array($stopped) && is_string($stopped['reason'] ?? null)
            && preg_match('/^[A-Za-z0-9_:.\/-]{1,160}$/D', $stopped['reason']) ? $stopped['reason'] : 'unavailable';
        file_put_contents(HA_PACKAGE.'/subprocess-status.json', json_encode(['status' => 'stopped', 'reason' => $reason, 'exit' => $process->getExitCode()], JSON_THROW_ON_ERROR));
        chmod(HA_PACKAGE.'/subprocess-status.json', 0600);
        throw new RuntimeException('release_subprocess_stopped');
    }
    $result = json_decode(trim($process->getOutput()), true, 16, JSON_THROW_ON_ERROR);
    $expected = ['prepare' => 'prepared', 'apply' => 'verified', 'verify' => 'verified', 'rollback' => 'rolled_back'][$phase];
    if (! is_array($result) || ($result['release'] ?? '') !== HA_RELEASE || ($result['phase'] ?? '') !== $expected
        || ($result['files'] ?? null) !== count(HA_TARGETS) || ($result['messages_sent'] ?? null) !== 0
        || ($result['provider_calls'] ?? null) !== 0 || ($result['schema_or_settings_changed'] ?? null) !== false
        || ($result['framework_bootstrapped'] ?? null) !== false
        || ($result['cron_unchanged'] ?? null) !== ($phase === 'rollback' ? null : true)) {
        throw new RuntimeException('release_result_invalid');
    }
    echo json_encode(['release' => HA_RELEASE, 'phase' => $phase, 'state' => $expected,
        'files' => count(HA_TARGETS), 'messages_sent' => 0, 'provider_calls' => 0,
        'schema_or_settings_changed' => false], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['release' => HA_RELEASE, 'phase' => $phase, 'status' => 'stopped',
        'exception_class' => $error::class, 'reason' => $error instanceof RuntimeException && preg_match('/^[a-z_]+$/D', $error->getMessage())
            ? $error->getMessage() : 'inspect_private_release_status'], JSON_THROW_ON_ERROR).PHP_EOL;
    $failed = true;
} finally {
    foreach (array_reverse($locks) as $lock) {
        try { if (! $lock->release()) { $lockReleaseFailed = true; } } catch (Throwable) { $lockReleaseFailed = true; }
    }
    if ($lockReleaseFailed) {
        echo json_encode(['release' => HA_RELEASE, 'phase' => $phase, 'status' => 'stopped', 'reason' => 'native_lock_release_unverified'], JSON_THROW_ON_ERROR).PHP_EOL;
        $failed = true;
    }
}
exit($failed ? 1 : 0);
