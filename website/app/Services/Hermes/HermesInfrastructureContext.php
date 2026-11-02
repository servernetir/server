<?php

namespace App\Services\Hermes;

use App\Models\CloudInstance;
use App\Models\CloudPlan;
use App\Models\Setting;
use App\Services\Cloud\CloudManager;
use App\Services\Cloud\CloudSalesHealth;
use Throwable;

/** Owner-only application evidence. The Operations caller must enforce its current owner binding. */
final class HermesInfrastructureContext
{
    public const VERSION = 'infrastructure-context-v1-20261102';
    public const MAX_CHARS = 3500;
    public const MAX_PROVIDERS = 16;
    private const ALIASES = [
        'arvan' => ['arvan', 'آروان', 'ارو ان', 'ابرآروان', 'ابر آروان'],
        'hetzner' => ['hetzner', 'هتزنر'], 'hetzner-robot' => ['hetzner-robot', 'robot', 'ربات هتزنر'],
        'aeza' => ['aeza', 'آیزا', 'ایزا'], 'ovh' => ['ovh'], 'proxmox' => ['proxmox', 'پراکس'],
        'salad' => ['salad', 'سالاد'], 'lightnode' => ['lightnode', 'لایت نود'],
        'vpstc' => ['vpstc', 'vps.tc', 'vps tc', 'وی پی اس تی سی'],
    ];
    private const REASONS = ['available', 'admin_disabled', 'not_configured', 'stale_price_snapshot',
        'provider_quote_unverified', 'sales_preflight', 'no_active_plan', 'no_stock', 'no_valid_price',
        'no_orderable_app', 'account_currency_unverified', 'authentication_failed', 'connection_failed',
        'rate_limited', 'access_denied', 'supplier_cost_read_failed', 'missing_quote', 'invalid_quote'];

    /** No provider calls, credential export, settings change, billing or inventory reconciliation. */
    public function read(string $query = '', array $operations = []): array
    {
        $registry = array_slice(CloudManager::REAL_NAMES, 0, self::MAX_PROVIDERS, true);
        $focused = self::focused($query, $registry);
        try { $sales = app(CloudSalesHealth::class)->snapshot(); }
        catch (Throwable) { $sales = []; }
        try { $disabled = CloudPlan::disabledProviders(); }
        catch (Throwable) { $disabled = null; }
        $rows = [];
        foreach ($registry as $id => $label) {
            $row = ['sales' => $sales[$id] ?? null,
                'disabled' => is_array($disabled) ? in_array($id, $disabled, true) : null,
                'scan' => $operations['scans'][$id] ?? null];
            if (in_array($id, $focused, true)) {
                try {
                    $base = CloudPlan::query()->where('provider', $id);
                    $row['catalog'] = ['plans' => (clone $base)->count(),
                        'active' => (clone $base)->where('is_active', true)->count(),
                        'in_stock' => (clone $base)->where('in_stock', true)->count(),
                        'admin_disabled' => (clone $base)->where('admin_disabled', true)->count(),
                        'synced_at' => (clone $base)->max('synced_at'),
                        'regions' => (clone $base)->whereNotNull('location_code')->distinct()->orderBy('location_code')->limit(9)->pluck('location_code')->all()];
                    // Read only currently blocked rows. Never pass their raw notes into the prompt.
                    $row['quarantine_records'] = (clone $base)->where('admin_disabled', true)
                        ->whereNotNull('admin_note')->orderByDesc('updated_at')->limit(32)
                        ->get(['admin_disabled', 'admin_note', 'updated_at'])->map(fn ($plan) => [
                            'admin_disabled' => (bool) $plan->admin_disabled, 'note' => (string) $plan->admin_note,
                            'updated_at' => $plan->updated_at?->toIso8601String()])->all();
                } catch (Throwable) { $row['catalog'] = null; }
                try {
                    $base = CloudInstance::query()->where('provider', $id);
                    $row['inventory'] = ['tracked' => (clone $base)->count(), 'synced_at' => (clone $base)->max('synced_at'),
                        'by_status' => (clone $base)->selectRaw('status, COUNT(*) AS count')->groupBy('status')->limit(16)
                            ->get()->mapWithKeys(fn ($item) => [(string) $item->status => (int) $item->count])->all()];
                } catch (Throwable) { $row['inventory'] = null; }
                try {
                    $driver = app(CloudManager::class)->driver($id);
                    $row['configured'] = $driver?->isConfigured();
                    $row['capabilities'] = $driver && method_exists($driver, 'capabilities') ? $driver->capabilities() : [];
                    $row['hourly_enabled'] = $id === 'arvan' ? Setting::get('arvan_hourly_sales_enabled') === '1' : null;
                } catch (Throwable) { /* Missing local adapter evidence stays unknown. */ }
            }
            $rows[$id] = $row;
        }
        return self::project($rows, $query, time(), $registry);
    }

