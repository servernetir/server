<?php

namespace App\Services\Domain;

use App\Http\Controllers\Account\CloudStoreController;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Billing\RenewalTerms;
use Illuminate\Support\Facades\DB;

class DomainRenewalInvoicer
{
    public function __construct(private LiveDomainPricing $pricing) {}

    public function open(Domain $domain): ?Invoice
    {
        return Invoice::where('domain_id', $domain->id)
            ->whereIn('status', ['unpaid', 'draft', 'partial'])->latest('id')->first();
    }

    public function effectivePerYear(Domain $domain): int
    {
        return $this->pricing->quote($domain->sld, $domain->tld, 'renew')['sell_toman'];
    }

    public function issue(Domain $domain, int $years = 1): Invoice
    {
        $quote = $this->pricing->quote($domain->sld, $domain->tld, 'renew', $years);
        $unit = $quote['sell_toman'];
        $taxPct = CloudStoreController::taxPercent();
        $tax = (int) round($unit * $taxPct / 100);

        return DB::transaction(function () use ($domain, $years, $quote, $unit, $tax, $taxPct) {
            $domain = Domain::whereKey($domain->id)->lockForUpdate()->firstOrFail();
            if (in_array($domain->provision_status, ['pending', 'running', 'manual'], true)) {
                $this->pricing->unavailable();
            }
            if ($open = $this->open($domain)) {
                if ((int) data_get($domain->meta, 'renew_invoice_id') !== $open->id || $open->paid > 0 || $this->pricing->hasPaymentInFlight($open)) {
                    $this->pricing->assertInvoice($open);

                    return $open;
                }
                $open->update(['subtotal' => $unit, 'tax' => $tax, 'total' => $unit + $tax]);
                $open->items()->update(['unit_price' => $unit, 'line_total' => $unit,
                    'description' => $years.' سال', 'tax_rate_bp' => (int) ($taxPct * 100), 'tax_amount' => $tax]);
                $domain->putMeta(['renew_years' => $years]);
                $this->pricing->remember($domain, $quote);
                $domain->forceFill(['renew_toman' => (int) ceil($unit / $years)])->save();

                return $open->refresh();
            }
            $invoice = Invoice::create([
                'customer_id' => $domain->customer_id, 'domain_id' => $domain->id,
                'kind' => 'domain', 'currency_code' => 'IRT',
                'subtotal' => $unit, 'tax' => $tax, 'total' => $unit + $tax,
                'paid' => 0, 'status' => 'unpaid', 'issued_at' => now(),
                'due_at' => RenewalTerms::domainDeadline($domain),
                'note' => 'تمدیدِ دامنهٔ '.$domain->domain,
            ]);
            InvoiceItem::create([
                'invoice_id' => $invoice->id, 'title' => 'تمدیدِ دامنهٔ '.$domain->domain,
                'description' => $years.' سال', 'quantity' => 1,
                'unit_price' => $unit, 'line_total' => $unit,
                'tax_rate_bp' => (int) ($taxPct * 100), 'tax_amount' => $tax,
            ]);
            $domain->putMeta(['renew_years' => $years, 'renew_invoice_id' => $invoice->id]);
            $this->pricing->remember($domain, $quote);
            $domain->forceFill(['renew_toman' => (int) ceil($unit / $years)])->save();

            return $invoice;
        });
    }
}
