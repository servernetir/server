<?php
declare(strict_types=1);

// Private CLI adapter; never install in public_html or expose as a web route.
// Credentials may only be requested by the same private controller process.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');

function failBridge(string $code): never {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => $code], JSON_THROW_ON_ERROR)."\n");
    exit(1);
}

function bridgePrivatePath(string $path, bool $directory = false): string {
    // Revalidation in one long-running transaction must not reuse cached
    // permissions or an earlier path identity after a concurrent change.
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || !$stat
        || is_link($path) || ($directory ? !is_dir($path) : !is_file($path))
        || ($stat['mode'] & 0077) !== 0 || $stat['uid'] !== posix_geteuid()) {
        throw new RuntimeException('private_path_required');
    }
    return $path;
}

function bridgePrivateJson(string $path): array {
    bridgePrivatePath($path);
    if (filesize($path) > 131072) { throw new RuntimeException('private_json_size'); }
    $raw = file_get_contents($path);
    if (!is_string($raw)) { throw new RuntimeException('private_json_read'); }
    $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($value) || array_is_list($value)) { throw new RuntimeException('private_json_shape'); }
    return $value;
}

function bridgeIdentity(array $value): array {
    $result = [];
    foreach (['provider', 'project_id', 'provider_id'] as $key) {
        if (!is_string($value[$key] ?? null) || $value[$key] === '' || strlen($value[$key]) > 160) {
            throw new RuntimeException('resource_identity');
        }
        $result[$key] = $value[$key];
    }
    if ($result['provider'] !== 'hetzner' || !preg_match('/\A[1-9][0-9]{0,19}\z/D', $result['provider_id'])
        || !is_int($value['generation'] ?? null) || $value['generation'] < 1) {
        throw new RuntimeException('resource_identity');
    }
    $result['generation'] = $value['generation'];
    return $result;
}

