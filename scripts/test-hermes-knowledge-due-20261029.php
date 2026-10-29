<?php

// Pure source checks: no Laravel bootstrap, vendor, network, database or state.
require dirname(__DIR__).'/infra/hermes/knowledge-recovery-20261029/HermesKnowledge.php';

use App\Services\Hermes\HermesKnowledge;

$checks = 0;
$check = static function (bool $actual, bool $expected) use (&$checks): void {
    if ($actual !== $expected) { throw new RuntimeException('Knowledge scheduling contract failed'); }
    $checks++;
};
$state = static fn (string $status, int $checked): array => ['sources' => [
    'cpanel' => ['status' => $status, 'checked_at' => $checked],
]];
$hour = 3600;
$check(HermesKnowledge::due([], 'cpanel', 20 * $hour + 633), true);
$check(HermesKnowledge::due($state('unavailable', 19 * $hour + 648), 'cpanel', 20 * $hour + 633), true);
$check(HermesKnowledge::due($state('unavailable', 20 * $hour + 633), 'cpanel', 20 * $hour + 999), false);
$check(HermesKnowledge::due($state('ok', 14 * $hour + 648), 'cpanel', 20 * $hour + 633), true);
$check(HermesKnowledge::due($state('ok', 15 * $hour + 648), 'cpanel', 20 * $hour + 633), false);
$check(HermesKnowledge::due($state('ok', 20 * $hour + 648), 'cpanel', 20 * $hour + 633), false);
$check(HermesKnowledge::due($state('unavailable', 0), 'cpanel', 20 * $hour), true);
try { HermesKnowledge::due([], 'unknown', 20 * $hour); throw new RuntimeException('Unknown source accepted'); }
catch (InvalidArgumentException) { $checks++; }
echo json_encode(['status' => 'passed', 'checks' => $checks], JSON_THROW_ON_ERROR).PHP_EOL;
