<?php
declare(strict_types=1);

require __DIR__.'/../../website/app/Services/Cloud/ArvanTrafficObservation.php';
require __DIR__.'/../../website/app/Services/Cloud/PrivatePrometheusTrafficSource.php';

use App\Services\Cloud\ArvanTrafficObservation as Observation;
use App\Services\Cloud\PrivatePrometheusTrafficSource as Source;

$assertions = 0;
function check(bool $condition, string $message): void {
    global $assertions;
    $assertions++;
    if (!$condition) { throw new RuntimeException($message); }
}
function unknown(array $result, string $reason): void {
    check($result['status'] === 'unknown' && $result['reason'] === $reason, $reason);
    check(($result['checkpoint'] ?? null) === null && ($result['sample'] ?? null) === null, 'Unknown must not advance state');
    check(($result['rx_delta_bytes'] ?? null) === null && ($result['tx_delta_bytes'] ?? null) === null, 'Unknown is never zero usage');
}
$identity = ['service_id' => 613, 'instance_id' => 77, 'customer_id' => 42,
    'provider_ref' => 'ir-tbz-sh1:00000000-0000-0000-0000-000000000001',
    'enrollment_id' => str_repeat('a', 32), 'device' => 'ens3'];
$now = 1793638800;
$sample = ['identity' => $identity, 'sample_at' => $now - 5, 'collected_at' => $now,
    'boot_at' => $now - 86400, 'collector_up' => true, 'netdev_up' => true, 'rx_bytes' => 1000, 'tx_bytes' => 2000];
$evaluator = new Observation;
$baseline = $evaluator->evaluate($identity, $sample, null, $now);
check($baseline['status'] === 'baseline' && $baseline['rx_delta_bytes'] === null && $baseline['checkpoint'] === $sample, 'Baseline is explicit, no historical zero');
$next = array_replace($sample, ['sample_at' => $now + 55, 'collected_at' => $now + 60, 'rx_bytes' => 1300, 'tx_bytes' => 2400]);
$delta = $evaluator->evaluate($identity, $next, $sample, $now + 60);
check($delta['status'] === 'observed' && $delta['rx_delta_bytes'] === 300 && $delta['tx_delta_bytes'] === 400 && $delta['interval_seconds'] === 60, 'Fresh exact RX/TX delta');
check($delta['billable_traffic_verified'] === false && $delta['financial_action_performed'] === false && $delta['lifecycle_action_performed'] === false, 'No financial or lifecycle claims');
$zero = $evaluator->evaluate($identity, array_replace($next, ['rx_bytes' => 1000, 'tx_bytes' => 2000]), $sample, $now + 60);
check($zero['status'] === 'observed' && $zero['rx_delta_bytes'] === 0 && $zero['tx_delta_bytes'] === 0, 'Only actual fresh counters establish zero');
unknown($evaluator->evaluate($identity, null, $sample, $now), 'sample_missing');
unknown($evaluator->evaluate($identity, $sample, $sample, $now), 'sample_replayed_or_out_of_order');
unknown($evaluator->evaluate($identity, $sample, null, $now + 151), 'sample_stale');
unknown($evaluator->evaluate($identity, array_replace($next, ['boot_at' => $now]), $sample, $now + 60), 'counter_reset_requires_rebaseline');
unknown($evaluator->evaluate($identity, array_replace($next, ['rx_bytes' => 1]), $sample, $now + 60), 'counter_reset_requires_rebaseline');
unknown($evaluator->evaluate($identity, array_replace($next, ['sample_at' => $now + 195, 'collected_at' => $now + 200]), $sample, $now + 200), 'sample_gap_requires_rebaseline');
unknown($evaluator->evaluate($identity, array_replace($next, ['sample_at' => $now + 61]), $sample, $now + 60), 'sample_timestamp_in_future');
foreach (['customer_id' => 43, 'instance_id' => 78, 'service_id' => 614,
          'provider_ref' => 'ir-thr-ba1:00000000-0000-0000-0000-000000000002', 'enrollment_id' => str_repeat('b', 32), 'device' => 'ens4'] as $key => $value) {
    $other = array_replace($identity, [$key => $value]);
    unknown($evaluator->evaluate($identity, array_replace($next, ['identity' => $other]), $sample, $now + 60), 'sample_identity_mismatch');
    unknown($evaluator->evaluate($other, array_replace($next, ['identity' => $other]), $sample, $now + 60), 'checkpoint_identity_changed_reenrollment_required');
}
foreach (['NaN', '+Inf', '-1', '0.5', '9007199254740992', '9007199254740990.9', '1e999', '1e-999', '001', ''] as $invalid) {
    check(Observation::counter($invalid) === null, 'Invalid or inexact numeric counter rejected');
}
check(Observation::counter('1.2e+06') === 1200000 && Observation::counter('0') === 0
    && Observation::counter('9007199254740991') === Observation::MAX_EXACT_COUNTER
    && Observation::counter('1000.00') === 1000 && Observation::counter('10e-1') === 1, 'Exact integer scientific format supported');
