<?php

namespace App\Services;

use RuntimeException;
use Throwable;

/** Tiny, private, atomic run receipts. A missed/OOM-killed job remains visibly unfinished. */
class MaintenanceReceipt
{
    private const JOBS = ['site_gate', 'fx_refresh', 'domain_catalogue', 'file_cache_prune'];
    private const METRICS = ['peak_memory_bytes', 'memory_limit_bytes', 'currencies', 'plans', 'count', 'total', 'excluded_accounts', 'remaining_bytes', 'removed_bytes', 'expired'];

    public function start(string $job): ?string
    {
        try {
            $run = bin2hex(random_bytes(12));
            $written = $this->update($job, function (?array $old) use ($job, $run) {
                $now = now()->timestamp;
                $limit = (int) config('operations_health.jobs.'.$job.'.run_limit', 60) * 60;
                // A second invocation must not mask the actual active one.
                // Once overdue, a naturally scheduled retry may supersede it.
                if (($old['status'] ?? null) === 'running' && $old['started_at'] <= $now
                    && $now - $old['started_at'] <= $limit) return null;

                return [
                    'run' => $run,
                    'status' => 'running',
                    'started_at' => $now,
                    'finished_at' => null,
                    'last_success_at' => $old['last_success_at'] ?? null,
                    'metrics' => [],
                ];
            });

            return $written ? $run : null;
        } catch (Throwable) {
            return null; // Telemetry failure must not block the actual work.
        }
    }

    public function finish(string $job, ?string $run, bool $success, array $metrics = []): void
    {
        if ($run === null) return;

        try {
            $this->update($job, function (?array $record) use ($run, $success, $metrics) {
                // A concurrent/newer attempt owns the receipt now. An older
                // process returning late cannot erase that attempt's state.
                if ($record === null || $record['run'] !== $run || $record['status'] !== 'running') return null;

                $at = now()->timestamp;
                if ($at < $record['started_at']) return null;

                $record['status'] = $success ? 'success' : 'failed';
                $record['finished_at'] = $at;
                if ($success) $record['last_success_at'] = $at;
                $record['metrics'] = self::metrics($metrics + [
                    'peak_memory_bytes' => memory_get_peak_usage(true),
                    'memory_limit_bytes' => ini_parse_quantity((string) ini_get('memory_limit')),
                ]);

                return $record;
            });
        } catch (Throwable) {
            // A missing/unfinished receipt becomes a health warning. Do not
            // change the command exit code, retry purchases or log secrets.
        }
    }

    public function read(string $job): ?array
    {
        try {
            $path = $this->path($job);
            clearstatcache(true, $path);
            if (is_link($path) || ! is_file($path) || filesize($path) > 8192) return null;
            $raw = @file_get_contents($path, false, null, 0, 8193);
            if (! is_string($raw) || strlen($raw) > 8192) return null;
            $state = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($state) || ! is_string($state['run'] ?? null) || ! preg_match('/^[a-f0-9]{24}$/D', $state['run'])
                || ! in_array($state['status'] ?? null, ['running', 'success', 'failed'], true)
                || ! is_int($state['started_at'] ?? null) || $state['started_at'] < 1) return null;

            $finished = $state['finished_at'] ?? null;
            $success = $state['last_success_at'] ?? null;
            if (($state['status'] === 'running' && $finished !== null)
                || ($state['status'] !== 'running' && (! is_int($finished) || $finished < $state['started_at']))
                || ($success !== null && (! is_int($success) || $success < 1))
                || ($state['status'] === 'success' && $success !== $finished)
                || ($state['status'] !== 'success' && $success !== null && $success > $state['started_at'])) return null;

            $state['finished_at'] = $finished;
            $state['last_success_at'] = $success;
            $state['metrics'] = self::metrics(is_array($state['metrics'] ?? null) ? $state['metrics'] : []);

            return $state;
        } catch (Throwable) {
            return null;
        }
    }

    private static function metrics(array $metrics): array
    {
        return array_filter(array_intersect_key($metrics, array_flip(self::METRICS)), fn ($v) => is_int($v) && $v >= 0);
    }

    private function path(string $job): string
    {
        if (! in_array($job, self::JOBS, true)) throw new RuntimeException('Unknown maintenance job');
        $root = rtrim((string) (config('operations_health.receipt_path') ?? storage_path('app/maintenance-health')), '/\\');
        if ($root === '' || is_link($root)) throw new RuntimeException('Invalid maintenance receipt directory');

        return $root.'/'.$job.'.json';
    }

    private function update(string $job, callable $callback): bool
    {
        $path = $this->path($job);
        $root = dirname($path);
        if (! is_dir($root) && ! @mkdir($root, 0700, true) && ! is_dir($root)) throw new RuntimeException('Receipt directory unavailable');
        if (is_link($path) || is_link($path.'.lock')) throw new RuntimeException('Invalid maintenance receipt path');
        $lock = @fopen($path.'.lock', 'c+b');
        if ($lock === false) throw new RuntimeException('Receipt lock unavailable');

        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Receipt busy');
            @chmod($path.'.lock', 0600);
            $next = $callback($this->read($job));
            if ($next === null) return false;

            $json = json_encode($next, JSON_THROW_ON_ERROR);
            $tmp = tempnam($root, '.receipt-');
            if ($tmp === false) throw new RuntimeException('Receipt staging failed');
            try {
                chmod($tmp, 0600);
                if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json) || ! rename($tmp, $path)) {
                    throw new RuntimeException('Receipt publication failed');
                }
            } finally {
                if (is_file($tmp)) @unlink($tmp);
            }

            return true;
        } finally {
            fclose($lock);
        }
    }
}
