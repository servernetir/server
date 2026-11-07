<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Domain;
use App\Models\DomainSupplierAccount;
use App\Services\Domain\Providers\DomainProviderFactory;
use App\Services\Domain\DomainSupplierLifecycle;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/** Attach only a domain proven to be in the specified real account. No remote writes. */
class DomainSupplierLink extends Command
{
    protected $signature = 'domains:supplier-link
        {--account= : Exact supplier account ID}
        {--domain= : Exact domain name}
        {--customer= : Exact customer ID or code}
        {--confirm : Persist the verified link; default is read-only preview}';

    protected $description = 'Preview or attach an existing domain from an authoritative supplier account portfolio';

    public function handle(DomainProviderFactory $providers): int
    {
        if (! Schema::hasTable('domain_supplier_accounts') || ! Schema::hasColumn('domains', 'supplier_account_id')) {
            $this->error('Domain supplier schema is not installed.');
            return self::FAILURE;
        }

        $accountId = (string) $this->option('account');
        $identifier = (string) $this->option('customer');
        $fqdn = strtolower(trim((string) $this->option('domain'), " \t\r\n."));
        if (! ctype_digit($accountId) || $identifier === '' || strlen($fqdn) > 253
            || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $fqdn)) {
            $this->error('Provide exact --account, --domain (ASCII/IDN A-label) and --customer.');
            return self::FAILURE;
        }
        $account = DomainSupplierAccount::find((int) $accountId);
        $customer = ctype_digit($identifier) ? Customer::find((int) $identifier)
            : Customer::where('code', $identifier)->first();
        if (! $account || ! $customer || ! $account->active || $account->environment !== 'production') {
            $this->error('An active production supplier account and existing customer are required.');
            return self::FAILURE;
        }

        $lock = Cache::lock(DomainSupplierLifecycle::domainLockKey($fqdn), 180);
        if (! $lock->get()) {
            $this->error('Another domain operation is in progress. Repeat the preview after it is reconciled.');
            return self::FAILURE;
        }
        try {
            // find() must inspect this account's portfolio, not RDAP or a public registrar name.
            $found = $providers->forAccount($account)->find($fqdn);
            if (($found['found'] ?? null) !== true || blank($found['object_id'] ?? null)
                || blank($found['expires_at'] ?? null)) {
                $this->error('The specified account did not prove a domain object and its expiry. Nothing was linked.');
                return self::FAILURE;
            }
            $expiry = Carbon::parse((string) $found['expires_at']);
            $objectId = (string) $found['object_id'];
            if ($account->provider === 'openprovider' && (! ctype_digit($objectId) || (int) $objectId <= 0)) {
                $this->error('The supplier returned an invalid domain object identity.');
                return self::FAILURE;
            }

            $rows = Domain::where('domain', $fqdn)->get();
            $this->validateLink($rows, $account, $customer, $objectId);
            $this->line('Portfolio verification succeeded. Account='.$account->id.'; customer='.$customer->id
                .'; domain='.$fqdn.'; expiry='.$expiry->toDateString().'.');
            if (! $this->option('confirm')) {
                $this->line('Preview only. Repeat the same command with --confirm to persist this exact link.');
                return self::SUCCESS;
            }

            DB::transaction(function () use ($account, $customer, $fqdn, $expiry, $objectId) {
                $freshAccount = DomainSupplierAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
                if (! $freshAccount->active || $freshAccount->environment !== 'production'
                    || $freshAccount->updated_at?->toISOString() !== $account->updated_at?->toISOString()) {
                    throw new \RuntimeException('account_changed_repeat_preview');
                }
                // Serialize absence of the domain as well as an existing row against other link requests.
                Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
                $rows = Domain::where('domain', $fqdn)->lockForUpdate()->get();
                $this->validateLink($rows, $freshAccount, $customer, $objectId);
                $domain = $rows->first();
                if ($domain) {
                    $values = ['supplier_account_id' => $freshAccount->id, 'supplier_object_id' => $objectId,
                        'registrar' => $freshAccount->provider];
                    if ($domain->expires_at === null) {
                        $values['expires_at'] = $expiry;
                    }
                    if ($freshAccount->provider === 'openprovider' && ! $domain->op_id) {
                        $values['op_id'] = (int) $objectId;
                    }
                    $domain->forceFill($values)->save();
                } else {
                    [$sld, $tld] = Domain::splitFqdn($fqdn);
                    Domain::create(['customer_id' => $customer->id, 'domain' => $fqdn, 'sld' => $sld, 'tld' => $tld,
                        'registrar' => $freshAccount->provider, 'supplier_account_id' => $freshAccount->id,
                        'supplier_object_id' => $objectId, 'op_id' => $freshAccount->provider === 'openprovider' ? (int) $objectId : null,
                        'status' => $expiry->isFuture() ? 'active' : 'expired', 'expires_at' => $expiry,
                        'provision_status' => 'done', 'auto_renew' => false,
                        'meta' => ['source' => 'verified_supplier_link', 'portfolio_verified_at' => now()->toISOString()]]);
                }
            });
            $this->info('Verified domain link saved.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            // Deliberately exclude raw provider responses, contact data, keys and session IDs.
            $this->error('Link could not be completed safely: '.($e instanceof \DomainException ? $e->getMessage() : 'verification_or_conflict'));
            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function validateLink($rows, DomainSupplierAccount $account, Customer $customer, string $objectId): void
    {
        if ($rows->count() > 1) {
            throw new \DomainException('ambiguous_local_domain');
        }
        $domain = $rows->first();
        if (! $domain) {
            return;
        }
        if ((int) $domain->customer_id !== (int) $customer->id) {
            throw new \DomainException('customer_ownership_conflict');
        }
        if ($domain->supplier_account_id && (int) $domain->supplier_account_id !== (int) $account->id) {
            throw new \DomainException('account_change_requires_separate_transfer');
        }
        if (filled($domain->supplier_object_id) && (string) $domain->supplier_object_id !== $objectId) {
            throw new \DomainException('supplier_object_conflict');
        }
        if ($domain->op_id && ($account->provider !== 'openprovider' || (string) $domain->op_id !== $objectId || ! $account->legacy_config && ! $domain->supplier_account_id)) {
            throw new \DomainException('existing_registrar_identity_conflict');
        }
        if (Schema::hasTable('domain_supplier_operations')
            && DB::table('domain_supplier_operations')->where('domain_id', $domain->id)
                ->whereNotIn('status', ['succeeded', 'failed'])->exists()) {
            throw new \DomainException('pending_domain_requires_operation_reconciliation');
        }
        if (! in_array($domain->status, ['active', 'expired'], true)
            || in_array($domain->provision_status, ['pending', 'running', 'manual'], true)) {
            throw new \DomainException('pending_domain_requires_operation_reconciliation');
        }
    }
}
