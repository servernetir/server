<?php

// Pure receipt/scheduling contracts: no bootstrap, HTTP, database or production state.
require dirname(__DIR__).'/infra/hermes/feed-resilience-20261102/HermesKnowledge.php';

use App\Services\Hermes\HermesKnowledge;

$checks = 0;
$check = static function (bool $ok) use (&$checks): void {
    if (! $ok) { throw new RuntimeException('Feed resilience contract failed at check '.($checks + 1)); }
    $checks++;
};
$hour = 3600; $at = 100 * $hour + 648;
$state = ['sources' => ['cpanel' => ['status' => 'ok', 'checked_at' => $at - 6 * $hour,
    'last_success_at' => $at - 6 * $hour]], 'items' => [
    'retained' => ['source' => 'cpanel', 'title' => 'Retained', 'published_at' => $at - 1000],
]];
$check(HermesKnowledge::due($state, 'cpanel', $at - 15));
$check(! HermesKnowledge::due(['sources' => ['cpanel' => ['status' => 'ok', 'checked_at' => $at]]], 'cpanel', $at - 1));
foreach ([1, 2, 4, 6, 6] as $index => $hours) {
    $checked = $at + $index * $hour;
    HermesKnowledge::record($state, 'cpanel', null, $checked);
    $retry = (intdiv($checked, $hour) + $hours) * $hour;
    $check($state['sources']['cpanel']['next_attempt_at'] === $retry);
    $check(! HermesKnowledge::due($state, 'cpanel', $retry - 1));
    $check(HermesKnowledge::due($state, 'cpanel', $retry));
    $check($state['sources']['cpanel']['last_success_at'] === $at - 6 * $hour);
    $check(isset($state['items']['retained']));
}
$check($state['sources']['cpanel']['consecutive_failures'] === 4);
$success = $at + 5 * $hour;
HermesKnowledge::record($state, 'cpanel', [], $success);
$check($state['sources']['cpanel']['consecutive_failures'] === 0);
$check($state['sources']['cpanel']['last_success_at'] === $success);
$check(! HermesKnowledge::due($state, 'cpanel', $success + 5 * $hour));
$check(HermesKnowledge::due($state, 'cpanel', (intdiv($success, $hour) + 6) * $hour));
$legacy = ['sources' => ['cpanel' => ['status' => 'unavailable', 'checked_at' => $at]]];
$check(HermesKnowledge::due($legacy, 'cpanel', $at + $hour - 15));
$malformed = $legacy;
$malformed['sources']['cpanel']['next_attempt_at'] = PHP_INT_MAX;
$check(HermesKnowledge::due($malformed, 'cpanel', (intdiv($at, $hour) + 6) * $hour));
$all = [];
foreach (array_keys(HermesKnowledge::SOURCES) as $key) { HermesKnowledge::record($all, $key, [], $at); }
$check(HermesKnowledge::availability($all, $at)['status'] === 'complete');
HermesKnowledge::record($all, 'cpanel', null, $at + $hour);
$health = HermesKnowledge::availability($all, $at + $hour);
$check($health['status'] === 'degraded');
$check($health['degraded_sources'] === ['cpanel']);
$check(count($health['usable_verified_sources']) === 6);
$check(HermesKnowledge::availability($all, $at + 13 * $hour)['status'] === 'unavailable');
$check(HermesKnowledge::availability([], $at)['status'] === 'unavailable');
$future = ['sources' => ['cpanel' => ['status' => 'ok', 'last_success_at' => $at + 1]]];
$check(HermesKnowledge::availability($future, $at)['usable_verified_sources'] === []);
try { HermesKnowledge::due([], 'unknown', $at); throw new RuntimeException('Unknown source accepted'); }
catch (InvalidArgumentException) { $checks++; }
echo json_encode(['status' => 'passed', 'checks' => $checks], JSON_THROW_ON_ERROR).PHP_EOL;
