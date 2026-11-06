<?php

namespace App\Services\Cloud;

use App\Support\ErrorTracker;
use Closure;

/** Private, single-slot catalog work. No customer resource or payment is queued. */
class CatalogRefreshRequests
{
    private const MAX_WAIT_SECONDS = 900;

    public function __construct(private ?string $directory = null) {}

    private function directory(): string
    {
        return $this->directory ?? storage_path('app/cloud-catalog-refresh');
    }

    private function lock(string $name, bool $wait = true)
    {
        $dir = $this->directory();
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Catalog refresh storage unavailable.');
        }
        $handle = @fopen($dir.'/'.$name.'.lock', 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('Catalog refresh lock unavailable.');
        }
        if (! flock($handle, LOCK_EX | ($wait ? 0 : LOCK_NB))) {
            fclose($handle);
            throw new CatalogRefreshBusy('Catalog refresh already running.');
        }

        return $handle;
    }

    private function controlled(Closure $callback): mixed
    {
        $handle = $this->lock('control');
        try {
            return $callback();
        } finally {
            fclose($handle);
        }
    }

    private function read(): ?array
    {
        $file = $this->directory().'/state.json';
        if (! is_file($file)) {
            return null;
        }
        $state = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($state) || ! isset($state['id'], $state['state'], $state['requested_at'], $state['prices_only'])
            || ! is_string($state['id']) || ! preg_match('/^[a-f0-9]{24}$/D', $state['id'])
            || ! is_string($state['requested_at']) || strtotime($state['requested_at']) === false
            || ! is_bool($state['prices_only'])
            || ! in_array($state['state'], ['queued', 'running', 'completed', 'partial', 'failed'], true)) {
            throw new \RuntimeException('Catalog refresh state invalid.');
        }

        return $state;
    }

    public function snapshot(): ?array
    {
        try {
            return $this->read();
        } catch (\Throwable) {
            return ['state' => 'unavailable'];
        }
    }

    private function save(array $state): void
    {
        $temporary = $this->directory().'/state.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            $data = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if (file_put_contents($temporary, $data, LOCK_EX) !== strlen($data)
                || ! rename($temporary, $this->directory().'/state.json')) {
                throw new \RuntimeException('Catalog refresh state could not be saved.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** A second click returns the same request, including while it is running. */
    public function request(bool $pricesOnly, int $actor): array
    {
        return $this->controlled(function () use ($pricesOnly, $actor) {
            $current = $this->read();
            if (in_array($current['state'] ?? '', ['queued', 'running'], true)) {
                return $current;
            }
            $state = ['id' => bin2hex(random_bytes(12)), 'state' => 'queued',
                'prices_only' => $pricesOnly, 'requested_by' => $actor,
                'requested_at' => now()->toIso8601String()];
            $this->save($state);

            return $state;
        });
    }

    /** Shared by ALL catalog sync/reprice entrypoints, not just the new worker. */
    public function exclusive(Closure $callback): mixed
    {
        $handle = $this->lock('catalog', false);
        try {
            return $callback();
        } finally {
            fclose($handle);
        }
    }

    /** A killed worker is marked failed; incomplete work is never replayed silently. */
    public function process(CloudCatalogSync $sync): string
    {
        try {
            $worker = $this->lock('worker', false);
        } catch (CatalogRefreshBusy) {
            return 'busy';
        }
        try {
            $terminal = null;
            $state = $this->controlled(function () use (&$terminal) {
                $state = $this->read();
                if (($state['state'] ?? '') === 'running') {
                    $this->finish($state, 'failed', ['reason' => 'interrupted']);
                    $terminal = 'failed';
                    return null;
                }
                if (($state['state'] ?? '') !== 'queued') {
                    return null;
                }
                if (\Carbon\Carbon::parse($state['requested_at'])->addSeconds(self::MAX_WAIT_SECONDS)->isPast()) {
                    $this->finish($state, 'failed', ['reason' => 'expired']);
                    $terminal = 'failed';
                    return null;
                }
                $state['state'] = 'running';
                $state['started_at'] = now()->toIso8601String();
                $this->save($state);

                return $state;
            });
            if ($state === null) {
                return $terminal ?? 'idle';
            }
            try {
                if ($state['prices_only']) {
                    if (! \Illuminate\Support\Facades\Schema::hasTable('cloud_plans')
                        || app(CloudPricing::class)->eurToToman() <= 0) {
                        $result = ['reason' => 'pricing_unavailable'];
                        $outcome = 'failed';
                    } else {
                        $result = ['plans' => $sync->reprice()];
                        $outcome = 'completed';
                    }
                } else {
                    $report = $sync->sync();
                    $providers = array_filter((array) ($report['providers'] ?? []),
                        fn ($row, $key) => ! str_starts_with((string) $key, '__') && is_array($row), ARRAY_FILTER_USE_BOTH);
                    $failed = array_keys(array_filter($providers, fn ($row) => ($row['ok'] ?? false) !== true));
                    $ok = count($providers) - count($failed);
                    $result = ['providers' => count($providers), 'failed_providers' => $failed,
                        'plans' => array_sum(array_column($providers, 'plans')),
                        'rate_available' => ($report['rate'] ?? 0) > 0,
                        'warnings' => array_values(array_map(fn ($warning) => mb_substr($warning, 0, 1000),
                            array_filter([$report['providers']['__cost'] ?? null, $report['providers']['__sanity'] ?? null], 'is_string')))];
                    if ($providers === []) {
                        $result['reason'] = 'no_providers';
                    }
                    $outcome = $ok === 0 ? 'failed'
                        : ($failed !== [] || ! $result['rate_available'] || $result['warnings'] !== [] ? 'partial' : 'completed');
                }
                $this->controlled(fn () => $this->finish($state, $outcome, $result));

                return $outcome;
            } catch (CatalogRefreshBusy) {
                // The scheduled FX/catalog job already owns the shared lock.
                // Defer the original request; do not overlap or extend its age.
                $state['state'] = 'queued';
                unset($state['started_at']);
                $this->controlled(fn () => $this->save($state));

                return 'busy';
            } catch (\Throwable $e) {
                $this->controlled(fn () => $this->finish($state, 'failed',
                    ['reason' => 'exception', 'exception_class' => $e::class]));
                ErrorTracker::note('cloud_catalog', 'Catalog refresh failed; inspect its receipt before retrying.',
                    ['request_id' => $state['id'], 'exception_class' => $e::class]);

                return 'failed';
            }
        } finally {
            fclose($worker);
        }
    }

    private function finish(array $state, string $outcome, array $result): void
    {
        $this->save(array_merge($state, ['state' => $outcome, 'finished_at' => now()->toIso8601String(), 'result' => $result]));
    }
}
