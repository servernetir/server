<?php

namespace App\Services\Domain;

use App\Models\{Customer, Domain, DomainQuote, Invoice, InvoiceItem, Service};
use App\Services\Dns\HostingDomainConnection;
use Illuminate\Validation\ValidationException;

/** One hosting invoice, with the same immutable supplier quote as standalone registration. */
class HostingDomainPurchase
{
    public function prepare(Customer $customer, string $fqdn, int $quoteId, string $currency): DomainQuote
    {
        if ($currency !== 'IRT') $this->reject('currency');
        $quote = DomainQuote::find($quoteId);
        if (! $quote || ! $quote->claimFor((int) $customer->id)
            || $quote->domain !== strtolower(trim($fqdn)) || ! $quote->supplier_account_id
            || $quote->operation !== 'create' || ! $quote->honour_until?->isFuture()
            || $quote->sell_toman < 1 || $quote->period_months < 12 || $quote->period_months > 120
            || $quote->period_months % 12 !== 0 || ! app(DomainProcurement::class)->enabled()) {
            $this->reject('recheck');
        }
        if (! DomainSearch::sells($quote->tld) || TldGate::isBlocked($quote->tld)) $this->reject('unavailable');
        $this->availableTo($customer, $quote->domain);
        if (! app(DomainRegistrationReadiness::class)->ready($customer, (int) $quote->supplier_account_id, $quote->tld)) {
            $this->reject('profile');
        }
        return app(DomainProcurement::class)->revalidate($quote);
    }

    /** Called inside the service/invoice transaction and the canonical domain lock. */
    public function attach(Service $service, Invoice $invoice, DomainQuote $quote): void
    {
        if ((int) $invoice->service_id !== (int) $service->id || $invoice->currency_code !== 'IRT'
            || (int) $invoice->customer_id !== (int) $service->customer_id
            || (int) $quote->customer_id !== (int) $service->customer_id
            || $service->domain !== $quote->domain || $invoice->domain_id
            || ! $quote->honour_until?->isFuture()) $this->reject('recheck');
        $customer = $service->customer;
        $existing = $this->availableTo($customer, $quote->domain);
        $ns = HostingDomainConnection::nameservers($service);
        if (count($ns) < 2) $ns = Domain::defaultNameServers();
        $ns = array_values(array_unique(array_filter($ns, fn ($name) => HostingDomainConnection::validName($name))));
        if (count($ns) < 2) $this->reject('nameservers');
        $pricing = app(DomainProcurement::class);
        $snapshot = $pricing->snapshot($quote);
        [$sld, $tld] = Domain::splitFqdn($quote->domain);
        $domain = $existing ?? new Domain;
        $domain->forceFill([
            'customer_id' => $customer->id, 'domain' => $quote->domain, 'sld' => $sld, 'tld' => $tld,
            'registrar' => $quote->registrar, 'supplier_account_id' => $quote->supplier_account_id,
            'supplier_object_id' => null, 'op_id' => null, 'owner_handle' => null,
            'order_type' => 'register', 'transfer_status' => null, 'status' => 'pending',
            'provision_status' => 'none', 'provision_tries' => 0, 'provision_error' => null,
            'period_years' => intdiv((int) $quote->period_months, 12), 'quote_id' => $quote->id,
            'price_toman' => $quote->sell_toman, 'renew_toman' => 0,
            'cost_amount' => $quote->purchase_minor, 'cost_currency' => $quote->currency,
            'name_servers' => $ns,
        ])->save();
        $amount = (int) $quote->sell_toman;
        $taxPct = \App\Http\Controllers\Account\CloudStoreController::taxPercent();
        $tax = (int) round($amount * $taxPct / 100);
        // This canonical title identifies the domain line in mixed invoice money guards.
        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'title' => 'ثبتِ دامنهٔ '.$domain->domain,
            'description' => __('hosting-domains.term', ['years' => $domain->period_years]),
            'quantity' => 1, 'unit_price' => $amount, 'line_total' => $amount,
            'tax_rate_bp' => $taxPct * 100, 'tax_amount' => $tax,
        ]);
        $invoice->forceFill([
            'domain_id' => $domain->id, 'subtotal' => $invoice->subtotal + $amount,
            'tax' => $invoice->tax + $tax, 'total' => $invoice->total + $amount + $tax,
        ])->save();
        $domain->forceFill(['invoice_id' => $invoice->id])->save();
        app(LiveDomainPricing::class)->remember($domain, $snapshot + ['supplier_quote' => $snapshot], $invoice);
    }

    private function availableTo(Customer $customer, string $fqdn): ?Domain
    {
        $existing = Domain::where('domain', $fqdn)->first();
        if ($existing && (! $existing->isDead() || (int) $existing->customer_id !== (int) $customer->id
            || DomainSupplierLifecycle::hasUnresolvedOperation($existing))) $this->reject('already');
        return $existing;
    }

    private function reject(string $reason): never
    {
        throw ValidationException::withMessages(['domain_buy' => __('hosting-domains.'.$reason)]);
    }
}
