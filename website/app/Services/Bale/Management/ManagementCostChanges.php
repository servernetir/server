<?php

namespace App\Services\Bale\Management;

use App\Models\CloudPlan;
use App\Services\Cloud\CloudManager;
use DateTimeImmutable;
use DateTimeZone;

/** Manager-only observations of normalized catalog estimates, never supplier invoices. */
class ManagementCostChanges
{
    public const SOURCE = 'normalized_eur_catalog_estimate_not_invoice';
    public const FRESH_SECONDS = 172800;
    public const MAX_PLANS = 10000;
    public const MAX_PROVIDERS = 12;
    public const ITEMS_PER_PROVIDER = 3;
    private const MAX_STATE_BYTES = 8388608;

    public function __construct(private ?string $statePath = null) {}

    /**
     * Reading a digest/detail/preview never creates a file or advances a baseline.
     * Call with true ONLY after a successful natural daily delivery. The dated
     * prior baseline is retained for today's details, and advancement is once per
     * Tehran day. This writes only private reporting state, never catalog prices.
     */
    public function snapshot(bool $persist = false): array
    {
        $lock = null;
        try {
            $path = $this->statePath ?? storage_path('app/private/bale/management-catalog-costs-v1.json');
            if ($persist) {
                $dir = dirname($path);
                if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
                    return $this->unavailable('baseline_storage_unavailable');
                }
                $lock = @fopen($path.'.lock', 'c');
                if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                    return $this->unavailable('baseline_update_in_progress');
                }
                @chmod($path.'.lock', 0600);
            }

            $now = $this->clock();
            $day = $now->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d');
            $state = $this->readState($path);
            $sameDay = ($state['cycle'] ?? null) === $day;
            $baseline = $sameDay ? $state['prior'] : ($state['latest'] ?? []);
            $baselineAt = $sameDay ? ($state['baseline_at'] ?? null) : ($state['latest_at'] ?? null);
            $raw = $this->catalogRows();
            if (count($raw) > self::MAX_PLANS) { return $this->unavailable('catalog_exceeds_snapshot_limit'); }
            [$current, $unknown] = $this->project($raw, $now);
            $report = $this->compare($current, $baseline, $unknown, $baselineAt, $now);
            $report['state_persisted'] = false;

            if ($persist) {
                if (! $sameDay) {
                    // Preserve a last known rate when today's row is missing/stale;
                    // its original source date travels with that exact rate.
                    $latest = [];
                    foreach ($current as $key => $row) {
                        $row['rates'] = array_replace($baseline[$key]['rates'] ?? [], $row['rates']);
                        if ($row['rates'] !== []) { $latest[$key] = $row; }
                    }
                    $this->writeState($path, ['version' => 1, 'cycle' => $day,
                        'baseline_at' => $baselineAt, 'prior' => $baseline,
                        'latest_at' => $now->format(DATE_ATOM), 'latest' => $latest]);
                }
                $report['state_persisted'] = true;
            }

