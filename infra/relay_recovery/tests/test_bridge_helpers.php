<?php
declare(strict_types=1);

// Offline helper contract test. No Laravel bootstrap, credentials or provider calls.
// Run using PHP 8.4 with pdo_sqlite. This does not replace DB transaction tests
// against the installed Laravel models and MariaDB before arming promotion.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!extension_loaded('pdo_sqlite') || !function_exists('posix_geteuid')) {
    fwrite(STDOUT, "SKIP: pdo_sqlite/posix required\n"); exit(77);
}
define('SERVERNET_RELAY_BRIDGE_TEST_HELPERS', true);
require dirname(__DIR__).'/servernet_bridge.php';
umask(0077);
$root = sys_get_temp_dir().'/relay-bridge-fixture-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$checks = 0;
function checkBridge(bool $value): void {
    global $checks;
    if (!$value) { throw new RuntimeException('assertion_failed'); }
    $checks++;
}
function rejectsBridge(callable $action): void {
    try { $action(); } catch (Throwable $error) { checkBridge(true); return; }
    throw new RuntimeException('expected_rejection');
}
function jsonBridge(string $path, array $value): void {
    file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR)); chmod($path, 0600);
}
try {
    $now = time();
    $intent = str_repeat('a', 40);
    $old = ['provider' => 'hetzner', 'project_id' => 'project-1', 'provider_id' => '40', 'generation' => 1];
    $new = ['provider' => 'hetzner', 'project_id' => 'project-1', 'provider_id' => '51', 'generation' => 2];
    $config = ['state_dir' => $root, 'policy' => ['enabled' => true, 'project_id' => 'project-1',
        'critical_consumers' => ['proxmox', 'vm109', 'vpn', 'dns', 'storage']], 'exit_upstream_ids' => [7]];
    $db = new PDO('sqlite:'.$root.'/controller.sqlite3', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE actions(id TEXT PRIMARY KEY,kind TEXT,body TEXT); CREATE TABLE state(id INTEGER PRIMARY KEY,body TEXT)');
    chmod($root.'/controller.sqlite3', 0600);
    $action = ['id' => $intent, 'kind' => 'PROMOTE', 'incident_id' => 'incident-1',
        'payload' => ['old_identity' => $old, 'identity' => $new, 'checkpoint' => 'checkpoint-1', 'gate_hash' => str_repeat('b', 64)]];
    $state = ['phase' => 'CUTOVER', 'pending_action' => $intent, 'active' => $old + ['config_hash' => str_repeat('c', 64)],
        'candidate' => $new + ['config_hash' => str_repeat('d', 64)], 'clock_unsafe' => false, 'last_now' => $now];
    $db->prepare('INSERT INTO actions VALUES(?,?,?)')->execute([$intent, 'PROMOTE', json_encode($action, JSON_THROW_ON_ERROR)]);
    $db->prepare('INSERT INTO state VALUES(1,?)')->execute([json_encode($state, JSON_THROW_ON_ERROR)]);
    $plan = bridgePromotionPlan($config, $intent, $now, false);
    checkBridge($plan['new_identity'] === $new && $plan['old_identity'] === $old);
    checkBridge($plan['config_hash'] === str_repeat('d', 64));
    rejectsBridge(static fn () => bridgePromotionPlan($config, str_repeat('f', 40), $now, false));
    $disabled = $config; $disabled['policy']['enabled'] = false;
    rejectsBridge(static fn () => bridgePromotionPlan($disabled, $intent, $now, false));
    checkBridge(bridgePromotionPlan($disabled, $intent, $now, true) === $plan);
    checkBridge(bridgeIdentity($new + ['ignored' => 'value']) === $new);
    $badIdentity = $new; $badIdentity['generation'] = true;
    rejectsBridge(static fn () => bridgeIdentity($badIdentity));
    $badIdentity = $new; $badIdentity['provider'] = 'other';
    rejectsBridge(static fn () => bridgeIdentity($badIdentity));
    $badIdentity = $new; $badIdentity['provider_id'] = '../51';
    rejectsBridge(static fn () => bridgeIdentity($badIdentity));
    $bundle = $plan + ['ipv4' => '95.217.1.2', 'ipv6' => null, 'location_code' => 'fi-helsinki',
        'all_checks_passed' => true, 'verified_at' => $now,
        'checks' => array_fill_keys(['proxmox', 'vm109', 'vpn', 'dns', 'storage',
            'backup_durable', 'restore_verified', 'old_fenced', 'dns_cutover_verified'], true)];
    $bundlePath = $root.'/promotion-'.$intent.'.json';
    jsonBridge($bundlePath, $bundle);
    checkBridge(bridgePromotionBundle($config, $plan, $now) === $bundle);
    $bad = $bundle; $bad['verified_at'] = $now - 31; jsonBridge($bundlePath, $bad);
    rejectsBridge(static fn () => bridgePromotionBundle($config, $plan, $now));
    $bad = $bundle; $bad['checks']['vm109'] = false; jsonBridge($bundlePath, $bad);
    rejectsBridge(static fn () => bridgePromotionBundle($config, $plan, $now));
    $bad = $bundle; unset($bad['checks']['old_fenced']); jsonBridge($bundlePath, $bad);
    rejectsBridge(static fn () => bridgePromotionBundle($config, $plan, $now));
    $bad = $bundle; $bad['new_identity']['provider_id'] = '52'; jsonBridge($bundlePath, $bad);
    rejectsBridge(static fn () => bridgePromotionBundle($config, $plan, $now));
    $bad = $bundle; $bad['ipv4'] = '127.0.0.1'; jsonBridge($bundlePath, $bad);
    rejectsBridge(static fn () => bridgePromotionBundle($config, $plan, $now));
    jsonBridge($bundlePath, $bundle); chmod($bundlePath, 0644);
    rejectsBridge(static fn () => bridgePromotionBundle($config, $plan, $now));
    chmod($bundlePath, 0600);
    $link = 'vless://fake-user@91.107.179.25:443?note=91.107.179.25#keep';
    checkBridge(bridgeLinkHost($link, 'vless', '91.107.179.25', '95.217.1.2')
        === 'vless://fake-user@95.217.1.2:443?note=91.107.179.25#keep');
    $stable = 'vless://fake-user@relay.example.invalid:443?note=keep';
    checkBridge(bridgeLinkHost($stable, 'vless', '91.107.179.25', '95.217.1.2') === $stable);
    rejectsBridge(static fn () => bridgeLinkHost('trojan://fake@91.107.179.25:443', 'vless', '91.107.179.25', '95.217.1.2'));
    rejectsBridge(static fn () => bridgeLinkHost('vless://fake@91.107.179.26:443', 'vless', '91.107.179.25', '95.217.1.2'));
    $state['phase'] = 'HEALTHY'; $state['active'] = $state['candidate']; $state['candidate'] = null; $state['pending_action'] = null;
    $db->prepare('UPDATE state SET body=? WHERE id=1')->execute([json_encode($state, JSON_THROW_ON_ERROR)]);
    rejectsBridge(static fn () => bridgePromotionPlan($config, $intent, $now, false));
    checkBridge(bridgePromotionPlan($config, $intent, $now, true) === $plan);
    fwrite(STDOUT, json_encode(['ok' => true, 'helper_assertions' => $checks], JSON_THROW_ON_ERROR)."\n");
} finally {
    unset($db);
    foreach (glob($root.'/*') ?: [] as $path) { unlink($path); }
    rmdir($root);
}
