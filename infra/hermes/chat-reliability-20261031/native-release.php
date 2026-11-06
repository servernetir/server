<?php
/** Private CLI release caller; never scheduled or served over HTTP. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('HOME') !== '/home/servernetcloud') { exit(1); }
umask(0077);
$mode = $argv[1] ?? '';
if (! in_array($mode, ['deploy', 'rollback', 'accept'], true)) { exit(1); }
$base = '/home/servernetcloud/servernet_app';
$source = '/home/servernetcloud/hermes-chat-source-20261031';
try {
    require $base.'/vendor/autoload.php';
    $app = require $base.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if ($mode !== 'accept') {
        $locks = [];
        try {
            foreach (['hermes:support-review', 'hermes:operations'] as $key) {
                $lock = Illuminate\Support\Facades\Cache::lock($key, 2700);
                if (! $lock->get()) { throw new RuntimeException('worker_busy'); }
                $locks[] = $lock;
            }
            foreach ($mode === 'deploy' ? ['prepare', 'apply', 'verify'] : ['rollback'] as $phase) {
                $process = new Symfony\Component\Process\Process(['/usr/bin/python3',
                    $source.'/infra/hermes/chat-reliability-20261031/deploy.py', $phase, $source],
                    $source, ['HERMES_REVIEW_LOCK_HELD' => '1'], null, 90);
                $process->run();
                if (! $process->isSuccessful()) { throw new RuntimeException('release_'.$phase.'_stopped'); }
                $receipt = json_decode(trim($process->getOutput()), true, 16, JSON_THROW_ON_ERROR);
                echo json_encode(['phase' => $receipt['phase'], 'release' => $receipt['release'],
                    'files' => count($receipt['files']), 'cron_unchanged' => true], JSON_THROW_ON_ERROR).PHP_EOL;
            }
        } finally {
            foreach (array_reverse($locks) as $lock) { $lock->release(); }
        }
        exit(0);
    }
    $ops = app(App\Services\Hermes\HermesOperations::class);
    $owner = $ops->owner();
    if (! $owner) { throw new RuntimeException('owner_unavailable'); }
    $state = app(App\Services\Hermes\HermesOperationsStore::class)->read($owner);
    $alerts = array_filter($state['alerts'], fn ($a) => $a['active'] && empty($a['acknowledged_at']));
    $counts = [];
    foreach ($alerts as $alert) {
        if ($alert['code'] === 'unassigned_resource') {
            $provider = (string) ($alert['data']['provider'] ?? 'unknown');
            $counts[$provider] = ($counts[$provider] ?? 0) + 1;
        }
    }
    $receipt = ['status' => 'cli_accepted', 'checked_at' => gmdate('c'), 'active_unacknowledged' => count($alerts),
        'unassigned_by_provider' => $counts, 'preview' => App\Services\Hermes\HermesAlertSummary::render($alerts, App\Services\Hermes\HermesBusinessReport::LABELS),
        'external_messages_sent' => 0, 'live_model_calls' => 0, 'inventory_refreshed' => false];
    $path = $base.'/storage/app/private/hermes-chat-reliability-20261031';
    if (is_link($path) || (! is_dir($path) && ! mkdir($path, 0700, true)) || is_link($path.'/acceptance.json')) { throw new RuntimeException('receipt_path_invalid'); }
    file_put_contents($path.'/acceptance.json', json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
    echo json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['status' => 'stopped', 'exception_class' => $e::class,
        'reason' => $e instanceof RuntimeException && preg_match('/^(worker_busy|release_[a-z]+_stopped|owner_unavailable|receipt_path_invalid)$/D', $e->getMessage())
            ? $e->getMessage() : 'inspect_private_runtime'], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