unknown($evaluator->evaluate($identity + ['password' => 'must-not-propagate'], $sample, null, $now), 'expected_identity_invalid');

// A real instant query reports its evaluation timestamp in value[0], even
// when the selected source sample is older. timestamp(selector) exposes the
// original scrape time as value[1] in separately identified evidence rows.
function vector(array $identity, int $scrapedAt, int $evaluatedAt): array {
    $labels = ['job' => 'servernet-arvan'];
    foreach (['service_id', 'instance_id', 'customer_id', 'provider_ref', 'enrollment_id'] as $key) {
        $labels['snet_'.$key] = (string) $identity[$key];
    }
    $rows = [];
    foreach (['up' => '1', 'node_scrape_collector_success' => '1', 'node_network_receive_bytes_total' => '1000',
              'node_network_transmit_bytes_total' => '2000', 'node_boot_time_seconds' => (string) ($scrapedAt - 86400)] as $name => $value) {
        $metric = $labels + ['__name__' => $name];
        if ($name === 'node_scrape_collector_success') { $metric['collector'] = 'netdev'; }
        if (str_starts_with($name, 'node_network_')) { $metric['device'] = $identity['device']; }
        $rows[] = ['metric' => $metric, 'value' => [$evaluatedAt, $value]];
        unset($metric['__name__']);
        $metric['snet_observed_metric'] = $name;
        $metric['snet_observation_kind'] = 'scrape_timestamp';
        $rows[] = ['metric' => $metric, 'value' => [$evaluatedAt, (string) $scrapedAt]];
    }
    return ['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $rows]];
}
$calls = [];
$body = vector($identity, $now - 5, $now);
$transport = function (string $url, array $query) use (&$calls, &$body): array {
    $calls[] = compact('url', 'query');
    return ['status' => 200, 'body' => json_encode($body, JSON_THROW_ON_ERROR)];
};
$source = new Source('http://127.0.0.1:19090', $transport);
$result = $source->read($identity, $now);
check($result['status'] === 'observed' && $result['sample']['rx_bytes'] === 1000, 'Complete source sample parsed');
check($result['sample']['sample_at'] === $now - 5 && $result['sample']['collected_at'] === $now,
    'Original scrape time differs from HTTP query/evaluation time');
check(count($calls) === 1 && $calls[0]['url'] === 'http://127.0.0.1:19090/api/v1/query'
    && str_contains($calls[0]['query']['query'], 'snet_customer_id="42"') && $calls[0]['query']['time'] === (string) $now, 'Only one exact identity-bound query');
foreach (['up', 'node_scrape_collector_success', 'node_network_receive_bytes_total',
          'node_network_transmit_bytes_total', 'node_boot_time_seconds'] as $name) {
    check(str_contains($calls[0]['query']['query'], 'timestamp('.$name.'{job="servernet-arvan",')
        && str_contains($calls[0]['query']['query'], '"snet_observed_metric", "'.$name.'"'),
        'Every required metric has direct-selector timestamp evidence');
}
check(!str_contains($calls[0]['query']['query'], '__name__=~')
    && substr_count($calls[0]['query']['query'], 'collector="netdev"') === 2,
    'Request only netdev health, not every default collector');
check(substr_count($calls[0]['query']['query'], 'device="ens3"') === 4,
    'Both RX/TX values and timestamps select only the trusted NIC');
check(substr_count($calls[0]['query']['query'], ' or ') === 9,
    'One query contains exactly five selected values and five timestamp evidence expressions');
foreach ([null, 'http://prometheus.example:9090', 'https://127.0.0.1:9090', 'http://127.0.0.1:9090/path', 'http://127.0.0.1:9090/?q=secret', 'http://127.0.0.1:99999'] as $endpoint) {
    $before = count($calls);
    $r = (new Source($endpoint, $transport))->read($identity, $now);
    check($r['status'] === 'unknown' && $r['sample'] === null && count($calls) === $before, 'Remote/malformed/disabled endpoints never queried');
}
$body['data']['result'] = [];
unknown($source->read($identity, $now), 'target_or_counter_missing');
$body = vector($identity, $now - 151, $now);
unknown($source->read($identity, $now), 'metric_timestamps_stale_or_incoherent');
$body = vector($identity, $now - 5, $now + 1);
unknown($source->read($identity, $now), 'metric_timestamp_invalid');
$body = vector($identity, $now + 1, $now);
unknown($source->read($identity, $now), 'scrape_timestamp_invalid');
$body = vector($identity, $now - 5, $now);
$body['data']['result'][3]['value'][1] = (string) ($now - 15);
unknown($source->read($identity, $now), 'metric_timestamps_stale_or_incoherent');
$body = vector($identity, $now - 5, $now);
$body['data']['result'][] = $body['data']['result'][4];
unknown($source->read($identity, $now), 'duplicate_target_or_counter');
$body = vector($identity, $now - 5, $now);
$body['data']['result'][6]['metric']['device'] = 'docker0';
unknown($source->read($identity, $now), 'counter_device_mismatch');
$body = vector($identity, $now - 5, $now);
$body['data']['result'][0]['value'][1] = '0';
unknown($source->read($identity, $now), 'target_or_netdev_down');
foreach (['snet_customer_id', 'snet_instance_id', 'snet_provider_ref', 'snet_enrollment_id'] as $label) {
    $body = vector($identity, $now - 5, $now);
    $body['data']['result'][0]['metric'][$label] = 'wrong';
    unknown($source->read($identity, $now), 'target_identity_mismatch');
}
$body = vector($identity, $now - 5, $now);
$body['warnings'] = ['partial response'];
unknown($source->read($identity, $now), 'prometheus_vector_invalid');

// Fresh query timestamps cannot turn the same cached scrape into a new sample.
$body = vector($identity, $now - 5, $now);
$firstRead = $source->read($identity, $now);
$body = vector($identity, $now - 5, $now + 60);
$secondRead = $source->read($identity, $now + 60);
check($secondRead['status'] === 'observed' && $secondRead['sample']['sample_at'] === $firstRead['sample']['sample_at'],
    'Later query retains the same original scrape time');
unknown($evaluator->evaluate($identity, $secondRead['sample'], $firstRead['sample'], $now + 60), 'sample_replayed_or_out_of_order');
$body = vector($identity, $now + 55, $now + 60);
$body['data']['result'][4]['value'][1] = '1300';
$body['data']['result'][6]['value'][1] = '2400';
$body['data']['result'][8]['value'][1] = (string) $firstRead['sample']['boot_at'];
$freshRead = $source->read($identity, $now + 60);
$freshDelta = $evaluator->evaluate($identity, $freshRead['sample'], $firstRead['sample'], $now + 60);
check($freshDelta['status'] === 'observed' && $freshDelta['interval_seconds'] === 60
    && $freshDelta['rx_delta_bytes'] === 300 && $freshDelta['tx_delta_bytes'] === 400,
    'New verified source scrape advances identity-bound counters by the real scrape interval');

$body = vector($identity, $now - 5, $now);
$body['data']['result'] = array_values(array_filter($body['data']['result'], fn ($r) => !isset($r['metric']['snet_observed_metric'])));
unknown($source->read($identity, $now), 'scrape_timestamp_evidence_missing');
$body = vector($identity, $now - 5, $now);
$body['data']['result'][] = $body['data']['result'][5];
unknown($source->read($identity, $now), 'duplicate_target_or_counter');
$body = vector($identity, $now - 5, $now);
$body['data']['result'][5]['metric']['snet_customer_id'] = 'wrong';
unknown($source->read($identity, $now), 'target_identity_mismatch');
$body = vector($identity, $now - 5, $now);
$body['data']['result'][1]['metric']['snet_observation_kind'] = 'query_time';
unknown($source->read($identity, $now), 'timestamp_evidence_invalid');
$body = vector($identity, $now - 5, $now);
$body['data']['result'][1]['value'][1] = 'NaN';
unknown($source->read($identity, $now), 'scrape_timestamp_invalid');
$body = vector($identity, $now - 5, $now);
foreach ($body['data']['result'] as &$row) {
    if (isset($row['metric']['snet_observed_metric'])) { $row['value'][1] = (string) ($now - 5).'.123'; }
}
unset($row);
check($source->read($identity, $now)['sample']['sample_at'] === $now - 5, 'Fractional original scrape seconds supported');
unknown((new Source('http://127.0.0.1:19090', fn () => ['status' => 302, 'body' => 'private']))->read($identity, $now), 'prometheus_response_unavailable');
unknown((new Source('http://127.0.0.1:19090', fn () => throw new RuntimeException('secret token')))->read($identity, $now), 'private_prometheus_read_failed');
unknown((new Source('http://127.0.0.1:19090', fn () => throw new RuntimeException('unix_transport_connect_failed')))->read($identity, $now), 'private_transport_unavailable');
unknown((new Source('http://127.0.0.1:19090', fn () => throw new RuntimeException('unix_transport_header_incomplete')))->read($identity, $now), 'private_transport_unavailable');
unknown((new Source('http://127.0.0.1:19090', fn () => throw new RuntimeException('unix_transport_duplicate_header')))->read($identity, $now), 'private_transport_protocol_invalid');
check(Source::diagnosticReason('secret token') === 'source_unavailable', 'Diagnostics must never persist arbitrary source text');
unknown((new Source('http://127.0.0.1:19090', fn () => ['status' => 200, 'body' => str_repeat('x', 262145)]))->read($identity, $now), 'prometheus_response_unavailable');
echo 'PASS '.$assertions." assertions; no network, billing, state writes or lifecycle calls.\n";
