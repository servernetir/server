<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BankTransferReceipt;
use App\Models\Payment;
use App\Services\Notify\CustomerNotifier;
use App\Services\Payment\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * تأیید «واریز به حساب» — پرداخت‌های دستی.
 *
 * تأیید، همان مسیرِ تسویهٔ درگاه‌های وب‌هوکی را می‌رود (یک Payment می‌سازد و
 * settleConfirmed می‌کند). پس فعال‌سازی سرویس، شارژ اعتبار و ثبت درآمد همه
 * خودکار و از یک جای واحد انجام می‌شود — نه منطق تسویهٔ موازی.
 */
class BankTransferController extends Controller
{
    public function index(Request $request): View
    {
        $ready  = Schema::hasTable('bank_transfer_receipts');
        $filter = (string) $request->query('status', 'pending');
        $q      = trim((string) $request->query('q', ''));

        $query = $ready
            ? BankTransferReceipt::with(['customer', 'invoice'])->latest('id')
            : null;

        if ($query && in_array($filter, ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $filter);
        }

        // جستجو: شناسهٔ پیگیریِ رسید یا مشتری (کد/ایمیل/موبایل)
        if ($query && $q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('reference', 'like', "%{$q}%")
                    ->orWhereHas('customer', function ($c) use ($q) {
                        $c->where('code', 'like', "%{$q}%")
                            ->orWhere('email', 'like', "%{$q}%")
                            ->orWhere('phone', 'like', "%{$q}%");
                    });
            });
        }

        return view('admin.bank-transfers', [
            'receipts' => $ready ? $query->paginate(30)->withQueryString() : collect()->paginate(30),
            'filter'   => $filter,
            'q'        => $q,
            'counts'   => [
                'pending'  => $ready ? BankTransferReceipt::where('status', 'pending')->count() : 0,
                'approved' => $ready ? BankTransferReceipt::where('status', 'approved')->count() : 0,
                'rejected' => $ready ? BankTransferReceipt::where('status', 'rejected')->count() : 0,
            ],
            'notReady' => ! $ready,
        ]);
    }

    public function approve(Request $request, BankTransferReceipt $receipt): RedirectResponse
    {
        if (! $receipt->isPending()) {
            return back()->withErrors('این رسید قبلاً بررسی شده است.');
        }

        $invoice = $receipt->invoice;

        // فاکتور هنوز قابل پرداخت است → تسویه‌اش کن؛ وگرنه فقط رسید را ببند
        if ($invoice !== null && $invoice->isPayable()) {
            // ⚠️ firstOrCreate و نه create: اگر تلاشِ قبلیِ تأیید وسطِ راه شکست
            // خورده باشد، ردیفِ Payment ساخته و **کامیت** شده (بیرونِ تراکنشِ
            // تسویه) ولی رسید هنوز pending مانده. با create، هر تلاشِ دوباره به
            // یکتاییِ external_ref می‌خورد و ۵۰۰ می‌داد — یعنی تأییدِ آن رسید
            // برای همیشه قفل می‌شد. حالا همان پرداخت بازاستفاده می‌شود.
            $payment = Payment::firstOrCreate(
                ['external_ref' => $receipt->reference],
                [
                    'invoice_id'    => $invoice->id,
                    'customer_id'   => $invoice->customer_id,
                    'gateway'       => 'bank_transfer',
                    'currency_code' => $invoice->currency_code,
                    'amount'        => $invoice->due(),
                    'status'        => 'redirected',
                ],
            );

            // پرداختِ قبلاً تسویه‌شده را دوباره تسویه نمی‌کنیم (اعتبارِ دوباره ندهد)
            if (! $payment->isPaid()) {
                app(PaymentService::class)->settleConfirmed($payment, $receipt->reference);
            }
        }

        $receipt->forceFill([
            'status'      => 'approved',
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
        ])->save();

        \App\Models\ActivityLog::record($receipt->customer_id, 'bank_approved',
            'واریز به حساب با شناسهٔ '.$receipt->reference.' تأیید شد', $request, 'staff');

        return back()->with('ok', 'واریز تأیید شد؛ فاکتور تسویه و سرویس/اعتبار مربوطه اعمال شد.');
    }

    public function reject(Request $request, BankTransferReceipt $receipt): RedirectResponse
    {
        if (! $receipt->isPending()) {
            return back()->withErrors('این رسید قبلاً بررسی شده است.');
        }

        $data = $request->validate([
            'reject_reason' => ['nullable', 'string', 'max:300'],
        ]);

        $receipt->forceFill([
            'status'        => 'rejected',
            'reject_reason' => $data['reject_reason'] ?? null,
            'reviewed_by'   => $request->user()?->id,
            'reviewed_at'   => now(),
        ])->save();

        // اطلاع به مشتری — پیامک و بله
        if ($receipt->customer !== null) {
            try {
                app(CustomerNotifier::class)->event(
                    $receipt->customer,
                    'bank_rejected',
                    // متغیر لازم است وگرنه الگوی /admin/templates بی‌اثر می‌مانَد
                    ['reason' => $data['reject_reason'] ?? '' ? 'علت: '.$data['reject_reason'] : ''],
                    'رسید واریز شما تأیید نشد.'.($data['reject_reason'] ?? '' ? ' علت: '.$data['reject_reason'] : '').' برای پیگیری با پشتیبانی در تماس باشید.',
                );
            } catch (\Throwable) {
            }
        }

        return back()->with('ok', 'رسید رد شد و به مشتری اطلاع داده شد.');
    }
}
