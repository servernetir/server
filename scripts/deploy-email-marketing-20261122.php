<?php
/** Scoped owner-authorized release. No campaign is queued or sent by this installer. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$sha = $argv[1] ?? '';
if (! preg_match('/\A[0-9a-f]{40}\z/', $sha)) { throw new RuntimeException('Pinned commit required.'); }
$base = '/home/servernetcloud/servernet_app';
$backup = '/home/servernetcloud/deploy-backups/email-marketing-20261122';
$manifest = json_decode(<<<'MANIFEST'
{
  "app/Console/Commands/EmailMarketingSend.php": {
    "before": null,
    "source": "02d34bbc6366acb3591bd79812bf131e4975179b4c35dbe45a92b970967ac3e0"
  },
  "app/Http/Controllers/Account/AccountController.php": {
    "before": "d393c4a5594e79013ab78df310538d4fa7c9b4c65a918cbc6b00bedaeb1fdf98",
    "source": "864900f71facb67f333f2122915d1c3dc6a96c9d5466a230ede540329154542a"
  },
  "app/Http/Controllers/Admin/EmailEvidenceDocumentController.php": {
    "before": null,
    "source": "a8da8a9860deba6173b8d25dc1c0535285876a1dfc48c61b3d8b4d4873d1d39f"
  },
  "app/Http/Controllers/Admin/EmailEvidenceReviewController.php": {
    "before": "36b2e9795eba52ee144c04691aa95e419630e8c7e140b9eb9ca5694eb949b25d",
    "source": "08fbf35ee59207b6e5749f53c8d194a3585ebb758bf359b7afc7596864c28aa0"
  },
  "app/Http/Controllers/Admin/EmailMarketingCampaignController.php": {
    "before": null,
    "source": "ee84d451f7eaa76c10bb8239e81ab12643f96b5b2cb47d0361c6a889e10e8d60"
  },
  "app/Http/Controllers/Admin/EmailMarketingController.php": {
    "before": "5318e6640cf88838282fb3cf541697df7ff1f7de6a1e2024456c4d710fd763b6",
    "source": "9a72b6c73865cddb3dbff9e768350aefbacc4c73260b29f598a40b943438b01a"
  },
  "app/Http/Controllers/EmailCampaignUnsubscribeController.php": {
    "before": null,
    "source": "aa0f1af4827ef4d9eb082a57ac108fb484c158d6791ae2beaf9658206ea4f0fe"
  },
  "app/Models/EmailMarketingCampaign.php": {
    "before": null,
    "source": "f0eebccf7b4fa84e51a65dd2e852275ddb7406d4e9db1d09ae54ab4842b72807"
  },
  "app/Models/EmailMarketingDelivery.php": {
    "before": null,
    "source": "6e226fd33372f257fa6efc91e27241faf4115c799a3a45541ac0fc6d62c5abe7"
  },
  "app/Models/EmailMarketingRecipient.php": {
    "before": null,
    "source": "e50f9a3dc678ddddf12af56156d20bdfdcbe63a27caa60910f17ebaee9eb615c"
  },
  "app/Services/Marketing/EmailMarketingCampaigns.php": {
    "before": null,
    "source": "2bd657690f1504730f0c9cc54b21e379bfbdead381907223562d088000b48810"
  },
  "database/migrations/2026_11_22_120000_create_email_marketing_campaigns.php": {
    "before": null,
    "source": "256ed9c115bece70a7ce4e4ca14a44d6e6e12c8deb0c1c09c7ac6492c15158f3"
  },
  "lang/en/email-campaigns.php": {
    "before": null,
    "source": "f0fdc2aa74a8f10834ae81c4aa579d4208ac3c2e97f07ea898063cb2cb3e5b2c"
  },
  "lang/en/email-evidence-review.php": {
    "before": "a24dfb8d0806640e11af8b0bdece1cb23bb7fdc9de96432be206212e1469f7ab",
    "source": "3cb13f7768375165b84ee564d8f7f5540a49e610cde06ad8abbf73d37f468078"
  },
  "lang/en/email-marketing.php": {
    "before": "ed80797d83e9cf22bbc7551b8e1a0fdd31600d0fbb81dda1d62c901b7298697a",
    "source": "47af5c4202beaadf938c850df60e7756a073e7fc1d0aafee3637ecc70943dc55"
  },
  "lang/fa/email-campaigns.php": {
    "before": null,
    "source": "293beeb1490d9988cf3488941739123318523def034b598a59d1da4bd9f50cad"
  },
  "lang/fa/email-evidence-review.php": {
    "before": "e2d10ba4bba5d1f2d41a7fcfe712a721b54dc53ac3362fffab657e9c9b2980eb",
    "source": "065ec1144dc673ff6e3783db45bfe5747c99cf1df93fb785bd0b6bd1d0de07e9"
  },
  "lang/fa/email-marketing.php": {
    "before": "6d656dc9c431dad823687d5b4690afeafb7b84ca2a18cffd509bfad76d4a75fe",
    "source": "dd87e02a3b704dd38948a39ee136ace83a35cdc10798dcd82a4afa4c448ffcb0"
  },
  "lang/tr/email-campaigns.php": {
    "before": null,
    "source": "bd2cea45cd1cc585c18ebbeb0447f77c4b4922790d5b195a8085c9b4055841cb"
  },
  "lang/tr/email-evidence-review.php": {
    "before": "f531e0d8072d546482c56bad50132889dab3bcebdfeaa0c7f889b7118aeff144",
    "source": "a7f3b053fe41bffa0862f5fa8ec0217eb69360a70ce321888d0cb1d091bf95f0"
  },
  "lang/tr/email-marketing.php": {
    "before": "c82d20cac7701901a3338ce6e49095c9b3376453778cb76a490a4f8b8f8ff45b",
    "source": "9dea9ddc9f7c0df9225d1e4851fbc241f8eea876beed2462c649f694b10af0bf"
  },
  "resources/views/admin/email-evidence-review.blade.php": {
    "before": "974b2436472e07e620beae82995c0a993237e5641969448746890d47ca7c4460",
    "source": "99848da253c190eb8fda118425383a328dc1eae6096dc594fdfc2c7f5545dcbc"
  },
  "resources/views/admin/email-marketing-campaigns.blade.php": {
    "before": null,
    "source": "a7edf872e7861c6ee7ec2eb3a3d31f0e57ff71b27ad7c913efdfc5814f186032"
  },
  "resources/views/admin/email-marketing.blade.php": {
    "before": "0679efa39f78245a18cfbf26a06dde07060ad096d108de930d6f1323637d1d12",
    "source": "b1cf02d9b7740a700363bbfcfb39797ee87a59a1fa09fce3e2fa5e69c332567a"
  },
  "resources/views/email-marketing/campaign-unsubscribe.blade.php": {
    "before": null,
    "source": "cb6fcb2f02fc758bdbed8057731aaabc1fa209e56ed9c18a911ff07bf225bc8f"
  },
  "routes/console.php": {
    "before": "9e66365e0a6165c8dd89b364074886a8a28b07c4041f3c7abd477e564cdf0e77",
    "source": "549b5469c3201037f3a67fbfb0c5438468ca13bb4cc3832d16cedfaced77423b"
  },
  "routes/email-marketing-account.php": {
    "before": "8e33a72e5a06ff553019fd319ddfb8648cc535a22d26933e1eecac49c4ee2911",
    "source": "be931cb2c1c5594c1305fd9ff4c8baa29f2b867d1aebf978d01cdf16b0edfa35"
  },
  "routes/email-marketing-admin.php": {
    "before": "5293e5696153f08a8880149c0eeafeb9b86c3fb5d5ee8805d24e6154127f3ce1",
    "source": "42dc1b6353881488ec796fd73e89a0e795375d52c83a9a06404d97e2caf3c340"
  },
  "routes/email-marketing-public.php": {
    "before": "f1162bac68bbd8742ea02cfb34c72e13a99d873eda5b18bcd8f2343d573d1294",
    "source": "00a689fbefb51283d3c37887b7a4b8555dfe158deca1bd7bdd3e8339cd2de782"
  }
}
MANIFEST, true, 512, JSON_THROW_ON_ERROR);
$accountRemove = <<<'TEXT'
                    ...((bool) config('email-marketing.enabled') && \Illuminate\Support\Facades\Route::has('account.email-preferences')
                        ? [['key' => 'email-preferences', 'icon' => 'mail', 'label' => __('email-marketing.account_title'), 'url' => lroute('account.email-preferences')]] : []),
TEXT;
$scheduleAppend = <<<'TEXT'

// Sends only drafts explicitly queued by an administrator; no campaign is created automatically.
Schedule::command('email-marketing:send --limit=25')->everyMinute()->withoutOverlapping(10);
TEXT;
if (realpath($base) !== $base || is_link($backup)) { throw new RuntimeException('Unexpected release destination.'); }
if (! is_dir($backup) && ! mkdir($backup, 0700, true)) { throw new RuntimeException('Backup unavailable.'); }
$lock = fopen(dirname($backup).'/.email-marketing-deployment.lock', 'c');
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
        $bytes = file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 40], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]));
        if ($bytes === false || hash('sha256', $bytes) !== $item['source']) { throw new RuntimeException('Artifact mismatch: '.$relative); }
        // Preserve independently deployed production changes in these shared files.
        if ($relative === 'app/Http/Controllers/Account/AccountController.php') {
            $old = file_get_contents($target);
            if (substr_count($old, $accountRemove) !== 1) { throw new RuntimeException('Account menu changed.'); }
            $bytes = str_replace($accountRemove."\n", '', $old);
        }
        if ($relative === 'routes/console.php') {
            $old = file_get_contents($target);
            if (str_contains($old, "email-marketing:send")) { throw new RuntimeException('Schedule already present.'); }
            $bytes = rtrim($old)."\n".$scheduleAppend."\n";
        }
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
    $migration = 'database/migrations/2026_11_22_120000_create_email_marketing_campaigns.php';
    $migrationReceipt = $backup.'/migration.json';
    if (Illuminate\Support\Facades\Schema::hasTable('email_marketing_recipients')) {
        $prior = is_file($migrationReceipt) ? json_decode(file_get_contents($migrationReceipt), true) : null;
        if (($prior['source'] ?? null) !== $manifest[$migration]['source']
            || ! Illuminate\Support\Facades\Schema::hasTable('email_marketing_campaigns')
            || ! Illuminate\Support\Facades\Schema::hasTable('email_marketing_deliveries')) {
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
    $receipt = ['sha' => $sha, 'installed_at' => gmdate('c'), 'files' => $prepared, 'schema' => 'three_additive_email_tables', 'campaigns_queued' => 0, 'messages_sent' => 0];
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
