<?php
/** Scoped owner-authorized release. No campaign is queued or sent by this installer. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$sha = $argv[1] ?? '';
if (! preg_match('/\A[0-9a-f]{40}\z/', $sha)) { throw new RuntimeException('Pinned commit required.'); }
$base = '/home/servernetcloud/servernet_app';
$backup = '/home/servernetcloud/deploy-backups/email-personalization-20261122';
$manifest = json_decode(<<<'MANIFEST'
{
  "app/Services/Marketing/EmailMarketingCampaigns.php": {
    "before": "2bd657690f1504730f0c9cc54b21e379bfbdead381907223562d088000b48810",
    "source": "4841fbf81b34d8655ff6d312257797cd0c5f0f05b2763fae81073b18e551a57d"
  },
  "app/Services/Marketing/EmailCampaignTemplate.php": {
    "before": null,
    "source": "3882f8d14a86765245c6bba8762931232839d3d24e36e1b69d736069fc45908d"
  },
  "app/Services/Marketing/MissingEmailRecipientNames.php": {
    "before": null,
    "source": "7be5d4820ddd5a3319e231dc610b888ca83c8cdaed57b6bbbd76481333b1c5a3"
  },
  "app/Models/EmailMarketingRecipient.php": {
    "before": "e50f9a3dc678ddddf12af56156d20bdfdcbe63a27caa60910f17ebaee9eb615c",
    "source": "7b2e31539257f6e45b3e06f962a6e1068eb8d13de1d86a5b0e95a11dd82db15f"
  },
  "app/Http/Controllers/Admin/EmailMarketingCampaignController.php": {
    "before": "ee84d451f7eaa76c10bb8239e81ab12643f96b5b2cb47d0361c6a889e10e8d60",
    "source": "b00fa39de6303bf11b76ffa029eab6f535a142641674b30080f871b6395a6ae5"
  },
  "resources/views/admin/email-marketing-campaigns.blade.php": {
    "before": "a7edf872e7861c6ee7ec2eb3a3d31f0e57ff71b27ad7c913efdfc5814f186032",
    "source": "52da29c2b54ee10d0c3e57bbd6a12ccc6e3850b489543d23de016920e9fd487e"
  },
  "lang/fa/email-campaigns.php": {
    "before": "293beeb1490d9988cf3488941739123318523def034b598a59d1da4bd9f50cad",
    "source": "bf1edc210d26b165fff6d8a360ba5d6be871af8fcf85fef9e6fe0289f82ec6bf"
  },
  "lang/en/email-campaigns.php": {
    "before": "f0fdc2aa74a8f10834ae81c4aa579d4208ac3c2e97f07ea898063cb2cb3e5b2c",
    "source": "c00ee775611624a36e14384110b93a20d6c06ba46023b1c5b5a35d8fd2b8ef39"
  },
  "lang/tr/email-campaigns.php": {
    "before": "bd2cea45cd1cc585c18ebbeb0447f77c4b4922790d5b195a8085c9b4055841cb",
    "source": "3a000bf3474e288ba41785c74631add40bb07ada1b86a5ca5414216ad4dc52a5"
  },
  "database/migrations/2026_11_22_140000_add_email_campaign_personalization.php": {
    "before": null,
    "source": "e585aa8b6ca665f8f5a027f8874a385ad6adc70418ff4870813c48fddcd1e128"
  }
}
MANIFEST, true, 512, JSON_THROW_ON_ERROR);
if (realpath($base) !== $base || is_link($backup)) { throw new RuntimeException('Unexpected release destination.'); }
if (! is_dir($backup) && ! mkdir($backup, 0700, true)) { throw new RuntimeException('Backup unavailable.'); }
$lock = fopen(dirname($backup).'/.email-personalization-deployment.lock', 'c');
if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Release locked.'); }
$stage = $backup.'/stage-'.bin2hex(random_bytes(8));
mkdir($stage, 0700);
$prepared = [];
$installed = [];
try {
    if (stat($base)['dev'] !== stat($stage)['dev']) { throw new RuntimeException('Stage must share destination filesystem.'); }
    if (glob($base.'/bootstrap/cache/routes-*.php')) { throw new RuntimeException('Cached routes require explicit release handling.'); }
    foreach ($manifest as $relative => $item) {
        $target = $base.'/'.$relative;
        $current = is_file($target) ? hash_file('sha256', $target) : null;
        if ($current !== $item['before']) { throw new RuntimeException('Changed target: '.$relative); }
        $url = 'https://raw.githubusercontent.com/servernetir/server/'.$sha.'/website/'.$relative;
        // This cPanel CLI disables URL fopen; use the verified system HTTPS client.
        $process = proc_open(['/usr/bin/curl', '--proto', '=https', '--tlsv1.2', '--fail', '--silent', '--show-error', '--location', '--max-time', '40', $url], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $downloadPipes);
        if (! is_resource($process)) { throw new RuntimeException('Download unavailable: '.$relative); }
        $bytes = stream_get_contents($downloadPipes[1]);
        $downloadError = stream_get_contents($downloadPipes[2]);
        fclose($downloadPipes[1]);
        fclose($downloadPipes[2]);
        if (proc_close($process) !== 0) { throw new RuntimeException('HTTPS download failed: '.$relative); }
        if ($bytes === false || hash('sha256', $bytes) !== $item['source']) { throw new RuntimeException('Artifact mismatch: '.$relative); }
        $path = $stage.'/'.$relative;
        if (! is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
        file_put_contents($path, $bytes);
        if (str_ends_with($relative, '.php') && ! str_contains($relative, '.blade.php')) {
            exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path).' 2>&1', $lint, $code);
            if ($code !== 0) { throw new RuntimeException('Lint failed: '.$relative); }
        }
        $prepared[$relative] = ['path' => $path, 'before' => $current, 'after' => hash('sha256', $bytes)];
        if ($current !== null) {
            $saved = $backup.'/files/'.$relative;
            if (! is_dir(dirname($saved))) { mkdir(dirname($saved), 0700, true); }
            if (file_exists($saved)) {
                if (hash_file('sha256', $saved) !== $current) { throw new RuntimeException('Existing backup mismatch.'); }
            } elseif (! copy($target, $saved) || hash_file('sha256', $saved) !== $current) {
                throw new RuntimeException('Backup mismatch.');
            }
        }
    }
    chdir($base);
    require $base.'/vendor/autoload.php';
    $app = require $base.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (Illuminate\Support\Facades\DB::table('email_marketing_campaigns')->where('status', 'queued')->exists()) { throw new RuntimeException('Active email campaign requires an isolated release.'); }
    $migration = 'database/migrations/2026_11_22_140000_add_email_campaign_personalization.php';
    $migrationReceipt = $backup.'/migration.json';
    if (Illuminate\Support\Facades\Schema::hasColumn('email_marketing_recipients', 'full_name')) {
        $prior = is_file($migrationReceipt) ? json_decode(file_get_contents($migrationReceipt), true) : null;
        if (($prior['source'] ?? null) !== $manifest[$migration]['source']
            || ! Illuminate\Support\Facades\Schema::hasColumn('email_marketing_campaigns', 'sender_email')
            || ! Illuminate\Support\Facades\Schema::hasColumn('email_marketing_campaigns', 'reply_to')
            || ! Illuminate\Support\Facades\Schema::hasColumn('email_marketing_campaigns', 'sender_name')) {
            throw new RuntimeException('New schema unexpectedly exists.');
        }
    } else {
        $exit = Illuminate\Support\Facades\Artisan::call('migrate', ['--path' => $prepared[$migration]['path'], '--realpath' => true, '--force' => true]);
        if ($exit !== 0) { throw new RuntimeException('Additive migration failed.'); }
        $migrationJson = json_encode(['source' => $manifest[$migration]['source'], 'at' => gmdate('c')], JSON_THROW_ON_ERROR);
        if (file_put_contents($migrationReceipt, $migrationJson) !== strlen($migrationJson) || ! chmod($migrationReceipt, 0600)) { throw new RuntimeException('Migration receipt unavailable.'); }
    }
    // New dependencies first; routes, account menu and parent views last.
    uksort($prepared, static function ($a, $b) use ($prepared) {
        $rank = fn ($p) => $prepared[$p]['before'] === null ? 0 : (str_starts_with($p, 'routes/') || $p === 'resources/views/admin/email-marketing.blade.php' ? 2 : 1);
        return $rank($a) <=> $rank($b);
    });
    foreach ($prepared as $relative => $entry) {
        $target = $base.'/'.$relative;
        if ((is_file($target) ? hash_file('sha256', $target) : null) !== $entry['before']) { throw new RuntimeException('Concurrent change: '.$relative); }
        if (! is_dir(dirname($target))) { mkdir(dirname($target), 0755, true); }
        chmod($entry['path'], 0644);
        if (! rename($entry['path'], $target)) { throw new RuntimeException('Install failed: '.$relative); }
        $installed[$relative] = $entry;
    }
    // Invalidate only the changed Blade templates; do not flush shared application caches.
    foreach (array_keys($prepared) as $relative) {
        if (str_ends_with($relative, '.blade.php')) {
            $compiled = $app->make('blade.compiler')->getCompiledPath($base.'/'.$relative);
            if (is_file($compiled)) { unlink($compiled); }
        }
    }
    foreach ($prepared as $relative => $entry) {
        if (hash_file('sha256', $base.'/'.$relative) !== $entry['after']) { throw new RuntimeException('Verification failed: '.$relative); }
    }
    $receipt = ['sha' => $sha, 'installed_at' => gmdate('c'), 'files' => $prepared, 'schema' => 'encrypted_name_and_sender_snapshot_columns', 'campaigns_queued' => 0, 'messages_sent' => 0];
    $receiptJson = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($backup.'/receipt.json', $receiptJson) !== strlen($receiptJson) || ! chmod($backup.'/receipt.json', 0600)) { throw new RuntimeException('Release receipt unavailable.'); }
    echo json_encode(['ok' => true, 'files' => count($prepared), 'migration' => 'complete', 'campaigns_queued' => 0, 'messages_sent' => 0]), PHP_EOL;
} catch (Throwable $e) {
    foreach (array_reverse($installed, true) as $relative => $entry) {
        $target = $base.'/'.$relative;
        if (! is_file($target) || hash_file('sha256', $target) !== $entry['after']) { continue; }
        if ($entry['before'] === null) {
            if (! unlink($target)) { fwrite(STDERR, 'Rollback removal failed: '.$relative.PHP_EOL); }
        } elseif (! copy($backup.'/files/'.$relative, $target) || hash_file('sha256', $target) !== $entry['before']) {
            fwrite(STDERR, 'Rollback restore failed: '.$relative.PHP_EOL);
        }
        if (isset($app) && str_ends_with($relative, '.blade.php')) {
            $compiled = $app->make('blade.compiler')->getCompiledPath($base.'/'.$relative);
            if (is_file($compiled)) { unlink($compiled); }
        }
    }
    // Additive schema/history is retained if the application release is rolled back.
    fwrite(STDERR, 'Release stopped: '.$e->getMessage().PHP_EOL);
    $exitCode = 1;
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($stage);
    flock($lock, LOCK_UN);
}

exit($exitCode ?? 0);
