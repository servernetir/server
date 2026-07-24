<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فروش و مدیریت سرویس‌های مشتری — سمت کارکنان.
 *
 * جریان کارفرما: در پروندهٔ مشتری یک سرویس می‌سازد (نام، توضیحات، مبلغ،
 * دوره). سیستم همان لحظه یک **پیش‌فاکتور** می‌سازد؛ سرویس در حالت «منتظر
 * پرداخت» می‌ماند تا مشتری پرداخت کند و آن‌گاه خودکار فعال می‌شود
 * (در PaymentService، هنگام تسویه).
 */
class ServiceController extends Controller
{
    /**
     * فروش سرویس به یک مشتری + ساخت پیش‌فاکتور، همه در یک تراکنش.
     */
    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price'       => ['required', 'integer', 'min:0', 'max:100000000000'],
            'tax_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'cycle'       => ['required', 'in:once,monthly,quarterly,yearly'],
        ], [], [
            'name' => 'نام سرویس', 'price' => 'مبلغ', 'cycle' => 'دوره',
        ]);

        $taxPct = (int) ($data['tax_percent'] ?? 0);

        $service = DB::transaction(function () use ($customer, $data, $taxPct, $request) {
            $service = Service::create([
                'customer_id'   => $customer->id,
                'name'          => $data['name'],
                'description'   => $data['description'] ?? null,
                'currency_code' => 'IRT',
                'price'         => (int) $data['price'],
                'tax_percent'   => $taxPct,
                'cycle'         => $data['cycle'],
                'status'        => 'pending',
                'created_by'    => $request->user()?->id,
            ]);

            $this->issueInvoice($service);

            return $service;
        });

        return redirect("/admin/customers/{$customer->id}")
            ->with('ok', 'سرویس «'.$service->name.'» ساخته شد و پیش‌فاکتور صادر گردید. پس از پرداخت مشتری، خودکار فعال می‌شود.');
    }

    /**
     * صدور یک فاکتور برای یک دورهٔ سرویس (اولین صدور یا تمدید).
     *
     * public و static-مانند تا فرمان تمدیدِ دوره‌ای هم بتواند از همین منطق
     * استفاده کند — یک جای واحد برای «فاکتور یک سرویس چه شکلی است».
     */
    public function issueInvoice(Service $service): Invoice
    {
        $subtotal = $service->price;
        $tax      = $service->taxAmount();
        $total    = $subtotal + $tax;

        $invoice = Invoice::create([
            'customer_id'   => $service->customer_id,
            'service_id'    => $service->id,
            'kind'          => 'service',
            'currency_code' => $service->currency_code,
            'subtotal'      => $subtotal,
            'tax'           => $tax,
            'total'         => $total,
            'paid'          => 0,
            'status'        => 'unpaid',
            'issued_at'     => now(),
            'note'          => $service->name,
        ]);

        InvoiceItem::create([
            'invoice_id'  => $invoice->id,
            'title'       => $service->name.' ('.$service->cycleLabel().')',
            'description' => $service->description,
            'quantity'    => 1,
            'unit_price'  => $subtotal,
            'line_total'  => $subtotal,
            'tax_rate_bp' => $service->tax_percent * 100,   // درصد → basis-points
            'tax_amount'  => $tax,
        ]);

        return $invoice;
    }

    /**
     * تغییر وضعیت سرویس — تعلیق، فعال‌سازی دستی، لغو.
     */
    public function update(Request $request, Service $service): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,suspended,cancelled'],
        ]);

        $service->status = $data['status'];
        if ($data['status'] === 'cancelled') {
            $service->cancelled_at = now();
        }
        if ($data['status'] === 'active' && $service->activated_at === null) {
            $service->activated_at = now();
        }
        $service->save();

        return back()->with('ok', 'وضعیت سرویس به‌روزرسانی شد.');
    }

    /** صدور دستیِ فاکتور تمدید برای یک سرویس (وقتی کارفرما زودتر می‌خواهد) */
    public function renew(Service $service): RedirectResponse
    {
        if (! $service->isRecurring()) {
            return back()->withErrors('این سرویس دوره‌ای نیست و تمدید ندارد.');
        }

        $this->issueInvoice($service);

        return back()->with('ok', 'فاکتور تمدید صادر شد؛ پس از پرداخت، سررسید سرویس یک دوره جلو می‌رود.');
    }
}
