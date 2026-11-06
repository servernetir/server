<?php

namespace App\Services\Provisioning;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Attach an explicitly authorized, already existing WHM account. Never sells or provisions. */
final class ExistingHostingAdoption
{
    public function adopt(Customer $customer, Server $server, string $username, string $domain, User $actor, string $requestId): Service
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        $username = strtolower(trim($username));
        if (! $actor->exists || ! $actor->fresh()?->isAdmin() || ! $customer->exists || $customer->status !== 'active'
            || ! $server->exists || $server->type !== 'whm' || $server->status !== 'active'
            || ! preg_match('/\A[a-z][a-z0-9]{0,15}\z/D', $username)
            || ! filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || ! str_contains($domain, '.')
            || strlen($domain) > 190 || ! preg_match('/\A[a-zA-Z0-9._:-]{8,100}\z/D', $requestId)) {
            throw new \DomainException('existing_hosting_invalid_authorization_or_identity');
        }
        foreach (['billing_mode', 'cycle', 'provision_meta', 'provision_status', 'server_id', 'username', 'domain'] as $column) {
            if (! Schema::hasColumn('services', $column)) { throw new \DomainException('existing_hosting_schema_unavailable'); }
        }
        if (! Schema::hasTable('activity_logs') || ! Schema::hasColumn('activity_logs', 'service_id')) {
            throw new \DomainException('existing_hosting_audit_unavailable');
        }
        $lock = Cache::lock('hosting:existing-adoption:'.hash('sha256', $domain), 120);
        if (! $lock->get()) { throw new \DomainException('existing_hosting_adoption_busy'); }
        try {
            $serverFingerprint = $this->fingerprint($server);
            $response = (new WhmClient($server))->call('accountsummary', ['user' => $username]);
            $rows = $response['data']['acct'] ?? null;
            if (($response['ok'] ?? false) !== true || ! is_array($rows) || count($rows) !== 1 || ! is_array($rows[0] ?? null)) {
                throw new \DomainException('existing_hosting_account_unverified');
            }
            $account = $rows[0];
            if (strtolower((string) ($account['user'] ?? '')) !== $username
                || strtolower(rtrim((string) ($account['domain'] ?? ''), '.')) !== $domain
                || ! in_array($account['suspended'] ?? null, [0, '0', false], true)) {
                throw new \DomainException('existing_hosting_account_mismatch_or_suspended');
            }
            $plan = (string) ($account['plan'] ?? '');
            if (mb_strlen($plan) > 80 || preg_match('/[\x00-\x1f\x7f]/u', $plan)) {
                throw new \DomainException('existing_hosting_account_invalid_plan');
            }
            $observedAt = now()->toIso8601String();

            return DB::transaction(function () use ($customer, $server, $actor, $username, $domain, $requestId, $plan, $observedAt, $serverFingerprint) {
                // Serializes imports for this owner/server and rechecks all local bindings.
                $owner = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
                $target = Server::whereKey($server->id)->lockForUpdate()->firstOrFail();
                $operator = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                if ($owner->status !== 'active' || $target->type !== 'whm' || $target->status !== 'active'
                    || ! $operator->isAdmin() || $this->fingerprint($target) !== $serverFingerprint) {
                    throw new \DomainException('existing_hosting_identity_changed');
                }
                if (Schema::hasTable('domains') && Domain::whereRaw('LOWER(TRIM(domain)) = ?', [$domain])
                    ->where('customer_id', '!=', $owner->id)->exists()) {
                    throw new \DomainException('existing_hosting_domain_owner_conflict');
                }
                $bindings = Service::query()->where(function ($q) use ($target, $username, $domain) {
                    $q->whereRaw('LOWER(TRIM(domain)) = ?', [$domain])->orWhere(function ($w) use ($target, $username) {
                        $w->whereRaw('LOWER(TRIM(username)) = ?', [$username])
                            ->where(fn ($s) => $s->where('server_id', $target->id)->orWhereNull('server_id'));
                    });
                })->lockForUpdate()->get();
                if ($bindings->isNotEmpty()) {
                    $existing = $bindings->count() === 1 ? $bindings->first() : null;
                    if ($existing && (int) $existing->customer_id === (int) $owner->id
                        && (int) $existing->server_id === (int) $target->id && $existing->username === $username
                        && $existing->domain === $domain && $existing->billing_mode === Service::BILLING_EXTERNAL
                        && $existing->cycle === Service::BILLING_EXTERNAL && $existing->status === 'active'
                        && $existing->provision_status === 'done' && $existing->hasUnverifiedBillingTerms()
                        && data_get($existing->provision_meta, 'existing_hosting_adoption.version') === 1
                        && ! $existing->invoices()->exists()) {
                        return $existing; // No second row, audit event, invoice or upstream change.
                    }
                    throw new \DomainException('existing_hosting_service_binding_conflict');
                }
                $service = Service::create([
                    'customer_id' => $owner->id, 'created_by' => $actor->id,
                    'name' => 'cPanel — '.$domain, 'description' => __('ui.svc_existing_management_notice'),
                    'server_id' => $target->id, 'username' => $username, 'domain' => $domain, 'plan' => $plan ?: null,
                    'status' => 'active', 'provision_status' => 'done', 'is_reseller' => false,
                    // Structural values only. External mode and metadata make billing unknown.
                    'billing_mode' => Service::BILLING_EXTERNAL, 'cycle' => Service::BILLING_EXTERNAL,
                    'price' => 0, 'tax_percent' => 0, 'currency_code' => 'IRT',
                    'next_due_at' => null, 'activated_at' => null, 'provisioned_at' => null,
                    'provision_meta' => ['existing_hosting_adoption' => [
                        'version' => 1, 'billing_terms' => 'unverified', 'observed_at' => $observedAt,
                        'adopted_at' => now()->toIso8601String(), 'actor_id' => (int) $actor->id,
                        'request_id' => $requestId, 'source' => 'whm_accountsummary_exact_user_and_primary_domain',
                        'upstream_mutation_performed' => false,
                    ]],
                ]);
                ActivityLog::create([
                    'customer_id' => $owner->id, 'service_id' => $service->id, 'actor' => 'staff',
                    'action' => 'existing_hosting_adopted',
                    'description' => 'Existing hosting attached for management; billing terms unverified; actor #'.$actor->id.'; request '.$requestId,
                ]);
                return $service;
            });
        } finally { $lock->release(); }
    }

    private function fingerprint(Server $server): string
    {
        return hash('sha256', json_encode([$server->id, $server->hostname, $server->port, $server->username,
            $server->verify_tls, $server->getRawOriginal('api_token')], JSON_THROW_ON_ERROR));
    }
}
