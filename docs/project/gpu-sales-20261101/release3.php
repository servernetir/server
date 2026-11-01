<?php
// Private CLI installer. Run only for the owner's GPU sales incident.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$home = '/home/servernetcloud';
$work = $home.'/.gpu-sales-20261101';
$live = $home.'/servernet_app';
$source = $work.'/source/website';
$prior=json_decode(file_get_contents($work.'/release2-receipt.json'),true,512,JSON_THROW_ON_ERROR);
$expected = [
    'app/Services/Cloud/CloudCatalogRepricer.php' => $prior['manifest']['app/Services/Cloud/CloudCatalogRepricer.php']['after'],
    'app/Services/Cloud/CloudSalesHealth.php' => $prior['manifest']['app/Services/Cloud/CloudSalesHealth.php']['after'],
    'app/Services/Cloud/VpsTcApi.php' => 'ddb8c91f42c7e0aaab9220d97c6eaba21a1812056ce67b06f0d894bd59484573',
    'app/Services/Cloud/VpsTcCatalog.php' => 'b62c66eb99e634dddc1086f4ac7cfc4eb7e6a2a4b2bb2eacf864e23d685ae71a',
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
        $data=file_get_contents($source.'/'.$relative);
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
    file_put_contents($work.'/release3-receipt.json', json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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
