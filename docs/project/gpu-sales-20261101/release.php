<?php
// Private CLI installer. Run only for the owner's GPU sales incident.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$home = '/home/servernetcloud';
$work = $home.'/.gpu-sales-20261101';
$live = $home.'/servernet_app';
$source = $work.'/source/website';
$expected = [
    'app/Services/Cloud/CloudCatalogRepricer.php' => '266aa0a110667047eb29f34c514da5ae90560a259be1a9df81b0d1fa30790e83',
    'app/Services/SystemHealth.php' => 'fac52e5a54ff3dce41e79fbdbcf0d7cb23f3bea1070602de52a55a905e1b677d',
    'routes/console.php' => '2dc691fb844b4829321980183132502f720e749c482fd2112d7f0be62113354d',
    'app/Services/Cloud/GpuSalesHealth.php' => null,
    'app/Console/Commands/WatchGpuSales.php' => null,
];
$locks = []; $changed = []; $manifest = [];
$backup = $work.'/backup-'.gmdate('Ymd-His');
try {
    foreach (['.servernet-web-release.lock','servernet-release.lock','.codex-web-deploy.lock',
        'servernet_app/storage/app/cloud-catalog-refresh/catalog.lock'] as $name) {
        $path = $home.'/'.$name;
        if (is_link($path)) { throw new RuntimeException('Lock symlink refused'); }
        $h = fopen($path, 'c+b');
        if (! $h || ! flock($h, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Release/catalog lock busy'); }
        $locks[] = $h;
    }
    mkdir($backup, 0700, true);
    foreach ($expected as $relative => $before) {
        $dst = $live.'/'.$relative;
        if (is_link($dst) || ($before === null ? file_exists($dst) : ! is_file($dst) || hash_file('sha256', $dst) !== $before)) {
            throw new RuntimeException('Source changed: '.$relative);
        }
        $data = $relative === 'routes/console.php'
            ? file_get_contents($dst)."\n// GPU availability must be checked independently of supplier sync and digest cadence.\nSchedule::command('cloud:watch-gpu-sales')\n    ->everyMinute()\n    ->runInBackground()\n    ->withoutOverlapping(2);\n"
            : file_get_contents($source.'/'.$relative);
        $candidate = $work.'/candidate-'.str_replace('/', '_', $relative);
        file_put_contents($candidate, $data);
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($candidate).' 2>&1', $output, $status);
        if ($status !== 0) { throw new RuntimeException('Syntax check failed: '.$relative); }
        if ($before !== null) {
            $saved = $backup.'/'.$relative;
            if (! is_dir(dirname($saved))) { mkdir(dirname($saved), 0700, true); }
            if (! copy($dst, $saved) || hash_file('sha256', $saved) !== $before) { throw new RuntimeException('Backup mismatch'); }
        }
        $manifest[$relative] = ['before' => $before, 'after' => hash('sha256', $data),
            'candidate' => $candidate, 'mode' => $before === null ? 0644 : fileperms($dst) & 0777];
    }
    foreach ($manifest as $relative => $row) {
        $dst = $live.'/'.$relative;
        if ($row['before'] === null ? file_exists($dst) : hash_file('sha256', $dst) !== $row['before']) {
            throw new RuntimeException('Concurrent edit: '.$relative);
        }
        $tmp = $dst.'.gpu-release-'.bin2hex(random_bytes(6));
        if (! copy($row['candidate'], $tmp) || ! chmod($tmp, $row['mode']) || ! rename($tmp, $dst)) {
            throw new RuntimeException('Install failed: '.$relative);
        }
        $changed[] = $relative;
        if (hash_file('sha256', $dst) !== $row['after']) { throw new RuntimeException('Installed hash mismatch'); }
    }
    $receipt = ['at' => gmdate('c'), 'status' => 'installed', 'backup' => $backup, 'manifest' => $manifest];
    file_put_contents($work.'/release-receipt.json', json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode(['status' => 'installed', 'files' => count($manifest), 'backup' => basename($backup)], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    foreach (array_reverse($changed) as $relative) {
        $row = $manifest[$relative]; $dst = $live.'/'.$relative;
        if (hash_file('sha256', $dst) !== $row['after']) { throw new RuntimeException('Rollback refused concurrent change'); }
        if ($row['before'] === null) {
            rename($dst, $work.'/retired-'.str_replace('/', '_', $relative));
        } else {
            $tmp = $dst.'.gpu-rollback'; copy($backup.'/'.$relative, $tmp); chmod($tmp, $row['mode']); rename($tmp, $dst);
        }
    }
    fwrite(STDERR, $e->getMessage().PHP_EOL); exit(1);
} finally { foreach (array_reverse($locks) as $h) { fclose($h); } }
