<?php
declare(strict_types=1);

/** Owner-approved ONE-VM diagnostic, 28 October 2026. REVIEW BEFORE PRIVATE INSTALL.
 * Exactly one paid create; immutable cleanup deadline is create intent + 40 minutes.
 * A failed/ambiguous create may only reconcile or clean up, never retry.
 * Fixed private manifest/state; no public route, support contact or sales writes.
 * Include this file for pure tests: inclusion never boots Laravel or reads a host.
 */
final class OwnerWindowsDiagnostic28
{
    public const ROOT = '/home/servernetcloud/servernet_app';
    public const DIR = self::ROOT.'/storage/app/private/arvan-owner-windiag-20261028';
    public const NAME = 'sn-owner1-windiag-20261028';
    public const REGION = 'ir-tbz-sh1';
    public const OWNER = 1;
    public const OWNER_CODE = 'SN-457136';
    public const BUDGET = 120000;
    // Deadline is anchored before the paid call; reconciliation may never extend it.
    public const RUN_SECONDS = 40 * 60;
    public const SCRIPT = self::DIR.'/owner-test-20261028.php';
    public const PHP = '/opt/cpanel/ea-php84/root/usr/bin/php';
    public const CRON = '* * * * * '.self::PHP.' '.self::SCRIPT.' cleanup --cron >/dev/null 2>&1';

    public static function guard(bool $ok, string $name): void
    {
        if (! $ok) {
            throw new RuntimeException('guard:'.$name);
        }
    }

