<?php

declare(strict_types=1);

namespace App\Services\Cloud;

/** Pure guest-counter evidence. No persistence, billing, lifecycle or network calls. */
final class ArvanTrafficObservation
{
    public const MAX_AGE_SECONDS = 150;
    public const MAX_EXACT_COUNTER = 9_007_199_254_740_991;
    private const IDENTITY_KEYS = ['service_id', 'instance_id', 'customer_id', 'provider_ref', 'enrollment_id', 'device'];

    public static function validIdentity(array $identity): bool
    {
        if (count($identity) !== count(self::IDENTITY_KEYS)
            || array_diff(self::IDENTITY_KEYS, array_keys($identity)) !== []) {
            return false;
        }
        foreach (['service_id', 'instance_id', 'customer_id'] as $key) {
            if (!is_int($identity[$key]) || $identity[$key] <= 0) {
                return false;
            }
        }

        return is_string($identity['provider_ref'])
            && preg_match('/\Air-[a-z0-9]+-[a-z0-9]+:[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}\z/D', $identity['provider_ref']) === 1
            && is_string($identity['enrollment_id']) && preg_match('/\A[a-f0-9]{32}\z/D', $identity['enrollment_id']) === 1
            && is_string($identity['device']) && preg_match('/\A[A-Za-z0-9_.:-]{1,15}\z/D', $identity['device']) === 1
            && $identity['device'] !== 'lo';
    }

    public static function sameIdentity(array $left, array $right): bool
    {
        if (!self::validIdentity($left) || !self::validIdentity($right)) {
            return false;
        }
        foreach (self::IDENTITY_KEYS as $key) {
            if ($left[$key] !== $right[$key]) {
                return false;
            }
        }

        return true;
    }

    /** Decode Prometheus's numeric string only within its exact-integer range. */
    public static function counter(mixed $value): ?int
    {
        if (!is_string($value) || strlen($value) > 32
            || preg_match('/\A(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d{1,3})?\z/D', $value) !== 1) {
            return null;
        }
        preg_match('/\A(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?\z/', $value, $parts);
        $fraction = $parts[2] ?? '';
        $digits = ltrim($parts[1].$fraction, '0');
        if ($digits === '') { return 0; }
        $shift = (int) ($parts[3] ?? 0) - strlen($fraction);
        if ($shift < 0) {
            $cut = -$shift;
            if ($cut > strlen($digits) || substr($digits, -$cut) !== str_repeat('0', $cut)) {
                return null;
            }
            $digits = substr($digits, 0, -$cut);
        } elseif ($shift > 0) {
            if (strlen($digits) + $shift > 16) { return null; }
            $digits .= str_repeat('0', $shift);
        }
        $limit = (string) self::MAX_EXACT_COUNTER;

        return strlen($digits) < strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) <= 0)
            ? (int) $digits : null;
    }

    private static function validSample(array $sample, array $identity, int $now): ?string
    {
        if (!is_array($sample['identity'] ?? null) || !self::sameIdentity($sample['identity'], $identity)) {
            return 'sample_identity_mismatch';
        }
        if (($sample['collector_up'] ?? null) !== true || ($sample['netdev_up'] ?? null) !== true) {
            return 'collector_or_netdev_not_verified';
        }
        foreach (['sample_at', 'collected_at', 'boot_at', 'rx_bytes', 'tx_bytes'] as $key) {
            if (!is_int($sample[$key] ?? null) || $sample[$key] < 0 || $sample[$key] > self::MAX_EXACT_COUNTER) {
                return 'sample_field_invalid';
            }
        }
        if ($sample['sample_at'] > $now || $sample['collected_at'] > $now
            || $sample['sample_at'] > $sample['collected_at']) {
            return 'sample_timestamp_in_future';
        }
        if ($sample['boot_at'] < 946684800 || $sample['boot_at'] > $sample['sample_at']) {
            return 'boot_timestamp_invalid';
        }
        if ($now - $sample['sample_at'] > self::MAX_AGE_SECONDS
            || $now - $sample['collected_at'] > self::MAX_AGE_SECONDS) {
            return 'sample_stale';
        }

        return null;
    }

    /**
     * A null previous checkpoint is an explicit new baseline, never zero use.
     * Unknown results do not advance a checkpoint. Reset/gap/identity changes
     * require a separately reviewed re-enrollment; never silently bridge them.
     */
    public function evaluate(array $identity, ?array $sample, ?array $previous, int $now): array
    {
        $out = ['status' => 'unknown', 'reason' => 'sample_missing', 'rx_delta_bytes' => null,
            'tx_delta_bytes' => null, 'interval_seconds' => null, 'checkpoint' => null,
            'scope' => 'observed_guest_interface_counters', 'billable_traffic_verified' => false,
            'financial_action_performed' => false, 'lifecycle_action_performed' => false];
        if (!self::validIdentity($identity)) {
            return array_replace($out, ['reason' => 'expected_identity_invalid']);
        }
        if ($sample === null) {
            return $out;
        }
        if (($reason = self::validSample($sample, $identity, $now)) !== null) {
            return array_replace($out, ['reason' => $reason]);
        }
        if ($previous === null) {
            return array_replace($out, ['status' => 'baseline', 'reason' => 'new_baseline_no_historical_usage', 'checkpoint' => $sample]);
        }
        if (!is_array($previous['identity'] ?? null) || !self::sameIdentity($previous['identity'], $identity)) {
            return array_replace($out, ['reason' => 'checkpoint_identity_changed_reenrollment_required']);
        }
        // Validate the old checkpoint at its own collection time. Its age is
        // handled explicitly below; a stale checkpoint must never become zero.
        if (!is_int($previous['collected_at'] ?? null)
            || self::validSample($previous, $identity, $previous['collected_at']) !== null) {
            return array_replace($out, ['reason' => 'checkpoint_invalid']);
        }
        if ($sample['sample_at'] <= $previous['sample_at'] || $sample['collected_at'] < $previous['collected_at']) {
            return array_replace($out, ['reason' => 'sample_replayed_or_out_of_order']);
        }
        if ($sample['sample_at'] - $previous['sample_at'] > self::MAX_AGE_SECONDS) {
            return array_replace($out, ['reason' => 'sample_gap_requires_rebaseline']);
        }
        if ($sample['boot_at'] !== $previous['boot_at'] || $sample['rx_bytes'] < $previous['rx_bytes']
            || $sample['tx_bytes'] < $previous['tx_bytes']) {
            return array_replace($out, ['reason' => 'counter_reset_requires_rebaseline']);
        }

        return array_replace($out, ['status' => 'observed', 'reason' => 'fresh_identity_bound_delta',
            'rx_delta_bytes' => $sample['rx_bytes'] - $previous['rx_bytes'],
            'tx_delta_bytes' => $sample['tx_bytes'] - $previous['tx_bytes'],
            'interval_seconds' => $sample['sample_at'] - $previous['sample_at'], 'checkpoint' => $sample]);
    }
}
