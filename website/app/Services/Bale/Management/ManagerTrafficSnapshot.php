<?php

namespace App\Services\Bale\Management;

use App\Services\Notify\BaleManagerPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/** Dated Google observations. Preview and detail reads never contact Google or refresh tokens. */
class ManagerTrafficSnapshot
{
    public const FRESH_SECONDS = 21600;

    public function __construct(private ManagerGoogleReporting $google) {}

    public function cachedSnapshot(): array
    {
        if (! BaleManagerPolicy::enabled()) { return $this->unknown('disabled'); }
        try {
            $identity = $this->google->accountFingerprint();
            if ($identity === null) { return $this->unknown('not_connected'); }
            $row = Cache::get($this->key($identity));
            if (! is_array($row) || ! $this->fresh($row)) {
                return $this->unknown(is_array($row) ? 'observation_expired' : 'no_observation', true);
            }

            return array_replace($row, ['cached' => true]);
        } catch (\Throwable) { return $this->unknown('snapshot_unavailable'); }
    }

    /** Explicit scheduled-delivery operation: at most one token refresh and four bounded read reports. */
    public function refresh(): array
    {
        if (! BaleManagerPolicy::enabled()) { return $this->unknown('disabled'); }
        $identity = $this->google->accountFingerprint();
        if ($identity === null) { return $this->unknown('not_connected'); }
        $lock = Cache::lock('bale:manager:traffic:refresh:'.$identity, 90);
        if (! $lock->get()) { return $this->unknown('refresh_in_progress', true); }
        try {
            $token = $this->google->accessToken();
            if (! $token['ok']) {
                $row = $this->unknown($token['reason'] ?? 'not_connected', true);
            } else {
                $identity = $token['identity'];
                $row = $this->collect($token['token'], $token['configuration']);
            }
            $row['as_of'] = now()->toIso8601String();
            $row['report_day'] = now('Asia/Tehran')->toDateString();
            if ($this->google->accountFingerprint() !== $identity) { return $this->unknown('connection_changed'); }
            Cache::put($this->key($identity), $row, now()->addDays(7));

            return $row;
        } catch (\Throwable) {
            // No provider response, signed URL, access/refresh token or email in errors.
            return $this->unknown('snapshot_unavailable', true);
        } finally { $lock->release(); }
    }

    private function collect(string $token, array $configuration): array
    {
        $row = $this->unknown('no_observation', true);
        $row['ga4']['property_id'] = $configuration['property_id'];
        $row['gsc']['site'] = $configuration['site'];
        // Relative yesterday is interpreted by GA4 in the property's own timezone.
        $reply = $this->ga4($token, 'yesterday', 'yesterday', $configuration);
        $timezone = $reply['body']['metadata']['timeZone'] ?? null;
        if (! is_string($timezone) || ! in_array($timezone, timezone_identifiers_list(), true)) { $timezone = null; }
        $gaPeriods = $this->periods($timezone ?? 'Asia/Tehran');
        $row['ga4']['periods']['yesterday'] = $this->gaPeriod($reply, $gaPeriods['yesterday'], $timezone, true);
        $month = $timezone !== null ? $this->ga4($token, $gaPeriods['previous_month']['start_date'], $gaPeriods['previous_month']['end_date'], $configuration)
            : ['ok' => false, 'reason' => 'timezone_unverified'];
        $row['ga4']['periods']['previous_month'] = $this->gaPeriod($month, $gaPeriods['previous_month'], $timezone, false);
        $gscPeriods = $this->periods('America/Los_Angeles');
        foreach ($gscPeriods as $name => $dates) {
            // date dimension on the single fresh day exposes firstIncompleteDate;
            // the month has no dimensions, so clicks/impressions are property totals.
            $reply = $this->google->report('gsc', $token, ['startDate' => $dates['start_date'], 'endDate' => $dates['end_date'],
                'type' => 'web', 'aggregationType' => 'byProperty',
                'dataState' => $name === 'yesterday' ? 'all' : 'final', 'rowLimit' => 1,
                'dimensions' => $name === 'yesterday' ? ['date'] : []], $configuration);
            $row['gsc']['periods'][$name] = $this->gscPeriod($reply, $dates, $name === 'yesterday');
        }
        foreach (['ga4', 'gsc'] as $source) {
            $known = count(array_filter($row[$source]['periods'], fn ($p) => $p['status'] === 'known'));
            $row[$source]['status'] = $known === 2 ? 'known' : ($known > 0 ? 'partial' : 'unknown');
        }
        $known = count(array_filter([$row['ga4']['status'], $row['gsc']['status']], fn ($s) => $s === 'known'));
        $row['available'] = $row['ga4']['status'] !== 'unknown' || $row['gsc']['status'] !== 'unknown';
        $row['status'] = $known === 2 ? 'known' : ($row['available'] ? 'partial' : 'unknown');
        $row['reason'] = $row['available'] ? null : 'no_verified_data';

        return $row;
    }

    /** Whole-range totalUsers is distinct users for that period, never summed daily users. */
    private function ga4(string $token, string $from, string $to, array $configuration): array
    {
        return $this->google->report('ga4', $token, ['dateRanges' => [['startDate' => $from, 'endDate' => $to]],
            'metrics' => [['name' => 'totalUsers'], ['name' => 'sessions']], 'dimensions' => [], 'limit' => '1'], $configuration);
    }