    public static function uuid(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $id) === 1;
    }

    public static function requireLockDatabaseDriver(string $driver): void
    {
        // Laravel exposes MariaDB separately; both still require the exact
        // observed REPEATABLE-READ/InnoDB/index checks below, never name alone.
        self::guard(in_array($driver, ['mysql', 'mariadb'], true), 'unattached_lock_database');
    }

    public static function manifest(array $m, int $now, bool $fresh = true): array
    {
        self::guard(($m['name'] ?? null) === self::NAME && ($m['region'] ?? null) === self::REGION
            && ($m['owner_id'] ?? null) === self::OWNER && ($m['owner_code'] ?? null) === self::OWNER_CODE, 'manifest_identity');
        self::guard(is_int($m['plan_id'] ?? null) && $m['plan_id'] > 0
            && is_string($m['plan_ref'] ?? null) && preg_match('/^[a-zA-Z0-9._-]{1,100}$/D', $m['plan_ref']) === 1
            && self::uuid($m['image_id'] ?? null)
            && is_string($m['image_key'] ?? null) && preg_match('/^[a-zA-Z0-9._-]{1,80}$/D', $m['image_key']) === 1, 'manifest_catalog');
        $s = $m['shape'] ?? [];
        self::guard(is_int($s['vcpu'] ?? null) && $s['vcpu'] >= 1 && $s['vcpu'] <= 2
            && is_int($s['ram_mb'] ?? null) && $s['ram_mb'] >= 1024 && $s['ram_mb'] <= 4096
            && is_int($s['disk_gb'] ?? null) && $s['disk_gb'] >= 20 && $s['disk_gb'] <= 40
            && ($s['cpu_kind'] ?? null) === 'shared', 'manifest_shape');
        $q = $m['quote'] ?? [];
        self::guard(is_int($q['observed_at'] ?? null) && is_int($q['expires_at'] ?? null)
            && $q['expires_at'] > $q['observed_at'] && $q['expires_at'] - $q['observed_at'] <= 1800
            && is_int($q['all_in_48h_irt'] ?? null) && $q['all_in_48h_irt'] > 0
            && $q['all_in_48h_irt'] <= self::BUDGET
            && is_int($q['provider_hourly_rial'] ?? null) && $q['provider_hourly_rial'] > 0
            && (int) ceil($q['provider_hourly_rial'] * 48 / 10) <= $q['all_in_48h_irt']
            && ($q['currency'] ?? null) === 'IRT' && ($q['hours'] ?? null) === 48, 'quote_bound');
        foreach (['tax_confirmed', 'disk_included', 'ipv4_included', 'license_included_or_free', 'minimum_bill_bounded'] as $flag) {
            self::guard(($q[$flag] ?? null) === true, 'quote_components');
        }
        if ($fresh) {
            self::guard($q['observed_at'] <= $now && $now <= $q['expires_at'], 'quote_expired');
        }
        self::guard(in_array($m['volume_schema'] ?? 'unverified', ['unverified', 'attachments.server_id', 'instance_id'], true), 'volume_schema');
        // A reviewed observed schema is required before interpreting nonempty volumes.
        if (($m['volume_schema'] ?? 'unverified') !== 'unverified') {
            self::guard(($m['volume_schema_observed'] ?? false) === true, 'volume_schema_not_observed');
        }
        return $m;
    }

    public static function rows(array $response): array
    {
        self::guard(($response['ok'] ?? false) === true && ($response['status'] ?? null) === 200
            && ($response['json_valid'] ?? false) === true && ($response['data_present'] ?? false) === true
            && ($response['data_is_list'] ?? false) === true && is_array($response['data'] ?? null)
            && array_is_list($response['data']), 'inventory_unreadable');
        $ids = [];
        foreach ($response['data'] as $row) {
            self::guard(is_array($row) && is_string($row['id'] ?? null) && $row['id'] !== ''
                && ! isset($ids[$row['id']]), 'inventory_identity');
            $ids[$row['id']] = true;
        }
        return $response['data'];
    }

    public static function exactName(array $rows): ?array
    {
        foreach ($rows as $row) {
            self::guard(is_string($row['name'] ?? null), 'server_name_schema');
        }
        $matches = array_values(array_filter($rows, static fn ($r) => $r['name'] === self::NAME));
        self::guard(count($matches) <= 1, 'duplicate_test_name');
        return $matches[0] ?? null;
    }

    /** Current provider schema; no label-based substitute or cached fallback. */
    public static function providerCatalog(array $sizes, array $images, array $m): void
    {
        $matches = array_values(array_filter(self::rows($sizes), static fn ($r) => $r['id'] === $m['plan_ref']));
        self::guard(count($matches) === 1, 'provider_flavor_missing');
        $s = $matches[0];
        foreach (['cpu_count', 'memory', 'disk', 'price_per_hour'] as $key) {
            self::guard(isset($s[$key]) && (is_int($s[$key]) || is_string($s[$key]))
                && preg_match('/^\d+$/D', (string) $s[$key]) === 1, 'provider_flavor_schema');
        }
        self::guard((int) $s['cpu_count'] === $m['shape']['vcpu']
            && (int) $s['memory'] * 1024 === $m['shape']['ram_mb']
            && (int) $s['disk'] === $m['shape']['disk_gb']
            && (int) $s['price_per_hour'] === $m['quote']['provider_hourly_rial'], 'provider_flavor_or_price_changed');
        self::guard(($images['ok'] ?? false) === true && ($images['status'] ?? null) === 200
            && ($images['json_valid'] ?? false) === true && ($images['data_present'] ?? false) === true
            && ($images['data_is_list'] ?? false) === true && is_array($images['data'] ?? null)
            && array_is_list($images['data']), 'provider_images_unreadable');
        $found = [];
        foreach ($images['data'] as $group) {
            self::guard(is_array($group), 'provider_image_schema');
            $children = $group['images'] ?? [$group];
            self::guard(is_array($children) && array_is_list($children), 'provider_image_schema');
            foreach ($children as $image) {
                self::guard(is_array($image) && is_string($image['id'] ?? null), 'provider_image_schema');
                if ($image['id'] !== $m['image_id']) { continue; }
                self::guard(strtolower(trim((string) ($group['name'] ?? ''))) === 'windows'
                    && ($image['name'] ?? null) === $m['image_key'], 'provider_image_not_exact_windows');
                foreach (['disk', 'ram'] as $key) {
                    self::guard(isset($image[$key]) && (is_int($image[$key]) || is_string($image[$key]))
                        && preg_match('/^\d+$/D', (string) $image[$key]) === 1, 'provider_image_minimum_unknown');
                }
                self::guard((int) $image['disk'] <= $m['shape']['disk_gb']
                    && (int) $image['ram'] <= $m['shape']['ram_mb'], 'provider_image_minimum_exceeded');
                $found[] = $image;
            }
        }
        self::guard(count($found) === 1, 'provider_image_missing_or_duplicate');
    }

    public static function serverIdentity(array $row, array $state): int
    {
        self::guard(self::uuid($row['id'] ?? null) && ($row['name'] ?? null) === self::NAME, 'provider_identity');
        if (isset($state['ref'])) {
            self::guard($state['ref'] === self::REGION.':'.$row['id'], 'provider_reference_changed');
        }
        $raw = $row['created_at'] ?? $row['created'] ?? null;
        self::guard(is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $raw) === 1, 'provider_creation_time_missing');
        $created = strtotime($raw);
        self::guard(is_int($created) && isset($state['create_intent_at'])
            && $created >= $state['create_intent_at'] - 120 && $created <= $state['create_intent_at'] + 600, 'provider_creation_time_mismatch');
        if (isset($state['provider_created_at'])) {
            self::guard($created === $state['provider_created_at'], 'provider_creation_time_changed');
        }
        return $created;
    }

    public static function relatedPorts(array $rows, string $server, array $savedIds = [], array $savedIps = []): array
    {
        $matches = [];
        foreach ($rows as $row) {
            self::guard(array_key_exists('instance_id', $row)
                && (is_string($row['instance_id']) || $row['instance_id'] === null)
                && is_array($row['ips'] ?? null) && array_is_list($row['ips']), 'port_schema');
            foreach ($row['ips'] as $ip) {
                self::guard(is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false, 'port_address_schema');
            }
            if ($row['instance_id'] === $server || in_array($row['id'], $savedIds, true)
                || array_intersect($row['ips'], $savedIps) !== []) {
                // A formerly dedicated IP/port reassigned elsewhere is not ours to remove.
                self::guard($row['instance_id'] === $server || $row['instance_id'] === null || $row['instance_id'] === '', 'port_reassigned');
                $matches[] = $row;
            }
        }
        return $matches;
    }

    public static function relatedVolumes(array $rows, string $server, string $schema, array $savedIds = []): array
    {
        if ($rows === []) {
            return [];
        }
        self::guard($schema !== 'unverified', 'volume_attachment_schema_unknown');
        $matches = [];
        foreach ($rows as $row) {
            $owners = [];
            if ($schema === 'instance_id') {
                self::guard(array_key_exists('instance_id', $row)
                    && (is_string($row['instance_id']) || $row['instance_id'] === null), 'volume_attachment_schema');
                $owners = [$row['instance_id']];
            } else {
                self::guard(is_array($row['attachments'] ?? null) && array_is_list($row['attachments']), 'volume_attachment_schema');
                foreach ($row['attachments'] as $attachment) {
                    self::guard(is_array($attachment) && is_string($attachment['server_id'] ?? null), 'volume_attachment_schema');
                    $owners[] = $attachment['server_id'];
                }
            }
            if (in_array($server, $owners, true) || in_array($row['id'], $savedIds, true)) {
                self::guard(array_diff(array_filter($owners), [$server]) === [], 'volume_shared_or_reassigned');
                $matches[] = $row;
            }
        }
        return $matches;
    }

    public static function requireCleanupSchema(array $m): void
    {
        // Empty preflight lists do not prove how a newly created Windows disk will be linked.
        // Fail before spending if cleanup would otherwise deadlock on an unknown schema.
        self::guard(in_array($m['volume_schema'] ?? 'unverified', ['attachments.server_id', 'instance_id'], true)
            && ($m['volume_schema_observed'] ?? false) === true, 'cleanup_volume_schema_unverified');
    }

    public static function createIntent(array $s, int $now): array
    {
        self::guard(! array_key_exists('create_intent_at', $s) && ! array_key_exists('cleanup_at', $s),
            'create_already_attempted_reconcile_only');
        self::guard($now > 0 && $now <= PHP_INT_MAX - self::RUN_SECONDS, 'intent_time');
        $s['create_intent_at'] = $now;
        $s['cleanup_at'] = $now + self::RUN_SECONDS;
        return $s;
    }

    public static function cleanupDeadline(array $s): int
    {
        $intent = $s['create_intent_at'] ?? null;
        $deadline = $s['cleanup_at'] ?? null;
        self::guard(is_int($intent) && $intent > 0 && $intent <= PHP_INT_MAX - self::RUN_SECONDS
            && is_int($deadline) && $deadline === $intent + self::RUN_SECONDS, 'immutable_cleanup_deadline');
        return $deadline;
    }

    public static function due(array $s, int $now): bool
    {
        if (! array_key_exists('create_intent_at', $s)) { return false; }
        $deadline = self::cleanupDeadline($s);
        return isset($s['early_cleanup_requested_at']) || $now >= $deadline;
    }

    /** Presentation only: later resource reconciliation cannot invent a create response. */
    public static function receiptEvidence(array $s): array
    {
        $out = ['receipt_outcome' => 'not_attempted', 'password_evidence' => 'unknown_without_successful_response'];
        if (! isset($s['create_intent_at'])) { return $out; }
        if (! array_key_exists('receipt', $s)) {
            $out['receipt_outcome'] = 'response_missing';
            return $out;
        }
        $out['receipt_outcome'] = 'unresolved_response';
        $r = $s['receipt'];
        if (! is_array($r) || (isset($r['raw']) && ! is_array($r['raw']))) { return $out; }
        $raw = $r['raw'] ?? [];
        $status = $raw['status'] ?? null;
        if ($status === 0) {
            $out['receipt_outcome'] = 'transport_unknown';
            return $out;
        }
        if (array_key_exists('status', $raw) && (! is_int($status) || $status < 100 || $status > 599)) { return $out; }
        $ref = $r['ref'] ?? null;
        $validRef = is_string($ref) && str_starts_with($ref, self::REGION.':')
            && self::uuid(substr($ref, strlen(self::REGION) + 1));
        if (($r['ok'] ?? false) === true && $validRef && ($status === null || ($status >= 200 && $status < 300))) {
            $out['receipt_outcome'] = 'accepted_response';
            $password = $r['root_password'] ?? null;
            if ($password === null) {
                $out['password_evidence'] = 'absent_from_validated_response';
            } elseif (is_string($password) && trim($password) !== '' && strlen($password) <= 4096
                && ! preg_match('/[\x00-\x1f\x7f]/', $password)) {
                $out['password_evidence'] = 'returned_unverified';
            } else {
                $out['password_evidence'] = 'invalid_in_response';
            }
        } elseif (($r['ok'] ?? null) === false && ($r['transient'] ?? null) === false
            && is_int($status) && $status >= 400 && $status < 500) {
            $out['receipt_outcome'] = 'rejected_response';
        }
        return $out;
    }

    public static function publicState(array $s): array
    {
        return [
            'test' => self::NAME, 'service_id' => $s['service_id'] ?? null,
            'instance_id' => $s['instance_id'] ?? null, 'create_attempted' => isset($s['create_intent_at']),
            'reference_preserved' => isset($s['ref']), 'password_returned' => ($s['password_returned'] ?? false) === true,
            'arvan_delivery' => 'unproven', 'provider_created_at' => $s['provider_created_at'] ?? null,
            'cleanup_at' => $s['cleanup_at'] ?? null, 'cleanup_heartbeat_at' => $s['cleanup_heartbeat_at'] ?? null,
            'cleanup_complete' => ($s['cleanup_complete'] ?? false) === true,
            'early_cleanup_requested' => isset($s['early_cleanup_requested_at']),
            'budget_cap_irt' => self::BUDGET, 'run_seconds' => self::RUN_SECONDS, 'budget_risk' => ($s['budget_risk'] ?? false) === true,
            'last_guard' => $s['last_guard'] ?? null,
        ] + self::receiptEvidence($s);
    }
}

