<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\CreditEntry;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Services\Payment\GatewayRegistry;
use App\Services\Payment\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * فاکتور، پرداخت، اعتبار.
 *
 * ═══ نکتهٔ امنیتی که ساده به نظر می‌رسد و نیست ═══
 *
 * مسیر بازگشت از درگاه (callback) **بدون احراز هویت** است و باید باشد:
 * کاربر ممکن است در مرورگر دیگری برگردد یا نشستش منقضی شده باشد. پس آن
 * مسیر حق ندارد به «کاربر واردشده» تکیه کند. پرداخت را فقط از روی
 * Authority پیدا می‌کند، و چون آن ستون unique است، دقیقاً یک ردیف می‌دهد.
 *
 * در مقابل، همهٔ مسیرهای دیگر مالکیت را صریح بررسی می‌کنند: فاکتور باید
 * مال همین مشتری باشد، وگرنه ۴۰۴ — نه ۴۰۳، چون ۴۰۳ تأیید می‌کند که آن
 * فاکتور وجود دارد.
 */
class PaymentController extends Controller
{
    /** سقف افزایش اعتبار در یک تراکنش */
    private const MAX_TOPUP = 500_000_000;

    public function __construct(
        private PaymentService $payments,
        private GatewayRegistry $gateways,
    ) {}

    // ───────────────────────────── فاکتورها ─────────────────────────────

    public function index()
    {
        $customer = Auth::guard('customer')->user();

        return view('account.invoices', AccountController::shell('invoices') + [
            'invoices' => $customer->invoices()->latest('id')->paginate(15),
            'balance'  => $this->balance($customer->id),
        ]);
    }

    public function show(Invoice $invoice)
    {
        $this->authorizeInvoice($invoice);

        return view('account.invoice', AccountController::shell('invoices') + [
            'invoice'  => $invoice->load('items', 'payments'),
            'gateways' => $this->gatewaysFor($invoice->currency_code),
        ]);
    }

    /**
     * درگاه‌های قابل نمایش برای این مشتری.
     *
     * بله فقط به مشتری‌ای نشان داده می‌شود که بله را وصل کرده — وگرنه گزینه‌ای
     * می‌بیند که با انتخابش خطای «اول بله را وصل کنید» می‌گیرد. بهتر است اصلاً
     * نبیندش تا وقتی آماده است.
     */
    private function gatewaysFor(string $currency): array
    {
        $gateways = $this->gateways->availableFor($currency);

        $customer = Auth::guard('customer')->user();
        $hasBale  = \Illuminate\Support\Facades\Schema::hasTable('bale_contacts')
            && filled($customer?->phone)
            && \App\Models\BaleContact::chatIdFor((string) $customer->phone) !== null;

        if (! $hasBale) {
            unset($gateways['bale']);
        }

        return $gateways;
    }

    // ───────────────────────────── پرداخت ─────────────────────────────

    public function pay(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorizeInvoice($invoice);

        $request->validate(['gateway' => ['required', 'string', 'max:24']]);

        $outcome = $this->payments->begin($invoice, $request->string('gateway')->toString(), $request);

        return $this->afterBegin($outcome, $invoice);
    }

    /**
     * بعد از شروع پرداخت: درگاه هدایتی (زرین‌پال) → away؛ درگاه دستوری
     * (بله: فاکتور در چت کاربر) → برگشت با پیام «در بله پرداخت کنید».
     */
    private function afterBegin(\App\Services\Payment\StartOutcome $outcome, Invoice $invoice): RedirectResponse
    {
        if (! $outcome->ok) {
            return back()->withErrors(['gateway' => $outcome->error]);
        }

        if ($outcome->redirectUrl !== null) {
            return redirect()->away($outcome->redirectUrl);
        }

        // بله: فاکتور به چت کاربر رفت؛ تسویه با وب‌هوک انجام می‌شود
        $message = $outcome->instructions['message'] ?? 'فاکتور فرستاده شد؛ برای تکمیل، پرداخت را انجام دهید.';

        return redirect()->route($this->rp().'account.invoice', $invoice)->with('ok', $message);
    }

