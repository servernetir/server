<?php

namespace App\Services\Hermes;

use RuntimeException;

/** Durable claims and encrypted drafts, outside the document root. */
final class HermesSupportState
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? storage_path('app/private/hermes-support');
    }

    public function read(): array
    {
        return $this->change(static fn (array $s): array => $s, false);
    }

    public function change(callable $change, bool $write = true): array
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Hermes support state directory unavailable');
        }
        chmod($this->directory, 0700);
        $lock = fopen($this->directory.'/.lock', 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Hermes support state lock unavailable');
        }
        chmod($this->directory.'/.lock', 0600);
        try {
            $path = $this->directory.'/state.json';
            $state = ['version' => 1, 'items' => [], 'errors' => [], 'runs' => []];
            if (is_file($path)) {
                $saved = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($saved) || ($saved['version'] ?? null) !== 1) {
                    throw new RuntimeException('Hermes support state is invalid');
                }
                $state = $saved + $state;
            }
            $next = $change($state);
            if (! is_array($next)) {
                throw new RuntimeException('Hermes support state mutation is invalid');
            }
            if ($write) {
                $next['items'] = array_slice($next['items'] ?? [], -5000, null, true);
                $next['errors'] = array_slice($next['errors'] ?? [], -200, null, true);
                $next['runs'] = array_slice($next['runs'] ?? [], -72);
                $tmp = $path.'.'.bin2hex(random_bytes(8));
                $json = json_encode($next, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                if (file_put_contents($tmp, $json, LOCK_EX) === false) {
                    throw new RuntimeException('Hermes support state write failed');
                }
                chmod($tmp, 0600);
                if (! rename($tmp, $path)) {
                    @unlink($tmp);
                    throw new RuntimeException('Hermes support state activation failed');
                }
            }
            return $next;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function item(string $key): ?array
    {
        return $this->read()['items'][$key] ?? null;
    }

    /** Crash-released OS lock also protects against independent native workers. */
    public function withItemLock(string $key, callable $callback): mixed
    {
        $this->read();
        // A bounded set of lock stripes also bounds disk usage over years.
        $path = $this->directory.'/item-'.substr(hash('sha256', $key), 0, 3).'.lock';
        $lock = fopen($path, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('Hermes support ticket lock unavailable');
        }
        chmod($path, 0600);
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return null;
        }
        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function put(string $key, array $item): void
    {
        $this->change(function (array $s) use ($key, $item): array {
            $s['items'][$key] = $item + ($s['items'][$key] ?? []);
            $s['items'][$key]['updated_at'] = time();
            $s['last_seen_customer_message_id'] = max((int) ($s['last_seen_customer_message_id'] ?? 0), (int) ($item['customer_message_id'] ?? 0));
            return $s;
        });
    }

    /** Queue skipped discovery without racing an already claimed/completed item. */
    public function queueIfUnclaimed(string $key, array $item): void
    {
        $this->change(function (array $s) use ($key, $item): array {
            if (! isset($s['items'][$key])) {
                $s['items'][$key] = $item + ['status' => 'retry', 'attempts' => 0, 'updated_at' => time()];
            }
            return $s;
        });
    }

    /** Crash claims are visible incidents, never permission to resend. */
    public function reconcileInterrupted(?int $at = null): int
    {
        $at ??= time();
        $count = 0;
        foreach ($this->read()['items'] as $key => $item) {
            if (! in_array($item['status'] ?? '', ['dispatching', 'message_written'], true)
                || (int) ($item['dispatch_started_at'] ?? $item['started_at'] ?? $at) > $at - 900) {
                continue;
            }
            $done = $this->withItemLock($key, function () use ($key, $at): bool {
                $current = $this->item($key);
                if (! $current || ! in_array($current['status'] ?? '', ['dispatching', 'message_written'], true)
                    || (int) ($current['dispatch_started_at'] ?? $current['started_at'] ?? $at) > $at - 900) {
                    return false;
                }
                $status = isset($current['reply_id']) ? 'sent_notification_error' : 'delivery_uncertain';
                $this->put($key, ['status' => $status, 'reason' => 'worker_interrupted', 'finished_at' => $at]);
                $this->error('worker_interrupted', (string) ($current['request_id'] ?? 'recovery'), [
                    'ticket_id' => $current['ticket_id'] ?? null, 'customer_message_id' => $current['customer_message_id'] ?? null,
                    'reply_id' => $current['reply_id'] ?? null, 'component' => 'delivery',
                ], false);
                return true;
            });
            $count += $done === true ? 1 : 0;
        }
        return $count;
    }

    public function error(string $code, string $requestId, array $context = [], bool $retryable = false): void
    {
        $allowed = array_intersect_key($context, array_flip(['ticket_id', 'customer_message_id', 'status', 'attempt', 'reply_id', 'component']));
        $allowed = array_filter($allowed, static fn ($v): bool => is_scalar($v) || $v === null);
        $fingerprint = substr(hash('sha256', 'support|'.$code.'|'.($allowed['status'] ?? '')), 0, 24);
        $this->change(function (array $s) use ($code, $requestId, $allowed, $retryable, $fingerprint): array {
            $prior = $s['errors'][$fingerprint] ?? [];
            $s['errors'][$fingerprint] = [
                'fingerprint' => $fingerprint, 'component' => 'hermes.support', 'code' => $code,
                'first_at' => $prior['first_at'] ?? time(), 'last_at' => time(),
                'count' => (int) ($prior['count'] ?? 0) + 1,
                'request_id' => substr($requestId, 0, 64), 'retryable' => $retryable, 'context' => $allowed,
            ];
            return $s;
        });
    }

    /** Safe status metadata. Drafts and customer text are never returned here. */
    public function status(): array
    {
        $s = $this->read();
        $counts = [];
        foreach ($s['items'] as $item) {
            $status = $item['status'] ?? 'unknown';
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        return [
            'last_run_at' => $s['last_run_at'] ?? null,
            'last_success_at' => $s['last_success_at'] ?? null,
            'last_seen_customer_message_id' => $s['last_seen_customer_message_id'] ?? 0,
            'last_error_review_at' => $s['last_error_review_at'] ?? null,
            'counts' => $counts,
            'recent_runs' => array_slice($s['runs'], -10),
            'errors' => array_values($s['errors']),
        ];
    }
}
