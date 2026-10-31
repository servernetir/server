<?php

namespace App\Services\Domain;

use App\Models\Domain;
use App\Models\DomainSupplierAccount;
use App\Models\DomainSupplierOperation;
use App\Models\DomainSupplierContact;
use App\Services\Domain\Providers\DomainProviderFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Account-bound management. No procurement, transfer, contact creation or DNS provisioning. */
class DomainManagement
{
    public function __construct(private OpenProviderClient $legacy, private DomainProviderFactory $providers) {}

    public static function legacyCompatible(Domain $domain): bool
    {
        // New normalized orders on the legacy login still use their real supplier_object_id.
        if ($domain->supplier_quote) return false;
        if (! in_array(strtolower((string) $domain->registrar), ['', 'openprovider'], true)) {
            return false;
        }
        if (! $domain->supplier_account_id) {
            return \App\Services\Domain\Providers\LegacyAccountIdentity::unboundCompatible();
        }
        $account = DomainSupplierAccount::find($domain->supplier_account_id);
        return $account !== null && $account->provider === 'openprovider'
            && $account->environment === 'production' && $account->legacy_config
            && \App\Services\Domain\Providers\LegacyAccountIdentity::matches($account);
    }

    public function registered(Domain $domain): bool
    {
        return self::legacyCompatible($domain) ? (int) $domain->op_id > 0
            : filled($domain->supplier_account_id) && filled($domain->supplier_object_id);
    }

    public function manage(Domain $domain, string $action, array $payload = []): array
    {
        if (! $this->registered($domain)) {
            return $this->failed('domain_not_linked');
        }
        try {
            if (self::legacyCompatible($domain)) {
                $response = match ($action) {
                    'nameservers' => $this->legacy->setNameServers((int) $domain->op_id, $payload['nameservers'] ?? []),
                    'lock' => $this->legacy->setLock((int) $domain->op_id, (bool) ($payload['locked'] ?? true)),
                    'auth_code' => $this->legacy->authCode((int) $domain->op_id),
                    default => $this->failed('unsupported_operation'),
                };
                // The EPP remains only in the transient response; no journal/log serialization.
                return ['ok' => (bool) ($response['ok'] ?? false), 'data' => isset($response['auth_code'])
                    ? ['auth_code' => $response['auth_code']] : [],
                    'auth_code' => $response['auth_code'] ?? null,
                    'error_code' => ($response['ok'] ?? false) ? null : 'provider_rejected'];
            }
            if (! config('domain_suppliers.enabled', false)) {
                return $this->failed('supplier_feature_disabled');
            }
            // OpenProvider authcode is a GET. Joker's generation is async and
            // remains unavailable until secure one-time secret retrieval exists.
            if ($action === 'auth_code') {
                $account = $this->account($domain);
                if ($account->provider !== 'openprovider') return $this->failed('unsupported_operation');
                $provider = $this->providers->forAccount($account);
                $found = $provider->find($domain->domain);
                if (($found['found'] ?? null) !== true || (string) ($found['object_id'] ?? '') !== (string) $domain->supplier_object_id) {
                    return $this->failed('portfolio_mismatch');
                }
                $response = $provider->manage($domain->domain, $domain->supplier_object_id, $action);
                return ['ok' => ($response['ok'] ?? false) === true, 'data' => [],
                    'auth_code' => data_get($response, 'data.auth_code'), 'error_code' => null];
            }
            return $this->journaledManage($domain, $action, $payload);
        } catch (\Throwable $e) {
            Log::warning('Domain management unavailable', ['domain_id' => $domain->id,
                'account_id' => $domain->supplier_account_id, 'action' => $action, 'exception_type' => get_class($e)]);
            return $this->failed('provider_unavailable');
        }
    }