            return $report;
        } catch (\Throwable) {
            // Raw DB/filesystem errors may contain credentials or internal paths.
            return $this->unavailable('catalog_or_baseline_unavailable');
        } finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    protected function catalogRows(): array
    {
        return CloudPlan::query()->orderBy('id')->limit(self::MAX_PLANS + 1)
            ->get(['id', 'provider', 'provider_ref', 'provider_location', 'public_name',
                'cost_eur_cents', 'cost_hour_eur_micro', 'synced_at'])
            ->map(fn (CloudPlan $plan) => $plan->getAttributes())->all();
    }

    protected function clock(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function project(array $rows, DateTimeImmutable $now): array
    {
        $current = $unknown = [];
        foreach ($rows as $index => $row) {
            $provider = $this->label($row['provider'] ?? '', 48);
            $id = $this->positiveInt($row['id'] ?? null);
            if ($provider === '' || $id === null) { $unknown['invalid:'.$index] = true; continue; }
            // A replacement provider product or location is a new observation,
            // even if the local catalog row id was reused.
            $key = hash('sha256', json_encode([$id, $provider,
                (string) ($row['provider_ref'] ?? ''), (string) ($row['provider_location'] ?? '')], JSON_THROW_ON_ERROR));
            $at = $this->date($row['synced_at'] ?? null);
            $fresh = $at && ($now->getTimestamp() - $at->getTimestamp()) >= -300
                && ($now->getTimestamp() - $at->getTimestamp()) <= self::FRESH_SECONDS;
            $rates = [];
            foreach (['month' => ['cost_eur_cents', 100], 'hour' => ['cost_hour_eur_micro', 1000000]] as $period => [$field, $scale]) {
                $amount = $this->positiveInt($row[$field] ?? null);
                if ($fresh && $amount !== null) {
                    $rates[$period] = ['amount' => $amount, 'scale' => $scale, 'currency' => 'EUR', 'source_at' => $at->format(DATE_ATOM)];
                }
            }
            $current[$key] = ['plan_id' => $id, 'provider' => $provider,
                'plan' => $this->label($row['public_name'] ?? '', 80), 'rates' => $rates];
            if ($rates === []) { $unknown[$key] = true; }
        }

        return [$current, $unknown];
    }

    private function compare(array $current, array $baseline, array $unknown, ?string $baselineAt, DateTimeImmutable $now): array
    {
        $groups = [];
        $compared = $increased = $new = 0;
        foreach ($current as $key => $row) {
            $before = $baseline[$key] ?? null;
            if (! is_array($before)) { $new++; $unknown[$key] = true; continue; }
            foreach (array_diff_key($before['rates'], $row['rates']) as $rate) { $unknown[$key] = true; }
            foreach ($row['rates'] as $period => $rate) {
                $old = $before['rates'][$period] ?? null;
                if (! $this->comparable($old, $rate)) { $unknown[$key] = true; continue; }
                $compared++;
                if ($rate['amount'] <= $old['amount']) { continue; }
                $increased++;
                $provider = $row['provider'];
                $groups[$provider] ??= ['provider' => $provider,
                    'label' => CloudManager::REAL_NAMES[$provider] ?? $provider,
                    'increase_count' => 0, 'items' => [],
                    'cause' => 'normalized_catalog_increase_observed_underlying_cause_unverified',
                    'action' => 'compare_dated_supplier_tariff_and_fx_then_review_margin'];
                $groups[$provider]['increase_count']++;
                if (count($groups[$provider]['items']) < self::ITEMS_PER_PROVIDER) {
                    $groups[$provider]['items'][] = ['plan_id' => $row['plan_id'], 'plan' => $row['plan'],
                        'period' => $period, 'currency' => $rate['currency'], 'scale' => $rate['scale'],
                        'before' => $old['amount'], 'after' => $rate['amount'],
                        'before_source_at' => $old['source_at'], 'source_at' => $rate['source_at']];
                }
            }
        }
        ksort($groups);
        $providerCount = count($groups);

        return ['available' => true, 'status' => $baseline === [] ? 'baseline_missing' : ($compared === 0 ? 'comparison_unknown' : 'compared'),
            'source' => self::SOURCE, 'observed_at' => $now->format(DATE_ATOM), 'baseline_at' => $baselineAt,
            'increase_count' => $compared === 0 ? null : $increased, 'comparison_count' => $compared,
            'unknown_count' => count($unknown), 'new_plan_count' => $new,
            'provider_count' => $providerCount, 'provider_increases' => array_values(array_slice($groups, 0, self::MAX_PROVIDERS, true)),
            'truncated' => $providerCount > self::MAX_PROVIDERS,
            'reason' => $baseline === [] ? 'dated_baseline_missing' : null,
            'basis' => 'positive_monthly_cents_and_reported_hourly_micro_eur_only_no_setup_fee_or_invoice',
            'cause' => 'catalog_estimate_fx_or_supplier_change_not_yet_verified',
            'action' => 'compare_dated_supplier_tariff_and_fx_then_review_margin'];
    }

    private function comparable(mixed $before, array $after): bool
    {
        return is_array($before) && $this->positiveInt($before['amount'] ?? null) !== null
            && ($before['currency'] ?? null) === $after['currency']
            && ($before['scale'] ?? null) === $after['scale']
            && $this->date($before['source_at'] ?? null) !== null;
    }

    private function readState(string $path): array
    {
        if (! file_exists($path)) { return []; }
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_STATE_BYTES) { throw new \RuntimeException('Invalid baseline'); }
        $state = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($state) || ($state['version'] ?? null) !== 1
            || ! is_array($state['latest'] ?? null) || ! is_array($state['prior'] ?? null)
            || count($state['latest']) > self::MAX_PLANS || count($state['prior']) > self::MAX_PLANS
            || ! is_string($state['cycle'] ?? null) || $this->date($state['latest_at'] ?? null) === null) {
            throw new \RuntimeException('Invalid baseline');
        }

        return $state;
    }

    private function writeState(string $path, array $state): void
    {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_STATE_BYTES) { throw new \RuntimeException('Baseline too large'); }
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            if (file_put_contents($temporary, $json, LOCK_EX) === false || ! chmod($temporary, 0600)
                || ! rename($temporary, $path)) { throw new \RuntimeException('Baseline write failed'); }
        } finally { if (file_exists($temporary)) { @unlink($temporary); } }
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') { return null; }
        try { return new DateTimeImmutable($value, new DateTimeZone('UTC')); }
        catch (\Throwable) { return null; }
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) { return $value > 0 ? $value : null; }
        if (! is_string($value) || ! preg_match('/\A[1-9][0-9]{0,14}\z/D', $value)) { return null; }

        return (int) $value;
    }

    private function label(mixed $value, int $limit): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $value) ?? ''), 0, $limit);
    }

    private function unavailable(string $reason): array
    {
        return ['available' => false, 'status' => 'unknown', 'reason' => $reason,
            'source' => self::SOURCE, 'increase_count' => null, 'comparison_count' => 0,
            'unknown_count' => null, 'provider_increases' => [], 'state_persisted' => false];
    }
}