    /**
     * بازگشت از درگاه — بدون احراز هویت، عمداً.
     */
    public function callback(Request $request, string $gateway)
    {
        $ref = (string) ($request->query('Authority') ?? $request->query('authority') ?? '');

        $payment = $ref === ''
            ? null
            : Payment::where('gateway', $gateway)->where('external_ref', $ref)->first();

        if ($payment === null) {
            return view('account.payment-result', [
                'ok'      => false,
                'message' => 'این پرداخت شناسایی نشد. اگر مبلغی از حساب شما کم شده، شمارهٔ پیگیری بانک را برای پشتیبانی بفرستید.',
                'payment' => null,
            ]);
        }

        $outcome = $this->payments->settle($payment, $request->query());

        return view('account.payment-result', [
            'ok'       => $outcome->ok,
            'canceled' => $outcome->canceled,
            'message'  => $outcome->ok
                ? 'پرداخت شما با موفقیت انجام شد.'
                : $outcome->error,
            'payment'  => $outcome->payment?->fresh('invoice'),
        ]);
    }

    // ─────────────────────────── افزایش اعتبار ───────────────────────────

    public function topupForm()
    {
        $customer = Auth::guard('customer')->user();

        return view('account.topup', AccountController::shell('invoices') + [
            'balance'  => $this->balance($customer->id),
            'gateways' => $this->gatewaysFor('IRT'),
        ]);
    }

    /**
     * افزایش اعتبار یک فاکتور می‌سازد و مستقیم به درگاه می‌برد.
     *
     * چرا فاکتور و نه پرداخت مستقیم: تا هر ریالی که وارد سیستم می‌شود یک
     * سند داشته باشد. حسابداریِ بدون سند، همان چیزی است که بعداً قابل
     * ممیزی نیست.
     */
    public function topup(Request $request): RedirectResponse
    {
        $customer = Auth::guard('customer')->user();

        $request->validate([
            'amount'  => ['required', 'integer', 'min:1000', 'max:'.self::MAX_TOPUP],
            'gateway' => ['required', 'string', 'max:24'],
        ], [], ['amount' => 'مبلغ']);

        $amount = (int) $request->integer('amount');

        $invoice = DB::transaction(function () use ($customer, $amount) {
            $invoice = Invoice::create([
                'customer_id'   => $customer->id,
                'kind'          => 'topup',
                'currency_code' => 'IRT',
                'subtotal'      => $amount,
                // افزایش اعتبار خودش خدمت نیست، پس مالیات ندارد؛ مالیات
                // موقع مصرف اعتبار روی فاکتور خدمت اعمال می‌شود
                'tax'           => 0,
                'total'         => $amount,
                'status'        => 'unpaid',
                'issued_at'     => now(),
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'title'      => 'افزایش اعتبار حساب کاربری',
                'quantity'   => 1,
                'unit_price' => $amount,
                'line_total' => $amount,
            ]);

            return $invoice;
        });

        $outcome = $this->payments->begin($invoice, $request->string('gateway')->toString(), $request);

        // بله فاکتور را به چت می‌فرستد (redirectUrl ندارد)؛ زرین‌پال away
        return $this->afterBegin($outcome, $invoice);
    }

    // ───────────────────────────── کمکی‌ها ─────────────────────────────

    private function rp(): string
    {
        return \App\Providers\AppServiceProvider::LOCALES[app()->getLocale()] ?? '';
    }

    /** موجودی = جمع سطرهای دفتر، نه یک ستون قابل‌تغییر */
    private function balance(int $customerId, string $currency = 'IRT'): int
    {
        return (int) CreditEntry::where('customer_id', $customerId)
            ->where('currency_code', $currency)
            ->sum('amount');
    }

    /** ۴۰۴ و نه ۴۰۳ — وگرنه وجود فاکتور دیگران تأیید می‌شود */
    private function authorizeInvoice(Invoice $invoice): void
    {
        abort_if($invoice->customer_id !== Auth::guard('customer')->id(), 404);
    }
}