final class OwnerWindowsDiagnostic28Run
{
    private array $state = [];
    private array $manifest = [];
    private string $manifestHash;
    private object $driver;

    private function save(): void
    {
        $file = OwnerWindowsDiagnostic28::DIR.'/state.enc';
        OwnerWindowsDiagnostic28::guard(! is_link($file), 'state_symlink');
        $tmp = $file.'.'.bin2hex(random_bytes(8)).'.tmp';
        $bytes = \Illuminate\Support\Facades\Crypt::encryptString(json_encode($this->state, JSON_THROW_ON_ERROR));
        OwnerWindowsDiagnostic28::guard(file_put_contents($tmp, $bytes, LOCK_EX) === strlen($bytes), 'state_write');
        chmod($tmp, 0600);
        OwnerWindowsDiagnostic28::guard(rename($tmp, $file), 'state_replace');
    }

    private function read(string $resource): array
    {
        OwnerWindowsDiagnostic28::guard(in_array($resource, ['servers', 'ports', 'volumes', 'volumes/os-volumes', 'images', 'sizes'], true)
            || preg_match('#^servers/[a-f0-9-]{36}$#D', $resource) === 1, 'read_path');
        return (new ReflectionMethod($this->driver, 'req'))->invoke($this->driver, 'GET',
            '/ecc/v1/regions/'.OwnerWindowsDiagnostic28::REGION.'/'.$resource, [], $resource === 'images' ? ['type' => 'distributions'] : []);
    }

