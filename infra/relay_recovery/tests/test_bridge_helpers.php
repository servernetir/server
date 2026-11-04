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
class BridgeUpstreamQueryFixture {
    private int $id;
    public function whereKey(int $id): self { $this->id = $id; return $this; }
    public function lockForUpdate(): self { return $this; }
    public function first(): ?object { return BridgeUpstreamFixture::$rows[$this->id] ?? null; }
}
class BridgeUpstreamFixture {
    public static array $rows = [];
    public int $id = 7;
    public string $role = 'relay';
    public string $type = 'vless';
    public string $host = '8.8.4.4';
    public int $port = 443;
    public bool $enabled = true;
    public string $secret = 'vless://fake-user@8.8.4.4:443?note=unchanged';
    public static function query(): BridgeUpstreamQueryFixture { return new BridgeUpstreamQueryFixture(); }
    public function secretPlain(): ?string { return $this->secret; }
}
class_alias(BridgeUpstreamFixture::class, 'App\\Models\\ExitUpstream');
// Exact query fixtures exercise the actual readonly DNS reader, not a second
// implementation. No write/cache/credential methods exist on these fixtures.
class BridgeDnsCollectionFixture {
    public function __construct(private array $rows) {}
    public function map(callable $fn): self { return new self(array_map($fn, $this->rows)); }
    public function all(): array { return $this->rows; }
}
class BridgeDnsQueryFixture {
    private ?int $key = null;
    public function __construct(private string $kind) {}
    public function whereKey(int $key): self { $this->key = $key; return $this; }
    public function with(string $value): self { checkBridge($value === 'customer:id,code'); return $this; }
    public function first(array $columns): ?object {
        if ($this->kind === 'instance') {
            checkBridge($columns === ['id', 'service_id', 'provider', 'provider_ref', 'ipv4'] && $this->key === 5);
            return BridgeDnsInstanceFixture::$row;
        }
        checkBridge($columns === ['id', 'customer_id', 'provision_meta'] && $this->key === 7001);
        return BridgeDnsServiceFixture::$row;
    }
    public function whereIn(string $key, array $ids): self { checkBridge($key === 'id' && $ids === [7]); return $this; }
    public function where(string $key, mixed $value): self {
        checkBridge(($this->kind === 'zones' && $key === 'customer_id' && $value === 1)
            || ($this->kind === 'settings' && $key === 'key' && $value === 'dns_catalog_zone'));
        return $this;
    }
    public function orderBy(string $key): self { checkBridge($key === 'id'); return $this; }
    public function toBase(): self { return $this; }
    public function get(array $columns): BridgeDnsCollectionFixture {
        checkBridge($columns === ['id', 'customer_id', 'domain', 'mode', 'status', 'publish_state']);
        return new BridgeDnsCollectionFixture(array_map(static fn ($row) => (object)$row, BridgeDnsZoneFixture::$rows));
    }
    public function limit(int $value): self { checkBridge($value === 2); return $this; }
    public function pluck(string $key): BridgeDnsCollectionFixture {
        checkBridge($key === 'value'); return new BridgeDnsCollectionFixture(BridgeDnsDatabaseFixture::$catalog);
    }
}
class BridgeDnsInstanceFixture {
    public static ?object $row = null;
    public static function query(): BridgeDnsQueryFixture { return new BridgeDnsQueryFixture('instance'); }
}
class BridgeDnsServiceFixture {
    public static ?object $row = null;
    public bool $protected = true;
    public int $id = 7001;
    public int $customer_id = 1;
    public object $customer;
    public function __construct() { $this->customer = (object)['id' => 1, 'code' => 'owner-fixture']; }
    public static function query(): BridgeDnsQueryFixture { return new BridgeDnsQueryFixture('service'); }
    public function isInternalInfrastructure(): bool { return $this->protected; }
}
class BridgeDnsZoneFixture {
    public static array $rows = [];
    public static function query(): BridgeDnsQueryFixture { return new BridgeDnsQueryFixture('zones'); }
}
class BridgeDnsDatabaseFixture {
    public static array $catalog = [];
    public static function transaction(callable $fn): array { return $fn(); }
    public static function table(string $name): BridgeDnsQueryFixture {
        checkBridge($name === 'settings'); return new BridgeDnsQueryFixture('settings');
    }
}
class BridgeDnsExemptFixture {
    public bool $exempt = true;
    public function customerExempt(object $customer): bool { return $this->exempt; }
}
function app(string $name): BridgeDnsExemptFixture {
    global $dnsExempt;
    checkBridge($name === 'App\\Services\\Billing\\ComplimentaryServices'); return $dnsExempt;
}
class_alias(BridgeDnsInstanceFixture::class, 'App\\Models\\CloudInstance');
class_alias(BridgeDnsServiceFixture::class, 'App\\Models\\Service');
class_alias(BridgeDnsZoneFixture::class, 'App\\Models\\CdnZone');
class_alias(BridgeDnsDatabaseFixture::class, 'Illuminate\\Support\\Facades\\DB');
try {
    // Golden digest generated by Python core.canonical: unicode is escaped,
    // slashes are not, and object keys are sorted recursively.
    checkBridge(hash('sha256', bridgeCanonical(['z' => 'https://example.invalid/مسیر', 'a' => ['b' => null, 'a' => 3]]))
        === '98e16d92d1026c68f6ce807c258e0f194bc84b2bf8c5464337862590e6896aa8');
    rejectsBridge(static fn () => bridgeCanonical(['unsafe_float' => 1.1]));
    $now = time();
    $intent = str_repeat('a', 40);
    $old = ['provider' => 'hetzner', 'project_id' => 'project-1', 'provider_id' => '40', 'generation' => 1];
    $new = ['provider' => 'hetzner', 'project_id' => 'project-1', 'provider_id' => '51', 'generation' => 2];
    $config = ['state_dir' => $root, 'binding' => ['instance_id' => 5, 'owner_id' => 1, 'service_id' => 7001],
        'policy' => ['enabled' => true, 'owner_id' => '1', 'service_id' => '7001', 'project_id' => 'project-1',
        'critical_consumers' => ['proxmox', 'vm109', 'vpn', 'dns', 'storage']], 'exit_upstream_ids' => [7]];
    $db = new PDO('sqlite:'.$root.'/controller.sqlite3', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE actions(id TEXT PRIMARY KEY,kind TEXT,body TEXT); CREATE TABLE state(id INTEGER PRIMARY KEY,body TEXT)');
    chmod($root.'/controller.sqlite3', 0600);
    $action = ['id' => $intent, 'kind' => 'PROMOTE', 'incident_id' => 'incident-1',
        'created_at' => $now, 'expires_at' => $now + 30,
        'payload' => ['old_identity' => $old, 'identity' => $new, 'checkpoint' => 'checkpoint-1', 'gate_hash' => str_repeat('b', 64)]];
    $state = ['phase' => 'CUTOVER', 'pending_action' => $intent, 'incident_id' => 'incident-1',
        'active' => $old + ['config_hash' => str_repeat('c', 64)],
        'candidate' => $new + ['config_hash' => str_repeat('d', 64)], 'clock_unsafe' => false, 'last_now' => $now];
    $db->prepare('INSERT INTO actions VALUES(?,?,?)')->execute([$intent, 'PROMOTE', json_encode($action, JSON_THROW_ON_ERROR)]);
    $db->prepare('INSERT INTO state VALUES(1,?)')->execute([json_encode($state, JSON_THROW_ON_ERROR)]);
    $plan = bridgePromotionPlan($config, $intent, $now, true);
    rejectsBridge(static fn () => bridgePromotionPlan($config, $intent, $now, false));
    checkBridge($plan['new_identity'] === $new && $plan['old_identity'] === $old);
    checkBridge($plan['config_hash'] === str_repeat('d', 64));
    rejectsBridge(static fn () => bridgePromotionPlan($config, $intent, $now + 30, false));
    checkBridge(bridgePromotionPlan($config, $intent, $now + 30, true) === $plan);
    foreach (['created_at' => $now + 1, 'expires_at' => $now, 'incident_id' => ''] as $key => $value) {
        $badAction = $action; $badAction[$key] = $value;
        $db->prepare('UPDATE actions SET body=? WHERE id=?')->execute([json_encode($badAction, JSON_THROW_ON_ERROR), $intent]);
        rejectsBridge(static fn () => bridgePromotionPlan($config, $intent, $now, false));
    }
    $db->prepare('UPDATE actions SET body=? WHERE id=?')->execute([json_encode($action, JSON_THROW_ON_ERROR), $intent]);
    rejectsBridge(static fn () => bridgePromotionPlan($config, str_repeat('f', 40), $now, false));
    $disabled = $config; $disabled['policy']['enabled'] = false;
    rejectsBridge(static fn () => bridgePromotionPlan($disabled, $intent, $now, false));
    checkBridge(bridgePromotionPlan($disabled, $intent, $now, true) === $plan);
    // CRM continuation uses the driver's exact durable row, never a caller's
    // success flag, a different namespace, or a longer-lived parent claim.
    $registryPath = $root.'/dependencies.json';
    $panel = ['id' => 'panel', 'kind' => 'panel_binding', 'instance_id' => 5, 'exit_upstream_ids' => [7]];
    $catalog = ['schema' => 'servernet.relay.dependency.registry.v1',
        'owner_id' => '1', 'service_id' => '7001', 'project_id' => 'project-1', 'revision' => 1,
        'consumers' => ['proxmox', 'vm109', 'vpn', 'dns', 'storage'], 'resources' => [$panel]];
    jsonBridge($registryPath, $catalog);
    $config['production_driver'] = ['enabled' => true,
        'registry' => ['path' => $registryPath, 'sha256' => hash_file('sha256', $registryPath)]];
    $context = ['schema' => 'servernet.relay.production-driver.v1', 'owner_id' => '1', 'service_id' => '7001',
        'project_id' => 'project-1', 'source_identity' => $old, 'identity' => $new,
        'config_hash' => $plan['config_hash'], 'registry_hash' => $config['production_driver']['registry']['sha256'],
        'parent_intent_id' => $intent, 'incident_id' => 'incident-1', 'restore_epoch' => null, 'restore_hash' => null,
        'gate_hash' => $plan['gate_hash'], 'checkpoint' => $plan['checkpoint']];
    $permit = ['schema' => 'servernet.relay.driver.permit.v1',
        'intent_id' => '123e4567-e89b-42d3-a456-426614174000', 'parent_intent_id' => $intent, 'kind' => 'CRM_PROMOTION',
        'context_hash' => hash('sha256', bridgeCanonical($context)), 'created_at' => $now, 'expires_at' => $now + 30];
    $driver = new PDO('sqlite:'.$root.'/production-driver.sqlite3', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $driver->exec('CREATE TABLE steps(id TEXT PRIMARY KEY,parent TEXT NOT NULL,kind TEXT NOT NULL,body TEXT NOT NULL,phase TEXT NOT NULL,result TEXT)');
    chmod($root.'/production-driver.sqlite3', 0600);
    $saveClaim = static function (array $claimPermit, array $claimContext, string $phase = 'DISPATCHED',
        string $kind = 'CRM_PROMOTION', ?string $parent = null) use ($driver, $permit, $intent): void {
        $driver->prepare('INSERT OR REPLACE INTO steps VALUES(?,?,?,?,?,NULL)')->execute([
            $permit['intent_id'], $parent ?? $intent, $kind,
            json_encode(['permit' => $claimPermit, 'context' => $claimContext], JSON_THROW_ON_ERROR), $phase]);
    };
    rejectsBridge(static fn () => bridgePromotionPlan($config, $intent, $now, false, $permit));
    $saveClaim($permit, $context);
    rejectsBridge(static fn () => bridgePromotionPlan($config, $intent, $now, false));
    $continuationPlan = bridgePromotionPlan($config, $intent, $now, false, $permit);
    checkBridge($continuationPlan === $plan + ['continuation_id' => $permit['intent_id'],
        'continuation_sha256' => hash('sha256', bridgeCanonical($permit))]);
    rejectsBridge(static fn () => bridgePromotionPlan($config, $intent, $now + 30, false, $permit));
    checkBridge(bridgePromotionPlan($config, $intent, $now + 30, true, $permit) === $plan);
    foreach (['COMMITTED', 'VERIFIED'] as $phase) {
        $saveClaim($permit, $context, $phase);
        rejectsBridge(static fn () => bridgePromotionPlan($config, $intent, $now, false, $permit));
    }
    $saveClaim($permit, $context, 'DISPATCHED', 'EXTERNAL_CUTOVER');
    rejectsBridge(static fn () => bridgePromotionPlan($config, $intent, $now, false, $permit));
    $saveClaim($permit, $context, 'DISPATCHED', 'CRM_PROMOTION', str_repeat('f', 40));
    rejectsBridge(static fn () => bridgePromotionPlan($config, $intent, $now, false, $permit));
    $saveClaim($permit, $context);
    foreach (['schema' => 'other', 'kind' => 'EXTERNAL_CUTOVER', 'parent_intent_id' => str_repeat('f', 40),
        'intent_id' => str_repeat('a', 36), 'context_hash' => str_repeat('f', 64),
        'created_at' => $now + 1, 'expires_at' => $now, 'extra' => true] as $key => $value) {
        $bad = $permit; $bad[$key] = $value;
        rejectsBridge(static fn () => bridgePromotionPlan($config, $intent, $now, false, $bad));
    }
    $bad = $permit; $bad['expires_at'] = $now + 31; $saveClaim($bad, $context);
    rejectsBridge(static fn () => bridgePromotionPlan($config, $intent, $now, false, $bad));
    $bad = $permit; $bad['created_at'] = true; $saveClaim($bad, $context);
    rejectsBridge(static fn () => bridgePromotionPlan($config, $intent, $now, false, $bad));
    $bad = $permit; $bad['expires_at']--;
    $saveClaim($bad, $context);
    rejectsBridge(static fn () => bridgePromotionPlan($config, $intent, $now, false, $permit));
    foreach (['owner_id' => '2', 'service_id' => '712', 'project_id' => 'other-project',
        'incident_id' => 'other-incident', 'config_hash' => str_repeat('f', 64), 'registry_hash' => str_repeat('f', 64),
        'gate_hash' => str_repeat('f', 64), 'checkpoint' => 'other-checkpoint', 'restore_epoch' => 'other-epoch',
        'restore_hash' => str_repeat('f', 64), 'identity' => $new + ['unused' => true],
        'source_identity' => $new] as $key => $value) {
        $badContext = $context; $badContext[$key] = $value;
        $bad = $permit; $bad['context_hash'] = hash('sha256', bridgeCanonical($badContext));
        $saveClaim($bad, $badContext);
        rejectsBridge(static fn () => bridgePromotionPlan($config, $intent, $now, false, $bad));
    }
    $saveClaim($permit, $context);
    foreach (['owner_id', 'service_id'] as $key) {
        $badConfig = $config; $badConfig['binding'][$key]++;
        rejectsBridge(static fn () => bridgePromotionPlan($badConfig, $intent, $now, false, $permit));
    }
    foreach ([['instance_id' => 6], ['instance_id' => '5']] as $change) {
        $badConfig = $config; $badConfig['binding'] = array_replace($config['binding'], $change);
        rejectsBridge(static fn () => bridgePromotionPlan($badConfig, $intent, $now, false, $permit));
    }
    foreach ([[8], [7, 8], ['7']] as $ids) {
        $badConfig = $config; $badConfig['exit_upstream_ids'] = $ids;
        rejectsBridge(static fn () => bridgePromotionPlan($badConfig, $intent, $now, false, $permit));
    }
    foreach ([[], [$panel, $panel], [array_replace($panel, ['exit_upstream_ids' => [7, 7]])],
        [$panel + ['command' => 'undeclared']]] as $resources) {
        $badCatalog = $catalog; $badCatalog['resources'] = $resources; jsonBridge($registryPath, $badCatalog);
        $badConfig = $config; $badConfig['production_driver']['registry']['sha256'] = hash_file('sha256', $registryPath);
        rejectsBridge(static fn () => bridgePanelBinding($badConfig));
    }
    jsonBridge($registryPath, $catalog);
    $badConfig = $config; $badConfig['production_driver']['enabled'] = false;
    rejectsBridge(static fn () => bridgePromotionPlan($badConfig, $intent, $now, false, $permit));
    $badConfig = $config; $badConfig['production_driver']['registry']['sha256'] = str_repeat('f', 64);
    rejectsBridge(static fn () => bridgePromotionPlan($badConfig, $intent, $now, false, $permit));
    $beforeDriver = hash_file('sha256', $root.'/production-driver.sqlite3');
    $beforeCore = hash_file('sha256', $root.'/controller.sqlite3');
    checkBridge(bridgePromotionPlan($config, $intent, $now, false, $permit) === $continuationPlan);
    checkBridge(hash_file('sha256', $root.'/production-driver.sqlite3') === $beforeDriver
        && hash_file('sha256', $root.'/controller.sqlite3') === $beforeCore);
    $badState = $state; $badState['candidate']['config_hash'] = str_repeat('e', 64);
    $db->prepare('UPDATE state SET body=? WHERE id=1')->execute([json_encode($badState, JSON_THROW_ON_ERROR)]);
    rejectsBridge(static fn () => bridgePromotionPlan($config, $intent, $now, false, $permit));
    $badState = $state; $badState['candidate']['generation']++;
    $db->prepare('UPDATE state SET body=? WHERE id=1')->execute([json_encode($badState, JSON_THROW_ON_ERROR)]);
    rejectsBridge(static fn () => bridgePromotionPlan($config, $intent, $now, false, $permit));
    $badState = $state; $badState['incident_id'] = 'other-incident';
    $db->prepare('UPDATE state SET body=? WHERE id=1')->execute([json_encode($badState, JSON_THROW_ON_ERROR)]);
    rejectsBridge(static fn () => bridgePromotionPlan($config, $intent, $now, false, $permit));
    $db->prepare('UPDATE state SET body=? WHERE id=1')->execute([json_encode($state, JSON_THROW_ON_ERROR)]);
    checkBridge(bridgeIdentity($new + ['ignored' => 'value']) === $new);
    $badIdentity = $new; $badIdentity['generation'] = true;
    rejectsBridge(static fn () => bridgeIdentity($badIdentity));
    $badIdentity = $new; $badIdentity['provider'] = 'other';
    rejectsBridge(static fn () => bridgeIdentity($badIdentity));
    $badIdentity = $new; $badIdentity['provider_id'] = '../51';
    rejectsBridge(static fn () => bridgeIdentity($badIdentity));
    $bundle = $plan + ['ipv4' => '8.8.4.4', 'ipv6' => null, 'location_code' => 'fi-helsinki',
        'all_checks_passed' => true, 'verified_at' => $now, 'proof_expires_at' => $now + 15,
        'checks' => array_fill_keys(['proxmox', 'vm109', 'vpn', 'dns', 'storage',
            'backup_durable', 'restore_verified', 'old_fenced', 'dns_cutover_verified'], true)];
    $bundlePath = $root.'/promotion-'.$intent.'.json';
    jsonBridge($bundlePath, $bundle);
    checkBridge(bridgePromotionBundle($config, $plan, $now) === $bundle);
    bridgePromotionCurrent($config, $continuationPlan, $bundle, $permit, $now); checkBridge(true);
    rejectsBridge(static fn () => bridgePromotionCurrent($config, $continuationPlan, $bundle, $permit, $now + 15));
    rejectsBridge(static fn () => bridgePromotionCurrent($config, $continuationPlan, $bundle, null, $now));
    rejectsBridge(static fn () => bridgePromotionCurrent($config, $continuationPlan, $bundle, $permit, $now + 30));
    $stale = $bundle; $stale['verified_at'] = $now - 31;
    rejectsBridge(static fn () => bridgePromotionCurrent($config, $continuationPlan, $stale, $permit, $now));
    $bad = $bundle; $bad['verified_at'] = $now - 31; jsonBridge($bundlePath, $bad);
    rejectsBridge(static fn () => bridgePromotionBundle($config, $plan, $now));
    foreach ([null, true, (string)($now + 15), $now, $now - 1, $now + 31] as $expiry) {
        $bad = $bundle; $bad['proof_expires_at'] = $expiry; jsonBridge($bundlePath, $bad);
        rejectsBridge(static fn () => bridgePromotionBundle($config, $plan, $now));
        rejectsBridge(static fn () => bridgePromotionCurrent($config, $continuationPlan, $bad, $permit, $now));
    }
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
    $link = 'vless://fake-user@1.1.1.1:443?note=1.1.1.1#keep';
    checkBridge(bridgeLinkHost($link, 'vless', '1.1.1.1', '8.8.4.4')
        === 'vless://fake-user@8.8.4.4:443?note=1.1.1.1#keep');
    $stable = 'vless://fake-user@relay.example.invalid:443?note=keep';
    checkBridge(bridgeLinkHost($stable, 'vless', '1.1.1.1', '8.8.4.4') === $stable);
    rejectsBridge(static fn () => bridgeLinkHost('trojan://fake@1.1.1.1:443', 'vless', '1.1.1.1', '8.8.4.4'));
    rejectsBridge(static fn () => bridgeLinkHost('vless://fake@1.1.1.2:443', 'vless', '1.1.1.1', '8.8.4.4'));
    // Reconciliation rereads current model rows and route authorities, rather
    // than treating an unchanged stored receipt as an unchanged upstream.
    $upstream = new BridgeUpstreamFixture(); BridgeUpstreamFixture::$rows[7] = $upstream;
    $snapshot = bridgeModelUpstreamSnapshot($upstream, '8.8.4.4');
    checkBridge(!str_contains(json_encode($snapshot, JSON_THROW_ON_ERROR), 'fake-user'));
    $receipt = ['instance_id' => 5, 'exit_upstream_ids' => [7], 'ipv4' => '8.8.4.4',
        'upstream_bindings' => [$snapshot]];
    bridgePromotionReadback($config, $receipt); checkBridge(true);
    $beforeUpstream = clone $upstream;
    foreach (['host' => '1.1.1.1', 'secret' => 'vless://fake-user@1.1.1.1:443',
        'role' => 'other', 'type' => 'trojan', 'port' => 444, 'enabled' => false] as $field => $value) {
        $upstream->$field = $value;
        rejectsBridge(static fn () => bridgePromotionReadback($config, $receipt));
        $upstream->$field = $beforeUpstream->$field;
    }
    foreach ([['exit_upstream_ids' => [8]], ['upstream_bindings' => []], ['instance_id' => 6]] as $change) {
        rejectsBridge(static fn () => bridgePromotionReadback($config, array_replace($receipt, $change)));
    }
    unset(BridgeUpstreamFixture::$rows[7]);
    rejectsBridge(static fn () => bridgePromotionReadback($config, $receipt));
    BridgeUpstreamFixture::$rows[7] = $upstream;
    $upstream->host = 'relay.example.invalid';
    $upstream->secret = 'vless://fake-user@relay.example.invalid:443';
    $stableSnapshot = bridgeModelUpstreamSnapshot($upstream, '8.8.4.4');
    $stableReceipt = array_replace($receipt, ['upstream_bindings' => [$stableSnapshot]]);
    bridgePromotionReadback($config, $stableReceipt); checkBridge(true);
    $upstream->secret = 'vless://fake-user@other.example.invalid:443';
    rejectsBridge(static fn () => bridgePromotionReadback($config, $stableReceipt));
    $state['phase'] = 'HEALTHY'; $state['active'] = $state['candidate']; $state['candidate'] = null; $state['pending_action'] = null;
    $db->prepare('UPDATE state SET body=? WHERE id=1')->execute([json_encode($state, JSON_THROW_ON_ERROR)]);
    rejectsBridge(static fn () => bridgePromotionPlan($config, $intent, $now, false));
    checkBridge(bridgePromotionPlan($config, $intent, $now, true) === $plan);
    // Original write authority and resource proof expiry do not suppress
    // later read-only reconciliation with fresh actual application rows.
    checkBridge(bridgePromotionPlan($config, $intent, $now + 3600, true) === $plan);
    // Protected readonly DNS metadata: a nullable/unrelated zone service_id is
    // not used as relay-service ownership; the exact customer + fixed selection
    // and protected service authority are all required.
    $dnsConfig = $config; $dnsConfig['binding']['owner_code'] = 'owner-fixture';
    $dnsEnrollment = ['schema' => 'servernet.relay.dns-ownership.enrollment.v1', 'agent_id' => 'ir-agent',
        'binding' => ['owner_id' => 1, 'service_id' => 7001, 'project_id' => 'project-1', 'config_hash' => str_repeat('d', 64)],
        'zone_ids' => [7]];
    $dnsPath = $root.'/dns-enrollment.json';
    file_put_contents($dnsPath, bridgeCanonical($dnsEnrollment)); chmod($dnsPath, 0600);
    $dnsConfig['dns_ownership'] = ['path' => $dnsPath, 'sha256' => hash_file('sha256', $dnsPath)];
    checkBridge(bridgeCanonical(bridgeDnsOwnershipEnrollment($dnsConfig)) === bridgeCanonical($dnsEnrollment));
    checkBridge(bridgeDnsOwnershipCurrent($dnsConfig, $dnsEnrollment) === $new);
    BridgeDnsInstanceFixture::$row = (object)['id' => 5, 'service_id' => 7001,
        'provider' => 'hetzner', 'provider_ref' => '51', 'ipv4' => '8.8.4.4'];
    BridgeDnsServiceFixture::$row = new BridgeDnsServiceFixture(); $dnsExempt = new BridgeDnsExemptFixture();
    $dnsRows = [['id' => 7, 'customer_id' => 1, 'domain' => 'owned.example.com',
        'mode' => 'ns', 'status' => 'active', 'publish_state' => 'published']];
    BridgeDnsZoneFixture::$rows = $dnsRows;
    BridgeDnsDatabaseFixture::$catalog = ['catalog.example.invalid'];
    $dnsResult = bridgeDnsOwnershipRead($dnsConfig, $dnsEnrollment, $new, time());
    checkBridge($dnsResult['catalog'] === 'catalog.example.invalid.' && $dnsResult['read_only'] === true);
    checkBridge($dnsResult['zones'] === [['id' => 7, 'name' => 'owned.example.com.']]);
    checkBridge(!str_contains(bridgeCanonical($dnsResult), 'owner-fixture'));
    checkBridge(!isset($dnsResult['credential'], $dnsResult['records'], $dnsResult['provision_meta']));
    foreach ([[], ['one.example.', 'two.example.'], [''], [null]] as $catalog) {
        BridgeDnsDatabaseFixture::$catalog = $catalog;
        rejectsBridge(static fn () => bridgeDnsOwnershipRead($dnsConfig, $dnsEnrollment, $new, time()));
    }
    BridgeDnsDatabaseFixture::$catalog = ['catalog.example.invalid'];
    foreach ([['customer_id' => 2], ['id' => 8], ['id' => true], ['domain' => 'bad.example..'],
        ['mode' => 'cname'], ['status' => 'suspended'], ['publish_state' => 'pending']] as $change) {
        BridgeDnsZoneFixture::$rows = [array_replace($dnsRows[0], $change)];
        rejectsBridge(static fn () => bridgeDnsOwnershipRead($dnsConfig, $dnsEnrollment, $new, time()));
    }
    BridgeDnsZoneFixture::$rows = $dnsRows;
    foreach (['customer_id', 'protected', 'provider_ref', 'exempt'] as $field) {
        $object = $field === 'provider_ref' ? BridgeDnsInstanceFixture::$row
            : ($field === 'exempt' ? $dnsExempt : BridgeDnsServiceFixture::$row);
        $prior = $object->$field;
        $object->$field = $field === 'customer_id' ? 2 : ($field === 'provider_ref' ? '99' : false);
        rejectsBridge(static fn () => bridgeDnsOwnershipRead($dnsConfig, $dnsEnrollment, $new, time()));
        $object->$field = $prior;
    }
    rejectsBridge(static fn () => bridgeDnsOwnershipSource($dnsEnrollment, str_repeat('a', 64), $new,
        '8.8.4.4', $dnsRows, 'catalog.example.invalid', $now, $now + 21));
    $badDns = $dnsConfig; $badDns['dns_ownership']['sha256'] = str_repeat('0', 64);
    rejectsBridge(static fn () => bridgeDnsOwnershipEnrollment($badDns));
    $badEnrollment = $dnsEnrollment; $badEnrollment['binding']['config_hash'] = str_repeat('0', 64);
    rejectsBridge(static fn () => bridgeDnsOwnershipCurrent($dnsConfig, $badEnrollment));
    fwrite(STDOUT, json_encode(['ok' => true, 'helper_assertions' => $checks], JSON_THROW_ON_ERROR)."\n");
} finally {
    unset($db, $driver, $saveClaim);
    foreach (glob($root.'/*') ?: [] as $path) { unlink($path); }
    rmdir($root);
}
