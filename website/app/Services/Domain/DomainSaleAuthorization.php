<?php

namespace App\Services\Domain;

use App\Models\Customer;
use App\Models\Domain;
use App\Models\DomainSupplierAccount;
use App\Models\DomainSupplierOperation;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Customer intent is separate from payment proof and owner pilot approval. */
class DomainSaleAuthorization
{
    /** Called only from authenticated payment POSTs or the authenticated reseller debit. */
    public function record(Invoice $invoice, Customer $customer, string $source): void
    {
        if (! $invoice->domain_id || ! $invoice->supplier_account_id || ! $invoice->supplier_quote) return;
        if (! in_array($source, ['panel', 'reseller_api'], true)
            || (int) $invoice->customer_id !== (int) $customer->id || $customer->status !== 'active') {
            throw ValidationException::withMessages(['domain' => __('domain-pricing.changed')]);
        }
        DB::transaction(function () use ($invoice, $customer, $source) {
            $fresh = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $domain = Domain::whereKey($fresh->domain_id)->lockForUpdate()->firstOrFail();
            if ((int) $domain->customer_id !== (int) $customer->id
                || (int) $fresh->customer_id !== (int) $customer->id
                || (int) $domain->supplier_account_id !== (int) $fresh->supplier_account_id
                || ! in_array($fresh->status, ['draft', 'unpaid', 'partial', 'paid'], true)) {
                throw ValidationException::withMessages(['domain' => __('domain-pricing.changed')]);
            }
            // An existing durable operation must be reconciled using its original intent.
            if (DomainSupplierOperation::where('invoice_id', $fresh->id)->exists()) return;
            $records = (array) data_get($domain->meta, 'supplier_customer_authorizations', []);
            $records[(string) $fresh->id] = ['customer_id' => (int) $customer->id,
                'source' => $source, 'authorized_at' => now()->toIso8601String(),
                'fingerprint' => $this->fingerprint($domain, $fresh)];
            $domain->putMeta(['supplier_customer_authorizations' => $records]);
        });
    }

    /** A deployment allowlist and cap authorize normal sales only after separate live acceptance. */
    public function allows(DomainSupplierOperation $op, Domain $domain, DomainSupplierAccount $account): bool
    {
        if (config('domain_suppliers.authorization_mode', 'pilot') !== 'production'
            || ! in_array((int) $account->id, array_map('intval', (array) config('domain_suppliers.production_account_ids', [])), true)
            || ! in_array($op->operation, ['create', 'renew'], true)) return false;
        $quote = (array) $op->quote_snapshot;
        $cap = config('domain_suppliers.production_caps_minor.'.($quote['currency'] ?? ''));
        if (! is_int($cap) || $cap < 1 || ! is_int($quote['amount_minor'] ?? null)
            || $quote['amount_minor'] < 1 || $quote['amount_minor'] > $cap
            || ($quote['premium'] ?? true) !== false || data_get($quote, 'options.privacy') !== 'none'
            || $account->environment !== 'production' || ! $account->saleEligible()) return false;
        $invoice = Invoice::find($op->invoice_id);
        $customer = Customer::find($domain->customer_id);
        $record = data_get($domain->meta, 'supplier_customer_authorizations.'.(string) $op->invoice_id);
        if (! $invoice || ! $customer || $customer->status !== 'active'
            || ! is_array($record) || (int) ($record['customer_id'] ?? 0) !== (int) $customer->id
            || ! in_array($record['source'] ?? null, ['panel', 'reseller_api'], true)
            || ! is_string($record['authorized_at'] ?? null)
            || ! is_string($record['fingerprint'] ?? null)
            || (int) $invoice->customer_id !== (int) $domain->customer_id
            || (int) $invoice->supplier_account_id !== (int) $account->id
            || $invoice->supplier_quote !== $op->quote_snapshot) return false;
        try {
            if (\Illuminate\Support\Carbon::parse($record['authorized_at'])->isFuture()) return false;
        } catch (\Throwable) { return false; }
        return hash_equals($record['fingerprint'], $this->fingerprint($domain, $invoice));
    }

    private function fingerprint(Domain $domain, Invoice $invoice): string
    {
        return hash('sha256', json_encode([(int) $invoice->id, (int) $invoice->customer_id,
            (int) $invoice->domain_id, (int) $invoice->supplier_account_id,
            $invoice->currency_code, (int) $invoice->subtotal, (int) $invoice->discount,
            (int) $invoice->tax, (int) $invoice->total, $invoice->supplier_quote,
            $domain->domain, $domain->supplier_object_id, $domain->name_servers,
            $domain->customer?->defaultProfile()?->id], JSON_THROW_ON_ERROR));
    }
}