    private function catalog(): object
    {
        $g = OwnerWindowsDiagnostic28::class;
        $owner = \App\Models\Customer::findOrFail($g::OWNER);
        $g::guard($owner->code === $g::OWNER_CODE, 'owner');
        $plan = \App\Models\CloudPlan::findOrFail($this->manifest['plan_id']);
        $g::guard($plan->provider === 'arvan' && $plan->provider_location === $g::REGION
            && $plan->provider_ref === $this->manifest['plan_ref'], 'plan_identity');
        foreach (['vcpu', 'ram_mb', 'disk_gb'] as $key) {
            $g::guard((int) $plan->{$key} === $this->manifest['shape'][$key], 'plan_shape');
        }
        $g::guard($plan->cpu_kind === $this->manifest['shape']['cpu_kind'], 'plan_cpu_kind');
        $image = \App\Models\CloudImage::where('provider', 'arvan')->where('key', $this->manifest['image_key'])->first();
        $g::guard($image !== null && strtolower((string) $image->family) === 'windows', 'windows_image');
        return $plan;
    }

    private function owner(): void
    {
        OwnerWindowsDiagnostic28::guard(\App\Models\Customer::findOrFail(OwnerWindowsDiagnostic28::OWNER)->code
            === OwnerWindowsDiagnostic28::OWNER_CODE, 'owner');
    }

    private function requireAttemptSchema(): void
    {
        $g = OwnerWindowsDiagnostic28::class;
        $schema = \Illuminate\Support\Facades\Schema::getColumnListing('arvan_create_attempts');
        $g::guard(array_diff(['operation_key', 'region', 'hostname', 'created_at'], $schema) === [], 'attempt_schema');
        $indexes = \Illuminate\Support\Facades\Schema::getIndexes('arvan_create_attempts');
        $g::guard(count(array_filter($indexes, static fn ($i) => ($i['unique'] ?? false)
            && ($i['columns'] ?? []) === ['operation_key'])) >= 1, 'attempt_unique_index');
        $this->requireOwnershipIndexes();
    }

    private function requireOwnershipIndexes(): void
    {
        $indexes = \Illuminate\Support\Facades\Schema::getIndexes('cloud_instances');
        OwnerWindowsDiagnostic28::guard(count(array_filter($indexes, static fn ($i) => ($i['unique'] ?? false)
            && ($i['columns'] ?? []) === ['service_id'])) >= 1, 'one_instance_unique_index');
        OwnerWindowsDiagnostic28::guard(count(array_filter($indexes, static fn ($i) =>
            ($i['columns'] ?? []) === ['provider', 'provider_ref'])) >= 1, 'reference_lock_index');
    }