    /** All mutating account-bound management shares the financial FQDN lock. */
    private function journaledManage(Domain $domain, string $action, array $payload): array
    {
        if (! Schema::hasTable('domain_supplier_operations')) return $this->failed('supplier_schema_unavailable');
        if (! in_array($action, ['nameservers', 'lock', 'contacts', 'dnssec'], true)) return $this->failed('unsupported_operation');
        $lock = Cache::lock(DomainSupplierLifecycle::domainLockKey($domain->domain), 180);
        if (! $lock->get()) return $this->pending('operation_busy');
        try {
            $domain->refresh();
            $account = $this->account($domain);
            $payload = $this->payload($action, $payload, $domain, $account);
            $operation = 'manage_'.$action;
            $context = ['action' => $action, 'payload' => $payload, 'customer_id' => (int) $domain->customer_id,
                'domain_id' => (int) $domain->id, 'fqdn' => strtolower($domain->domain),
                'account_id' => (int) $account->id, 'object_id' => (string) $domain->supplier_object_id];
            $hash = hash('sha256', json_encode($context, JSON_THROW_ON_ERROR));
            $unresolved = DomainSupplierOperation::where('fqdn', strtolower($domain->domain))
                ->whereNotIn('status', ['succeeded', 'failed'])->orderBy('id')->first();
            if ($unresolved) {
                if ($unresolved->operation === $operation && (int) $unresolved->domain_id === (int) $domain->id
                    && (int) $unresolved->supplier_account_id === (int) $account->id
                    && hash_equals((string) $unresolved->request_hash, $hash)) {
                    return $this->reconcileLocked($unresolved, $domain, $account);
                }
                return $this->pending('another_operation_unresolved');
            }
            $previous = DomainSupplierOperation::where('domain_id', $domain->id)->where('supplier_account_id', $account->id)
                ->where('operation', $operation)->orderByDesc('id')->first();
            $provider = $this->providers->forAccount($account);
            if ($previous && hash_equals((string) $previous->request_hash, $hash)) {
                if ($previous->status !== 'succeeded') return $this->failed('management_manual_review');
                // The old operation remains a historical success. Verify the
                // current state before treating a repeated settings POST as done.
                // Only a proven later drift permits this explicit reapplication.
                try {
                    $current = $provider->reconcile(['operation' => $previous->operation, 'status' => $previous->status, 'fqdn' => $previous->fqdn,
                        'object_id' => $previous->object_id, 'process_id' => $previous->process_id,
                        'tracking_id' => $previous->tracking_id, 'request_context' => (array) $previous->request_context]);
                } catch (\Throwable) {
                    $current = ['status' => 'unknown'];
                }
                if (($current['status'] ?? '') === 'succeeded' && (string) ($current['object_id'] ?? '') === (string) $domain->supplier_object_id) return $this->success();
                if (($current['status'] ?? '') !== 'failed' || ($current['error_code'] ?? '') !== 'management_state_drifted') {
                    return $this->pending('management_current_state_unknown', 'unknown');
                }
            }
            $found = $provider->find($domain->domain);
            if (($found['found'] ?? null) !== true || (string) ($found['object_id'] ?? '') !== (string) $domain->supplier_object_id) {
                return $this->failed('portfolio_mismatch');
            }
            $op = DB::transaction(function () use ($domain, $account, $operation, $context, $hash) {
                $fresh = Domain::whereKey($domain->id)->lockForUpdate()->firstOrFail();
                if (! $this->bindingMatches($fresh, $account, $context)) throw new \RuntimeException('management_binding_changed');
                return DomainSupplierOperation::create([
                    'domain_id' => $domain->id, 'invoice_id' => null, 'supplier_account_id' => $account->id,
                    'operation' => $operation, 'idempotency_key' => 'domain-management:'.bin2hex(random_bytes(24)),
                    'fqdn' => strtolower($domain->domain), 'active_fqdn' => strtolower($domain->domain),
                    'period_months' => 0, 'status' => 'submitting',
                    'object_id' => (string) $domain->supplier_object_id, 'request_hash' => $hash,
                    'request_context' => $context, 'attempts' => 1, 'sent_at' => now(),
                ]);
            });
            // The committed submitting row survives crashes/timeouts. Never retry here.
            try {
                $result = $provider->manage($domain->domain, (string) $domain->supplier_object_id, $action, $payload);
            } catch (\Throwable) {
                $result = ['status' => 'unknown', 'error_code' => 'management_result_unknown'];
            }
            return $this->applyResult($op, $domain, $account, $result);
        } finally {
            $lock->release();
        }
    }

    /** Read-only reconciliation is allowed even when new sales/mutations are off. */
    public function reconcile(DomainSupplierOperation $operation): array
    {
        $lock = Cache::lock(DomainSupplierLifecycle::domainLockKey($operation->fqdn), 180);
        if (! $lock->get()) return $this->pending('operation_busy');
        try {
            $op = $operation->fresh();
            $domain = Domain::findOrFail($op->domain_id);
            $account = DomainSupplierAccount::findOrFail($op->supplier_account_id);
            return $this->reconcileLocked($op, $domain, $account);
        } catch (\Throwable) {
            return $this->pending('management_reconciliation_unknown', 'unknown');
        } finally {
            $lock->release();
        }
    }

