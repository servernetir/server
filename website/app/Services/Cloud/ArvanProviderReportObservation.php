<?php

declare(strict_types=1);

namespace App\Services\Cloud;

/** Provider chart diagnostics only. The published schema does not specify units or sample time. */
final class ArvanProviderReportObservation
{
    public const MAX_BODY_BYTES = 262144;
    private const IDENTITY_KEYS = ['service_id', 'instance_id', 'customer_id', 'provider', 'provider_ref'];

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

        return $identity['provider'] === 'arvan' && is_string($identity['provider_ref'])
            && preg_match('/\A[a-z][a-z0-9]{1,11}(?:-[a-z0-9]{1,12}){1,4}:[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}\z/D', $identity['provider_ref']) === 1;
    }

    public static function sameIdentity(array $expected, mixed $current): bool
    {
        if (!self::validIdentity($expected) || !is_array($current) || !self::validIdentity($current)) {
            return false;
        }
        foreach (self::IDENTITY_KEYS as $key) {
            if ($expected[$key] !== $current[$key]) {
                return false;
            }
        }

        return true;
    }

    public static function unknown(string $reason): array
    {
        return ['status' => 'unknown', 'reason' => $reason, 'observation' => null,
            'source_freshness' => 'unknown', 'sample_at' => null, 'unit' => null,
            'traffic_used_gb' => null, 'financial_action_performed' => false,
            'lifecycle_action_performed' => false];
    }

    private static function label(mixed $label, int $maximum): bool
    {
        return is_string($label) && $label !== '' && strlen($label) <= $maximum
            && preg_match('//u', $label) === 1 && preg_match('/[\x00-\x1f\x7f]/', $label) === 0;
    }

    /**
     * Keep chart categories as opaque labels. Neither title text nor the local
     * fetch time establishes a timestamp, byte unit, direction or coverage.
     * Repeated reads can never make an old chart claim fresh data here.
     */
    public function parse(string $body, array $identity, int $collectedAt): array
    {
        if (!self::validIdentity($identity) || $collectedAt < 946684800) {
            return self::unknown('expected_identity_or_collection_time_invalid');
        }
        if ($body === '' || strlen($body) > self::MAX_BODY_BYTES) {
            return self::unknown('provider_report_size_invalid');
        }
        try {
            $root = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return self::unknown('provider_report_json_invalid');
        }
        if (!is_object($root)) {
            return self::unknown('provider_report_shape_unknown');
        }
        // Existing Arvan responses may be data-wrapped; ambiguous alternatives
        // are rejected rather than choosing one network chart silently.
        if (property_exists($root, 'data')) {
            if (!is_object($root->data) || property_exists($root, 'charts')) {
                return self::unknown('provider_report_shape_unknown');
            }
            $root = $root->data;
        }
        $chart = is_object($root->charts ?? null) ? ($root->charts->network ?? null) : null;
        if (!is_object($chart) || !self::label($chart->title ?? null, 256)
            || !is_array($chart->categories ?? null) || !array_is_list($chart->categories)
            || count($chart->categories) < 1 || count($chart->categories) > 2048
            || !is_array($chart->series ?? null) || !array_is_list($chart->series)
            || count($chart->series) < 1 || count($chart->series) > 8) {
            return self::unknown('provider_network_chart_invalid');
        }
        foreach ($chart->categories as $category) {
            if (!self::label($category, 128)) {
                return self::unknown('provider_network_categories_invalid');
            }
        }
        $series = []; $names = [];
        foreach ($chart->series as $dataset) {
            if (!is_object($dataset) || !self::label($dataset->name ?? null, 128)
                || in_array($dataset->name, $names, true)
                || !is_array($dataset->data ?? null) || !array_is_list($dataset->data)
                || count($dataset->data) !== count($chart->categories)) {
                return self::unknown('provider_network_series_invalid');
            }
            foreach ($dataset->data as $value) {
                // Never coerce null/missing values, strings, booleans or gaps to zero.
                if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                    return self::unknown('provider_network_value_invalid');
                }
            }
            $names[] = $dataset->name;
            $series[] = ['name' => $dataset->name, 'data' => $dataset->data];
        }

        return ['status' => 'diagnostic_only', 'reason' => 'provider_chart_semantics_unverified',
            'source_freshness' => 'unknown', 'sample_at' => null, 'unit' => null,
            'traffic_used_gb' => null, 'financial_action_performed' => false,
            'lifecycle_action_performed' => false,
            'observation' => ['identity' => $identity, 'identity_scope' => 'requested_reference',
                'local_binding_verified_before_after' => false,
                'provider_identity_echo_verified' => false, 'collected_at' => $collectedAt,
                'collected_at_is_sample_time' => false, 'period_requested' => '1d',
                'collection_origin' => 'unverified', 'direction_semantics' => 'unverified',
                'aggregation_semantics' => 'unverified',
                'chart_sha256' => hash('sha256', json_encode([$chart->title, $chart->categories, $series], JSON_THROW_ON_ERROR)),
                'network' => ['title' => $chart->title, 'categories' => $chart->categories, 'series' => $series]]];
    }
}