/** Match core.canonical for the fixed, integer/string-only driver contract. */
function bridgeCanonical(mixed $value): string {
    $sort = static function (mixed $item) use (&$sort): mixed {
        if (is_array($item)) {
            if (!array_is_list($item)) { ksort($item, SORT_STRING); }
            return array_map($sort, $item);
        }
        if ($item !== null && !is_string($item) && !is_int($item) && !is_bool($item)) {
            throw new RuntimeException('continuation_json_type');
        }
        return $item;
    };
    return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/** A caller flag is never enough: the exact CRM claim must be durable/current. */
function bridgePromotionContinuation(array $config, array $action, array $state,
    array $plan, array $permit, int $now): array {
    $keys = array_keys($permit); sort($keys, SORT_STRING);
    if ($keys !== ['context_hash', 'created_at', 'expires_at', 'intent_id', 'kind', 'parent_intent_id', 'schema']
        || ($permit['schema'] ?? null) !== 'servernet.relay.driver.permit.v1'
        || ($permit['kind'] ?? null) !== 'CRM_PROMOTION'
        || ($permit['parent_intent_id'] ?? null) !== $plan['intent_id']
        || !is_string($permit['intent_id'] ?? null)
        || !preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D', $permit['intent_id'])
        || !is_string($permit['context_hash'] ?? null)
        || !preg_match('/\A[a-f0-9]{64}\z/D', $permit['context_hash'])
        || !is_int($permit['created_at'] ?? null) || !is_int($permit['expires_at'] ?? null)
        || $permit['created_at'] < $action['created_at'] || $permit['created_at'] > $now
        || $permit['expires_at'] <= $now || $permit['expires_at'] <= $permit['created_at']
        || $permit['expires_at'] - $permit['created_at'] > 30
        || $permit['expires_at'] > $action['expires_at']) {
        throw new RuntimeException('promotion_continuation_scope');
    }
    $policy = $config['policy'] ?? [];
    foreach (['owner_id', 'service_id'] as $key) {
        if (!is_string($policy[$key] ?? null) || $policy[$key] === ''
            || (string)($config['binding'][$key] ?? '') !== $policy[$key]) {
            throw new RuntimeException('promotion_continuation_binding');
        }
    }
    $driver = $config['production_driver'] ?? [];
    $registry = $driver['registry'] ?? [];
    if (($driver['enabled'] ?? null) !== true || !is_string($registry['path'] ?? null)
        || !is_string($registry['sha256'] ?? null)
        || !preg_match('/\A[a-f0-9]{64}\z/D', $registry['sha256'])) {
        throw new RuntimeException('promotion_continuation_registry');
    }
    $registryPath = bridgePrivatePath($registry['path']);
    if (filesize($registryPath) > 65536 || !is_string($raw = file_get_contents($registryPath))
        || !hash_equals($registry['sha256'], hash('sha256', $raw))) {
        throw new RuntimeException('promotion_continuation_registry');
    }
    $catalog = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($catalog) || ($catalog['schema'] ?? null) !== 'servernet.relay.dependency.registry.v1'
        || !is_int($catalog['revision'] ?? null) || $catalog['revision'] < 1) {
        throw new RuntimeException('promotion_continuation_registry');
    }
    foreach (['owner_id', 'service_id', 'project_id'] as $key) {
        if ((string)($catalog[$key] ?? '') !== (string)($policy[$key] ?? '')) {
            throw new RuntimeException('promotion_continuation_registry');
        }
    }
    $context = ['schema' => 'servernet.relay.production-driver.v1',
        'owner_id' => $policy['owner_id'], 'service_id' => $policy['service_id'], 'project_id' => $policy['project_id'],
        'source_identity' => $plan['old_identity'], 'identity' => $plan['new_identity'],
        'config_hash' => $plan['config_hash'], 'registry_hash' => $registry['sha256'],
        'parent_intent_id' => $plan['intent_id'], 'incident_id' => $plan['incident_id'],
        'restore_epoch' => $state['candidate']['restore_epoch'] ?? null,
        'restore_hash' => $state['candidate']['restore_hash'] ?? null,
        'gate_hash' => $plan['gate_hash'], 'checkpoint' => $plan['checkpoint']];
    if (!hash_equals(hash('sha256', bridgeCanonical($context)), $permit['context_hash'])) {
        throw new RuntimeException('promotion_continuation_context');
    }
    $path = bridgePrivatePath(rtrim((string)$config['state_dir'], '/').'/production-driver.sqlite3');
    $db = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    $db->exec('PRAGMA query_only=ON'); $db->exec('PRAGMA busy_timeout=5000');
    $db->beginTransaction();
    try {
        $statement = $db->prepare('SELECT id,parent,kind,body,phase FROM steps WHERE id=?');
        $statement->execute([$permit['intent_id']]); $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['parent'] !== $plan['intent_id'] || $row['kind'] !== 'CRM_PROMOTION'
            || $row['phase'] !== 'DISPATCHED') { throw new RuntimeException('promotion_continuation_uncommitted'); }
        $body = json_decode($row['body'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($body) || count($body) !== 2 || !isset($body['permit'], $body['context'])
            || bridgeCanonical($body['permit']) !== bridgeCanonical($permit)
            || bridgeCanonical($body['context']) !== bridgeCanonical($context)) {
            throw new RuntimeException('promotion_continuation_changed');
        }
    } finally {
        if ($db->inTransaction()) { $db->rollBack(); }
    }
    return ['continuation_id' => $permit['intent_id'], 'continuation_sha256' => hash('sha256', bridgeCanonical($permit))];
}

