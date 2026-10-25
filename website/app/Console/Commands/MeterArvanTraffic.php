<?php

namespace App\Console\Commands;

use App\Services\Finance\ArvanTrafficMeter;
use App\Services\Finance\ArvanTrafficSource;
use App\Support\ErrorTracker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

final class MeterArvanTraffic extends Command
{
    protected $signature = 'finance:meter-arvan-traffic {--apply} {--status} {--initialize=} {--authorization=} {--exclude-customer=*}';

    protected $description = 'Settle only new attributed traffic from complete finance reports, with durable paid checkpoints';

    public function handle(ArvanTrafficMeter $meter, ArvanTrafficSource $source): int
    {
        $dir = storage_path('app/private/arvan-traffic-meter');
        File::ensureDirectoryExists($dir, 0700);
        $lock = fopen($dir.'/run.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->warn('Traffic meter already running');

            return self::SUCCESS;
        }
        try {
            $state = $meter->state();
            if ($this->option('status')) {
                $this->line(json_encode(['initialized' => $state !== null, 'enabled' => $state['enabled'] ?? false,
                    'last_success' => $state['last_success'] ?? null, 'checkpoint_count' => count($state['checkpoints'] ?? []),
                    'last_result' => $state['last_result'] ?? null], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            if (! $state && ! $this->option('initialize')) {
                throw new \RuntimeException('Traffic meter needs a verified paid baseline');
            }
            if ($this->option('initialize') && (! $this->option('apply') || $state)) {
                throw new \RuntimeException('Initialization requires --apply and an absent meter');
            }
            $snapshot = $source->validate($source->fetch($state['cycles'] ?? []));
            // Save successful complete source BEFORE any money moves; private and outside webroot.
            $path = $dir.'/'.now()->format('Ymd_His').'-'.bin2hex(random_bytes(4)).'.json';
            File::put($path, json_encode($snapshot, JSON_THROW_ON_ERROR));
            chmod($path, 0600);
            if ($this->option('initialize')) {
                $planPath = realpath($this->option('initialize'));
                if (! $planPath || ! is_file($planPath) || filesize($planPath) > 1_000_000) {
                    throw new \RuntimeException('Invalid paid baseline file');
                }
                $state = $meter->initialize(json_decode(file_get_contents($planPath), true, 512, JSON_THROW_ON_ERROR),
                    $snapshot, $this->option('exclude-customer'), (string) $this->option('authorization'));
                $this->line(json_encode(['initialized' => true, 'checkpoint_count' => count($state['checkpoints'])]));

                return self::SUCCESS;
            }
            $result = $meter->run($snapshot, (bool) $this->option('apply'));
            File::put($dir.'/last-result.json', json_encode(['at' => now()->toIso8601String(), 'source_file' => basename($path), 'result' => $result], JSON_THROW_ON_ERROR));
            chmod($dir.'/last-result.json', 0600);
            if ($result['held'] !== []) {
                ErrorTracker::note('billing', 'Traffic items require reconciliation', ['held_count' => count($result['held']),
                    'reasons' => array_values(array_unique(array_column($result['held'], 'reason')))]);
            }
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $e) {
            File::put($dir.'/last-error.json', json_encode(['at' => now()->toIso8601String(), 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
            chmod($dir.'/last-error.json', 0600);
            ErrorTracker::note('billing', 'Traffic meter failed; no inferred charge', ['reason' => $e->getMessage()]);
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
