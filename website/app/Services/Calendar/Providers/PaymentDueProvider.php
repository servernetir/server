<?php

namespace App\Services\Calendar\Providers;

use App\Models\Invoice;
use App\Services\Calendar\CalendarEventProvider;
use App\Services\Calendar\CalendarItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * سررسیدِ فاکتورهای پرداخت‌نشده — از `invoices.due_at`.
 *
 * ⚠️ فاکتورِ `paid`/`void`/`refunded` این‌جا نمی‌آید. تقویم فهرستِ **کارهای
 * باز** است؛ فاکتورِ پرداخت‌شده کاری برای انجام ندارد و اگر بماند، ماهِ شلوغ
 * یک دیوارِ قرمز می‌شود و مدیر از دیدنش صرف‌نظر می‌کند — همان درسِ «۹۶ اعلانِ
 * تکراری در روز» که هشدارِ واقعی را بی‌اثر کرد.
 */
class PaymentDueProvider implements CalendarEventProvider
{
    use CapsLayerRows;

    /**
     * وضعیت‌هایی که یعنی «هنوز پولی نیامده».
     *
     * `draft` عمداً هست: پیش‌فاکتوری که صادر شده ولی هنوز نهایی نیست هم پولِ
     * در راه است و مدیر باید سررسیدش را ببیند.
     *
     * @var list<string>
     */
    private const OPEN_STATUSES = ['unpaid', 'draft', 'overdue'];

    public function getEvents(Carbon $from, Carbon $to): Collection
    {
        if (! Schema::hasTable('invoices')) {
            return collect();
        }

        return Invoice::query()
            ->whereIn('status', self::OPEN_STATUSES)
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [$from, $to])
            ->orderBy('due_at')
            ->limit($this->rowCap())
            ->get()
            ->map(fn (Invoice $invoice) => new CalendarItem(
                type: 'payment_due',
                source: 'invoice',
                sourceId: $invoice->id,
                title: 'فاکتور '.fa_num((string) $invoice->number),
                description: $this->describe($invoice),
                at: $invoice->due_at,
                status: 'pending',
                meta: [
                    'invoice_id'  => $invoice->id,
                    'customer_id' => $invoice->customer_id,
                    'number'      => $invoice->number,
                    'total'       => (int) $invoice->total,
                    'paid'        => (int) $invoice->paid,
                    'currency'    => $invoice->currency_code,
                    'state'       => $invoice->status,
                ],
                // پروندهٔ مشتری، جایی که فاکتور و دکمه‌هایش هست
                url: $invoice->customer_id ? '/admin/customers/'.$invoice->customer_id : null,
                editable: false,
            ));
    }

    private function describe(Invoice $invoice): string
    {
        $currency = (string) ($invoice->currency_code ?: 'IRT');
        $parts = [invoice_money((int) $invoice->total, $currency)];

        // پرداختِ جزئی: «۲ میلیون از ۵ میلیون» — عددی که تصمیمِ پیگیری را عوض می‌کند
        if ($invoice->paid > 0 && $invoice->paid < $invoice->total) {
            $parts[] = 'پرداخت‌شده: '.invoice_money((int) $invoice->paid, $currency);
        }

        return implode(' — ', $parts);
    }
}
