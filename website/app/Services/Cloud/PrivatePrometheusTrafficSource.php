<?php

declare(strict_types=1);

namespace App\Services\Cloud;

use Closure;
use Illuminate\Support\Facades\Http;
use Throwable;

/** One bounded read over an approved private transport. Never opens a tunnel. */
final class PrivatePrometheusTrafficSource
{
    private const METRICS = ['up', 'node_scrape_collector_success', 'node_network_receive_bytes_total',
        'node_network_transmit_bytes_total', 'node_boot_time_seconds'];
    private const LABELS = ['service_id' => 'snet_service_id', 'instance_id' => 'snet_instance_id',
        'customer_id' => 'snet_customer_id', 'provider_ref' => 'snet_provider_ref', 'enrollment_id' => 'snet_enrollment_id'];
    private const EVIDENCE_METRIC = 'snet_observed_metric';
    private const EVIDENCE_KIND = 'snet_observation_kind';

    /** Fixed reason classes only: never persist/log an upstream body or exception. */
    public static function diagnosticReason(mixed $reason): string
    {
        return match ($reason) {
            'private_transport_unavailable' => 'transport_unavailable',
            'private_transport_protocol_invalid' => 'source_protocol_invalid',
            'target_or_counter_missing', 'target_or_netdev_down', 'scrape_timestamp_evidence_missing' => 'target_metrics_unavailable',
            'metric_timestamps_stale_or_incoherent' => 'target_metrics_stale',
            'target_identity_mismatch', 'counter_device_mismatch' => 'source_identity_mismatch',
            default => 'source_unavailable',
        };
    }

    /**
     * The application runner supplies unixTransport only. The legacy loopback
     * endpoint remains available for isolated compatibility tests and explicit
     * operator callers; null with no Unix transport is disabled.
     * Transport returns ['status'=>int,'body'=>string] and must never log body.
     */
    public function __construct(private ?string $endpoint = null, private ?Closure $transport = null,
        private ?UnixPrometheusTrafficTransport $unixTransport = null) {}

    private function unknown(string $reason): array
    {
        return ['status' => 'unknown', 'reason' => $reason, 'sample' => null,
            'financial_action_performed' => false, 'lifecycle_action_performed' => false];
    }

    /** Prometheus timestamps may contain fractional seconds; counters may not. */
    private function scrapeTimestamp(mixed $value, int $now): ?int
    {
        if (!is_string($value) || strlen($value) > 32
            || preg_match('/\A(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d{1,3})?\z/D', $value) !== 1) {
            return null;
        }
        $seconds = (float) $value;

        return is_finite($seconds) && $seconds >= 946684800 && $seconds <= $now
            ? (int) floor($seconds) : null;
    }

