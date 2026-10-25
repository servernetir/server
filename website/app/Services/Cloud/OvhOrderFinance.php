<?php

namespace App\Services\Cloud;

use App\Models\{CreditEntry, Invoice, OvhOrder, Service, Setting};
use App\Services\ExchangeRate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Reuses paid invoices; provisioning never debits a customer wallet again. */
class OvhOrderFinance
{
    public function paidInvoice(Service $service): Invoice
    {
        if ($service->isDead() || $service->isHourly() || $service->cycle !== 'monthly') {
            throw new OvhOrderHold('unsupported_billing', 'فقط سفارش ماهانهٔ پرداخت‌شده قابل خرید است.');
        }
        $invoices = Invoice::where('service_id', $service->id)->orderBy('id')->get();
        $invoice = $invoices->first();
        if (! $invoice || (int) $invoice->customer_id !== (int) $service->customer_id
            || $invoice->status !== 'paid' || (int) $invoice->total < 1
            || (int) $invoice->paid !== (int) $invoice->total
            || in_array($invoice->kind, ['topup', 'renewal'], true)) {
            throw new OvhOrderHold('customer_unpaid', 'فاکتور اولیهٔ تسویه‌شدهٔ همین مشتری و سرویس تأیید نشد.');
        }
        if (CreditEntry::where('source_type', Invoice::class)->where('source_id', $invoice->id)
            ->where('amount', '>', 0)->whereIn('reason', ['refund', 'undelivered_refund'])->exists()) {
            throw new OvhOrderHold('customer_refunded', 'فاکتور دارای سابقهٔ برگشت اعتبار است؛ خرید متوقف شد.');
        }
        return $invoice;
    }

    public static function minor(mixed $value): int
    {
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 10000000) {
            throw new OvhOrderHold('invalid_price', 'مبلغ معتبر در پاسخ قیمت وجود ندارد.');
        }
                $rounding = defined(RoundingMode::class.'::Ceiling') ? constant(RoundingMode::class.'::Ceiling') : constant(RoundingMode::class.'::CEILING');
        return BigDecimal::of((string) $value)->multipliedBy(100)->toScale(0, $rounding)->toInt();
    }

    public function validate(OvhOrder $order, array $checkout, array $renewal, string $accountCurrency): array
    {
        $service = $order->service()->firstOrFail();
        $invoice = $this->paidInvoice($service);
        $gross = data_get($checkout, 'prices.withTax');
        $net = data_get($checkout, 'prices.withoutTax');
        $tax = data_get($checkout, 'prices.tax');
        if (! is_array($gross) || ! is_array($net) || ! is_array($tax)) {
            throw new OvhOrderHold('incomplete_quote', 'قیمت نهایی شامل مالیات از سبد تأیید نشد.');
        }
        $currency = strtoupper((string) ($gross['currencyCode'] ?? ''));
        if (! in_array($currency, ['EUR', 'USD'], true) || $currency !== $accountCurrency
            || ($net['currencyCode'] ?? null) !== $currency || ($tax['currencyCode'] ?? null) !== $currency
            || ($renewal['currencyCode'] ?? null) !== $currency) {
            throw new OvhOrderHold('currency_mismatch', 'ارز حساب، سبد و تمدید یکسان یا پشتیبانی‌شده نیست.');
        }
        $cost = self::minor($gross['value'] ?? null);
        $netMinor = self::minor($net['value'] ?? null);
        $taxMinor = self::minor($tax['value'] ?? null);
        $renew = self::minor($renewal['value'] ?? null);
        if ($cost < 1 || $netMinor < 1 || $renew < 1 || abs($netMinor + $taxMinor - $cost) > 1) {
            throw new OvhOrderHold('invalid_quote', 'مبالغ سبد، مالیات و هزینه تمدید سازگار نیستند.');
        }
        $revenueCurrency = strtoupper((string) ($invoice->currency_code ?: 'IRT'));
        $revenue = max(0, (int) $invoice->total - (int) $invoice->tax);
        $fx = [];
        foreach (array_unique([$currency, $revenueCurrency]) as $c) {
            if ($c === 'IRT') { $fx[$c] = ['rate_toman' => 1]; continue; }
            if (! in_array($c, ['EUR', 'USD'], true)) {
                throw new OvhOrderHold('unsupported_currency', 'واحد پول فاکتور پشتیبانی نمی‌شود.');
            }
            $row = app(ExchangeRate::class)->current($c);
            if (! is_array($row) || (int) ($row['rate_toman'] ?? 0) <= 0) {
                throw new OvhOrderHold('fx_missing', 'نرخ معتبر ذخیره‌شدهٔ ارز در دسترس نیست.');
            }
            $fx[$c] = array_intersect_key($row, array_flip(['rate_toman', 'at', 'source', 'source_item', 'currency']));
        }
        $pricing = app(CloudPricing::class);
        $fee = $pricing->fxFeePctFor('ovh');
        $margin = Setting::get('cloud_margin_pct_ovh');
        $margin = is_numeric($margin) ? max(0, min(500, (float) $margin)) : $pricing->marginPct('ovh');
        // Include supplier tax and transfer fee once; customer VAT is not revenue.
        $costIrt = (int) ceil($cost * $fx[$currency]['rate_toman'] / 100 * (1 + $fee / 100));
        $renewIrt = (int) ceil($renew * $cost / $netMinor * $fx[$currency]['rate_toman'] / 100 * (1 + $fee / 100));
        $revenueIrt = $revenueCurrency === 'IRT' ? $revenue : (int) floor($revenue * $fx[$revenueCurrency]['rate_toman'] / 100);
        if ($revenueIrt < (int) ceil(max($costIrt, $renewIrt) * (1 + $margin / 100))) {
            throw new OvhOrderHold('margin_shortfall', 'درآمد خالص فاکتور، بهای خرید/تمدید و حاشیهٔ سود تعیین‌شده را پوشش نمی‌دهد.');
        }
        return [
            'invoice_id' => $invoice->id, 'currency' => $currency, 'cost_minor' => $cost,
            'renewal_minor' => $renew, 'revenue_minor' => $revenue, 'revenue_currency' => $revenueCurrency,
            'cost_irt' => $costIrt, 'revenue_irt' => $revenueIrt, 'profit_irt' => $revenueIrt - $costIrt,
            'fx_snapshot' => ['rates' => $fx, 'fee_pct' => $fee, 'margin_pct' => $margin, 'at' => now()->toIso8601String()],
            // Contracts, payment URLs and raw account data are deliberately not persisted.
            'quote' => ['with_tax_minor' => $cost, 'without_tax_minor' => $netMinor, 'tax_minor' => $taxMinor, 'renewal' => $renewal],
            'financial_state' => 'customer_paid_held',
        ];
    }
}