    private function cronConfigured(): bool
    {
        $pipes = [];
        $p = proc_open(['/usr/bin/crontab', '-l'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($p)) {
            return false;
        }
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($p);
        return $exit === 0 && in_array(OwnerWindowsDiagnostic28::CRON, preg_split('/\r?\n/', $out), true);
    }

    private function inventories(?string $id = null): array
    {
        $g = OwnerWindowsDiagnostic28::class;
        $r = [];
        foreach (['servers', 'ports', 'volumes', 'volumes/os-volumes'] as $resource) {
            $r[$resource] = $g::rows($this->read($resource));
        }
        // Validate schemas even before creation, without assuming the region is empty.
        $id ??= '00000000-0000-0000-0000-000000000000';
        $r['target_ports'] = $g::relatedPorts($r['ports'], $id, $this->state['port_ids'] ?? [], $this->state['ips'] ?? []);
        foreach (['volumes', 'volumes/os-volumes'] as $resource) {
            $r['target_'.$resource] = $g::relatedVolumes($r[$resource], $id,
                $this->manifest['volume_schema'] ?? 'unverified', $this->state['volume_ids'] ?? []);
        }
        return $r;
    }

    private function captureResources(array $r): void
    {
        foreach ($r['target_ports'] as $port) {
            $this->state['port_ids'][] = $port['id'];
            foreach ($port['ips'] as $ip) { $this->state['ips'][] = $ip; }
        }
        foreach (['target_volumes', 'target_volumes/os-volumes'] as $key) {
            foreach ($r[$key] as $volume) { $this->state['volume_ids'][] = $volume['id']; }
        }
        foreach (['port_ids', 'ips', 'volume_ids'] as $key) {
            $this->state[$key] = array_values(array_unique($this->state[$key] ?? []));
        }
        $this->save();
    }

    private function reconcile(bool $attach): void
    {
        $g = OwnerWindowsDiagnostic28::class;
        $g::guard(isset($this->state['create_intent_at']), 'no_create_intent');
        $g::cleanupDeadline($this->state);
        $row = $g::exactName($g::rows($this->read('servers')));
        $g::guard($row !== null, 'accepted_resource_not_yet_resolved');
        $g::guard($g::uuid($row['id']), 'provider_id');
        $detail = $this->read('servers/'.$row['id']);
        $g::guard(($detail['ok'] ?? false) && ($detail['status'] ?? 0) === 200
            && is_array($detail['data'] ?? null) && ($detail['data']['id'] ?? null) === $row['id'], 'provider_detail_unreadable');
        $created = $g::serverIdentity($detail['data'], $this->state);
        $this->state['ref'] = $g::REGION.':'.$row['id'];
        $this->state['provider_created_at'] = $created;
        // Keep the original pre-POST deadline; delayed reconciliation cannot buy more runtime.
        $g::cleanupDeadline($this->state);
        $this->save();
        $this->captureResources($this->inventories($row['id']));
        if ($attach) { $this->attach(); }
    }

    private function exactService(): ?object
    {
        $g = OwnerWindowsDiagnostic28::class;
        $instances = \App\Models\CloudInstance::where('provider', 'arvan')->where('provider_ref', $this->state['ref'])->get();
        $g::guard($instances->count() <= 1, 'duplicate_attachment');
        if ($instances->isEmpty() || $instances->first()->service_id === null) {
            $g::guard(! isset($this->state['service_id']), 'attachment_disappeared');
            if (! $instances->isEmpty()) {
                $g::guard(blank(data_get($instances->first()->meta, 'public_domain')), 'unattached_gateway_present');
            }
            return null;
        }
        $i = $instances->first(); $s = $i->service;
        $g::guard($s !== null && (int) $s->customer_id === $g::OWNER && ! in_array((int) $s->id, [456, 491, 501], true)
            && ! $s->isInternalInfrastructure() && \App\Services\Cloud\CloudProvisioner::handles($s)
            && blank(data_get($i->meta, 'public_domain')) && $s->name === $g::NAME
            && (int) $s->price === 0 && ! $s->isHourly(), 'attachment_identity');
        $g::guard(\App\Models\CloudInstance::where('service_id', $s->id)->count() === 1, 'one_instance_per_service');
        $g::guard(! isset($this->state['service_id']) || ((int) $s->id === $this->state['service_id']
            && (int) $i->id === $this->state['instance_id']), 'attachment_changed');
        $g::guard(data_get($i->meta, 'owner_windows_diagnostic.name') === $g::NAME
            && data_get($i->meta, 'owner_windows_diagnostic.manifest_sha256') === $this->manifestHash, 'diagnostic_marker');
        $this->state['service_id'] = (int) $s->id; $this->state['instance_id'] = (int) $i->id;
        $this->save();
        return $s;
    }

    private function attach(): void
    {
        $g = OwnerWindowsDiagnostic28::class;
        if ($this->exactService() !== null) { return; }
        $this->catalog();
        \Illuminate\Support\Facades\DB::transaction(function () use ($g) {
            $request = \Illuminate\Http\Request::create('/admin/cloud/attach', 'POST', [
                'customer_id' => $g::OWNER, 'cloud_plan_id' => $this->manifest['plan_id'],
                'provider_ref' => $this->state['ref'], 'name' => $g::NAME, 'price' => 0,
                'cycle' => 'monthly', 'activated_at' => gmdate('Y-m-d', $this->state['provider_created_at']),
                'next_due_at' => now()->addMonth()->toDateString(),
            ]);
            $request->setLaravelSession(app('session.store'));
            $old = app('request'); app()->instance('request', $request);
            try { app(\App\Http\Controllers\Admin\CloudAttachController::class)->store($request); }
            finally { app()->instance('request', $old); }
            $i = \App\Models\CloudInstance::where('provider', 'arvan')->where('provider_ref', $this->state['ref'])->lockForUpdate()->first();
            $g::guard($i !== null && $i->service !== null && (int) $i->service->customer_id === $g::OWNER
                && $i->service->name === $g::NAME && (int) $i->service->price === 0
                && ! in_array((int) $i->service_id, [456, 491, 501], true), 'native_attachment_failed');
            $meta = $i->meta ?? [];
            $meta['owner_windows_diagnostic'] = ['name' => $g::NAME, 'manifest_sha256' => $this->manifestHash,
                'budget_cap_irt' => $g::BUDGET, 'cleanup_at' => $this->state['cleanup_at']];
            $i->forceFill(['image_key' => $this->manifest['image_key'], 'meta' => $meta])->save();
            $i->service->update(['cloud_image_key' => $this->manifest['image_key'], 'username' => 'Administrator']);
            // The native attach path has no password promise. Do not turn a diagnostic
            // receipt into delivered/verified credentials or overwrite a guest password.
            $this->audit($i->service, 'purchase', 'Owner-approved one-VM diagnostic attached; zero customer charge; automatic password delivery remains unproven.');
        });
        $g::guard($this->exactService() !== null, 'attachment_not_persisted');
    }

    private function audit(object $service, string $action, string $description): void
    {
        \App\Models\ActivityLog::forService($service, $action, $description, 'staff');
        OwnerWindowsDiagnostic28::guard(\App\Models\ActivityLog::where('service_id', $service->id)
            ->where('customer_id', OwnerWindowsDiagnostic28::OWNER)->where('action', $action)
            ->where('description', $description)->exists(), 'audit_not_persisted');
    }

    private function cleanup(bool $cron): void
    {
        $g = OwnerWindowsDiagnostic28::class;
        if ($cron) {
            $g::guard($this->cronConfigured(), 'dedicated_cron_missing');
            $this->state['cleanup_heartbeat_at'] = time(); $this->save();
        }
        if (! isset($this->state['create_intent_at']) || ($this->state['cleanup_complete'] ?? false)) { return; }
        if (! isset($this->state['ref'])) { $this->reconcile(false); }
        if (! $g::due($this->state, time())) { return; }
        $g::guard(is_string($this->state['ref']) && str_starts_with($this->state['ref'], $g::REGION.':'), 'cleanup_region');
        $id = substr($this->state['ref'], strlen($g::REGION) + 1);
        $g::guard($g::uuid($id), 'cleanup_reference');
        $before = $this->inventories($id); $this->captureResources($before);
        $detail = $this->read('servers/'.$id);
        $absent = ($detail['status'] ?? 0) === 404
            && count(array_filter($before['servers'], static fn ($s) => $s['id'] === $id)) === 0;
        if (! $absent) {
            $g::guard(($detail['ok'] ?? false) && is_array($detail['data'] ?? null), 'cleanup_detail');
            $g::serverIdentity($detail['data'], $this->state);
        }
        $service = $this->exactService();
        $this->state['cleanup_attempt_at'] = time(); $this->save();
        $this->requireOwnershipIndexes();
        if ($service !== null) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($service, $g) {
                // Preserve service -> instance lock order. Native termination reads
                // these same rows; no reassignment/ref/domain drift may pass between
                // identity verification and its destructive provider call.
                \App\Models\Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
                \App\Models\CloudInstance::where('service_id', $service->id)->lockForUpdate()->get();
                $locked = $this->exactService();
                $g::guard($locked !== null && $locked->id === $service->id, 'locked_attachment_changed');
                $this->audit($locked, 'terminate', 'Owner-approved exact diagnostic cleanup intent; '.self::class.'; '.gmdate('c'));
                $native = app(\App\Services\Provisioning\ProvisioningService::class);
                $locked->isDead() ? $native->releaseAndTrack($locked) : $native->terminate($locked);
            }, 1); // Never repeat an external DELETE automatically on transaction retry.
        } elseif (! $absent) {
            // Only an accepted, verified but unattached receipt may use direct cleanup.
            $g::guard(! isset($this->state['service_id']), 'no_unowned_delete');
            $this->requireUnattachedGapLock();
            \Illuminate\Support\Facades\DB::transaction(function () use ($g) {
                \App\Models\CloudInstance::where('provider', 'arvan')->where('provider_ref', $this->state['ref'])->lockForUpdate()->get();
                $g::guard($this->exactService() === null, 'attachment_appeared');
                $this->driver->deleteServer($this->state['ref']);
            }, 1);
        }
        $after = $this->inventories($id);
        $detail = $this->read('servers/'.$id);
        $resourcesGone = ($detail['status'] ?? 0) === 404
            && count(array_filter($after['servers'], static fn ($s) => $s['id'] === $id)) === 0
            && $after['target_ports'] === [] && $after['target_volumes'] === [] && $after['target_volumes/os-volumes'] === [];
        $localGone = true;
        if ($service !== null) {
            $service = $this->exactService(); $i = \App\Models\CloudInstance::findOrFail($this->state['instance_id']);
            $localGone = $service->isDead() && $service->provision_status === 'none' && $i->status === 'deleted'
                && filled(data_get($i->meta, 'provider_absence_verified_at'))
                && blank(data_get($i->meta, 'release_pending')) && blank($i->last_error);
            if ($resourcesGone && $localGone) {
                $this->audit($service, 'terminate', 'Exact diagnostic VM, dedicated disk and fixed-port absence independently verified; ledger and provider reference retained.');
            }
        } else {
            // Do not report a newly attached or inventory-imported ghost as complete.
            $localGone = ! \App\Models\CloudInstance::where('provider', 'arvan')->where('provider_ref', $this->state['ref'])->exists();
        }
        $this->state['cleanup_complete'] = $resourcesGone && $localGone;
        $this->state['last_cleanup_verification_at'] = time(); $this->save();
    }

    private function requireUnattachedGapLock(): void
    {
        $db = \Illuminate\Support\Facades\DB::connection();
        OwnerWindowsDiagnostic28::requireLockDatabaseDriver($db->getDriverName());
        try { $row = $db->selectOne('SELECT @@transaction_isolation AS isolation'); }
        catch (Throwable) { $row = $db->selectOne('SELECT @@tx_isolation AS isolation'); }
        OwnerWindowsDiagnostic28::guard(strtoupper((string) ($row->isolation ?? '')) === 'REPEATABLE-READ', 'unattached_gap_lock_isolation');
        $table = $db->selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['cloud_instances']);
        OwnerWindowsDiagnostic28::guard(strtolower((string) ($table->engine ?? '')) === 'innodb', 'unattached_gap_lock_engine');
    }

    public function run(string $mode, bool $cron): array
    {
        $g = OwnerWindowsDiagnostic28::class;
        $g::guard(realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === $g::SCRIPT, 'private_install_path');
        $g::guard(is_dir($g::DIR) && ! is_link($g::DIR) && (fileperms($g::DIR) & 0777) === 0700, 'private_directory');
        $manifestFile = $g::DIR.'/manifest.json';
        $g::guard(is_file($manifestFile) && ! is_link($manifestFile)
            && (fileperms($manifestFile) & 0777) === 0600 && filesize($manifestFile) <= 32768, 'private_manifest');
        $raw = file_get_contents($manifestFile);
        $this->manifest = $g::manifest(json_decode($raw, true, 32, JSON_THROW_ON_ERROR), time(), in_array($mode, ['prepare', 'create'], true));
        $this->manifestHash = hash('sha256', $raw);
        $stateFile = $g::DIR.'/state.enc';
        if (is_file($stateFile)) {
            $g::guard(! is_link($stateFile) && (fileperms($stateFile) & 0777) === 0600 && filesize($stateFile) <= 1048576, 'private_state');
            $this->state = json_decode(\Illuminate\Support\Facades\Crypt::decryptString(file_get_contents($stateFile)), true, 64, JSON_THROW_ON_ERROR);
            $g::guard(($this->state['manifest_sha256'] ?? '') === $this->manifestHash, 'manifest_changed');
            if (array_key_exists('create_intent_at', $this->state)) { $g::cleanupDeadline($this->state); }
        }
        $g::guard($mode === 'prepare' || $this->state !== [], 'prepare_required');
        $this->driver = app(\App\Services\Cloud\ArvanClient::class);
        try {
            if ($mode === 'status') { return $g::publicState($this->state); }
            // Cleanup must remain possible if a catalog row/image is later retired.
            $this->owner();
            if (in_array($mode, ['prepare', 'create'], true)) { $this->catalog(); }
            if ($mode === 'prepare') {
                $g::guard($this->state === [], 'already_prepared');
                $this->requireAttemptSchema();
                $this->requireUnattachedGapLock(); // Prove the fallback is usable before spending.
                $g::providerCatalog($this->read('sizes'), $this->read('images'), $this->manifest);
                $r = $this->inventories();
                $g::guard($g::exactName($r['servers']) === null, 'existing_test_name');
                $this->state = ['manifest_sha256' => $this->manifestHash, 'prepared_at' => time(), 'budget_cap_irt' => $g::BUDGET];
                $this->save();
            } elseif ($mode === 'create') {
                $g::guard(! isset($this->state['create_intent_at']), 'create_already_attempted_reconcile_only');
                $g::requireCleanupSchema($this->manifest);
                $g::guard($this->cronConfigured() && isset($this->state['cleanup_heartbeat_at'])
                    && is_int($this->state['cleanup_heartbeat_at']) && is_int($this->state['prepared_at'] ?? null)
                    && $this->state['cleanup_heartbeat_at'] >= $this->state['prepared_at']
                    && time() - $this->state['cleanup_heartbeat_at'] >= 0 && time() - $this->state['cleanup_heartbeat_at'] <= 180, 'scheduled_cleanup_not_verified');
                $this->requireAttemptSchema();
                $this->requireUnattachedGapLock();
                $g::providerCatalog($this->read('sizes'), $this->read('images'), $this->manifest);
                $r = $this->inventories();
                $g::guard($g::exactName($r['servers']) === null, 'existing_test_name');
                $g::guard(! \Illuminate\Support\Facades\DB::table('arvan_create_attempts')->where('region', $g::REGION)->where('hostname', $g::NAME)->exists(), 'existing_create_attempt');
                $spec = ['name' => $g::NAME, 'location_ref' => $g::REGION, 'plan_ref' => $this->manifest['plan_ref'],
                    'image_ref' => $this->manifest['image_id']] + $this->manifest['shape'];
                $resolvedFlavor = (new ReflectionMethod($this->driver, 'flavorForRegion'))->invoke($this->driver, $g::REGION, $spec['plan_ref'], $spec);
                $resolvedImage = (new ReflectionMethod($this->driver, 'imageForRegion'))->invoke($this->driver, $g::REGION, $spec['image_ref'], $spec);
                $g::guard($resolvedFlavor === $spec['plan_ref'] && $resolvedImage === $spec['image_ref'], 'resolved_catalog_drift');
                $g::manifest($this->manifest, time());
                $this->state = $g::createIntent($this->state, time());
                $this->save(); // Durable BEFORE the only allowed paid call, including on crash.
                $receipt = $this->driver->createServer($spec);
                $this->state['receipt'] = $receipt; // Encrypted; never emitted or logged here.
                $this->state['receipt_at'] = time();
                $this->state['password_returned'] = is_string($receipt['root_password'] ?? null) && $receipt['root_password'] !== '';
                if (is_string($receipt['ref'] ?? null) && str_starts_with($receipt['ref'], $g::REGION.':')
                    && $g::uuid(substr($receipt['ref'], strlen($g::REGION) + 1))) {
                    $this->state['ref'] = $receipt['ref'];
                }
                $this->save(); // Accepted reference survives any subsequent validation/attach failure.
                $this->reconcile(true);
            } elseif ($mode === 'reconcile') {
                $this->reconcile(! $g::due($this->state, time()));
            } elseif ($mode === 'request-early-cleanup') {
                $g::guard(isset($this->state['create_intent_at']), 'no_create_intent');
                $this->state['early_cleanup_requested_at'] = time(); $this->save();
                $this->cleanup(false);
            } elseif ($mode === 'cleanup') {
                $this->cleanup($cron);
            }
            unset($this->state['last_guard']);
        } catch (Throwable $e) {
            $reason = str_starts_with($e->getMessage(), 'guard:')
                ? preg_replace('/[^a-z0-9_:]/', '', $e->getMessage()) : 'operation_failed';
            if ($this->state !== []) { $this->state['last_guard'] = $reason; }
            if (isset($this->state['create_intent_at'])) {
                $this->state['budget_risk'] = time() >= ($this->state['cleanup_at'] ?? 0);
            }
            if ($this->state !== []) { $this->save(); }
            throw new RuntimeException($reason);
        }
        if (isset($this->state['create_intent_at'])) {
            $this->state['budget_risk'] = ! ($this->state['cleanup_complete'] ?? false)
                && time() >= ($this->state['cleanup_at'] ?? 0);
        }
        $this->save();
        return $g::publicState($this->state);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    ini_set('display_errors', '0'); umask(0077); date_default_timezone_set('UTC');
    $mode = $argv[1] ?? 'status'; $cron = ($argv[2] ?? '') === '--cron';
    $lock = null;
    try {
        OwnerWindowsDiagnostic28::guard(in_array($mode, ['prepare', 'status', 'create', 'reconcile', 'cleanup', 'request-early-cleanup'], true)
            && count($argv) <= ($cron ? 3 : 2) && (! $cron || $mode === 'cleanup'), 'mode');
        OwnerWindowsDiagnostic28::guard(realpath(__FILE__) === OwnerWindowsDiagnostic28::SCRIPT, 'private_install_path');
        OwnerWindowsDiagnostic28::guard(realpath(OwnerWindowsDiagnostic28::DIR) === OwnerWindowsDiagnostic28::DIR
            && (fileperms(OwnerWindowsDiagnostic28::DIR) & 0777) === 0700
            && (fileperms(__FILE__) & 0777) === 0600, 'private_install_permissions');
        $lockFile = OwnerWindowsDiagnostic28::DIR.'/lifecycle.lock';
        OwnerWindowsDiagnostic28::guard(! is_link($lockFile), 'lock_symlink');
        $lock = fopen($lockFile, 'c');
        OwnerWindowsDiagnostic28::guard(is_resource($lock) && flock($lock, LOCK_EX | LOCK_NB), 'exclusive_lock');
        chmod($lockFile, 0600);
        chdir(OwnerWindowsDiagnostic28::ROOT);
        require 'vendor/autoload.php'; $app = require 'bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $result = (new OwnerWindowsDiagnostic28Run())->run($mode, $cron);
        echo json_encode(['ok' => true, 'utc' => gmdate('c')] + $result, JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (Throwable $e) {
        $reason = preg_match('/^guard:[a-z0-9_]+$/D', $e->getMessage()) ? $e->getMessage() : 'operation_failed';
        echo json_encode(['ok' => false, 'reason' => $reason, 'arvan_delivery' => 'unproven', 'cleanup_complete' => false], JSON_THROW_ON_ERROR).PHP_EOL;
        exit(2);
    } finally {
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    }
}