    /** Pure whitelist projection; useful independently of Laravel/database/network. */
    public static function project(array $records, string $query, int $now, array $registry): array
    {
        $registry = array_filter(array_slice($registry, 0, self::MAX_PROVIDERS, true),
            fn ($label, $id) => is_string($id) && preg_match('/^[a-z0-9-]{1,24}$/D', $id)
                && is_string($label) && mb_strlen($label) <= 60, ARRAY_FILTER_USE_BOTH);
        $focused = self::focused($query, $registry);
        // Put the specifically requested supplier ahead of the registry list.
        $order = array_unique([...$focused, ...array_keys($registry)]);
        $result = ['version' => self::VERSION, 'available' => $records !== [], 'audience' => 'owner_private',
            'checked_at' => $now, 'source' => 'application_database_and_saved_receipts',
            'provider_live_checked' => false, 'product_scope' => 'cloud_vps_gpu_sales',
            'read_paths' => ['sales' => 'platform_cloud_sales_health', 'catalog' => 'platform_cloud_plans',
                'inventory' => 'local_instances_and_saved_scan', 'live_quota' => 'not_integrated'],
            'providers' => [], 'omitted_providers' => 0];
        foreach ($order as $id) {
            $input = is_array($records[$id] ?? null) ? $records[$id] : [];
            $sales = is_array($input['sales'] ?? null) ? $input['sales'] : [];
            $reason = self::reason($sales['reason'] ?? null);
            $disabled = ($input['disabled'] ?? null) === true;
            if ($disabled && $reason === null) { $reason = 'admin_disabled'; }
            $row = ['id' => $id, 'label' => $registry[$id], 'sales' => [
                'state' => $disabled ? 'closed' : (($sales['ok'] ?? null) === true ? 'open' : (($sales['ok'] ?? null) === false ? 'closed' : 'unknown')),
                'reason_code' => $reason, 'offers' => self::count($sales['offers'] ?? null)]];
            if (in_array($id, $focused, true)) {
                $row = self::details($row, $input, $now);
            }
            $candidate = $result; $candidate['providers'][] = $row;
            if (self::length($candidate) <= self::MAX_CHARS - 32) { $result = $candidate; continue; }
            // Retain all suppliers' compact sales states before optional detail.
            foreach ($result['providers'] as &$existing) {
                if (! in_array($existing['id'], $focused, true)) { unset($existing['capabilities'], $existing['catalog'], $existing['inventory'], $existing['quota']); }
            }
            unset($existing);
            $compact = ['id' => $id, 'label' => $registry[$id], 'sales' => $row['sales']];
            $candidate = $result; $candidate['providers'][] = $compact;
            if (self::length($candidate) <= self::MAX_CHARS - 32) { $result = $candidate; }
            else { $result['omitted_providers']++; }
        }
        return $result;
    }