    public function read(array $identity, int $now): array
    {
        if (!ArvanTrafficObservation::validIdentity($identity)) {
            return $this->unknown('expected_identity_invalid');
        }
        if ($this->unixTransport === null && $this->endpoint === null) {
            return $this->unknown('private_transport_not_configured');
        }
        // No redirects, user-info, path, query, hostnames, public IP or remote
        // 9090. A separately managed verified SSH tunnel is a prerequisite.
        if ($this->unixTransport === null && (preg_match('~\Ahttp://127\.0\.0\.1:([1-9]\d{0,4})\z~D', $this->endpoint, $port) !== 1
            || (int) $port[1] > 65535)) {
            return $this->unknown('private_loopback_endpoint_required');
        }
        $selectors = ['job="servernet-arvan"'];
        foreach (self::LABELS as $key => $label) {
            // validIdentity excludes quotes/backslashes; serialize anyway.
            $selectors[] = $label.'='.json_encode((string) $identity[$key], JSON_THROW_ON_ERROR);
        }
        try {
            $labels = implode(',', $selectors);
            $expressions = [];
            foreach (self::METRICS as $metric) {
                $metricLabels = $labels;
                if ($metric === 'node_scrape_collector_success') {
                    $metricLabels .= ',collector="netdev"';
                } elseif (str_starts_with($metric, 'node_network_')) {
                    $metricLabels .= ',device='.json_encode($identity['device'], JSON_THROW_ON_ERROR);
                }
                $selector = $metric.'{'.$metricLabels.'}';
                $expressions[] = $selector;
                // timestamp() must receive the selector directly: wrapping the
                // selector in another function first can replace scrape time
                // with evaluation time. Add identifying labels afterwards.
                $expressions[] = 'label_replace(label_replace(timestamp('.$selector.'), "'
                    .self::EVIDENCE_METRIC.'", "'.$metric.'", "", ""), "'.self::EVIDENCE_KIND
                    .'", "scrape_timestamp", "", "")';
            }
            // Preserve distinct raw metric names and timestamp evidence in set union.
            $query = ['query' => implode(' or on(__name__, snet_observed_metric, snet_observation_kind) ', $expressions), 'time' => (string) $now];
            if ($this->unixTransport !== null) {
                $response = $this->unixTransport->request($query);
            } elseif ($this->transport !== null) {
                $response = ($this->transport)($this->endpoint.'/api/v1/query', $query);
            } else {
                $http = Http::withOptions(['allow_redirects' => false, 'proxy' => '', 'stream' => true, 'read_timeout' => 5])
                    ->connectTimeout(2)->timeout(5)->withHeaders(['Accept' => 'application/json'])
                    ->get($this->endpoint.'/api/v1/query', $query);
                $stream = $http->toPsrResponse()->getBody();
                $content = '';
                $deadline = microtime(true) + 5;
                try {
                    while (!$stream->eof() && strlen($content) <= 262144) {
                        if (microtime(true) > $deadline) { throw new \RuntimeException('read_deadline'); }
                        $chunk = $stream->read(min(8192, 262145 - strlen($content)));
                        if ($chunk === '') { break; }
                        $content .= $chunk;
                    }
                } finally { $stream->close(); }
                $response = ['status' => $http->status(), 'body' => $content];
            }
            if (!is_array($response) || ($response['status'] ?? null) !== 200
                || !is_string($response['body'] ?? null) || strlen($response['body']) > 262144) {
                return $this->unknown('prometheus_response_unavailable');
            }
            $body = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
            if (($body['status'] ?? null) !== 'success' || ($body['data']['resultType'] ?? null) !== 'vector'
                || !is_array($body['data']['result'] ?? null) || !array_is_list($body['data']['result'])
                || count($body['data']['result']) > 20 || !empty($body['warnings']) || !empty($body['infos'])) {
                return $this->unknown('prometheus_vector_invalid');
            }
            $values = []; $scrapeTimes = [];
            foreach ($body['data']['result'] as $row) {
                $metric = $row['metric'] ?? null;
                if (!is_array($metric) || ($metric['job'] ?? null) !== 'servernet-arvan') {
                    return $this->unknown('target_identity_mismatch');
                }
                foreach (self::LABELS as $key => $label) {
                    if (($metric[$label] ?? null) !== (string) $identity[$key]) {
                        return $this->unknown('target_identity_mismatch');
                    }
                }
                $evidence = array_key_exists(self::EVIDENCE_KIND, $metric) || array_key_exists(self::EVIDENCE_METRIC, $metric);
                if ($evidence && ($metric[self::EVIDENCE_KIND] ?? null) !== 'scrape_timestamp') {
                    return $this->unknown('timestamp_evidence_invalid');
                }
                $name = $evidence ? ($metric[self::EVIDENCE_METRIC] ?? null) : ($metric['__name__'] ?? null);
                if (!in_array($name, self::METRICS, true)) {
                    return $this->unknown('unexpected_metric');
                }
                if ($name === 'node_scrape_collector_success' && ($metric['collector'] ?? null) !== 'netdev') {
                    continue; // Other collectors are neither traffic nor target health.
                }
                if (str_starts_with($name, 'node_network_') && ($metric['device'] ?? null) !== $identity['device']) {
                    return $this->unknown('counter_device_mismatch');
                }
                if (($evidence && isset($scrapeTimes[$name])) || (!$evidence && isset($values[$name]))) {
                    return $this->unknown('duplicate_target_or_counter');
                }
                $value = $row['value'] ?? null;
                if (!is_array($value) || !array_is_list($value) || count($value) !== 2
                    || (!is_int($value[0]) && !is_float($value[0])) || !is_finite((float) $value[0])
                    || (float) $value[0] !== (float) $now) {
                    return $this->unknown('metric_timestamp_invalid');
                }
                if ($evidence) {
                    $scrapeAt = $this->scrapeTimestamp($value[1], $now);
                    if ($scrapeAt === null) {
                        return $this->unknown('scrape_timestamp_invalid');
                    }
                    $scrapeTimes[$name] = $scrapeAt;
                } else {
                    $counter = ArvanTrafficObservation::counter($value[1]);
                    if ($counter === null) {
                        return $this->unknown('metric_value_invalid');
                    }
                    $values[$name] = $counter;
                }
            }
            if (array_diff(self::METRICS, array_keys($values)) !== []) {
                return $this->unknown('target_or_counter_missing');
            }
            if (array_diff(self::METRICS, array_keys($scrapeTimes)) !== []) {
                return $this->unknown('scrape_timestamp_evidence_missing');
            }
            if ($values['up'] !== 1 || $values['node_scrape_collector_success'] !== 1) {
                return $this->unknown('target_or_netdev_down');
            }
            if (max($scrapeTimes) - min($scrapeTimes) > 2 || $now - min($scrapeTimes) > ArvanTrafficObservation::MAX_AGE_SECONDS) {
                return $this->unknown('metric_timestamps_stale_or_incoherent');
            }
            $sample = ['identity' => $identity, 'sample_at' => min($scrapeTimes), 'collected_at' => $now,
                'collector_up' => true, 'netdev_up' => true, 'rx_bytes' => $values['node_network_receive_bytes_total'],
                'tx_bytes' => $values['node_network_transmit_bytes_total'], 'boot_at' => $values['node_boot_time_seconds']];
            $evaluated = (new ArvanTrafficObservation)->evaluate($identity, $sample, null, $now);
            if ($evaluated['status'] !== 'baseline') {
                return $this->unknown($evaluated['reason']);
            }

            return ['status' => 'observed', 'reason' => 'fresh_identity_bound_sample', 'sample' => $sample,
                'financial_action_performed' => false, 'lifecycle_action_performed' => false];
        } catch (Throwable $error) {
            // No body, provider reference, token, query or raw exception in output/logs.
            if ($error instanceof \RuntimeException && in_array($error->getMessage(), [
                'unix_transport_connect_failed', 'unix_transport_deadline', 'unix_transport_read_failed',
                'unix_transport_pin_changed', 'unix_transport_metadata_invalid',
                'unix_transport_write_failed', 'unix_transport_header_incomplete',
                'unix_transport_body_incomplete', 'unix_transport_timeout_unavailable',
            ], true)) {
                return $this->unknown('private_transport_unavailable');
            }
            if ($error instanceof \RuntimeException && in_array($error->getMessage(), [
                'unix_transport_header_limit', 'unix_transport_status_invalid', 'unix_transport_header_invalid',
                'unix_transport_duplicate_header', 'unix_transport_response_forbidden',
                'unix_transport_content_type_invalid', 'unix_transport_body_limit',
            ], true)) {
                return $this->unknown('private_transport_protocol_invalid');
            }
            return $this->unknown('private_prometheus_read_failed');
        }
    }
}
