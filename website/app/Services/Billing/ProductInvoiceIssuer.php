<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;

/**
 * پیش‌فاکتورِ اولین دورهٔ یک پکیج — شاملِ هزینهٔ راه‌اندازی.
 *
 * ═══ چرا از `StoreController` بیرون کشیده شد ═══
 *
 * تا امروز این منطق `private` داخلِ تسویهٔ پنلِ مشتری بود، و فروشِ تلفنی از
 * رباتِ بله ناچار بود `ServiceController::issueInvoice()` را صدا بزند — همان
 * که برای **تمدید** نوشته شده و هزینهٔ راه‌اندازی ندارد.
 *
 * 🔴 نتیجه‌اش یک اختلافِ خاموشِ مالی بود: همان پکیج، اگر مشتری خودش از پنل
 * می‌خرید هزینهٔ راه‌اندازی داشت و اگر کارفرما پشتِ تلفن می‌فروخت **نداشت**.
 * هیچ خطایی، هیچ لاگی — فقط پولی که گرفته نمی‌شد.
 *
 * پس یک تعریف، دو فراخوان.
 */
class ProductInvoiceIssuer
{
    public function issue(Service $service, Product $product): Invoice
    {
        $lineTax = fn (int $amount) => (int) round($amount * $service->tax_percent / 100);

        $unitPrice = (int) $service->price;
        $setupFee  = $product->effectiveSetup();

        $subtotal = $unitPrice + $setupFee;
        $tax      = $lineTax($unitPrice) + $lineTax($setupFee);

        $invoice = Invoice::create([
            'customer_id'   => $service->customer_id,
            'service_id'    => $service->id,
            'kind'          => 'service',
            'currency_code' => $service->currency_code,
            'subtotal'      => $subtotal,
            'tax'           => $tax,
            'total'         => $subtotal + $tax,
            'paid'          => 0,
            'status'        => 'unpaid',
            'issued_at'     => now(),
            'note'          => $product->name,
        ]);

        InvoiceItem::create([
            'invoice_id'  => $invoice->id,
            'title'       => $product->name.' ('.$service->cycleLabel().')',
            'description' => $service->domain,
            'quantity'    => 1,
            'unit_price'  => $unitPrice,
            'line_total'  => $unitPrice,
            'tax_rate_bp' => $service->tax_percent * 100,
            'tax_amount'  => $lineTax($unitPrice),
        ]);

        if ($setupFee > 0) {
            InvoiceItem::create([
                'invoice_id'  => $invoice->id,
                'title'       => 'هزینهٔ راه‌اندازی — '.$product->name,
                'quantity'    => 1,
                'unit_price'  => $setupFee,
                'line_total'  => $setupFee,
                'tax_rate_bp' => $service->tax_percent * 100,
                'tax_amount'  => $lineTax($setupFee),
            ]);
        }

        return $invoice;
    }
}
