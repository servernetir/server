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
use App\Services\Analytics\DataLayerService;
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

        /*
        | 🔴 خریدارِ حقوقی، نه شخصِ پشتِ حساب.
        |
        | تا امروز این‌جا همیشه `displayName()` می‌نشست — نامِ شخصِ حقیقیِ
        | صاحبِ حساب. مشتری‌ای که اطلاعاتِ شرکتش را وارد کرده بود، هم روی
        | پیش‌فاکتور و هم روی فاکتورِ فروش نامِ خودش را می‌دید؛ سندی که برای
        | دفاترِ آن شرکت بی‌مصرف است و ارزش افزوده‌اش هم قابلِ استفاده نیست.
        |
        | ⚠️ هویت **زنده** خوانده می‌شود، نه منجمد روی فاکتور: جدولِ
        | `invoices` ستونی برای پروفایل ندارد. پس اگر مشتری بعداً نامِ شرکتش
        | را عوض کند، فاکتورهای قدیمی هم عوض می‌شوند. منجمدکردنش یک ستونِ
        | `billing_profile_id` می‌خواهد — کارِ جدا.
        */
        $buyerProfile = $invoice->customer?->billingProfile();

        return view('account.invoice-print', [
            'invoice'   => $invoice,
            'paid'      => $invoice->payments->firstWhere('status', 'paid'),
            'contact'   => site_contact(),
            // نامِ شرکت اگر پروفایلِ حقوقی هست، وگرنه همان نامِ شخصیِ قبلی
            'buyerName'     => $buyerProfile?->displayName()
                ?: ($invoice->customer?->displayName() ?? '—'),
            'buyerIdentity' => $buyerProfile?->invoiceIdentity() ?? [],
            'buyerAddress'  => $buyerProfile?->invoiceAddress(),
            'buyerPhone'    => $buyerProfile?->mobile ?: $invoice->customer?->phone,
            /*
            | 🔴 نامِ ثبتی از **هویتِ حقوقیِ شرکت** می‌آید، نه از `bank_holder`.
            |
            | `bank_holder` نامِ صاحبِ حسابِ بانکی است و لزوماً نامِ ثبتیِ شرکت
            | نیست؛ نشاندنش روی فاکتورِ رسمی یعنی سندی که نامِ حقوقیِ اشتباه
            | دارد. حالا مدیر یک‌جا در `/admin/settings` واردش می‌کند و همه‌جا
            | یکی است: صفحهٔ تماس، دادهٔ ساختاریافته، و فاکتور.
            |
            | ⚠️ `bank_holder` راهِ دوم می‌مانَد تا نصبی که هنوز هویتِ حقوقی را
            | پر نکرده، فاکتورِ بی‌نامِ فروشنده ندهد.
            */
            'legalName'      => company_value('legal_name') ?: Setting::get('bank_holder'),
            // شناسه‌های ثبتی و نشانی — هرچه پر نباشد اصلاً چاپ نمی‌شود
            'sellerIdentity' => company_identity(),
            'sellerAddress'  => company_address(),
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
            return back()->withErrors(['invoice' => __('ui.iv_no_delete_paid')]);
        }

        /*
        | 🔴 منطقِ واقعی در `InvoiceCanceller` است، نه این‌جا.
        |
        | از وقتی کرونِ انقضای ۷۲ ساعته هم فاکتور لغو می‌کند، دو فراخوان داریم.
        | تعریفِ دومِ «لغو» یعنی دو تعریف که روزی واگرا می‌شوند — و یک طرفِ
        | واگرایی، سابقهٔ مالی است.
        |
        | ⚠️ مشتری رسیدِ بانکیِ در انتظار را **رد** می‌کند (خودش دارد لغو
        | می‌کند)، ولی کرون نه: رسیدِ در انتظار یعنی آدمی ادعا کرده پولِ واقعی
        | فرستاده، و ردِ خودکارش یعنی گم‌کردنِ پول.
        */
        app(\App\Services\Billing\InvoiceCanceller::class)
            ->cancel($invoice, 'فاکتور توسط مشتری لغو شد', rejectPendingReceipt: true);

        return redirect()->route($this->rp().'account.invoices')
            ->with('ok', $invoice->service_id ? __('ui.iv_canceled_svc') : __('ui.iv_canceled'));
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
            // موجودیِ اعتبار — برای دکمهٔ «پرداخت از اعتبار» روی فاکتورهای تومانی
            'creditBalance' => $invoice->currency_code === 'IRT'
                ? $this->balance((int) Auth::guard('customer')->id())
                : 0,
            /*
            | کوپنِ هدیهٔ زندهٔ همین مشتری — فقط برای نمایشِ فرم.
            |
            | ⚠️ `Schema::hasTable` لازم است: تا وقتی مهاجرت روی سرور اجرا
            | نشده، این صفحه نباید ۵۰۰ بدهد. صفحهٔ فاکتور مسیرِ پول است و
            | خرابی‌اش یعنی مشتری نمی‌تواند پرداخت کند.
            */
            'giftCoupon'   => Schema::hasTable('gift_coupons')
                ? \App\Models\GiftCoupon::where('customer_id', (int) Auth::guard('customer')->id())
                    ->whereNull('used_at')->where('expires_at', '>', now())
                    ->latest('id')->first()
                : null,
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
            return back()->withErrors(['reference' => __('ui.iv_not_payable')]);
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
                return back()->withErrors(['reference' => __('ui.iv_gw_unavailable')]);
            }
        } elseif ($this->bankDetails() === null) {
            return back()->withErrors(['reference' => __('ui.iv_bank_unavailable')]);
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
                /*
                | تا شهریور ۱۴۰۵ این‌جا رشتهٔ خالی بود، چون `bank_receipt` در
                | کاتالوگ `audience = ADMIN` داشت و متن اصلاً مصرف نمی‌شد.
                | حالا که کارفرما الگوی پیامکِ خطاب‌به‌مشتری برایش ساخته و
                | مخاطب `BOTH` شده، متنِ خالی یعنی پیامِ بلهٔ **بی‌متن** —
                | پس این‌جا هم باید متنِ واقعی باشد. (پیامک از الگو می‌رود؛
                | این متن پشتیبانِ بله و ایمیل است.)
                */
                'رسیدِ واریزِ شما برای پیش‌فاکتورِ '.fa_num((string) $invoice->number)
                    .' ثبت شد و در انتظارِ بررسی است. نتیجه را همین‌جا اطلاع می‌دهیم.',
                [
                    'شناسهٔ پرداخت' => (string) $data['reference'],
                    'به حساب'      => $account?->label,
                ],
                url('/admin/bank-transfers'),
                '🧾',
            );
        }

        \App\Models\ActivityLog::record($invoice->customer_id, 'bank_receipt',
            __('ui.act_bank_receipt', ['n' => $invoice->number, 'ref' => $data['reference']]), $request, 'customer');

        return redirect()->route($this->rp().'account.invoice', $invoice)
            ->with('ok', __('ui.iv_receipt_saved'));
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

        if ($outcome->ok && ! $outcome->alreadySettled && $outcome->payment !== null) {
            DataLayerService::flashPurchase($outcome->payment);
        }

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

        /*
        | مشتریِ en/tr مبلغ را **یورو** وارد می‌کند (خواستِ کارفرما، ۶ شهریور:
        | «همه‌چیز به یورو باشد؛ تبدیل به تومان فقط در پس‌زمینه»). دفترِ اعتبار
        | و فاکتور تومانی می‌مانند — نمایششان برای en/tr از قبل یورویی است
        | (invoice_money/cloud_price) — پس فقط ورودی تبدیل می‌شود، با همان
        | نرخِ زنده‌ای که قیمت‌های فروشگاه را می‌سازد. گردِ رو به بالا به
        | ۱۰هزار تومان، همان قاعدهٔ CloudPricing.
        */
        if (app()->getLocale() !== 'fa') {
            $request->validate([
                'amount' => ['required', 'integer', 'min:5', 'max:5000'],
            ], [], ['amount' => __('ui.top_amount_label')]);

            $rate = cloud_eur_rate();

            if ($rate <= 0) {
                return back()->withErrors(['amount' => __('ui.top_rate_missing')]);
            }

            $amount = (int) (ceil(((int) $request->integer('amount')) * $rate / 10_000) * 10_000);
        } else {
            $request->validate([
                'amount' => ['required', 'integer', 'min:1000', 'max:'.self::MAX_TOPUP],
            ], [], ['amount' => __('ui.top_amount_label')]);

            $amount = (int) $request->integer('amount');
        }

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
                'title'      => __('ui.top_item_title'),
                'quantity'   => 1,
                'unit_price' => $amount,
                'line_total' => $amount,
            ]);

            return $invoice;
        });

        // به صفحهٔ فاکتور که همهٔ روش‌های پرداخت آن‌جاست
        return redirect()->route($this->rp().'account.invoice', $invoice);
    }

    /**
     * پرداختِ فاکتور از اعتبارِ داخلی.
     *
     * ═══ چرا (ممیزی + خواستهٔ کارفرما، ۳ شهریور ۱۴۰۵) ═══
     *
     * 🔴 هر بازگشتِ وجهِ خودکار (ثبت/تمدید/انتقالِ ناموفقِ دامنه، فاکتورِ
     * لغوشده) به `credit_ledger` می‌نشیند و به مشتری می‌گوییم «می‌توانید
     * دوباره اقدام کنید» — ولی هیچ درگاهی برای خرجِ آن اعتبار روی فاکتور
     * نبود. پولی که مشتری می‌دید و نمی‌توانست خرج کند.
     *
     * ═══ قواعد ═══
     *
     * • تسویه از **همان** مسیرِ رسمیِ درگاه‌ها می‌رود (`settleConfirmed` با
     *   یک ردیفِ Payment از جنسِ `credit`) — پس فعال‌سازیِ سرویس، صف‌گذاریِ
     *   دامنه و ثبتِ درآمد همه خودکار و از یک جا. منطقِ تسویهٔ موازی ممنوع
     *   (همان قاعدهٔ BankReceiptReviewer).
     * • درآمدِ اعتبارِ خرج‌شده همین‌جا شناسایی می‌شود — `recordPayment` روی
     *   topup عمداً هیچ نمی‌نویسد و می‌گوید «درآمد موقعِ مصرفِ اعتبار».
     * • فقط پرداختِ **کامل**ِ مانده: پرداختِ جزئی حالت‌های partial را باز
     *   می‌کند که هیچ مصرف‌کننده‌ای درست نمی‌شناسدشان.
     * • فاکتورِ topup با اعتبار پرداخت نمی‌شود — اعتبار با اعتبار، دور است.
     * • کسرِ اعتبار و ساختِ Payment و تسویه همه در **یک** تراکنش با قفلِ
     *   ردیفِ مشتری‌اند؛ دو کلیکِ هم‌زمان یک موجودی را دو بار خرج نمی‌کند.
     */
    public function payCredit(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorizeInvoice($invoice);

        if ($invoice->kind === 'topup') {
            return back()->withErrors(__('ui.iv_credit_self'));
        }

        if (! in_array($invoice->status, ['unpaid', 'partial'], true) || $invoice->due() <= 0) {
            return back()->withErrors(__('ui.iv_credit_state'));
        }

        if ($invoice->currency_code !== 'IRT') {
            return back()->withErrors(__('ui.iv_credit_irt_only'));
        }

        $customerId = (int) Auth::guard('customer')->id();

        try {
            $outcome = DB::transaction(function () use ($invoice, $customerId) {
                // قفلِ مشتری: موازی‌ترین دشمنِ این مسیر دوبار-خرج‌شدنِ یک موجودی است
                \App\Models\Customer::whereKey($customerId)->lockForUpdate()->first();

                /** @var Invoice $fresh */
                $fresh = Invoice::whereKey($invoice->id)->lockForUpdate()->first();
                $due = $fresh->due();

                if (! in_array($fresh->status, ['unpaid', 'partial'], true) || $due <= 0) {
                    return ['ok' => false, 'msg' => 'این فاکتور در وضعیتِ قابلِ پرداخت نیست.'];
                }

                /*
                | 🔴 از M3-correct این کسر از `Wallet` می‌گذرد: گاردِ حقیقت
                | داخلِ همان قفلِ مشتری، روی «در دسترس» (منهایِ رزروهایِ
                | زندهٔ AI) است. چکِ پیام‌خوش پایین فقط UX است — تصمیمِ
                | پولی را Wallet می‌گیرد.
                */
                $wallet = app(\App\Services\Finance\Wallet::class);
                $available = $wallet->availableOf($customerId);

                if ($available < $due) {
                    return ['ok' => false, 'msg' => 'اعتبارِ در دسترس کافی نیست (در دسترس: '
                        .number_format($available).' تومان، مبلغِ فاکتور: '.number_format($due).' تومان).'];
                }

                $wallet->debit($customerId, 'IRT', $due, 'invoice_payment', $fresh,
                    'پرداختِ فاکتور '.$fresh->number.' از اعتبار');

                /*
                | ⚠️ `firstOrCreate` روی `external_ref` — دو کلیکِ هم‌زمان (یا
                | تلاشِ نیمه‌شکسته) یک Payment می‌سازد، و `settleConfirmed`
                | روی پرداختِ قبلاً تسویه‌شده idempotent است.
                */
                $payment = Payment::firstOrCreate(
                    ['external_ref' => 'credit-inv-'.$fresh->id],
                    [
                        'invoice_id'    => $fresh->id,
                        'customer_id'   => $customerId,
                        'gateway'       => 'credit',
                        'currency_code' => 'IRT',
                        'amount'        => $due,
                        'status'        => 'redirected',
                    ],
                );

                $settle = $this->payments->settleConfirmed($payment, 'credit-inv-'.$fresh->id);

                /*
                | 🔴 استثنا **داخلِ** تراکنش، تا تسویهٔ ناموفق کسرِ اعتبار و
                | ردیفِ Payment را هم با خودش برگرداند — «مبلغی کسر نشد» باید
                | حقیقت باشد، نه آرزو.
                */
                if (! $settle->ok) {
                    throw new \RuntimeException('credit settle failed for invoice '.$fresh->id);
                }

                return ['ok' => true, 'msg' => ''];
            });
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('payment', $e, ['area' => 'pay-credit', 'invoice' => $invoice->id]);

            return back()->withErrors(__('ui.iv_credit_failed'));
        }

        if (! $outcome['ok']) {
            return back()->withErrors($outcome['msg']);
        }

        $latestPayment = $invoice->payments()->latest('id')->first();
        if ($latestPayment !== null) {
            DataLayerService::flashPurchase($latestPayment);
        }

        return redirect()->route($this->rp().'account.invoice', $invoice)
            ->with('ok', __('ui.iv_credit_paid'));
    }

    /**
     * اعمالِ کوپنِ هدیه روی فاکتور.
     *
     * 🔴 کوپن روی ریلِ **پرداخت** می‌نشیند، نه روی قیمت.
     *
     * اگر به‌جایش `subtotal` را کم می‌کردیم، پایهٔ ارزش‌افزوده هم کم می‌شد —
     * یعنی دست‌بردن در عددی که اظهارنامه‌اش جای دیگری می‌رود. این‌جا
     * `subtotal`/`tax`/`total` دست‌نخورده می‌مانند و فقط `paid` بالا می‌رود،
     * دقیقاً مثلِ پرداخت از اعتبار.
     *
     * ⚠️ مبلغِ کوپن اگر از ماندهٔ فاکتور بیشتر باشد **بریده** می‌شود و باقیش
     * از بین می‌رود؛ کوپن یک‌بارمصرف است. برای همین کفِ فاکتور وجود دارد.
     */
    public function applyCoupon(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorizeInvoice($invoice);

        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $code = \App\Models\GiftCoupon::normalize($data['code']);

        if ($invoice->currency_code !== 'IRT') {
            return back()->withErrors(__('ui.iv_cp_irt_only'));
        }

        /*
        | 🔴 فاکتورِ شارژِ کیفِ پول هرگز کوپن نمی‌گیرد — و این گارد باید در
        | **کنترلر** باشد، نه فقط در ویو.
        |
        | نسخهٔ اول فقط فرم را روی topup پنهان می‌کرد. یک POSTِ مستقیم (یا
        | فرمی که از تبِ دیگر مانده) کوپن را روی فاکتورِ شارژ می‌نشاند، و
        | پرداختِ آن فاکتور **اعتبارِ نقدِ بی‌انقضا** به کیفِ پول می‌ریزد —
        | یعنی دقیقاً همان چیزی که کوپن برای جلوگیری‌اش ساخته شد، به‌علاوهٔ
        | اینکه پنجرهٔ ۲۴ ساعته کلاً بی‌معنا می‌شد. پنهان‌کردنِ دکمه محافظ
        | نیست؛ تستِ `test_a_topup_invoice_cannot_use_a_coupon` همین را گرفت.
        */
        if ($invoice->kind === 'topup') {
            return back()->withErrors(__('ui.iv_cp_state'));
        }

        if (! in_array($invoice->status, ['unpaid', 'partial'], true) || $invoice->due() <= 0) {
            return back()->withErrors(__('ui.iv_cp_state'));
        }

        $customerId = (int) Auth::guard('customer')->id();

        try {
            $outcome = DB::transaction(function () use ($invoice, $customerId, $code) {
                /** @var Invoice $fresh */
                $fresh = Invoice::whereKey($invoice->id)->lockForUpdate()->first();
                $due = $fresh->due();

                if (! in_array($fresh->status, ['unpaid', 'partial'], true) || $due <= 0) {
                    return ['ok' => false, 'msg' => __('ui.iv_cp_state')];
                }

                /*
                | 🔴 `customer_id` در خودِ پرس‌وجوست، نه یک `if` بعدی.
                |
                | کدِ کسِ دیگری باید **پیدا نشود**، نه اینکه پیدا شود و بعد رد
                | شود: پیامِ «این کد مالِ شما نیست» تأیید می‌کند که کد معتبر
                | است، و آن‌وقت حدس‌زدنِ کدها ارزش پیدا می‌کند.
                */
                $coupon = \App\Models\GiftCoupon::query()
                    ->where('customer_id', $customerId)
                    ->where('code', $code)
                    ->first();

                if ($coupon === null) {
                    return ['ok' => false, 'msg' => __('ui.iv_cp_unknown')];
                }

                if ($coupon->isUsed()) {
                    return ['ok' => false, 'msg' => __('ui.iv_cp_used')];
                }

                if ($coupon->isExpired()) {
                    return ['ok' => false, 'msg' => __('ui.iv_cp_expired')];
                }

                /*
                | 🔴 کفِ فاکتور — خطِ قرمزِ «هرگز زیر بها نفروش».
                |
                | مقدار از **خودِ کوپن** خوانده می‌شود نه از تنظیمات: مشتری
                | شرطی را می‌بیند که موقعِ صدور اعلام شده، نه شرطی که دیروز
                | عوض شده.
                */
                if ($coupon->min_invoice > 0 && $fresh->total < $coupon->min_invoice) {
                    return ['ok' => false, 'msg' => __('ui.iv_cp_min', [
                        'amount' => invoice_money($coupon->min_invoice, 'IRT'),
                    ])];
                }

                if (! $coupon->claim($fresh->id)) {
                    // مصرفِ هم‌زمان از یک تبِ دیگر
                    return ['ok' => false, 'msg' => __('ui.iv_cp_used')];
                }

                $amount = min((int) $coupon->amount, $due);

                $payment = Payment::firstOrCreate(
                    ['external_ref' => 'gift-'.$coupon->code],
                    [
                        'invoice_id'    => $fresh->id,
                        'customer_id'   => $customerId,
                        'gateway'       => 'gift',
                        'currency_code' => 'IRT',
                        'amount'        => $amount,
                        'status'        => 'redirected',
                    ],
                );

                $settle = $this->payments->settleConfirmed($payment, 'gift-'.$coupon->code);

                /*
                | 🔴 استثنا **داخلِ** تراکنش، تا تسویهٔ ناموفق مصرفِ کوپن را هم
                | برگرداند — وگرنه کوپن سوخته و فاکتور پرداخت‌نشده می‌مانْد.
                */
                if (! $settle->ok) {
                    throw new \RuntimeException('gift settle failed for invoice '.$fresh->id);
                }

                return ['ok' => true, 'msg' => ''];
            });
        } catch (\Throwable $e) {
            \App\Support\ErrorTracker::note('payment', $e, ['area' => 'gift-coupon', 'invoice' => $invoice->id]);

            return back()->withErrors(__('ui.iv_cp_failed'));
        }

        if (! $outcome['ok']) {
            return back()->withErrors($outcome['msg']);
        }

        return redirect()->route($this->rp().'account.invoice', $invoice)
            ->with('ok', __('ui.iv_cp_applied'));
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