    /** @see https://developers.google.com/analytics/devguides/reporting/data/v1/rest/v1beta/properties/runReport */
    private function gaPeriod(array $reply, array $dates, ?string $timezone, bool $preliminary): array
    {
        $row = $dates + ['status' => 'unknown', 'total_users' => null, 'sessions' => null,
            'timezone' => $timezone, 'preliminary' => $preliminary, 'reason' => $reply['reason'] ?? 'no_reported_data'];
        if (! $reply['ok'] || $timezone === null) { return $row; }
        $body = $reply['body'];
        if (($body['metadata']['timeZone'] ?? null) !== $timezone
            || array_column($body['metricHeaders'] ?? [], 'name') !== ['totalUsers', 'sessions']
            || array_column($body['metricHeaders'] ?? [], 'type') !== ['TYPE_INTEGER', 'TYPE_INTEGER']
            || count($body['rows'] ?? []) !== 1 || ! empty($body['dimensionHeaders'])) { return $row; }
        $values = $body['rows'][0]['metricValues'] ?? [];
        $users = $this->integer($values[0]['value'] ?? null);
        $sessions = $this->integer($values[1]['value'] ?? null);
        if (count($values) !== 2 || $users === null || $sessions === null || ! empty($body['metadata']['emptyReason'])) { return $row; }
        $limited = ! empty($body['metadata']['subjectToThresholding']) || ! empty($body['metadata']['dataLossFromOtherRow'])
            || ! empty($body['metadata']['samplingMetadatas']) || ! empty($body['metadata']['dataTruncationReasons']);

        return array_replace($row, ['status' => 'known', 'total_users' => $users, 'sessions' => $sessions,
            'preliminary' => $preliminary || $limited, 'reason' => $limited ? 'google_reporting_limits' : null]);
    }

    /** @see https://developers.google.com/webmaster-tools/v1/searchanalytics/query */
    private function gscPeriod(array $reply, array $dates, bool $preliminary): array
    {
        $freshDay = $preliminary;
        $preliminary = $preliminary || Carbon::parse($dates['end_date'], 'America/Los_Angeles')
            ->gte(now('America/Los_Angeles')->subDays(3)->startOfDay());
        $row = $dates + ['status' => 'unknown', 'clicks' => null, 'impressions' => null,
            'timezone' => 'America/Los_Angeles', 'preliminary' => $preliminary, 'reason' => $reply['reason'] ?? 'google_data_pending'];
        if (! $reply['ok'] || count($reply['body']['rows'] ?? []) !== 1) { return $row; }
        $body = $reply['body'];
        if (($body['responseAggregationType'] ?? null) !== 'byProperty') { return $row; }
        $metrics = $body['rows'][0];
        if ($freshDay && ($metrics['keys'] ?? null) !== [$dates['start_date']]) { return $row; }
        $clicks = $this->integer($metrics['clicks'] ?? null);
        $impressions = $this->integer($metrics['impressions'] ?? null);
        if ($clicks === null || $impressions === null) { return $row; }

        return array_replace($row, ['status' => 'known', 'clicks' => $clicks, 'impressions' => $impressions,
            'reason' => $preliminary ? 'fresh_google_data_may_change' : null]);
    }

    private function periods(string $timezone): array
    {
        $now = now($timezone);
        $yesterday = $now->copy()->subDay()->toDateString();
        // The manager's previous-month label follows the Tehran report calendar;
        // Google interprets these explicit dates in each source's own timezone.
        $month = now('Asia/Tehran')->startOfMonth()->subMonth();

        return ['yesterday' => ['start_date' => $yesterday, 'end_date' => $yesterday],
            'previous_month' => ['start_date' => $month->toDateString(), 'end_date' => $month->copy()->endOfMonth()->toDateString()]];
    }

    private function unknown(string $reason, bool $connected = false): array
    {
        $config = $this->google->configuration();
        $row = ['available' => false, 'status' => 'unknown', 'connected' => $connected,
            'as_of' => null, 'cached' => false, 'reason' => $reason,
            'ga4' => ['property_id' => $config['property_id'], 'status' => 'unknown', 'periods' => []],
            'gsc' => ['site' => $config['site'], 'status' => 'unknown', 'periods' => []]];
        foreach (['ga4' => 'Asia/Tehran', 'gsc' => 'America/Los_Angeles'] as $source => $timezone) {
            foreach ($this->periods($timezone) as $name => $dates) {
                $row[$source]['periods'][$name] = $dates + ['status' => 'unknown', 'timezone' => $source === 'ga4' ? null : $timezone,
                    'preliminary' => $name === 'yesterday', 'reason' => $reason]
                    + ($source === 'ga4' ? ['total_users' => null, 'sessions' => null] : ['clicks' => null, 'impressions' => null]);
            }
        }

        return $row;
    }

    private function integer(mixed $value): ?int
    {
        if (is_float($value) && is_finite($value) && floor($value) === $value && $value <= PHP_INT_MAX) { $value = (int) $value; }
        if (! is_int($value) && ! is_string($value)) { return null; }
        $string = (string) $value;
        if (! preg_match('/\A(?:0|[1-9][0-9]{0,14})\z/D', $string)) { return null; }

        return (int) $string;
    }

    private function key(string $identity): string
    {
        return 'bale:manager:traffic:v1:'.$identity;
    }

    private function fresh(array $row): bool
    {
        if (($row['report_day'] ?? '') !== now('Asia/Tehran')->toDateString() || ! is_string($row['as_of'] ?? null)) { return false; }
        try { $age = now()->timestamp - Carbon::parse($row['as_of'])->timestamp; }
        catch (\Throwable) { return false; }

        return $age >= 0 && $age < self::FRESH_SECONDS;
    }
}