    private function reconcileLocked(DomainSupplierOperation $op, Domain $domain, DomainSupplierAccount $account): array
    {
        $context = (array) $op->request_context;
        if (! str_starts_with($op->operation, 'manage_') || ! $this->bindingMatches($domain, $account, $context)
            || ! hash_equals((string) $op->request_hash, hash('sha256', json_encode($context, JSON_THROW_ON_ERROR)))) {
            $op->forceFill(['status' => 'manual_review', 'error_code' => 'management_binding_changed', 'reconciled_at' => now()])->save();
            return $this->failed('management_binding_changed', 'manual_review');
        }
        if ($op->status === 'succeeded') return $this->success();
        if (in_array($op->status, ['failed', 'manual_review'], true)) return $this->failed('management_manual_review', $op->status);
        try {
            $result = $this->providers->forAccount($account)->reconcile([
                'operation' => $op->operation, 'status' => $op->status, 'fqdn' => $op->fqdn,
                'object_id' => $op->object_id, 'process_id' => $op->process_id,
                'tracking_id' => $op->tracking_id, 'request_context' => $context,
            ]);
        } catch (\Throwable) {
            $result = ['status' => 'unknown', 'error_code' => 'management_reconciliation_unknown'];
        }
        return $this->applyResult($op, $domain, $account, $result);
    }