/** Read only: caller-supplied identities, addresses and success flags are ignored. */
function bridgePromotionPlan(array $config, string $intentId, int $now, bool $readOnly, ?array $continuation = null): array {
    if (!preg_match('/\A[a-f0-9]{40}\z/D', $intentId)) { throw new RuntimeException('promotion_intent'); }
    if (!$readOnly && ($config['policy']['enabled'] ?? null) !== true) { throw new RuntimeException('controller_disabled'); }
    $root = bridgePrivatePath((string)($config['state_dir'] ?? ''), true);
    $path = bridgePrivatePath($root.'/controller.sqlite3');
    $db = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    $db->exec('PRAGMA query_only=ON');
    $db->exec('PRAGMA busy_timeout=5000');
    $db->beginTransaction();
    try {
        $statement = $db->prepare('SELECT kind,body FROM actions WHERE id=?');
        $statement->execute([$intentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $stateRaw = $db->query('SELECT body FROM state WHERE id=1')->fetchColumn();
        if (!$row || $row['kind'] !== 'PROMOTE' || !is_string($stateRaw)) { throw new RuntimeException('uncommitted_promotion'); }
        $action = json_decode($row['body'], true, 64, JSON_THROW_ON_ERROR);
        $state = json_decode($stateRaw, true, 64, JSON_THROW_ON_ERROR);
        if (($action['id'] ?? null) !== $intentId || ($action['kind'] ?? null) !== 'PROMOTE'
            || !is_string($action['incident_id'] ?? null) || $action['incident_id'] === ''
            || ($state['clock_unsafe'] ?? true) !== false || !is_int($state['last_now'] ?? null)
            || $now < $state['last_now']) { throw new RuntimeException('promotion_journal_scope'); }
        // A committed journal row is not an evergreen mutation permission.
        // Historical reconciliation may inspect it after expiry, but a new
        // application write must still be inside its original claim window.
        if (!$readOnly && (!is_int($action['created_at'] ?? null) || !is_int($action['expires_at'] ?? null)
            || $action['created_at'] > $now || $now >= $action['expires_at'])) {
            throw new RuntimeException('promotion_claim_expired');
        }
        $old = bridgeIdentity($action['payload']['old_identity'] ?? []);
        $new = bridgeIdentity($action['payload']['identity'] ?? []);
        $project = (string)($config['policy']['project_id'] ?? '');
        if ($old['project_id'] !== $project || $new['project_id'] !== $project
            || $new['provider_id'] === $old['provider_id'] || $new['generation'] !== $old['generation'] + 1
            || !is_string($action['payload']['checkpoint'] ?? null) || $action['payload']['checkpoint'] === ''
            || !preg_match('/\A[a-f0-9]{64}\z/D', (string)($action['payload']['gate_hash'] ?? ''))) {
            throw new RuntimeException('promotion_identity');
        }
        $inProgress = ($state['phase'] ?? null) === 'CUTOVER' && ($state['pending_action'] ?? null) === $intentId
            && ($state['incident_id'] ?? null) === ($action['incident_id'] ?? null)
            && bridgeIdentity($state['active'] ?? []) === $old && bridgeIdentity($state['candidate'] ?? []) === $new;
        $alreadyActive = $readOnly && bridgeIdentity($state['active'] ?? []) === $new;
        if (!$inProgress && !$alreadyActive) { throw new RuntimeException('promotion_not_current'); }
        $resource = $inProgress ? $state['candidate'] : $state['active'];
        $hash = $resource['config_hash'] ?? null;
        if (!is_string($hash) || !preg_match('/\A[a-f0-9]{64}\z/D', $hash)) {
            throw new RuntimeException('promotion_configuration');
        }
        $plan = ['intent_id' => $intentId, 'incident_id' => $action['incident_id'],
            'old_identity' => $old, 'new_identity' => $new, 'config_hash' => $hash,
            'gate_hash' => $action['payload']['gate_hash'], 'checkpoint' => $action['payload']['checkpoint']];
        if (!$readOnly && $continuation !== null) {
            $plan += bridgePromotionContinuation($config, $action, $state, $plan, $continuation, $now);
        }
        return $plan;
    } finally {
        if ($db->inTransaction()) { $db->rollBack(); }
    }
}

function bridgePromotionBundle(array $config, array $plan, int $now): array {
    $bundle = bridgePrivateJson(rtrim((string)$config['state_dir'], '/').'/promotion-'.$plan['intent_id'].'.json');
    foreach (['intent_id', 'incident_id', 'config_hash', 'gate_hash', 'checkpoint'] as $key) {
        if (($bundle[$key] ?? null) !== $plan[$key]) { throw new RuntimeException('promotion_bundle_scope'); }
    }
    if (bridgeIdentity($bundle['old_identity'] ?? []) !== $plan['old_identity']
        || bridgeIdentity($bundle['new_identity'] ?? []) !== $plan['new_identity']
        || ($bundle['all_checks_passed'] ?? null) !== true
        || !is_int($bundle['verified_at'] ?? null) || $now - $bundle['verified_at'] < 0
        || $now - $bundle['verified_at'] > 30
        || !filter_var($bundle['ipv4'] ?? '', FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        throw new RuntimeException('promotion_bundle_unverified');
    }
    if (($bundle['ipv6'] ?? null) !== null && !filter_var($bundle['ipv6'], FILTER_VALIDATE_IP,
        FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        throw new RuntimeException('promotion_ipv6');
    }
    $checks = $bundle['checks'] ?? null;
    $critical = $config['policy']['critical_consumers'] ?? ['proxmox', 'vm109', 'vpn', 'dns', 'storage'];
    if (!is_array($checks) || !is_array($critical) || !$critical) { throw new RuntimeException('promotion_checks'); }
    foreach (array_merge($critical, ['backup_durable', 'restore_verified', 'old_fenced', 'dns_cutover_verified']) as $check) {
        if (!is_string($check) || ($checks[$check] ?? null) !== true) { throw new RuntimeException('promotion_checks'); }
    }
    if (!is_string($bundle['location_code'] ?? null) || !preg_match('/\A[a-z0-9][a-z0-9-]{1,95}\z/D', $bundle['location_code'])) {
        throw new RuntimeException('promotion_location');
    }
    $ids = $config['exit_upstream_ids'] ?? null;
    if (!is_array($ids) || !array_is_list($ids) || count($ids) > 32
        || count(array_unique($ids, SORT_REGULAR)) !== count($ids)) { throw new RuntimeException('upstream_allowlist_required'); }
    foreach ($ids as $id) { if (!is_int($id) || $id < 1) { throw new RuntimeException('upstream_allowlist'); } }
    return $bundle;
}

/** Recheck after row-lock waits and immediately before each application write. */
function bridgePromotionCurrent(array $config, array $plan, array $bundle, ?array $continuation, int $now): void {
    if ($now - $bundle['verified_at'] < 0 || $now - $bundle['verified_at'] > 30
        || bridgePromotionPlan($config, $plan['intent_id'], $now, false, $continuation) !== $plan) {
        throw new RuntimeException('promotion_proof_changed');
    }
}

/** Rewrite only a parsed connection authority; never global-replace a secret. */
function bridgeLinkHost(string $secret, string $type, string $oldIp, string $newIp): string {
    $parsed = parse_url($secret);
    if (!is_array($parsed) || ($parsed['scheme'] ?? '') !== $type || !is_string($parsed['host'] ?? null)) {
        throw new RuntimeException('upstream_link_shape');
    }
    if ($parsed['host'] !== $oldIp) {
        if (filter_var($parsed['host'], FILTER_VALIDATE_IP)) { throw new RuntimeException('upstream_link_drift'); }
        return $secret; // A stable DNS name is updated by the DNS adapter.
    }
    $changed = preg_replace_callback('/\A([a-z]+:\/\/[^\/?#]*@)([^:\/?#]+)(.*)\z/sD',
        static fn (array $parts): string => $parts[1].$newIp.$parts[3], $secret, 1, $count);
    if (!is_string($changed) || $count !== 1 || (parse_url($changed, PHP_URL_HOST) ?: '') !== $newIp) {
        throw new RuntimeException('upstream_link_rewrite');
    }
    return $changed;
}

// A private, local fixture may exercise validation helpers without booting Laravel.
if (defined('SERVERNET_RELAY_BRIDGE_TEST_HELPERS')) { return; }

try {
    $raw = stream_get_contents(STDIN, 65537);
    if (!is_string($raw) || strlen($raw) > 65536) { failBridge('request_size'); }
    $request = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    $configPath = $argv[1] ?? '';
    $stat = @lstat($configPath);
    if (!$stat || is_link($configPath) || !is_file($configPath)
        || ($stat['mode'] & 0077) !== 0 || $stat['uid'] !== posix_geteuid()) {
        failBridge('private_config_required');
    }
    $config = json_decode(file_get_contents($configPath), true, 32, JSON_THROW_ON_ERROR);
    $expected = $config['binding'] ?? [];
    foreach (['instance_id', 'service_id', 'owner_id', 'owner_code', 'provider_id', 'ipv4'] as $field) {
        if (!isset($expected[$field]) || (string)$expected[$field] === '') { failBridge('binding_incomplete'); }
    }
    $root = rtrim((string)($config['app_root'] ?? ''), '/');
    if ($root === '' || !is_dir($root) || !is_file($root.'/bootstrap/app.php')) { failBridge('app_root'); }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $operation = $request['op'] ?? '';
    if (!in_array($operation, ['describe', 'credentials', 'protect', 'promote', 'reconcile_promotion'], true)) { failBridge('operation'); }
    $now = time();
    $continuation = $operation === 'promote' ? ($request['continuation'] ?? null) : null;
    $plan = in_array($operation, ['promote', 'reconcile_promotion'], true)
        ? bridgePromotionPlan($config, (string)($request['intent_id'] ?? ''), $now, $operation === 'reconcile_promotion', $continuation) : null;
    $bundle = $operation === 'promote' ? bridgePromotionBundle($config, $plan, $now) : null;

    $result = Illuminate\Support\Facades\DB::transaction(function () use ($expected, $operation, $request, $configPath, $config, $plan, $bundle, $now, $continuation) {
        $instance = App\Models\CloudInstance::query()->whereKey((int)$expected['instance_id'])->lockForUpdate()->first();
        $service = App\Models\Service::query()->whereKey((int)$expected['service_id'])->lockForUpdate()->first();
        if (!$instance || !$service || (int)$instance->service_id !== (int)$service->id
            || (int)$service->customer_id !== (int)$expected['owner_id']
            || (string)$service->customer?->code !== (string)$expected['owner_code']
            || $instance->provider !== 'hetzner') {
            throw new RuntimeException('binding_mismatch');
        }
        if (!app(App\Services\Billing\ComplimentaryServices::class)->customerExempt($service->customer)) {
            throw new RuntimeException('owner_policy_mismatch');
        }
        if ($operation === 'credentials') {
            // Stable, exact instance/service/owner binding survives generation changes.
            // This private CLI already runs as the account owning the encrypted settings.
            // No target supplied by the caller can select another customer or instance.
            if (!$service->isInternalInfrastructure()) { throw new RuntimeException('internal_protection_required'); }
            // Returned only on a private pipe, never to a web request, status log or report.
            return ['ok' => true, 'hetzner_token' => App\Models\Setting::getSecret('hetzner_api_token'),
                'cloudflare_token' => App\Models\Setting::getSecret('cloudflare_token'),
                'internal_protected' => true, 'binding' => [
                    'instance_id' => (int)$instance->id, 'service_id' => (int)$service->id,
                    'owner_id' => (int)$service->customer_id, 'owner_code' => (string)$service->customer->code,
                    'provider_id' => (string)$instance->provider_ref, 'ipv4' => (string)$instance->ipv4]];
        }
        if (in_array($operation, ['describe', 'protect'], true)
            && ((string)$instance->provider_ref !== (string)$expected['provider_id']
                || (string)$instance->ipv4 !== (string)$expected['ipv4']
                || !in_array($instance->status, ['running', 'building', 'off'], true))) {
            throw new RuntimeException('seed_generation_mismatch');
        }
        if ($plan !== null) {
            if (!$service->isInternalInfrastructure()) { throw new RuntimeException('internal_protection_required'); }
            $meta = $service->provision_meta ?? [];
            $previous = $meta['relay_recovery']['promotion'] ?? null;
            $new = $plan['new_identity'];
            $old = $plan['old_identity'];
            if (is_array($previous) && ($previous['intent_id'] ?? null) === $plan['intent_id']) {
                if (($previous['new_identity'] ?? null) !== $new
                    || (string)$instance->provider_ref !== $new['provider_id']
                    || (string)$instance->ipv4 !== ($previous['ipv4'] ?? null)
                    || (($instance->meta ?? [])['relay_recovery'] ?? null) !== $previous) {
                    throw new RuntimeException('promotion_receipt_drift');
                }
                foreach (['incident_id', 'config_hash', 'gate_hash', 'checkpoint'] as $key) {
                    if (($previous[$key] ?? null) !== $plan[$key]) { throw new RuntimeException('promotion_receipt_drift'); }
                }
                return ['ok' => true, 'outcome' => 'promoted', 'receipt' => $previous, 'changed' => false];
            }
            if ((string)$instance->provider_ref !== $old['provider_id']) { throw new RuntimeException('promotion_old_identity_drift'); }
            $generation = $meta['relay_recovery']['generation'] ?? $old['generation'];
            if ($generation !== $old['generation']) { throw new RuntimeException('promotion_generation_drift'); }
            if ($operation === 'reconcile_promotion') {
                return ['ok' => true, 'outcome' => 'not_promoted', 'changed' => false];
            }
            $oldIp = (string)$instance->ipv4;
            if (is_array($previous)) {
                if (($previous['new_identity'] ?? null) !== $old || ($previous['ipv4'] ?? null) !== $oldIp) {
                    throw new RuntimeException('promotion_previous_generation_drift');
                }
            } elseif ((string)$expected['provider_id'] !== $old['provider_id'] || (string)$expected['ipv4'] !== $oldIp) {
                throw new RuntimeException('promotion_seed_address_drift');
            }
            if (!filter_var($oldIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $oldIp === $bundle['ipv4']) {
                throw new RuntimeException('promotion_address_drift');
            }
            if (!App\Models\CloudLocation::query()->where('code', $bundle['location_code'])->exists()) {
                throw new RuntimeException('promotion_unknown_location');
            }
            $updatedUpstreams = [];
            foreach ($config['exit_upstream_ids'] as $id) {
                $upstream = App\Models\ExitUpstream::query()->whereKey($id)->lockForUpdate()->first();
                if (!$upstream || !in_array($upstream->role, ['relay', 'exit'], true)) { throw new RuntimeException('upstream_binding'); }
                $host = trim((string)$upstream->host);
                if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) && $host !== $oldIp) {
                    throw new RuntimeException('upstream_host_drift');
                }
                if ($host === $oldIp) { $upstream->host = $bundle['ipv4']; }
                if (in_array($upstream->type, ['vless', 'trojan'], true)) {
                    $secret = $upstream->secretPlain();
                    if (!is_string($secret) || $secret === '') { throw new RuntimeException('upstream_secret_unavailable'); }
                    $replacement = bridgeLinkHost($secret, $upstream->type, $oldIp, $bundle['ipv4']);
                    if ($replacement !== $secret) { $upstream->secret = $replacement; }
                } elseif (!in_array($upstream->type, ['ssh', 'socks', 'wireguard'], true)) {
                    throw new RuntimeException('upstream_type');
                }
                bridgePromotionCurrent($config, $plan, $bundle, $continuation, time());
                $upstream->save();
                $updatedUpstreams[] = $id;
            }
            bridgePromotionCurrent($config, $plan, $bundle, $continuation, time());
            $receipt = ['schema' => 'servernet.relay.crm-promotion.v1', 'intent_id' => $plan['intent_id'],
                'incident_id' => $plan['incident_id'], 'old_identity' => $old, 'new_identity' => $new,
                'instance_id' => (int)$instance->id, 'service_id' => (int)$service->id,
                'owner_id' => (int)$service->customer_id, 'ipv4' => $bundle['ipv4'],
                'ipv6' => $bundle['ipv6'] ?? null, 'location_code' => $bundle['location_code'],
                'config_hash' => $plan['config_hash'], 'gate_hash' => $plan['gate_hash'],
                'checkpoint' => $plan['checkpoint'], 'exit_upstream_ids' => $updatedUpstreams,
                'committed_at' => $now];
            foreach (['continuation_id', 'continuation_sha256'] as $key) {
                if (isset($plan[$key])) { $receipt[$key] = $plan[$key]; }
            }
            $instanceMeta = $instance->meta ?? [];
            $instanceMeta['relay_recovery'] = $receipt;
            $instance->forceFill(['provider_ref' => $new['provider_id'], 'ipv4' => $bundle['ipv4'],
                'ipv6' => $bundle['ipv6'] ?? null, 'location_code' => $bundle['location_code'],
                'status' => 'running', 'last_error' => null, 'root_password_enc' => null,
                'password_seen' => true, 'synced_at' => now(), 'meta' => $instanceMeta])->save();
            $meta['relay_recovery'] = array_merge((array)($meta['relay_recovery'] ?? []),
                ['generation' => $new['generation'], 'promotion' => $receipt]);
            $meta['ip'] = $bundle['ipv4'];
            $meta['ipv6'] = $bundle['ipv6'] ?? null;
            $meta['location'] = $bundle['location_code'];
            $service->provision_meta = $meta;
            bridgePromotionCurrent($config, $plan, $bundle, $continuation, time());
            $service->save();
            if (!$service->fresh()->isInternalInfrastructure()) { throw new RuntimeException('protection_lost'); }
            bridgePromotionCurrent($config, $plan, $bundle, $continuation, time());
            return ['ok' => true, 'outcome' => 'promoted', 'receipt' => $receipt, 'changed' => true];
        }
        $before = $service->provision_meta ?? [];
        $beforeHash = hash('sha256', json_encode($before, JSON_THROW_ON_ERROR));
        $changed = false;
        if ($operation === 'protect' && !$service->isInternalInfrastructure()) {
            if (!hash_equals($beforeHash, (string)($request['expected_meta_hash'] ?? ''))) {
                throw new RuntimeException('concurrent_metadata_change');
            }
            $backupPath = dirname($configPath).'/protection-before.json';
            $fh = @fopen($backupPath, 'x');
            if ($fh === false) { throw new RuntimeException('backup_exists_reconcile_first'); }
            chmod($backupPath, 0600);
            $backup = json_encode(['service_id' => $service->id, 'hash' => $beforeHash,
                'provision_meta' => $before], JSON_THROW_ON_ERROR);
            if (fwrite($fh, $backup) !== strlen($backup) || !fflush($fh)) {
                fclose($fh); throw new RuntimeException('backup_failed');
            }
            if (function_exists('fsync')) { fsync($fh); }
            fclose($fh);
            $after = $before;
            $after['internal_infrastructure'] = true;
            $after['internal_infrastructure_customer'] = (int)$service->customer_id;
            $service->provision_meta = $after;
            $service->save();
            if (!$service->fresh()->isInternalInfrastructure()) { throw new RuntimeException('protection_verify'); }
            $changed = true;
        }
        return ['ok' => true, 'binding' => [
            'instance_id' => $instance->id, 'service_id' => $service->id,
            'owner_id' => $service->customer_id, 'owner_code' => $service->customer->code,
            'provider_id' => (string)$instance->provider_ref, 'ipv4' => $instance->ipv4],
            'internal_protected' => $service->fresh()->isInternalInfrastructure(),
            'previous_meta_hash' => $beforeHash,
            'protection_changed' => $changed];
    });
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR)."\n");
} catch (Throwable $error) {
    // Exception messages may contain SQL or credentials. Emit only a fixed code.
    failBridge('bridge_failed');
}