    private static function details(array $row, array $input, int $now): array
    {
        $catalog = is_array($input['catalog'] ?? null) ? $input['catalog'] : [];
        $regions = array_values(array_unique(array_filter((array) ($catalog['regions'] ?? []),
            fn ($code) => is_string($code) && preg_match('/^[a-z0-9_-]{1,48}$/D', $code))));
        $row['sales']['disabled'] = is_bool($input['disabled'] ?? null) ? $input['disabled'] : null;
        $row['sales']['hourly_enabled'] = is_bool($input['hourly_enabled'] ?? null) ? $input['hourly_enabled'] : null;
        $row['configured'] = is_bool($input['configured'] ?? null) ? $input['configured'] : null;
        $row['connected'] = null;
        $row['catalog'] = ['plans' => self::count($catalog['plans'] ?? null), 'active' => self::count($catalog['active'] ?? null),
            'in_stock' => self::count($catalog['in_stock'] ?? null), 'admin_disabled' => self::count($catalog['admin_disabled'] ?? null),
            'synced_at' => self::timestamp($catalog['synced_at'] ?? null, $now), 'regions' => array_slice($regions, 0, 6)];
        $causes = []; $updated = null; $sampled = 0;
        foreach (array_slice((array) ($input['quarantine_records'] ?? []), 0, 32) as $record) {
            if (! is_array($record) || ($record['admin_disabled'] ?? null) !== true || ! is_string($record['note'] ?? null)) { continue; }
            $sampled++;
            $note = mb_strtolower(mb_substr($record['note'], 0, 500));
            if (! str_starts_with($note, 'خودکار بسته شد:')) { continue; }
            $code = match (true) {
                str_contains($note, 'you have reached ram size limit') => 'account_ram_limit',
                str_contains($note, 'quota'), str_contains($note, 'resource_limit'), str_contains($note, 'limit reached') => 'account_quota',
                str_contains($note, 'balance'), str_contains($note, 'insufficient'), str_contains($note, 'payment') => 'account_credit',
                str_contains($note, 'unauthor'), str_contains($note, 'permission'), str_contains($note, 'forbidden') => 'provider_permission',
                str_contains($note, 'firewall'), str_contains($note, 'security group') => 'firewall_configuration',
                str_contains($note, 'proxy internal server error'), str_contains($note, 'proxy_internal_server_error') => 'upstream_gateway',
                default => 'automatic_quarantine',
            };
            $causes[$code] = true;
            $date = self::timestamp($record['updated_at'] ?? null, $now);
            if ($date !== null) { $updated = max($updated ?? 0, $date); }
        }
        $row['sales']['quarantine_reason_codes'] = array_keys($causes);
        $row['sales']['quarantine_reason_scope'] = 'sample_of_current_blocked_rows';
        $row['sales']['quarantine_records_sampled'] = $sampled;
        $blocked = self::count($catalog['admin_disabled'] ?? null);
        $row['sales']['quarantine_sample_complete'] = $blocked !== null ? $sampled >= $blocked : null;
        $row['sales']['quarantine_record_updated_at'] = $updated;
        if (($row['sales']['disabled'] ?? null) === true && $row['sales']['state'] === 'unknown') { $row['sales']['state'] = 'closed'; }
        $row['quota'] = ['status' => isset($causes['account_ram_limit']) ? 'recorded_ram_quota_refusal'
            : (isset($causes['account_quota']) ? 'recorded_quota_refusal' : 'unknown'),
            'limit_mib' => null, 'used_mib' => null, 'headroom_mib' => null, 'provider_observed_at' => null];
        $inventory = is_array($input['inventory'] ?? null) ? $input['inventory'] : [];
        $states = [];
        foreach (['running', 'active', 'off', 'stopped', 'paused', 'building', 'pending', 'starting', 'stopping',
            'rebuilding', 'suspended', 'deleting', 'deleted', 'error', 'failed', 'unknown'] as $state) {
            if (self::count($inventory['by_status'][$state] ?? null) !== null) { $states[$state] = $inventory['by_status'][$state]; }
        }
        $scan = is_array($input['scan'] ?? null) ? $input['scan'] : [];
        $at = self::timestamp($scan['at'] ?? null, $now);
        $row['inventory'] = ['source' => 'local_cloud_instances', 'tracked' => self::count($inventory['tracked'] ?? null),
            'by_status' => $states, 'synced_at' => self::timestamp($inventory['synced_at'] ?? null, $now),
            'scan_at' => $at, 'scan_complete' => $at !== null && is_bool($scan['complete'] ?? null) ? $scan['complete'] : null,
            'scan_stale' => $at === null || $now - $at > 7200];
        $capabilities = [];
        foreach (['console', 'rebuild', 'resize', 'snapshot', 'metrics', 'reset_password', 'ipv6', 'rescue', 'ssh_key', 'extra_ip'] as $name) {
            if (is_bool($input['capabilities'][$name] ?? null)) { $capabilities[$name] = $input['capabilities'][$name]; }
        }
        $row['capabilities'] = $capabilities;
        return $row;
    }

    private static function focused(string $query, array $registry): array
    {
        $query = mb_strtolower(str_replace(['ي', 'ك', '‌'], ['ی', 'ک', ' '], mb_substr($query, 0, 4000)));
        $ids = [];
        foreach ($registry as $id => $label) {
            foreach (self::ALIASES[$id] ?? [$id] as $alias) {
                if (str_contains($query, $alias)) { $ids[] = $id; break; }
            }
        }
        if (in_array('hetzner-robot', $ids, true)) { $ids = array_values(array_diff($ids, ['hetzner'])); }
        return array_slice($ids, 0, 2);
    }

    private static function reason(mixed $reason): ?string
    {
        return is_string($reason) && (in_array($reason, self::REASONS, true) || preg_match('/^upstream_http_5[0-9]{2}$/D', $reason)) ? $reason : null;
    }

    private static function count(mixed $value): ?int { return is_int($value) && $value >= 0 ? $value : null; }

    private static function timestamp(mixed $value, int $now): ?int
    {
        if (is_int($value)) { return $value > 0 && $value <= $now ? $value : null; }
        if (! is_string($value) || strlen($value) > 40) { return null; }
        $at = strtotime($value);
        return $at !== false && $at > 0 && $at <= $now ? $at : null;
    }

    private static function length(array $data): int { return mb_strlen(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
}
