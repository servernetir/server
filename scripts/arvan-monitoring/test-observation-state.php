<?php

declare(strict_types=1);

// Pure classes only. No Laravel, DB, UDS, exporter, SSH, provider or financial calls.
require dirname(__DIR__, 2).'/website/app/Services/Cloud/ArvanTrafficObservation.php';
require dirname(__DIR__, 2).'/website/app/Services/Cloud/ArvanObservationState.php';

use App\Services\Cloud\ArvanObservationState as State;

$checks = 0;
function check(bool $value, string $label): void
{
    global $checks;
    $checks++;
    if (!$value) { throw new RuntimeException('Assertion failed: '.$label); }
}
function refuses(Closure $operation, string $label): void
{
    try { $operation(); }
    catch (Throwable) { check(true, $label); return; }
    check(false, $label);
}

$now = 1793700000;
$identity = ['service_id' => 613, 'instance_id' => 407, 'customer_id' => 545,
    'provider_ref' => 'ir-thr-ba1:00000000-0000-0000-0000-000000000001', 'enrollment_id' => str_repeat('a', 32), 'device' => 'ens3'];
$sample = ['identity' => $identity, 'sample_at' => $now - 60, 'collected_at' => $now - 60,
    'collector_up' => true, 'netdev_up' => true, 'rx_bytes' => 1000, 'tx_bytes' => 2000, 'boot_at' => $now - 10000];
$enrollment = ['version' => 1, 'provider' => 'arvan', 'enabled' => true, 'identity' => $identity,
    'operator_acceptance_sha256' => str_repeat('b', 64), 'checkpoint_sha256' => null];
check(State::enrollment($enrollment, 613), 'approved full identity');
check(!State::enrollment($enrollment, 614), 'service binding strict');
check(State::previous($enrollment, null) === null, 'never-started history may baseline');

$first = State::advance($identity, null, $sample, $now);
check($first['result']['status'] === 'baseline', 'first scrape prospective baseline');
check($first['result']['rx_delta_bytes'] === null, 'no historical traffic invented');
check($first['checkpoint']['sequence'] === 1 && $first['checkpoint']['fresh_samples'] === 1, 'first checkpoint generation');
check($first['result']['two_fresh_samples_verified'] === false, 'single scrape not accepted as two');
$firstRaw = State::encode($first['checkpoint']);
$enrollment['checkpoint_sha256'] = hash('sha256', $firstRaw);
check(State::previous($enrollment, $firstRaw) === $first['checkpoint'], 'exact persisted history accepted');
refuses(fn () => State::previous($enrollment, null), 'missing started history not rebaseline');
refuses(fn () => State::previous($enrollment, $firstRaw.' '), 'changed history bytes refused');
refuses(fn () => State::previous(array_replace($enrollment, ['checkpoint_sha256' => null]), $firstRaw), 'orphan history not silently reset');

$nextSample = array_replace($sample, ['sample_at' => $now, 'collected_at' => $now, 'rx_bytes' => 1500, 'tx_bytes' => 2600]);
$second = State::advance($identity, $first['checkpoint'], $nextSample, $now);
check($second['result']['status'] === 'observed' && $second['result']['two_fresh_samples_verified'], 'two increasing scrapes accepted');
check($second['result']['rx_delta_bytes'] === 500 && $second['result']['tx_delta_bytes'] === 600, 'only prospective integer delta');
check($second['checkpoint']['sequence'] === 2 && $second['checkpoint']['fresh_samples'] === 2, 'bounded fresh evidence counter');
check($second['checkpoint']['previous_sha256'] === hash('sha256', $firstRaw), 'chain points to exact prior checkpoint');

foreach (['replay' => $sample, 'counter reset' => array_replace($nextSample, ['rx_bytes' => 1]),
    'boot reset' => array_replace($nextSample, ['boot_at' => $now - 10]),
    'wrong owner' => array_replace($nextSample, ['identity' => array_replace($identity, ['customer_id' => 574])]),
    'stale' => array_replace($sample, ['sample_at' => $now - 151]),
    'missing' => null] as $label => $bad) {
    $result = State::advance($identity, $first['checkpoint'], $bad, $now);
    check($result['checkpoint'] === null, $label.' cannot advance history');
    check($result['result']['status'] === 'unknown' && !$result['result']['two_fresh_samples_verified'], $label.' cannot remain green');
    check(!$result['result']['financial_action_performed'] && !$result['result']['lifecycle_action_performed'], $label.' no external action');
}
$oldSample = array_replace($sample, ['sample_at' => $now - 200, 'collected_at' => $now - 200]);
$old = State::advance($identity, null, $oldSample, $now - 200)['checkpoint'];
check(State::advance($identity, $old, $nextSample, $now)['result']['reason'] === 'sample_gap_requires_rebaseline', 'gap not bridged by a new baseline');
$foreignEnrollment = array_replace($enrollment, ['identity' => array_replace($identity, ['enrollment_id' => str_repeat('c', 32)])]);
refuses(fn () => State::previous($foreignEnrollment, $firstRaw), 'checkpoint belongs to exact enrollment generation');
$overflow = array_replace($first['checkpoint'], ['sequence' => PHP_INT_MAX - 1]);
refuses(fn () => State::advance($identity, $overflow, $nextSample, $now), 'sequence overflow stops checkpoint advance');
refuses(fn () => State::decode(str_repeat('x', State::MAX_RECORD_BYTES + 1)), 'persisted record cap');
refuses(fn () => State::encode(['oversized' => str_repeat('x', State::MAX_RECORD_BYTES)]), 'outgoing record cap');
refuses(fn () => State::decode('[]'), 'record list invalid');
check(State::decode(null) === null, 'absent record explicit');
$unknown = State::unknown('trusted_observation_unavailable', $now);
check($unknown['status'] === 'unknown' && !$unknown['two_fresh_samples_verified'] && $unknown['checked_at'] === $now, 'central failure bounded unknown');
check(!$unknown['financial_action_performed'] && !$unknown['lifecycle_action_performed'], 'unknown no financial or power side effect');
echo json_encode(['status' => 'passed', 'assertions' => $checks, 'application_booted' => false, 'external_calls' => 0], JSON_THROW_ON_ERROR)."\n";
