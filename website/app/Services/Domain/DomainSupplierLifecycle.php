<?php

namespace App\Services\Domain;

use App\Models\Domain;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\DomainSupplierContact;
use App\Models\DomainSupplierAccount;
use App\Models\DomainSupplierOperation;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\RegistryHandle;
use App\Models\User;
use App\Services\Domain\Providers\DomainProviderFactory;
use App\Services\Domain\Providers\DomainProviderException;
use App\Services\Domain\Pricing\DomainProcurementException;
use App\Services\Finance\BusinessLedger;
use App\Support\ErrorTracker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Durable financial journal. A timed-out submission is reconciled, never resubmitted. */
class DomainSupplierLifecycle
{
    private const IN_FLIGHT = ['submitting', 'queued', 'unknown'];

    public static function domainLockKey(string $fqdn): string
    {
        $canonical = strtolower(trim($fqdn, '. '));
        $ascii = idn_to_ascii($canonical, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        return 'domain-supplier:fqdn:'.hash('sha256', is_string($ascii) ? strtolower($ascii) : $canonical);
    }

    public function __construct(private DomainProviderFactory $providers, private DomainProcurement $pricing) {}

    public static function handles(Domain $domain): bool
    {
        if (! self::legacyCompatible($domain)) {
            return true;
        }

        return filled($domain->supplier_account_id) && filled($domain->supplier_quote);
    }

    /** Legacy OpenProvider code may use only the actual legacy production account. */
    public static function legacyCompatible(Domain $domain): bool
    {
        if (! in_array(strtolower((string) $domain->registrar), ['', 'openprovider'], true)) {
            return false;
        }
        if (! filled($domain->supplier_account_id)) {
            return \App\Services\Domain\Providers\LegacyAccountIdentity::unboundCompatible();
        }

        $account = DomainSupplierAccount::find($domain->supplier_account_id);

        return $account !== null && $account->provider === 'openprovider'
            && $account->environment === 'production' && $account->legacy_config
            && \App\Services\Domain\Providers\LegacyAccountIdentity::matches($account);
    }

    /** Bind only a confirmed object returned by the legacy authenticated account. */
    public static function bindLegacyResult(Domain $domain, array $remote): void
    {
        if (! in_array(strtolower((string) $domain->registrar), ['', 'openprovider'], true)
            || ! Schema::hasColumn('domains', 'supplier_object_id')) { return; }
        $fqdn = strtolower((string) data_get($remote, 'domain.name').'.'.(string) data_get($remote, 'domain.extension'));
        $id = (int) ($remote['id'] ?? 0);
        if ($fqdn !== strtolower($domain->domain) || $id <= 0) { return; }
        $account = DomainSupplierAccount::where('provider', 'openprovider')->where('environment', 'production')
            ->where('legacy_config', true)->first();
        if (! $account || ($domain->supplier_account_id && (int) $domain->supplier_account_id !== (int) $account->id)) { return; }
        if (! \App\Services\Domain\Providers\LegacyAccountIdentity::confirmObject($account, $fqdn, (string) $id)) { return; }
        $domain->forceFill(['supplier_account_id' => $account->id, 'supplier_object_id' => (string) $id])->save();
    }

    /** Journal existence also prevents an old resolve-stuck job from refunding an uncertain purchase. */
    public static function hasUnresolvedOperation(Domain $domain): bool
    {
        return Schema::hasTable('domain_supplier_operations')
            && DomainSupplierOperation::where('domain_id', $domain->id)
                ->whereNotIn('status', ['failed', 'succeeded'])->exists();
    }

    /** Production approval is bound to this immutable request, payment and supplier cost. */
    public function approve(DomainSupplierOperation $operation, int $actorId): void
    {
        if (! $this->isApprover($actorId)) {
            throw ValidationException::withMessages(['operation' => __('domain-suppliers.operation_invalid')]);
        }
        DB::transaction(function () use ($operation, $actorId) {
            $op = DomainSupplierOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($op->status !== 'prepared' || ! $op->request_hash) {
                throw ValidationException::withMessages(['operation' => __('domain-suppliers.operation_invalid')]);
            }
            $domain = Domain::whereKey($op->domain_id)->lockForUpdate()->firstOrFail();
            $payment = $this->paymentSnapshot($domain, $op->operation);
            if ($payment !== $op->payment_snapshot || ! hash_equals($op->request_hash, $this->requestHash($op))) {
                throw ValidationException::withMessages(['operation' => __('domain-suppliers.operation_invalid')]);
            }
            $context = (array) $op->request_context;
            $context['approval'] = ['actor_id' => $actorId, 'approved_at' => now()->toIso8601String(), 'request_hash' => $op->request_hash];
            $op->forceFill(['request_context' => $context, 'approved_at' => now(), 'approved_by' => $actorId, 'approval_hash' => $op->request_hash])->save();
            $domain->forceFill(['provision_status' => 'pending', 'provision_error' => null])->save();
        });
    }

    /** Explicit read-only recovery for an unsent paid order whose quote expired. */
    public function refreshQuote(DomainSupplierOperation $operation, int $actorId): void
    {
        if (! $this->isApprover($actorId)) {
            throw ValidationException::withMessages(['operation' => __('domain-suppliers.operation_invalid')]);
        }
        $lock = Cache::lock(self::domainLockKey($operation->fqdn), 180);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['operation' => __('domain-suppliers.review_required')]);
        }
        try {
            $op = $operation->fresh();
            if (! in_array($op->status, ['prepared', 'manual_review'], true) || $op->sent_at !== null || $op->attempts > 0) {
                throw ValidationException::withMessages(['operation' => __('domain-suppliers.operation_invalid')]);
            }
            $domain = Domain::findOrFail($op->domain_id);
            $invoice = Invoice::findOrFail($op->invoice_id);
            $old = (array) $op->quote_snapshot;
            $this->paymentSnapshot($domain, $op->operation);
            $quoted = $op->operation === 'transfer'
                ? $this->pricing->quoteTransfer($op->fqdn, $op->period_months, DomainSupplierAccount::findOrFail($op->supplier_account_id),
                    (array) ($old['options'] ?? []), (int) $domain->customer_id, $domain)
                : $this->pricing->quote($op->fqdn, $op->operation, $op->period_months, (array) ($old['options'] ?? []), $domain);
            $fresh = $this->pricing->snapshot($quoted);
            if ((int) ($fresh['supplier_account_id'] ?? 0) !== (int) $op->supplier_account_id
                || ($fresh['operation'] ?? null) !== $op->operation || (int) ($fresh['period_months'] ?? 0) !== $op->period_months
                || ($fresh['options'] ?? []) !== ($old['options'] ?? []) || ($fresh['premium'] ?? false) !== ($old['premium'] ?? false)
                || ($fresh['resulting_period_months'] ?? null) !== ($old['resulting_period_months'] ?? null)
                || ($op->operation === 'renew' && isset($old['prior_expires_at'])
                    && (($fresh['prior_expires_at'] ?? null) !== $old['prior_expires_at'] || ($fresh['target_expires_at'] ?? null) !== ($old['target_expires_at'] ?? null)))
                || (int) ($fresh['floor_toman'] ?? 0) <= 0
                || (int) $fresh['floor_toman'] > max(0, (int) $invoice->subtotal - (int) $invoice->discount)
                || ! $quoted->claimFor((int) $domain->customer_id)) {
                throw ValidationException::withMessages(['operation' => __('domain-suppliers.operation_invalid')]);
            }
            DB::transaction(function () use ($op, $domain, $invoice, $fresh, $actorId) {
                $locked = DomainSupplierOperation::whereKey($op->id)->lockForUpdate()->firstOrFail();
                if ($locked->sent_at !== null || ! in_array($locked->status, ['prepared', 'manual_review'], true)) {
                    throw ValidationException::withMessages(['operation' => __('domain-suppliers.operation_invalid')]);
                }
                $invoice->forceFill(['supplier_quote' => $fresh])->save();
                $domain->forceFill(['supplier_quote' => $fresh])->save();
                $domain->putMeta(['live_price' => $fresh + ['supplier_quote' => $fresh]]);
                $context = (array) $locked->request_context;
                unset($context['approval']);
                $history = (array) ($context['quote_history'] ?? []);
                $history[] = ['prior_quote_id' => data_get($locked->quote_snapshot, 'quote_id'),
                    'actor_id' => $actorId, 'refreshed_at' => now()->toIso8601String()];
                $context['quote_history'] = $history;
                $context['supplier_identity_hash'] = $this->accountIdentityHash(DomainSupplierAccount::findOrFail($locked->supplier_account_id));
                $locked->forceFill(['status' => 'prepared', 'error_code' => null, 'quote_snapshot' => $fresh,
                    'approved_at' => null, 'approved_by' => null, 'approval_hash' => null,
                    'request_context' => $context, 'payment_snapshot' => $this->paymentSnapshot($domain, $locked->operation)])->save();
                $locked->forceFill(['request_hash' => $this->requestHash($locked)])->save();
                $domain->forceFill(['provision_status' => 'manual', 'provision_error' => $this->message('operation_approval_required')])->save();
            });
        } finally {
            $lock->release();
        }
    }

    /** No paid provider request happens before all locks, journal and approvals are durable. */
    public function execute(Domain $domain, string $operation, int $months, array $extra = []): array
    {
        if (! config('domain_suppliers.enabled', false) || ! Schema::hasTable('domain_supplier_operations')) {
            return $this->hold($domain, 'supplier_feature_disabled');
        }
        if (! in_array($operation, ['create', 'renew', 'transfer', 'restore'], true) || $months <= 0 || $months % 12 !== 0) {
            return $this->hold($domain, 'operation_period_invalid');
        }
        $lock = Cache::lock(self::domainLockKey($domain->domain), 180);
        if (! $lock->get()) {
            return $this->answer('queued', 'operation_busy');
        }
        $accountLock = null;
        try {
            $domain->refresh();
            $account = DomainSupplierAccount::find($domain->supplier_account_id);
            if (! $account || $account->provider !== $domain->registrar || ! $account->active) {
                return $this->hold($domain, 'supplier_binding_invalid');
            }
            $accountLock = Cache::lock('domain-supplier-account:'.$account->id, 180);
            if (! $accountLock->get()) {
                return $this->answer('queued', 'account_busy');
            }
            $payment = $this->paymentSnapshot($domain, $operation);
            $invoiceId = $payment['invoice_id'];
            $op = DomainSupplierOperation::where('invoice_id', $invoiceId)->where('operation', $operation)->first();
            if ($op) {
                if ((int) $op->domain_id !== (int) $domain->id || (int) $op->supplier_account_id !== (int) $account->id
                    || $op->fqdn !== strtolower($domain->domain) || (int) $op->period_months !== $months || $op->operation !== $operation) {
                    return $this->hold($domain, 'operation_binding_mismatch');
                }
                if ($op->status === 'succeeded') {
                    return $this->answer('succeeded');
                }
                if (in_array($op->status, self::IN_FLIGHT, true)) {
                    return $this->reconcileLocked($op, $domain, $account);
                }
                if ($op->status !== 'prepared') {
                    return $this->hold($domain, 'operation_manual_review');
                }
            } else {
                $expectedStatus = ['create' => 'pending', 'renew' => 'active', 'restore' => 'expired', 'transfer' => Domain::STATUS_TRANSFERRING][$operation];
                if ($domain->status !== $expectedStatus || ! in_array($domain->provision_status, ['pending', 'running'], true)
                    || ($operation === 'create' && $domain->isTransfer())
                    || ($operation === 'transfer' && (! $domain->isTransfer() || $domain->transfer_status !== 'pending'))) {
                    return $this->hold($domain, 'domain_operation_state_invalid');
                }
                if (DomainSupplierOperation::where('fqdn', strtolower($domain->domain))
                    ->whereNotIn('status', ['succeeded', 'failed'])->exists()) {
                    return $this->hold($domain, 'another_operation_unresolved');
                }
                $quote = (array) Invoice::findOrFail($invoiceId)->supplier_quote;
                $context = $this->context($domain, $account, $operation, $months, $extra, $quote);
                if (($quote['operation'] ?? null) !== $operation || (int) ($quote['period_months'] ?? 0) !== $months
                    || (int) ($quote['supplier_account_id'] ?? 0) !== (int) $account->id) {
                    return $this->hold($domain, 'quote_binding_invalid');
                }
                $op = DB::transaction(function () use ($domain, $account, $operation, $months, $invoiceId, $context, $quote, $payment) {
                    Domain::whereKey($domain->id)->lockForUpdate()->firstOrFail();
                    $op = DomainSupplierOperation::firstOrCreate(
                        ['invoice_id' => $invoiceId, 'operation' => $operation],
                        ['domain_id' => $domain->id, 'supplier_account_id' => $account->id,
                            'idempotency_key' => 'domain-invoice:'.$invoiceId.':'.$operation,
                            'fqdn' => strtolower($domain->domain), 'active_fqdn' => strtolower($domain->domain), 'period_months' => $months,
                            'status' => 'prepared', 'request_context' => $context,
                            'quote_snapshot' => $quote, 'payment_snapshot' => $payment, 'attempts' => 0]
                    );
                    if ($op->wasRecentlyCreated) {
                        $op->forceFill(['request_hash' => $this->requestHash($op)])->save();
                    }
                    return $op;
                });
            }
            if ($op->payment_snapshot !== $payment || ! hash_equals((string) $op->request_hash, $this->requestHash($op))) {
                return $this->failOperation($op, $domain, 'operation_snapshot_changed');
            }
            $approval = (array) data_get($op->request_context, 'approval', []);
            if ($account->environment === 'production'
                && (! $this->isApprover((int) ($approval['actor_id'] ?? 0)) || ($approval['request_hash'] ?? null) !== $op->request_hash
                    || ! $op->approved_at || (int) $op->approved_by !== (int) ($approval['actor_id'] ?? 0) || $op->approval_hash !== $op->request_hash)) {
                return $this->hold($domain, 'operation_approval_required');
            }
            $accountIdentity = $this->accountIdentityHash($account);
            if (! hash_equals((string) data_get($op->request_context, 'supplier_identity_hash', ''), $accountIdentity)) {
                return $this->failOperation($op, $domain, 'supplier_account_changed');
            }
            // A stale quote/changed terms never silently increase the supplier spend or invoice.
            $quote = $this->pricing->revalidateDomain($domain, $operation, $months, Invoice::findOrFail($invoiceId));
            $context = (array) $op->request_context;
            unset($context['approval']);
            $context['max_price_minor'] = (int) ($quote['amount_minor'] ?? 0);
            if (isset($quote['provider_debit_minor'])) {
                $context['provider_price_ceiling_minor'] = $quote['provider_debit_minor'];
            }
            if (isset($quote['premium'])) { $context['quoted_premium'] = $quote['premium']; }
            if (isset($quote['provider_terms_hash'])) { $context['quoted_provider_terms_hash'] = $quote['provider_terms_hash']; }
            $context['currency'] = (string) ($quote['currency'] ?? '');
            $context['object_id'] = (string) $domain->supplier_object_id;
            $context['cltrid'] = 'sn-domain-op-'.$op->id;
            if ($context['max_price_minor'] <= 0 || $context['currency'] === '') {
                return $this->failOperation($op, $domain, 'quote_missing');
            }
            $requiredDebit = $context['provider_price_ceiling_minor'] ?? $context['max_price_minor'];
            if (! is_int($requiredDebit) || $requiredDebit < 1 || $requiredDebit > $context['max_price_minor']) {
                return $this->failOperation($op, $domain, 'quote_missing');
            }
            $reserved = DomainSupplierOperation::where('supplier_account_id', $account->id)
                ->whereKeyNot($op->id)->whereIn('status', self::IN_FLIGHT)->get()
                ->sum(fn ($other) => (int) data_get($other->quote_snapshot, 'provider_debit_minor',
                    data_get($other->quote_snapshot, 'amount_minor', data_get($other->quote_snapshot, 'purchase_minor', 0))));
            $probe = $this->providers->forAccount($account)->probe();
            if (! ($probe['healthy'] ?? false) || ($probe['currency'] ?? null) !== $context['currency']
                || ! isset($probe['balance_minor']) || (int) $probe['balance_minor'] - $reserved < $requiredDebit) {
                return $this->hold($domain, 'supplier_balance_unavailable');
            }
            // Final decision after the last slow provider read. Lock the same
            // customer/invoice/domain order used by settlement, then account and
            // journal. A refund, ownership/account change or approval revocation
            // during quote/probe cannot pass this durable claim.
            $decision = DB::transaction(function () use ($op, $domain, $account, $accountIdentity, $invoiceId, $operation, $months, $payment, $quote, $probe, $context, $requiredDebit) {
                Customer::whereKey($domain->customer_id)->lockForUpdate()->firstOrFail();
                $invoice = Invoice::whereKey($invoiceId)->lockForUpdate()->firstOrFail();
                $invoice->items()->orderBy('id')->lockForUpdate()->get();
                $freshDomain = Domain::whereKey($domain->id)->lockForUpdate()->firstOrFail();
                $freshAccount = DomainSupplierAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
                $fresh = DomainSupplierOperation::whereKey($op->id)->lockForUpdate()->firstOrFail();
                if ($fresh->status !== 'prepared' || $fresh->sent_at !== null) {
                    return ['claimed' => false, 'operation' => $fresh, 'domain' => $freshDomain, 'account' => $freshAccount];
                }
                $expectedStatus = ['create' => 'pending', 'renew' => 'active', 'restore' => 'expired', 'transfer' => Domain::STATUS_TRANSFERRING][$operation];
                if (! config('domain_suppliers.enabled', false) || $freshDomain->status !== $expectedStatus
                    || (int) $freshDomain->customer_id !== (int) $domain->customer_id
                    || (int) $freshDomain->supplier_account_id !== (int) $freshAccount->id
                    || $freshDomain->domain !== $op->fqdn || $freshDomain->registrar !== $freshAccount->provider
                    || (string) $freshDomain->supplier_object_id !== (string) $domain->supplier_object_id
                    || (int) $fresh->domain_id !== (int) $freshDomain->id || (int) $fresh->invoice_id !== (int) $invoiceId
                    || (int) $fresh->supplier_account_id !== (int) $freshAccount->id
                    || $fresh->operation !== $operation || (int) $fresh->period_months !== $months
                    || $fresh->fqdn !== $freshDomain->domain || $fresh->active_fqdn !== $freshDomain->domain) {
                    throw new DomainProcurementException('operation_binding_mismatch');
                }
                if (! $freshAccount->active || ! hash_equals($accountIdentity, $this->accountIdentityHash($freshAccount))) {
                    throw new DomainProcurementException('supplier_account_changed');
                }
                $reservedNow = 0;
                foreach (DomainSupplierOperation::where('supplier_account_id', $freshAccount->id)->whereKeyNot($fresh->id)
                    ->whereIn('status', self::IN_FLIGHT)->orderBy('id')->lockForUpdate()->get() as $other) {
                    $amount = data_get($other->quote_snapshot, 'provider_debit_minor',
                        data_get($other->quote_snapshot, 'amount_minor', data_get($other->quote_snapshot, 'purchase_minor')));
                    if (! is_int($amount) || $amount < 1 || $reservedNow > PHP_INT_MAX - $amount) {
                        throw new DomainProcurementException('supplier_reserved_cost_unknown');
                    }
                    $reservedNow += $amount;
                }
                if ((int) $probe['balance_minor'] - $reservedNow < $requiredDebit) {
                    throw new DomainProcurementException('supplier_balance_unavailable');
                }
                $currentPayment = $this->paymentSnapshot($freshDomain, $operation, true);
                if ($currentPayment !== $payment || $currentPayment !== $fresh->payment_snapshot
                    || ! hash_equals((string) $fresh->request_hash, $this->requestHash($fresh))
                    || ! hash_equals((string) $op->request_hash, (string) $fresh->request_hash)) {
                    throw new DomainProcurementException('operation_snapshot_changed');
                }
                $approval = (array) data_get($fresh->request_context, 'approval', []);
                if ($freshAccount->environment === 'production') {
                    User::whereKey((int) ($approval['actor_id'] ?? 0))->lockForUpdate()->first();
                    if (! $this->isApprover((int) ($approval['actor_id'] ?? 0)) || ! $fresh->approved_at
                        || (int) $fresh->approved_by !== (int) ($approval['actor_id'] ?? 0)
                        || ($approval['request_hash'] ?? null) !== $fresh->request_hash || $fresh->approval_hash !== $fresh->request_hash) {
                        throw new DomainProcurementException('operation_approval_required');
                    }
                }
                $expires = $this->date(data_get($fresh->quote_snapshot, 'honour_until'));
                if (! $expires || ! $expires->isFuture()) {
                    throw new DomainProcurementException('supplier_quote_expired');
                }
                app(LiveDomainPricing::class)->assertSnapshotCoverage($freshDomain, $invoice, $quote);
                // Compare-and-swap remains authoritative even if a cache lease expired.
                $claimed = DomainSupplierOperation::whereKey($fresh->id)->where('status', 'prepared')
                    ->where('request_hash', $fresh->request_hash)->whereNull('sent_at')
                    ->update(['status' => 'submitting', 'sent_at' => now(), 'attempts' => 1, 'updated_at' => now()]);
                return ['claimed' => $claimed === 1, 'operation' => $fresh->fresh(), 'domain' => $freshDomain, 'account' => $freshAccount];
            }, 3);
            $op = $decision['operation'];
            $domain = $decision['domain'];
            $account = $decision['account'];
            if (! $decision['claimed']) {
                return $this->reconcileLocked($op, $domain, $account);
            }
            // COMMIT before network I/O. A crash after this point may have spent funds.
            try {
                $result = $this->providers->forAccount($account)->submit($operation, $domain->domain, $months, $context);
            } catch (DomainProviderException $e) {
                $result = ['status' => $e->uncertain ? 'unknown' : 'failed', 'error_code' => $e->reason];
            } catch (\Throwable) {
                $result = ['status' => 'unknown', 'error_code' => 'submission_result_unknown'];
            }
            return $this->applyResult($op, $domain, $account, $result);
        } catch (\Throwable $e) {
            // Never expose raw provider exception bodies or EPP/contact/session data.
            $reason = $e instanceof DomainProcurementException ? $e->reason
                : ($e instanceof ValidationException ? 'quote_or_payment_review_required' : 'supplier_preflight_failed');
            if ($e instanceof ValidationException) {
                $allowed = ['contact_mapping_required', 'nameservers_required', 'transfer_authorization_required', 'supplier_object_required', 'payment_reconciliation_required', 'renewal_target_invalid'];
                $candidate = (string) data_get($e->errors(), 'domain.0', '');
                if (in_array($candidate, $allowed, true)) { $reason = $candidate; }
            }
            if (isset($op) && $op instanceof DomainSupplierOperation) {
                // An unsent invalid quote needs a new review, not a hot retry loop
                // that can starve other approved work. Never overwrite an in-flight claim.
                DomainSupplierOperation::whereKey($op->id)->where('status', 'prepared')->whereNull('sent_at')
                    ->update(['status' => 'manual_review', 'error_code' => $reason,
                        'approved_at' => null, 'approved_by' => null, 'approval_hash' => null, 'updated_at' => now()]);
            }
            return $this->hold($domain, $reason);
        } finally {
            $accountLock?->release();
            $lock->release();
        }
    }

    public function reconcile(DomainSupplierOperation $operation): array
    {
        $lock = Cache::lock(self::domainLockKey($operation->fqdn), 180);
        if (! $lock->get()) {
            return $this->answer('queued', 'operation_busy');
        }
        try {
            $op = $operation->fresh();
            $domain = Domain::findOrFail($op->domain_id);
            $account = DomainSupplierAccount::findOrFail($op->supplier_account_id);
            return $this->reconcileLocked($op, $domain, $account);
        } finally {
            $lock->release();
        }
    }

    private function reconcileLocked(DomainSupplierOperation $op, Domain $domain, DomainSupplierAccount $account): array
    {
        if (! in_array($op->status, self::IN_FLIGHT, true)) {
            return $this->answer($op->status);
        }
        if ((int) $domain->supplier_account_id !== (int) $account->id || $domain->domain !== $op->fqdn) {
            return $this->hold($domain, 'operation_binding_mismatch');
        }
        try {
            $result = $this->providers->forAccount($account)->reconcile(array_replace($op->getAttributes(), [
                'request_context' => $op->request_context, 'quote_snapshot' => $op->quote_snapshot,
            ]));
        } catch (\Throwable) {
            $result = ['status' => 'unknown', 'error_code' => 'reconciliation_unavailable'];
        }
        return $this->applyResult($op, $domain, $account, $result);
    }

    private function applyResult(DomainSupplierOperation $op, Domain $domain, DomainSupplierAccount $account, array $result): array
    {
        $status = in_array($result['status'] ?? '', ['queued', 'succeeded', 'failed', 'unknown'], true) ? $result['status'] : 'unknown';
        if ($status === 'succeeded') {
            // Domain access and actual delivered state must be confirmed from the same account.
            try {
                $found = $this->providers->forAccount($account)->find($op->fqdn);
            } catch (\Throwable) {
                $found = ['found' => null];
            }
            if (($found['found'] ?? null) !== true || blank($found['object_id'] ?? null)) {
                $status = 'unknown';
                $result['error_code'] = 'delivery_not_confirmed';
            } elseif ($op->operation !== 'create' && filled($domain->supplier_object_id)
                && (string) $domain->supplier_object_id !== (string) $found['object_id']) {
                $status = 'unknown';
                $result['error_code'] = 'supplier_object_mismatch';
            } else {
                $result['object_id'] = $found['object_id'];
                $result['expires_at'] = $found['expires_at'] ?? $result['expires_at'] ?? null;
            }
            if ($status === 'succeeded' && $op->operation === 'restore'
                && ! $this->date($result['expires_at'] ?? null)?->isFuture()) {
                $status = 'unknown';
                $result['error_code'] = 'restore_expiry_not_confirmed';
            }
            if ($status === 'succeeded' && $op->operation === 'renew') {
                $before = data_get($op->request_context, 'prior_expires_at');
                $after = $this->date($result['expires_at'] ?? null);
                $expected = $this->date($before)?->addMonthsNoOverflow($op->period_months);
                if ($after === null || $expected === null || $after->lt($expected->subDay())) {
                    $status = 'unknown';
                    $result['error_code'] = 'renewal_expiry_not_confirmed';
                }
            }
        }
        $safeCode = preg_match('/^[a-zA-Z0-9_.-]{1,80}$/', (string) ($result['error_code'] ?? '')) ? $result['error_code'] : ($status === 'failed' ? 'supplier_rejected' : null);
        DB::transaction(function () use ($op, $domain, $status, $safeCode, $result) {
            $fresh = DomainSupplierOperation::whereKey($op->id)->lockForUpdate()->firstOrFail();
            if ($fresh->status === 'succeeded') {
                return;
            }
            $context = (array) $fresh->request_context;
            // EPP is needed only until first submission. Retain a hash, never a redisplayable code.
            if (isset($context['auth_code'])) {
                unset($context['auth_code']);
            }
            $quote = (array) $fresh->quote_snapshot;
            if ($status === 'succeeded') {
                $evidence = ['cost_status' => 'unreconciled'];
                if (($result['cost_status'] ?? null) === 'provider_reported' && is_int($result['purchase_minor'] ?? null)
                    && $result['purchase_minor'] > 0 && ($result['currency'] ?? null) === ($quote['currency'] ?? null)
                    && filled($result['provider_order_id'] ?? null)) {
                    $evidence = ['cost_status' => 'provider_reported', 'purchase_minor' => $result['purchase_minor'],
                        'currency' => $result['currency'], 'provider_order_id' => (string) $result['provider_order_id'],
                        'reported_at' => now()->toIso8601String()];
                }
                $quote['purchase_evidence'] = $evidence;
                $invoice = Invoice::whereKey($fresh->invoice_id)->lockForUpdate()->firstOrFail();
                $invoiceQuote = (array) $invoice->supplier_quote;
                $invoiceQuote['purchase_evidence'] = $evidence;
                $invoice->forceFill(['supplier_quote' => $invoiceQuote])->save();
            }
            $fresh->forceFill(['status' => $status, 'active_fqdn' => in_array($status, ['succeeded', 'failed'], true) ? null : $fresh->fqdn,
                'error_code' => $safeCode, 'quote_snapshot' => $quote,
                'object_id' => $result['object_id'] ?? $fresh->object_id,
                'process_id' => $result['process_id'] ?? $fresh->process_id,
                'tracking_id' => $result['tracking_id'] ?? $fresh->tracking_id,
                'request_context' => $context, 'reconciled_at' => now()])->save();
            if ($status === 'succeeded') {
                $changes = ['supplier_object_id' => (string) $result['object_id'], 'provision_status' => 'done', 'provision_error' => null, 'status' => 'active'];
                if (($expires = $this->date($result['expires_at'] ?? null)) !== null) {
                    $changes['expires_at'] = $expires;
                }
                if ($op->operation === 'create') {
                    $changes['registered_at'] = $domain->registered_at ?: now();
                }
                if ($op->operation === 'transfer') {
                    $changes['transfer_status'] = 'completed';
                }
                $domain->forceFill($changes)->save();
                $domain->putMeta(['exp_stage' => null, 'supplier_operation_id' => $op->id,
                    'completed_supplier_invoice_id' => $op->invoice_id]);
            } else {
                if ($op->operation === 'transfer' && in_array($status, ['queued', 'unknown'], true)) {
                    $domain->forceFill(['transfer_status' => 'submitted', 'transfer_submitted_at' => $op->sent_at,
                        'transfer_checked_at' => now()])->save();
                }
                $domain->forceFill(['provision_status' => 'manual', 'provision_error' => $this->message($safeCode ?: 'operation_'.$status)])->save();
                $domain->putMeta(['supplier_operation_id' => $op->id]);
            }
        });
        if ($status === 'succeeded') {
            app(BusinessLedger::class)->recordDomainWholesale($domain, $op->operation === 'create' ? 'register' : $op->operation, intdiv($op->period_months, 12), Invoice::find($op->invoice_id));
        } elseif ($status === 'failed' || $status === 'unknown') {
            ErrorTracker::noteOnce('domain', 'Domain supplier operation requires administrator reconciliation.', 3600,
                ['operation_id' => $op->id, 'state' => $status, 'reason' => $safeCode]);
        }
        return $this->answer($status, $safeCode);
    }

    private function context(Domain $domain, DomainSupplierAccount $account, string $operation, int $months, array $extra, array $quote): array
    {
        $context = ['supplier_identity_hash' => $this->accountIdentityHash($account),
            'object_id' => (string) $domain->supplier_object_id,
            'prior_expires_at' => $quote['prior_expires_at'] ?? $domain->expires_at?->toIso8601String(),
            'target_expires_at' => $quote['target_expires_at'] ?? null,
            'privacy' => data_get($quote, 'options.privacy', $domain->whois_privacy ? 'basic' : 'none'),
            'language' => data_get($quote, 'options.language')];
        foreach (['provider_debit_minor' => 'provider_price_ceiling_minor', 'premium' => 'quoted_premium',
            'provider_terms_hash' => 'quoted_provider_terms_hash'] as $source => $target) {
            if (isset($quote[$source])) { $context[$target] = $quote[$source]; }
        }
        if (in_array($operation, ['create', 'transfer'], true)) {
            $profile = $domain->customer?->defaultProfile();
            $handle = $profile && Schema::hasTable('domain_supplier_contacts') ? DomainSupplierContact::where('customer_profile_id', $profile->id)
                ->where('supplier_account_id', $account->id)->where('role', 'registrant')->where('status', 'active')
                ->whereNotNull('verified_at')->first() : null;
            if (! $handle && $profile && $account->legacy_config) {
                $handle = RegistryHandle::where('customer_profile_id', $profile->id)
                    ->where('supplier_account_id', $account->id)->where('role', 'registrant')->where('status', 'active')->first();
            }
            if (! $handle || blank($handle->handle)) {
                throw ValidationException::withMessages(['domain' => 'contact_mapping_required']);
            }
            $context['contact_handles'] = array_fill_keys(['owner', 'billing', 'admin', 'tech'], $handle->handle);
            $context['handle'] = $handle->handle;
            $context['name_servers'] = $operation === 'create' ? $domain->effectiveNameServers() : (array) $domain->name_servers;
            if ($operation === 'create' && count($context['name_servers']) < 2) {
                throw ValidationException::withMessages(['domain' => 'nameservers_required']);
            }
        }
        if ($operation === 'transfer') {
            if (blank($extra['auth_code'] ?? null)) {
                throw ValidationException::withMessages(['domain' => 'transfer_authorization_required']);
            }
            $context['auth_code'] = $extra['auth_code'];
        }
        if ($operation === 'renew') {
            if (! $domain->expires_at || blank($domain->supplier_object_id)) {
                throw ValidationException::withMessages(['domain' => 'supplier_object_required']);
            }
            $prior = $this->date($context['prior_expires_at']);
            $target = $this->date($context['target_expires_at']) ?? $prior?->copy()->addMonthsNoOverflow($months);
            if (! $prior || ! $target || ! $target->equalTo($prior->copy()->addMonthsNoOverflow($months))) {
                throw ValidationException::withMessages(['domain' => 'renewal_target_invalid']);
            }
            $context['target_expires_at'] = $target->toIso8601String();
            $context['target_expiration_year'] = (int) $target->format('Y');
        }
        return $context;
    }

    private function paymentSnapshot(Domain $domain, string $operation, bool $locked = false): array
    {
        $invoiceId = match ($operation) {
            'renew' => (int) data_get($domain->meta, 'renew_invoice_id'),
            'restore' => (int) data_get($domain->meta, 'restore_invoice_id'),
            default => (int) $domain->invoice_id,
        };
        $invoice = Invoice::find($invoiceId);
        if (! $invoice || (int) $invoice->domain_id !== (int) $domain->id
            || (int) $invoice->customer_id !== (int) $domain->customer_id || $invoice->kind !== 'domain'
            || $invoice->status !== 'paid' || $invoice->total <= 0 || $invoice->paid < $invoice->total || $invoice->currency_code !== 'IRT') {
            throw ValidationException::withMessages(['domain' => 'payment_reconciliation_required']);
        }
        $payments = Payment::where('invoice_id', $invoiceId)->where('customer_id', $domain->customer_id)
            ->where('currency_code', 'IRT')->where('status', 'paid')->orderBy('id')
            ->when($locked, fn ($query) => $query->lockForUpdate())->get(['id', 'amount']);
        $creditId = null;
        if ((int) $payments->sum('amount') < (int) $invoice->total) {
            // The reseller API debits Wallet directly and creates no Payment. Its exact
            // immutable ledger ID is pinned on this invoice in the charging transaction.
            $creditId = (int) $invoice->supplier_payment_ledger_id;
            $reason = 'domain_'.($operation === 'create' ? 'register' : $operation).'_api';
            $debit = $creditId > 0 ? CreditEntry::whereKey($creditId)->where('customer_id', $domain->customer_id)
                ->where('currency_code', 'IRT')->where('amount', -$invoice->total)->where('reason', $reason)
                ->where('source_type', $domain->getMorphClass())->where('source_id', $domain->id)
                ->when($locked, fn ($query) => $query->lockForUpdate())->first() : null;
            $reused = $creditId > 0 && Invoice::where('supplier_payment_ledger_id', $creditId)->whereKeyNot($invoiceId)->exists();
            if (! $debit || $reused) {
                throw ValidationException::withMessages(['domain' => 'payment_reconciliation_required']);
            }
        }
        if ((int) $invoice->supplier_account_id !== (int) $domain->supplier_account_id || ! is_array($invoice->supplier_quote)) {
            throw ValidationException::withMessages(['domain' => 'payment_reconciliation_required']);
        }
        return ['invoice_id' => $invoiceId, 'customer_id' => (int) $domain->customer_id,
            'total' => (int) $invoice->total, 'paid' => (int) $invoice->paid, 'subtotal' => (int) $invoice->subtotal,
            'discount' => (int) $invoice->discount, 'tax' => (int) $invoice->tax,
            'currency' => 'IRT', 'payment_ids' => $payments->pluck('id')->all(), 'payments' => $payments->toArray(),
            'credit_entry_id' => $creditId, 'supplier_account_id' => (int) $invoice->supplier_account_id,
            'quote_digest' => hash('sha256', json_encode($invoice->supplier_quote, JSON_THROW_ON_ERROR))];
    }

    /** Private hash of the supplier principal/readiness approved for this request. */
    private function accountIdentityHash(DomainSupplierAccount $account): string
    {
        return hash('sha256', json_encode([$account->id, $account->provider, $account->environment,
            $account->legacy_config, $account->credentials, $account->settings, $account->capabilities,
            $account->active, $account->connection_status, $account->sales_enabled, $account->contract_verified, $account->identity_verified,
            $account->pricing_verified, $account->legacy_config ? \App\Services\Domain\Providers\LegacyAccountIdentity::currentFingerprint() : null], JSON_THROW_ON_ERROR));
    }

    private function isApprover(int $actorId): bool
    {
        $allowed = array_map('intval', (array) config('domain_suppliers.approver_user_ids', []));
        return $actorId > 0 && in_array($actorId, $allowed, true)
            && User::whereKey($actorId)->where('role', 'admin')->exists();
    }

    private function requestHash(DomainSupplierOperation $op): string
    {
        $context = (array) $op->request_context;
        unset($context['approval']);
        return hash('sha256', json_encode([$op->domain_id, $op->invoice_id, $op->supplier_account_id,
            $op->operation, $op->fqdn, $op->period_months, $context, $op->quote_snapshot, $op->payment_snapshot], JSON_THROW_ON_ERROR));
    }

    private function failOperation(DomainSupplierOperation $op, Domain $domain, string $reason): array
    {
        $op->forceFill(['status' => 'manual_review', 'error_code' => $reason])->save();
        return $this->hold($domain, $reason);
    }

    private function hold(Domain $domain, string $reason): array
    {
        $domain->forceFill(['provision_status' => 'manual', 'provision_error' => $this->message($reason)])->save();
        return $this->answer('manual_review', $reason);
    }

    private function answer(string $status, ?string $reason = null): array
    {
        return ['ok' => $status === 'succeeded', 'manual' => ! in_array($status, ['succeeded', 'queued'], true),
            'status' => $status, 'message' => $status === 'succeeded' ? '' : $this->message($reason ?: 'operation_'.$status)];
    }

    private function message(string $reason): string
    {
        // Only curated local reason codes are persisted; details are available in the admin journal.
        return __('domain-suppliers.review_required').' ['.$reason.']';
    }

    private function date(mixed $value): ?Carbon
    {
        try {
            return filled($value) ? Carbon::parse($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
