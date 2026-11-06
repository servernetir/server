<?php
declare(strict_types=1);
use App\Services\Hermes\HermesRelayMonitor as Monitor;
require dirname(__DIR__, 3).'/website/app/Services/Hermes/HermesRelayMonitor.php';
$checks = 0;
$check = static function (bool $ok) use (&$checks): void { $checks++; if (! $ok) { throw new RuntimeException('Relay projection failed'); } };
$file = tempnam(sys_get_temp_dir(), 'hermes-relay-'); chmod($file, 0600); $now = 1793613600;
try {
    $row = ['schema' => Monitor::SCHEMA, 'at' => $now, 'state' => 'healthy', 'observation_at' => $now - 5, 'outage_open' => false];
    file_put_contents($file, json_encode($row));
    $check(Monitor::snapshot($now, $file)['state'] === 'healthy');
    $check(Monitor::snapshot($now + 121, $file)['state'] === 'unknown');
    $check(Monitor::snapshot($now - 1, $file)['state'] === 'unknown');
    $row['observation_at'] = $now - 121; file_put_contents($file, json_encode($row));
    $check(Monitor::snapshot($now, $file)['state'] === 'unknown');
    $row['state'] = 'unknown'; file_put_contents($file, json_encode($row));
    $check(Monitor::snapshot($now, $file)['state'] === 'unknown');
    chmod($file, 0644); clearstatcache(); $check(Monitor::snapshot($now, $file)['state'] === 'unknown');
    foreach (Monitor::EVENTS as $type) {
        $event = ['schema' => Monitor::SCHEMA, 'type' => $type, 'event_id' => str_repeat('a', 64), 'at' => $now];
        $text = Monitor::text($event, $now); $check(str_contains($text, 'هرمس') && mb_strlen($text) < 1000);
        $rejected = false; try { Monitor::text($event, $now + 181); } catch (InvalidArgumentException) { $rejected = true; } $check($rejected);
    }
    $check(! str_contains(Monitor::text(['schema' => Monitor::SCHEMA, 'type' => 'relay_monitor_unavailable', 'event_id' => str_repeat('a', 64), 'at' => $now], $now), 'قطعی رله تأیید شد'));
    echo json_encode(['ok' => true, 'checks' => $checks, 'network_calls' => 0]).PHP_EOL;
} finally { unlink($file); }
