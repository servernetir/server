<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\BankTransferReceipt;
use App\Models\CreditEntry;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\Payment\GatewayRegistry;
use App\Services\Payment\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    /**
     * نسخهٔ چاپیِ فاکتور — صفحهٔ مستقل و بهینه برای چاپ/ذخیرهٔ PDF.
     *
     * چرا صفحهٔ چاپ و نه PDF سمت سرور: تولید PDF فارسی سمت سرور به mPDF نیاز
     * دارد که هزاران فایل vendor است و بدون SSH روی این سرور نصب نمی‌شود؛ و
     * dompdf حروف فارسی را جدا/برعکس می‌کند. مرورگر فارسی را بی‌نقص رندر
     * می‌کند، پس «ذخیره به‌صورت PDF»ِ خودِ مرورگر یک PDF واقعی و درست می‌دهد.
     */
    public function printInvoice(Invoice $invoice)
    {
        $this->authorizeInvoice($invoice);

        $invoice->load('items', 'payments', 'customer.identityVerification');

        return view('account.invoice-print', [
            'invoice'   => $invoice,
            'paid'      => $invoice->payments->firstWhere('status', 'paid'),
            'contact'   => site_contact(),
            'legalName' => Setting::get('bank_holder'),
            // مهر فقط روی فاکتورِ پرداخت‌شده (رسمی)، نه پیش‌فاکتور — به‌صورت
            // data-uri جاسازی می‌شود تا در PDF هم بیاید
            'stamp'     => (function () use ($invoice) {
                if ($invoice->status !== 'paid' || ! Schema::hasTable('settings')) {
                    return null;
                }
                $p = Setting::get('stamp_path');
                if (! $p || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($p)) {
                    return null;
                }

                return 'data:'.(Setting::get('stamp_mime') ?: 'image/png').';base64,'
                    .base64_encode(\Illuminate\Support\Facades\Storage::disk('local')->get($p));
            })(),
        ]);
    }

    /**
     * لغو/حذف یک فاکتورِ در انتظار پرداخت توسط مشتری — برای تمیز کردن کارتابل.
     *
     * منافع شرکت: فقط فاکتورِ **پرداخت‌نشده** (paid == 0) لغو می‌شود؛ فاکتوری
     * که پولی رویش آمده هرگز با یک کلیک پاک نمی‌شود. با لغو:
     *   • تلاش‌های پرداختِ باز و رسیدهای واریزِ در انتظار باطل می‌شوند
     *     (تا مدیر رسیدِ یک فاکتورِ لغوشده را تأیید نکند)
     *   • اگر فاکتورِ یک سرویسِ هنوز فعال‌نشده بود، آن سرویس لغو می‌شود
     *   • سرویسِ فعالِ در حال تمدید دست‌نخورده می‌ماند (لغوِ فاکتورِ تمدید ≠
     *     قطعِ سرویسِ فعال)
     */
    public function cancel(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorizeInvoice($invoice);

        if ($invoice->status === 'paid' || $invoice->paid > 0) {
            return back()->withErrors(['invoice' => 'فاکتوری که پرداخت روی آن انجام شده قابل حذف نیست.']);
        }

        DB::transaction(function () use ($invoice) {
            $invoice->forceFill(['status' => 'canceled'])->save();

            Payment::where('invoice_id', $invoice->id)
                ->whereIn('status', ['pending', 'redirected'])
                ->update(['status' => 'canceled', 'updated_at' => now()]);

            if (Schema::hasTable('bank_transfer_receipts')) {
                BankTransferReceipt::where('invoice_id', $invoice->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'rejected', 'reject_reason' => 'فاکتور توسط مشتری لغو شد', 'updated_at' => now()]);
            }

            // سرویسِ هنوز-فعال‌نشده → لغو. سرویسِ فعال (تمدید) → دست‌نخورده.
            if ($invoice->service_id !== null && Schema::hasTable('services')) {
                $service = \App\Models\Service::find($invoice->service_id);
                if ($service !== null && $service->status === 'pending') {
                    $service->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
                }
            }

            /*
            | 🔴 دامنه هم باید آزاد شود، وگرنه آن نام برای **همیشه** قفل می‌مانَد.
            |
            | ردیفِ دامنهٔ پرداخت‌نشده `status='pending'` و `provision_status='none'`
            | دارد. تا امروز این‌جا فقط شاخهٔ سرویس بود، پس با لغوِ فاکتور آن ردیف
            | یتیم می‌مانْد — نه مرده بود نه ثبت‌شده. و `order()` سفارشِ دوباره را با
            | «این دامنه از قبل در سامانه ثبت شده است» رد می‌کند (به‌علاوهٔ قیدِ
            | یکتاییِ دیتابیس). یعنی مشتری با یک لغوِ ساده، همان نام را برای خودش
            | **و برای هر مشتریِ دیگری** می‌سوزاند.
            |
            | ⚠️ فقط ردیفی که هرگز پول نگرفته و هرگز ثبت نشده. دامنهٔ `active` یا
            | در حالِ ثبت دست‌نخورده می‌مانَد — لغوِ فاکتورِ تمدید نباید دامنهٔ
            | زندهٔ مشتری را بکُشد.
            */
            if ($invoice->domain_id !== null && Schema::hasTable('domains')) {
                \App\Models\Domain::where('id', $invoice->domain_id)
                    ->where('status', 'pending')
                    ->where('provision_status', 'none')
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);
            }
        });

        return redirect()->route($this->rp().'account.invoices')
            ->with('ok', 'فاکتور لغو شد.'.($invoice->service_id ? ' سرویس مربوطه هم غیرفعال شد.' : ''));
    }

    public function show(Invoice $invoice)
    {
        $this->authorizeInvoice($invoice);

        /*
        | 🔴 پرداختِ بازِ رمزارز **اول** خوانده می‌شود، چون فهرستِ گزینه‌ها به
        |    آن وابسته است.
        |
        | تا امروز کارت‌ها و پنل‌های رمزارز هر دو از `available()` می‌آمدند —
        | یعنی فقط دارایی‌هایی که آدرسِ **آزاد** دارند. با استخرِ تک‌آدرسی، همان
        | لحظه که مشتری آدرس می‌گرفت، آن تنها آدرس مشغول می‌شد و در بارگذاریِ
        | بعدی کلِ گزینهٔ رمزارز — از جمله آدرس و مبلغ و شمارشِ معکوسِ خودِ او —
        | از صفحه غیب می‌شد.
        */
        $cryptoOpen = Schema::hasTable('crypto_payments')
            ? \App\Models\CryptoPayment::where('invoice_id', $invoice->id)
                ->whereIn('status', ['pending', 'seen'])->where('expires_at', '>', now())
                ->latest('id')->first()
            : null;

        return view('account.invoice', AccountController::shell('invoices') + [
            'invoice'      => $invoice->load('items', 'payments'),
            'gateways'     => $this->gatewaysFor($invoice->currency_code),
            'bank'         => $this->bankDetails(),
            // آخرین رسیدِ در انتظارِ همین فاکتور — تا کاربر بداند ثبت شده
            'pendingBank'  => Schema::hasTable('bank_transfer_receipts')
                ? BankTransferReceipt::where('invoice_id', $invoice->id)->where('status', 'pending')->latest('id')->first()
                : null,

            /*
            | مقصدهای آفلاین: حوالهٔ ارزی هم‌ارزِ فاکتور + کیف‌های رمزارز.
            |
            | 🔴 تا امروز مشتریِ en/tr می‌توانست سفارش بدهد ولی صفحهٔ فاکتور
            |    فقط دو کارتِ غیرفعالِ «به‌زودی» نشانش می‌داد — یعنی فاکتوری
            |    داشت که هیچ راهی برای پرداختش نبود. بدترین حالتِ ممکن: پول
            |    نمی‌گرفتیم و مشتری هم فکر می‌کرد سایت خراب است.
            */
            'offline'      => \App\Models\PaymentAccount::forInvoiceCurrency($invoice->currency_code),

            /*
            | رمزارز: هر دارایی با **وضعیتِ خودش** —
            |   ready → قابلِ صدور
            |   busy  → آدرس داریم ولی همه مشغول‌اند («کمی بعد دوباره امتحان کنید»)
            |   open  → پرداختِ بازِ همین مشتری، همیشه دیدنی
            |
            | ⚠️ دارایی‌ای که قیمتش را نداریم اصلاً نمی‌آید — نه ready نه busy.
            */
            'cryptoAssets' => Schema::hasTable('crypto_wallets')
                ? app(\App\Services\Payment\CryptoIssuer::class)->checkout($invoice, $cryptoOpen)
                : [],
            'cryptoOpen'   => $cryptoOpen,
        ]);
    }

    /**
     * صدورِ یک پرداختِ رمزارز — آدرس و مبلغ و مهلت.
     *
     * ⚠️ هیچ پولی این‌جا تسویه نمی‌شود. فقط «به این آدرس این مقدار بفرست» ساخته
     * می‌شود؛ تأیید کارِ کرونِ `crypto:watch` است که زنجیره را می‌خواند.
     */
    public function cryptoIssue(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorizeInvoice($invoice);

        if (! $invoice->isPayable()) {
            return back()->withErrors(['crypto' => __('ui.cy_not_payable')]);
        }

        $asset = (string) $request->input('asset', 'USDT');
        $cp = app(\App\Services\Payment\CryptoIssuer::class)->issue($invoice, $asset);

        /*
        | 🔴 شکست باید **صریح** باشد.
        |
        | سه علتِ ممکن: نرخ به دست نیامد، استخرِ آدرس خالی بود، یا دارایی
        | ناشناخته. در هر سه، صفحه‌ای که آدرسِ خالی یا مبلغِ صفر نشان دهد یعنی
        | مشتری پول را به ناکجا می‌فرستد. پس هیچ‌چیز نشان نمی‌دهیم و می‌گوییم
        | فعلاً در دسترس نیست.
        */
        if ($cp === null) {
            return back()->withErrors(['crypto' => __('ui.cy_unavailable')]);
        }

        return redirect()->route($this->rp().'account.invoice', $invoice)->withFragment('cy-'.$cp->id);
    }

    /**
     * وضعیتِ زندهٔ پرداخت — صفحهٔ مشتری هر چند ثانیه می‌پرسد.
     *
     * ⚠️ عمداً فقط چیزی برمی‌گرداند که خودِ مشتری از قبل می‌بیند: وضعیت، ثانیهٔ
     * باقی‌مانده و مبلغِ رسیده. نه شناسهٔ داخلی، نه کیفِ ما، نه چیزی از
     * فاکتورِ دیگران.
     */
    public function cryptoStatus(Invoice $invoice)
    {
        $this->authorizeInvoice($invoice);

        $cp = \App\Models\CryptoPayment::where('invoice_id', $invoice->id)
            ->latest('id')->first();

        if ($cp === null) {
            return response()->json(['status' => 'none']);
        }

        return response()->json([
            'status' => $cp->status,
            'seconds_left' => $cp->secondsLeft(),
            'received' => $cp->received_atomic,
            'expected' => $cp->amount_atomic,
            'invoice_paid' => $invoice->fresh()->status === 'paid',
        ]);
    }

    /** مشخصات حساب شرکت برای «واریز به حساب» — از تنظیماتِ قابل‌ویرایشِ مدیر */
    private function bankDetails(): ?array
    {
        if (! Schema::hasTable('settings') || ! Setting::bankReady()) {
            return null;
        }

        return [
            'holder'  => Setting::get('bank_holder'),
            'bank'    => Setting::get('bank_name'),
            'account' => Setting::get('bank_account'),
            'sheba'   => Setting::get('bank_sheba'),
            'card'    => Setting::get('bank_card'),
            'note'    => Setting::get('bank_note'),
        ];
    }

    /**
     * ثبت رسیدِ «واریز به حساب».
     *
     * پول همین‌جا تسویه نمی‌شود — فقط یک رسیدِ «در انتظار» ساخته می‌شود تا
     * مدیر تأییدش کند. بدون تأیید، پول واقعاً به حساب ما نرسیده و نباید سرویس
     * فعال شود.
     */
    public function bankTransfer(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorizeInvoice($invoice);

        if (! $invoice->isPayable()) {
            return back()->withErrors(['reference' => 'این فاکتور قابل پرداخت نیست.']);
        }

        $data = $request->validate([
            'reference' => ['required', 'string', 'max:120'],
            'paid_from' => ['nullable', 'string', 'max:120'],
            'note'      => ['nullable', 'string', 'max:500'],
            'payment_account_id' => ['nullable', 'integer'],
            'sent_amount' => ['nullable', 'numeric', 'min:0'],
        ], [], ['reference' => 'شناسهٔ پرداخت']);

        /*
        | مقصد یا حسابِ ریالیِ تنظیمات است یا یکی از حساب‌های ارزی/رمزارزی.
        |
        | ⚠️ شناسهٔ حساب از **فهرستِ همین فاکتور** اعتبارسنجی می‌شود، نه فقط
        |    «در جدول هست». وگرنه کاربر می‌توانست شناسهٔ یک حسابِ بایگانی‌شده یا
        |    ارزِ دیگری را بفرستد و رسیدی بسازد که مدیر هرگز نمی‌تواند تطبیق دهد.
        */
        $account = null;

        if (! empty($data['payment_account_id'])) {
            $account = \App\Models\PaymentAccount::forInvoiceCurrency($invoice->currency_code)
                ->firstWhere('id', (int) $data['payment_account_id']);

            if ($account === null) {
                return back()->withErrors(['reference' => 'روش پرداخت انتخاب‌شده در دسترس نیست.']);
            }
        } elseif ($this->bankDetails() === null) {
            return back()->withErrors(['reference' => 'واریز به حساب فعلاً در دسترس نیست.']);
        }

        // رسیدِ باز تکراری نساز
        $exists = BankTransferReceipt::where('invoice_id', $invoice->id)
            ->where('status', 'pending')->exists();

        if (! $exists) {
            BankTransferReceipt::create([
                'customer_id' => $invoice->customer_id,
                'invoice_id'  => $invoice->id,
                'amount'      => $invoice->due(),
                'reference'   => $data['reference'],
                'paid_from'   => $data['paid_from'] ?? null,
                'note'        => $data['note'] ?? null,
                'status'      => 'pending',

                // ⚠️ «به کدام حساب» و «چقدر فرستاد» — بی‌اینها رسیدِ ارزی
                //    قابلِ تطبیق نیست و مدیر پرداختِ درست را رد می‌کند.
                'payment_account_id' => $account?->id,
                'sent_amount'   => isset($data['sent_amount']) ? (int) round((float) $data['sent_amount'] * 100) : null,
                'sent_currency' => $account?->currency_code,
            ]);

            /*
            | 🔴 مدیر باید بداند — این رسید **منتظرِ اوست**.
            |
            | مشتری پول را فرستاده و تا تأییدِ دستی، فاکتور تسویه نمی‌شود و
            | سرویس راه نمی‌افتد. تا امروز تنها راهِ فهمیدن این بود که مدیر
            | خودش `/admin/bank-transfers` را باز کند — یعنی پولِ رسیده
            | می‌توانست روزها بی‌آنکه کسی بداند منتظر بمانَد.
            |
            | ⚠️ **داخلِ** `if (! $exists)` است، نه بیرونش: مشتری‌ای که صفحه را
            | دوباره ارسال کند نباید اعلانِ دوم بسازد. رسیدِ تکراری ساخته
            | نمی‌شود، پس اعلانش هم نباید برود.
            */
            app(\App\Services\Notify\Notifier::class)->fire(
                'bank_receipt',
                $invoice->customer,
                [
                    'number' => (string) $invoice->number,
                    'amount' => invoice_money($invoice->due(), $invoice->currency_code ?: 'IRT'),
                ],
                '',                               // مخاطب فقط مدیر است
                [
                    'شناسهٔ پرداخت' => (string) $data['reference'],
                    'به حساب'      => $account?->label,
                ],
                url('/admin/bank-transfers'),
                '🧾',
            );
        }

        \App\Models\ActivityLog::record($invoice->customer_id, 'bank_receipt',
            'رسید واریز برای فاکتور '.$invoice->number.' با شناسهٔ '.$data['reference'].' ثبت شد', $request, 'customer');

        return redirect()->route($this->rp().'account.invoice', $invoice)
            ->with('ok', 'رسید واریز شما ثبت شد و در انتظار تأیید پشتیبانی است. پس از تأیید، فاکتور تسویه و سرویس فعال می‌شود.');
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
        // بله هم نشان داده می‌شود حتی اگر کاربر هنوز وصل نکرده باشد — کارفرما
        // خواست کنار زرین‌پال گزینه‌اش باشد. اگر وصل نبود، هنگام انتخاب پیامِ
        // «اول حسابتان را در ربات بله وصل کنید» می‌گیرد (در BaleGateway).
        return $this->gateways->availableFor($currency);
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

        // روش پرداخت دیگر این‌جا انتخاب نمی‌شود؛ فقط مبلغ. بعد به صفحهٔ فاکتور
        // می‌رود که همهٔ روش‌ها (آنلاین، بله، واریز به حساب، کریپتو) آن‌جاست.
        return view('account.topup', AccountController::shell('invoices') + [
            'balance' => $this->balance($customer->id),
        ]);
    }

    /**
     * افزایش اعتبار یک فاکتور می‌سازد و به صفحهٔ فاکتور می‌برد تا کاربر روش
     * پرداخت را انتخاب کند (آنلاین/بله/واریز به حساب).
     *
     * چرا فاکتور و نه پرداخت مستقیم: تا هر ریالی که وارد سیستم می‌شود یک
     * سند داشته باشد. حسابداریِ بدون سند، همان چیزی است که بعداً قابل
     * ممیزی نیست.
     */
    public function topup(Request $request): RedirectResponse
    {
        $customer = Auth::guard('customer')->user();

        $request->validate([
            'amount' => ['required', 'integer', 'min:1000', 'max:'.self::MAX_TOPUP],
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

        // به صفحهٔ فاکتور که همهٔ روش‌های پرداخت آن‌جاست
        return redirect()->route($this->rp().'account.invoice', $invoice);
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
