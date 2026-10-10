<?php

namespace Tests\Support;

use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/** Explicit supplier account and operation prices for successful legacy scenarios. */
trait SupplierDomainPrices
{
    protected function configureSupplierPrices(): void
    {
        config(['services.openprovider.username' => 'u', 'services.openprovider.password' => 'p',
            'domain_pricing.supplier_vat_pct' => 0, 'domain_pricing.supplier_fee_pct' => 0,
            'domain_reseller.min_margin_pct' => 8]);
        Setting::put('pricing_rate_override', '100000');
        Http::preventStrayRequests();
    }

    protected function supplierPriceStubs(float $create = 10, float $renew = 10, float $restore = 20, string $currency = 'EUR'): array
    {
        return [
            '*/auth/login*' => Http::response(['code' => 0, 'data' => ['token' => 't']]),
            '*/resellers*' => Http::response(['code' => 0, 'data' => ['vatperc' => 0]]),
            '*/domains/prices*' => function ($request) use ($create, $renew, $restore, $currency) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                $amount = match ($query['operation']) {
                    // This fixture's selected-term create price is supplied as a total.
                    'create' => $create * (int) $query['period'],
                    'renew', 'transfer' => $renew,
                    'restore' => $restore,
                };

                return Http::response(['code' => 0, 'data' => ['price' => ['reseller' => ['price' => $amount, 'currency' => $currency]]]]);
            },
        ];
    }

    protected function paidDomainInvoice(Domain $domain, string $operation = 'create', int $subtotal = 2500000): Invoice
    {
        $invoice = Invoice::create(['customer_id' => $domain->customer_id, 'domain_id' => $domain->id,
            'kind' => 'domain', 'currency_code' => 'IRT', 'subtotal' => $subtotal, 'tax' => 0,
            'total' => $subtotal, 'paid' => $subtotal, 'status' => 'paid', 'paid_at' => now()]);
        if ($operation === 'create') {
            $domain->update(['invoice_id' => $invoice->id]);
        } else {
            $domain->putMeta([$operation.'_invoice_id' => $invoice->id, 'renew_years' => 1]);
        }

        return $invoice;
    }
}