    private function applyResult(DomainSupplierOperation $operation, Domain $domain, DomainSupplierAccount $account, array $result): array
    {
        $state = (string) ($result['status'] ?? (($result['ok'] ?? false) === true ? 'succeeded' : 'unknown'));
        if (! in_array($state, ['queued', 'unknown', 'succeeded', 'failed', 'manual_review'], true)) $state = 'unknown';
        return DB::transaction(function () use ($operation, $domain, $account, $result, $state) {
            $op = DomainSupplierOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            $fresh = Domain::whereKey($domain->id)->lockForUpdate()->firstOrFail();
            $context = (array) $op->request_context;
            if (! $this->bindingMatches($fresh, $account, $context)) $state = 'manual_review';
            if (isset($result['object_id']) && (string) $result['object_id'] !== (string) $op->object_id) $state = 'unknown';
            $safeCode = in_array($state, ['succeeded', 'queued'], true) ? null : 'management_review_required';
            $refs = [];
            foreach (['process_id', 'tracking_id'] as $field) {
                if (is_string($result[$field] ?? null) && preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D', $result[$field])) $refs[$field] = $result[$field];
            }
            $op->forceFill($refs + ['status' => $state, 'active_fqdn' => in_array($state, ['succeeded', 'failed'], true) ? null : $op->fqdn,
                'error_code' => $safeCode, 'reconciled_at' => now()])->save();
            if ($state === 'succeeded') {
                $payload = (array) ($context['payload'] ?? []);
                // Only verified completion updates the customer-visible state;
                // reconciliation never creates zones, contacts, invoices or payments.
                if ($context['action'] === 'nameservers') $fresh->forceFill(['name_servers' => $payload['nameservers']])->save();
                if ($context['action'] === 'lock') $fresh->forceFill(['is_locked' => $payload['locked']])->save();
                if ($context['action'] === 'dnssec') $fresh->putMeta(['dnssec_keys' => $payload['keys']]);
                return $this->success();
            }
            return in_array($state, ['queued', 'unknown'], true) ? $this->pending('management_pending', $state)
                : $this->failed('management_manual_review', $state);
        });
    }

    private function bindingMatches(Domain $domain, DomainSupplierAccount $account, array $context): bool
    {
        return (int) $domain->supplier_account_id === (int) $account->id
            && (int) ($context['account_id'] ?? 0) === (int) $account->id
            && (int) ($context['domain_id'] ?? 0) === (int) $domain->id
            && (int) ($context['customer_id'] ?? 0) === (int) $domain->customer_id
            && strtolower((string) ($context['fqdn'] ?? '')) === strtolower($domain->domain)
            && (string) ($context['object_id'] ?? '') === (string) $domain->supplier_object_id
            && $account->provider === $domain->registrar && $account->environment === 'production';
    }

    /** Whitelist deterministic payloads; no owner change, arbitrary API arguments or secrets. */
    private function payload(string $action, array $payload, Domain $domain, DomainSupplierAccount $account): array
    {
        if ($action === 'lock') {
            if (! is_bool($payload['locked'] ?? null)) throw new \InvalidArgumentException;
            return ['locked' => $payload['locked']];
        }
        if ($action === 'nameservers') {
            $names = array_values((array) ($payload['nameservers'] ?? []));
            if (count($names) < 2 || count($names) > 13) throw new \InvalidArgumentException;
            $out = [];
            foreach ($names as $name) {
                if (! is_string($name)) throw new \InvalidArgumentException;
                $name = strtolower(rtrim($name, '.'));
                if (! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $name)) throw new \InvalidArgumentException;
                $out[] = $name;
            }
            $out = array_values(array_unique($out)); sort($out);
            if (count($out) < 2) throw new \InvalidArgumentException;
            return ['nameservers' => $out];
        }
        if ($action === 'contacts') {
            $profile = $domain->customer?->defaultProfile();
            if (! $profile || ! Schema::hasTable('domain_supplier_contacts')) throw new \InvalidArgumentException;
            $handles = [];
            foreach (['admin', 'tech', 'billing'] as $role) {
                if (isset($payload['handles'][$role])) {
                    $handle = $payload['handles'][$role];
                    if (! is_string($handle) || ! preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', $handle)) throw new \InvalidArgumentException;
                    $owned = DomainSupplierContact::where('supplier_account_id', $account->id)
                        ->where('customer_profile_id', $profile->id)->whereIn('role', [$role, 'registrant'])
                        ->where('handle', $handle)->where('status', 'active')->whereNotNull('verified_at')->where('verified_at', '<=', now())->exists();
                    if (! $owned) throw new \InvalidArgumentException;
                    $handles[$role] = $handle;
                }
            }
            if ($handles === []) throw new \InvalidArgumentException;
            return ['handles' => $handles];
        }
        $keys = array_values((array) ($payload['keys'] ?? []));
        if (count($keys) > 6) throw new \InvalidArgumentException;
        $out = [];
        foreach ($keys as $key) {
            if (! is_array($key)) throw new \InvalidArgumentException;
            $type = $key['record_type'] ?? 'DS';
            if ($type === 'DNSKEY') {
                if (! is_int($key['algorithm'] ?? null) || $key['algorithm'] < 1 || $key['algorithm'] > 255
                    || ! in_array($key['flags'] ?? null, [256, 257], true) || ($key['protocol'] ?? null) !== 3
                    || ! is_string($key['public_key'] ?? null) || strlen($key['public_key']) > 8192
                    || ! preg_match('/^[A-Za-z0-9+\/=]{8,8192}$/D', $key['public_key'])
                    || ($decoded = base64_decode($key['public_key'], true)) === false) throw new \InvalidArgumentException;
                $out[] = ['record_type' => 'DNSKEY', 'algorithm' => $key['algorithm'], 'flags' => $key['flags'],
                    'protocol' => 3, 'public_key' => base64_encode($decoded)];
                continue;
            }
            if ($type !== 'DS') throw new \InvalidArgumentException;
            foreach (['tag', 'algorithm', 'digest_type'] as $field) {
                if (! isset($key[$field]) || ! is_int($key[$field]) || $key[$field] < 0 || $key[$field] > ($field === 'tag' ? 65535 : 255)) throw new \InvalidArgumentException;
            }
            $length = [1 => 40, 2 => 64, 4 => 96][$key['digest_type']] ?? 0;
            if ($key['algorithm'] < 1 || $length === 0 || ! is_string($key['digest'] ?? null)
                || strlen($key['digest']) !== $length || ! ctype_xdigit($key['digest'])) throw new \InvalidArgumentException;
            $out[] = ['record_type' => 'DS', 'tag' => $key['tag'], 'algorithm' => $key['algorithm'],
                'digest_type' => $key['digest_type'], 'digest' => strtoupper($key['digest'])];
        }
        usort($out, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
        return ['keys' => $out];
    }

    private function success(): array { return ['ok' => true, 'status' => 'succeeded', 'data' => [], 'auth_code' => null, 'error_code' => null]; }
    private function pending(string $reason, string $state = 'queued'): array { return $this->failed($reason, $state); }

    public function find(Domain $domain): array
    {
        try {
            if (self::legacyCompatible($domain)) {
                if (! $domain->op_id) {
                    return ['found' => null];
                }
                $response = $this->legacy->getDomain((int) $domain->op_id);
                return ['found' => ($response['ok'] ?? false) ? true : null,
                    'object_id' => (string) $domain->op_id,
                    'expires_at' => data_get($response, 'data.expiration_date') ?: data_get($response, 'data.expiration_date_time')];
            }
            if (! config('domain_suppliers.enabled', false)) {
                return ['found' => null];
            }
            $found = $this->providers->forAccount($this->account($domain))->find($domain->domain);
            if (($found['found'] ?? null) === true && (string) ($found['object_id'] ?? '') !== (string) $domain->supplier_object_id) {
                return ['found' => null];
            }
            return $found;
        } catch (\Throwable) {
            return ['found' => null];
        }
    }

    private function account(Domain $domain): DomainSupplierAccount
    {
        $account = DomainSupplierAccount::findOrFail($domain->supplier_account_id);
        if ($account->provider !== $domain->registrar || $account->environment !== 'production' || ! $account->active) {
            throw new \RuntimeException('supplier_account_mismatch');
        }
        return $account;
    }

    private function failed(string $reason, string $state = 'failed'): array
    {
        return ['ok' => false, 'status' => $state, 'error_code' => $reason, 'data' => [], 'auth_code' => null];
    }
}
