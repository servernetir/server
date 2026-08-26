<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\DomainQuote;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Domain\DomainRegistrar;
use App\Services\Domain\OpenProviderClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * خرید و مدیریتِ دامنهٔ مشتری.
 *
 * ═══ قاعده‌های حاکم ═══
 *
 * ۱) **قیمت از استعلامِ ذخیره‌شده می‌آید، نه از فرم.** اگر مبلغ را از ورودی
 *    بگیریم، هرکس می‌تواند دامنهٔ ده‌میلیونی را به هزار تومان سفارش دهد.
 *
 * ۲) **پنجرهٔ اعتبارِ استعلام رعایت می‌شود.** قیمتِ کهنه یعنی ما به نرخِ دیروز
 *    می‌فروشیم و به نرخِ امروز می‌خریم — روی دامنه که حاشیه‌اش کم است، همین
 *    یک جهشِ ارز کلِ سود را می‌خورد.
 *
 * ۳) **ثبت هرگز از این‌جا صدا زده نمی‌شود.** فقط فاکتور ساخته می‌شود؛ ثبت پس از
 *    پرداخت و توسطِ کرون انجام می‌گیرد.
 */
class DomainController extends Controller
{
    public function __construct(private DomainRegistrar $registrar, private OpenProviderClient $op) {}

    // ═══════════════════════ فهرست و مدیریت ═══════════════════════

    /**
     * فهرستِ دامنه‌ها + جستجو و ثبتِ دامنهٔ تازه در همین صفحه.
     *
     * ⚠️ استعلام **این‌جا** گرفته می‌شود، نه در صفحهٔ عمومی. صفحهٔ عمومی فقط
     * نامِ دامنه را می‌فرستد (`?register=`) چون استعلام ۱۵ دقیقه اعتبار دارد و
     * بینِ دیدنِ قیمت و ورود به حساب ممکن است بیشتر طول بکشد. با استعلامِ تازه،
     * قیمتی که مشتری روی دکمهٔ خرید می‌بیند همیشه همانی است که پرداخت می‌کند.
     */
    public function index(Request $request, \App\Services\Domain\DomainSearch $search): View
    {
        /*
        | ⚠️ `expired` عمداً **دیده می‌شود** (برخلافِ `alive()`): دورهٔ redemption
        | یعنی دامنه هنوز قابلِ نجات است و پنهان‌کردنش از پنل همان «بن‌بستِ
        | بازیابی» بود که ممیزی پیدا کرد. مرده‌های واقعی (لغو/منتقل‌شده)
        | همچنان پنهان‌اند. منقضی‌ها تهِ فهرست، ثبت‌درجریان‌ها اول.
        */
        $domains = Domain::where('customer_id', $this->customerId())
            ->whereNotIn('status', ['cancelled', 'transferred_away'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 WHEN status = 'expired' THEN 2 ELSE 1 END")
            ->orderBy('expires_at')
            ->get();

        $query = trim((string) $request->query('register', ''));
        $results = [];

        // 🔴 سه پاسخِ متفاوت که تا امروز همه به یک جملهٔ «نتیجه‌ای پیدا نشد.
        //    املای دامنه را بررسی کنید» می‌رسیدند:
        //      • جستجو استثنا داد        → searchFailed
        //      • رجیسترار جواب نداد      → lookupOk = false
        //      • واقعاً چیزی پیدا نشد    → هیچ‌کدام
        //    اولی و دومی تقصیرِ مشتری نیستند و نباید املایش را زیرِ سؤال ببرند.
        $lookupOk = true;
        $searchFailed = false;

        if ($query !== '' && mb_strlen($query) <= 120) {
            try {
                $results = $search->search($query);
                $lookupOk = $search->lookupOk();
            } catch (\Throwable $e) {
                // جستجوی خراب نباید فهرستِ دامنه‌های مشتری را هم بخوابانَد
                \Illuminate\Support\Facades\Log::warning('panel domain search failed', ['err' => $e->getMessage()]);

                // ⚠️ لاگِ بالا فقط در laravel.log می‌نشیند و به `/admin/errors`
                //    نمی‌رسد؛ بی این خط، یک صفحهٔ خریدِ کاملاً مرده هیچ ردی در
                //    تنها سطحی که مدیر نگاه می‌کند نمی‌گذاشت.
                \App\Support\ErrorTracker::note('domain', $e, ['area' => 'panel-search']);

                $searchFailed = true;
                $lookupOk = false;
            }
        }

        /*
        | دادهٔ مشترکِ «چهار اتاق» — برای سوییچرِ بالای صفحه (شمارشِ هر بخش)،
        | برای فاکتورِ بازِ تمدید روی هر ردیف، و برای تشخیصِ دامنهٔ بی‌سرویس.
        |
        | ⚠️ `$domains` را دوباره از خودِ سازنده می‌گیریم و نه از متغیرِ بالا:
        | ترتیب و اسکوپ باید در هر چهار صفحه یکی باشد، و اگر روزی این متد
        | فیلترِ دیگری اضافه کند نباید سوییچر عددِ دیگری بگوید.
        */
        $customer = Auth::guard('customer')->user();

        $section = \App\Support\PanelSections::build(
            $customer,
            \Illuminate\Support\Facades\Schema::hasTable('services') && $customer
                ? $customer->services()
                    ->whereIn('status', \App\Models\Service::PANEL_STATUSES)
                    ->with('server')->get()
                : collect(),
        );

        return view('account.domains', AccountController::shell('domains') + $section + [
            'domains'      => $section['secDomains'],
            'query'        => $query,
            'results'      => $results,
            'lookupOk'     => $lookupOk,
            'searchFailed' => $searchFailed,
        ]);
    }

    public function show(Domain $domain): View
    {
        $this->owned($domain);

        /*
        | ⚠️ برای سفارشِ انتقال، صفحه باید بداند فاکتور پرداخت شده یا نه:
        | پیش از پرداخت لینکِ فاکتور نشان می‌دهد، پس از پرداخت فرمِ کدِ
        | انتقال (EPP). بی‌این، مشتری پیامِ «کد را در صفحهٔ دامنه وارد کنید»
        | را می‌گرفت و در صفحه هیچ فرمی نبود — بن‌بستی که ممیزی پیدا کرد.
        */
        $openInvoice = null;

        if ($domain->isTransfer() && $domain->transfer_status === 'pending' && ! $domain->hasPaidInvoice()) {
            $openInvoice = Invoice::where('domain_id', $domain->id)
                ->whereIn('status', ['unpaid', 'draft', 'partial'])
                ->latest('id')
                ->first();
        }

        /*
        | قیمتِ تمدیدی که فرم نشان می‌دهد باید همانی باشد که فاکتور می‌گیرد —
        | ذخیره + استعلامِ تازهٔ پسوند + کفِ ارزی، هرکدام بلندتر
        | (`DomainRenewalInvoicer::effectivePerYear`، کشِ ۶ساعته). بی‌این،
        | مشتری عددی می‌دید و فاکتورِ بزرگ‌تری می‌گرفت.
        */
        $renewUnit = $domain->isActive()
            ? app(\App\Services\Domain\DomainRenewalInvoicer::class)->effectivePerYear($domain)
            : 0;

        return view('account.domain-show', AccountController::shell('domains') + [
            'domain'         => $domain,
            'defaultNs'      => Domain::defaultNameServers(),
            'transferUnpaid' => $openInvoice,
            'renewUnit'      => $renewUnit,
        ]);
    }

    /**
     * تغییرِ nameserver.
     *
     * ⚠️ حداقل دو تا: تقریباً همهٔ رجیستری‌ها کمتر از دو nameserver را رد
     * می‌کنند، و اگر این‌جا نگیریمش خطای انگلیسیِ رجیسترار به مشتری می‌رسد.
     */
    public function nameservers(Request $request, Domain $domain): RedirectResponse
    {
        $this->owned($domain);

        $data = $request->validate([
            'ns'   => ['required', 'array', 'min:2', 'max:5'],
            'ns.*' => ['nullable', 'string', 'max:253', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
        ], [], [
            'ns' => 'نام‌سرورها',
        ]);

        $ns = array_values(array_filter(array_map('trim', $data['ns'])));

        if (count($ns) < 2) {
            return back()->withErrors('دستِ‌کم دو نام‌سرور لازم است.');
        }

        if (! $domain->op_id) {
            return back()->withErrors('این دامنه هنوز نزدِ رجیسترار ثبت نشده است.');
        }

        $res = $this->op->setNameServers((int) $domain->op_id, $ns);

        if (! $res['ok']) {
            return back()->withErrors($this->safeMessage($res['message']));
        }

        $domain->update(['name_servers' => $ns]);

        // برگشت به نیم‌سرورهای ما؟ zone باید باشد وگرنه دامنه از هوا می‌افتد.
        app(\App\Services\Dns\DomainZoneProvisioner::class)->ensure($domain->fresh());

        return back()->with('ok', 'نام‌سرورها به‌روز شد. انتشارِ کامل تا ۲۴ ساعت طول می‌کشد.');
    }

    /** قفلِ انتقال — روشن یعنی کسی نمی‌تواند دامنه را ببرد */
    public function lock(Request $request, Domain $domain): RedirectResponse
    {
        $this->owned($domain);

        if (! $domain->op_id) {
            return back()->withErrors('این دامنه هنوز نزدِ رجیسترار ثبت نشده است.');
        }

        $lock = $request->boolean('lock');
        $res = $this->op->setLock((int) $domain->op_id, $lock);

        if (! $res['ok']) {
            return back()->withErrors($this->safeMessage($res['message']));
        }

        $domain->update(['is_locked' => $lock]);

        return back()->with('ok', $lock ? 'قفلِ انتقال روشن شد.' : 'قفلِ انتقال خاموش شد.');
    }

    /**
     * کدِ انتقال (EPP).
     *
     * 🔴 ذخیره نمی‌شود و لاگ هم نمی‌شود: این کد **کلیدِ مالکیت** است و هرکس
     * داشته باشد می‌تواند دامنه را ببرد. در لحظه گرفته و یک‌بار نشان داده
     * می‌شود.
     */
    public function authCode(Domain $domain): RedirectResponse
    {
        $this->owned($domain);

        if (! $domain->op_id) {
            return back()->withErrors('این دامنه هنوز نزدِ رجیسترار ثبت نشده است.');
        }

        if ($domain->is_locked) {
            return back()->withErrors('برای گرفتنِ کدِ انتقال، اول قفلِ انتقال را خاموش کنید.');
        }

        $res = $this->op->authCode((int) $domain->op_id);

        if (! $res['ok'] || blank($res['auth_code'])) {
            return back()->withErrors($this->safeMessage($res['message'] ?: 'کدِ انتقال در دسترس نیست.'));
        }

        return back()->with('authCode', $res['auth_code']);
    }

    public function autoRenew(Request $request, Domain $domain): RedirectResponse
    {
        $this->owned($domain);

        $on = $request->boolean('auto_renew');
        $domain->update(['auto_renew' => $on]);

        // ⚠️ عمداً نزدِ رجیسترار روشن نمی‌شود. تمدید را **ما** می‌فروشیم؛ اگر
        // رجیسترار خودش تمدید کند، برای دامنه‌ای که مشتری پولش را نداده هم ما
        // پول می‌دهیم و راهی برای پس‌گرفتنش نیست.
        return back()->with('ok', $on
            ? 'تمدیدِ خودکار روشن شد. پیش از سررسید فاکتور صادر می‌شود.'
            : 'تمدیدِ خودکار خاموش شد.');
    }

    /**
     * تمدیدِ دستی — دکمهٔ «تمدید دامنه» در پنل.
     *
     * ═══ چرا این متد ساخته شد ═══
     *
     * 🔴 تنها مسیرِ تمدید، فاکتورِ خودکارِ کرون در ۲۱ روزِ آخر بود. مشتری‌ای
     * که می‌خواست زودتر خیالش را راحت کند یا چندساله تمدید کند **هیچ راهی
     * نداشت** — دکمه‌ای وجود نداشت و کامنتِ کرون می‌گفت «چندساله را مشتری
     * دستی می‌خرد»، ولی آن مسیرِ دستی هرگز ساخته نشده بود.
     *
     * هیچ تماسی با رجیسترار این‌جا نیست؛ فقط فاکتور ساخته می‌شود. تمدیدِ
     * واقعی پس از پرداخت و با کرونِ `domains:renew` است — همان قاعدهٔ
     * «ثبت هرگز از وب صدا زده نمی‌شود».
     */
    public function renew(Request $request, Domain $domain, \App\Services\Domain\DomainRenewalInvoicer $invoicer): RedirectResponse
    {
        $this->owned($domain);

        if (! $domain->isActive()) {
            return back()->withErrors('فقط دامنهٔ فعال از این‌جا تمدید می‌شود. اگر دامنه منقضی شده، با پشتیبانی تماس بگیرید.');
        }

        // تمدیدِ پرداخت‌شده‌ای همین حالا در صفِ رجیسترار است؛ فاکتورِ تازه
        // یعنی پولِ دوباره برای همان کار.
        if (in_array($domain->provision_status, ['pending', 'running'], true)) {
            return back()->with('ok', 'تمدید در حالِ انجام است؛ تا چند دقیقهٔ دیگر تاریخِ انقضای تازه را می‌بینید.');
        }

        // تمدیدِ قبلی شکست خورده و در صفِ بررسیِ انسانی است. فاکتورِ دوم
        // همان خطای «دو بار پول، صفر تمدید» را می‌سازد که ممیزی پیدا کرد.
        if ($domain->provision_status === 'manual') {
            return back()->withErrors('تمدیدِ قبلی در دستِ بررسی است؛ تا روشن‌شدنِ نتیجه فاکتورِ تازه صادر نمی‌شود.');
        }

        $years = (int) $request->validate([
            'years' => ['required', 'integer', 'min:1', 'max:5'],
        ], [], ['years' => 'مدتِ تمدید'])['years'];

        // قیمتِ مؤثر (ذخیره + استعلامِ تازه + کف) — صفر یعنی هیچ منبعی قیمت ندارد
        if ($invoicer->effectivePerYear($domain) <= 0) {
            return back()->withErrors('قیمتِ تمدید برای این دامنه در دسترس نیست؛ با پشتیبانی تماس بگیرید.');
        }

        // قفل + بازبینی زیرِ قفل: دو کلیکِ هم‌زمان (یا دوبار زدنِ دکمه) نباید
        // دو فاکتور بسازد — فاکتورِ باز همیشه بازمصرف می‌شود.
        $invoice = DB::transaction(function () use ($domain, $years, $invoicer) {
            Domain::whereKey($domain->id)->lockForUpdate()->first();

            return $invoicer->open($domain) ?? $invoicer->issue($domain, $years);
        });

        // ⚠️ lroute و نه route: کاربرِ /en/ و /tr/ نباید وسطِ پرداخت به صفحهٔ
        //    فارسی بیفتد — همان قاعدهٔ بالای domain-show.blade.php.
        return redirect()->to(lroute('account.invoice', $invoice));
    }

    /**
     * بازیابیِ دامنهٔ منقضی (redemption) — مسیرِ نجاتی که وجود نداشت.
     *
     * ═══ چرا (ممیزی + خواستهٔ کارفرما، ۳ شهریور ۱۴۰۵) ═══
     *
     * 🔴 دامنهٔ `expired` از پنل غیب می‌شد و تنها راهش «تماس با پشتیبانی»
     * بود — دقیقاً در پنجره‌ای که رجیستری هنوز بازیابی را می‌پذیرد و هر روز
     * تأخیر شانسِ نجات را کم می‌کند.
     *
     * قیمت = قیمتِ مؤثرِ تمدید + کارمزدِ بازیابی (`domain_restore_fee_toman`
     * در تنظیمات — کارمزدِ redemption نزدِ رجیستری چند برابرِ تمدید است و
     * per-TLD از API نمی‌آید، پس عددش تصمیمِ کارفرماست). تا وقتی آن تنظیم
     * خالی است، این مسیر عمداً بسته می‌مانَد و به پشتیبانی ارجاع می‌دهد —
     * فروختنِ نجات زیرِ قیمتِ تمام‌شده بدتر از نفروختنش است.
     *
     * مثلِ renew: این‌جا فقط فاکتور؛ تماسِ رجیسترار پس از پرداخت با کرون
     * (`restorePaid`)، و شکستِ قطعی = رفاندِ خودکار.
     */
    public function restore(Request $request, Domain $domain, \App\Services\Domain\DomainRenewalInvoicer $invoicer): RedirectResponse
    {
        $this->owned($domain);

        if ($domain->status !== 'expired') {
            return back()->withErrors('این دامنه در وضعیتِ بازیابی نیست.');
        }

        if (! $domain->op_id) {
            return back()->withErrors('بازیابیِ این دامنه فقط از راهِ پشتیبانی ممکن است.');
        }

        if (in_array($domain->provision_status, ['pending', 'running'], true)) {
            return back()->with('ok', 'بازیابی در حالِ انجام است؛ نتیجه به شما اطلاع داده می‌شود.');
        }

        if ($domain->provision_status === 'manual') {
            return back()->withErrors('بازیابیِ قبلی در دستِ بررسی است؛ تا روشن‌شدنِ نتیجه فاکتورِ تازه صادر نمی‌شود.');
        }

        $fee = (int) \App\Models\Setting::get('domain_restore_fee_toman');

        if ($fee <= 0) {
            return back()->withErrors('بازیابیِ آنلاین فعلاً فعال نیست؛ برای نجاتِ دامنه با پشتیبانی تماس بگیرید.');
        }

        $renewPerYear = $invoicer->effectivePerYear($domain);

        if ($renewPerYear <= 0) {
            return back()->withErrors('قیمتِ بازیابی در دسترس نیست؛ با پشتیبانی تماس بگیرید.');
        }

        $invoice = DB::transaction(function () use ($domain, $invoicer, $renewPerYear, $fee) {
            Domain::whereKey($domain->id)->lockForUpdate()->first();

            if ($open = $invoicer->open($domain)) {
                return $open;
            }

            $subtotal = $renewPerYear + $fee;
            $taxPct = \App\Http\Controllers\Account\CloudStoreController::taxPercent();
            $tax = (int) round($subtotal * $taxPct / 100);

            $invoice = Invoice::create([
                'customer_id'   => $domain->customer_id,
                'domain_id'     => $domain->id,
                'kind'          => 'domain',
                'currency_code' => 'IRT',
                'subtotal'      => $subtotal,
                'tax'           => $tax,
                'total'         => $subtotal + $tax,
                'paid'          => 0,
                'status'        => 'unpaid',
                'issued_at'     => now(),
                'note'          => 'بازیابیِ دامنهٔ منقضیِ '.$domain->domain,
            ]);

            InvoiceItem::create([
                'invoice_id'  => $invoice->id,
                'title'       => 'بازیابیِ دامنهٔ '.$domain->domain,
                'description' => 'نجات از دورهٔ redemption + یک سال تمدید',
                'quantity'    => 1,
                'unit_price'  => $subtotal,
                'line_total'  => $subtotal,
                'tax_rate_bp' => (int) ($taxPct * 100),
                'tax_amount'  => $tax,
            ]);

            // نشانِ فاکتور برای رفاندِ هدفمند + یک‌سالِ تمدیدِ همراهِ بازیابی
            $domain->putMeta(['restore_invoice_id' => $invoice->id, 'renew_years' => 1]);

            return $invoice;
        });

        return redirect()->to(lroute('account.invoice', $invoice));
    }

    // ═══════════════════════ خرید ═══════════════════════

    /**
     * از نتیجهٔ جستجو → دامنهٔ در انتظار + فاکتور.
     *
     * هیچ تماسی با رجیسترار انجام نمی‌شود: ثبت بعد از پرداخت و با کرون است.
     */
    /**
     * صفحهٔ تسویهٔ دامنه — نام‌سرور، مشخصاتِ مالک، و بعد پرداخت.
     *
     * ═══ چرا این صفحه ساخته شد ═══
     *
     * کارفرما: «وقتی کاربر دامنه رو انتخاب می‌کنه مستقیم بره به صفحهٔ گرفتنِ
     * نیم‌سرور و پرداخت، دیگه دوباره `/account/domains?register=x` نره.»
     *
     * ولی سودِ بزرگ‌ترش جای دیگری است: این تنها لحظه‌ای است که کاربر **حاضر
     * است** و می‌تواند نشانی و تلفنش را بدهد. تا امروز آن داده هرگز پرسیده
     * نمی‌شد و ثبتِ خودکار ساعت‌ها بعد، بی‌سروصدا، به‌خاطرِ نبودنش شکست
     * می‌خورد — با پولِ گرفته‌شده.
     *
     * ⚠️ فیلدهای پرشده **دوباره پرسیده نمی‌شوند**؛ فقط آنچه کم است اجباری
     * می‌شود. صفحه‌ای که همه‌چیز را دوباره بپرسد، خریدار را می‌پرانَد.
     */
    public function checkout(Request $request, DomainQuote $quote): View|RedirectResponse
    {
        /*
        | 🔴 مالکیت پیش از هر چیز: شناسهٔ ترتیبی + نبودِ این گارد یعنی هر
        | مشتری می‌توانست ۱..N را بپیماید و جستجوهای دامنهٔ بقیه را ببیند.
        | ۴۰۴ و نه ۴۰۳ — وجودِ استعلامِ دیگران هم اطلاعات است.
        */
        abort_unless($quote->claimFor($this->customerId()), 404);

        if ($quote->honour_until !== null && $quote->honour_until->isPast()) {
            return redirect()->route('account.domains')
                ->withErrors(__('ui.dch_quote_expired'));
        }

        $profile = auth('customer')->user()?->defaultProfile();

        // ⚠️ `shell()` دادهٔ مشترکِ لایوتِ پنل را می‌دهد (`$pnlUser` و منو).
        //    بی‌آن صفحه با «Undefined variable $pnlUser» ۵۰۰ می‌شود — خطایی که
        //    فقط موقعِ رندرِ واقعی پیدا می‌شود، نه در تستِ منطق.
        return view('account.domain-checkout', AccountController::shell('domains') + [
            'quote'   => $quote,
            'profile' => $profile,
            // 🔴 «چه چیزی کم است» از همان تابعِ رجیسترار می‌آید، نه از فهرستِ
            //    دستی. یک منبعِ حقیقت برای «کامل یعنی چه».
            'missing' => $profile === null
                ? self::OWNER_FIELDS
                : $this->registrar->missingOwnerFields($profile),
            'ns'      => Domain::defaultNameServers(),
            'years'   => (int) $request->query('years', 1),
        ]);
    }

    /** فیلدهایی که رجیسترار برای مالک لازم دارد — ترتیبش ترتیبِ فرم است. */
    public const OWNER_FIELDS = [
        'first_name', 'last_name', 'email', 'address', 'city', 'postal_code', 'mobile',
    ];

    public function order(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'quote_id' => ['required', 'integer'],
            'years'    => ['nullable', 'integer', 'min:1', 'max:10'],

            // مشخصاتِ مالک — از صفحهٔ تسویه می‌آیند و فقط آنچه کم بوده پر است
            'first_name'  => ['nullable', 'string', 'max:60'],
            'last_name'   => ['nullable', 'string', 'max:60'],
            'email'       => ['nullable', 'email', 'max:190'],
            'address'     => ['nullable', 'string', 'max:190'],
            'city'        => ['nullable', 'string', 'max:60'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'mobile'      => ['nullable', 'string', 'max:24'],

            // نام‌سرور: خالی = پیش‌فرضِ شرکت
            'ns'   => ['nullable', 'array', 'max:4'],
            'ns.*' => ['nullable', 'string', 'max:120'],
        ]);

        $quote = DomainQuote::find($data['quote_id']);

        if ($quote === null) {
            return back()->withErrors('استعلامِ این دامنه پیدا نشد. دوباره جستجو کنید.');
        }

        // مالکیتِ استعلام — همان گاردِ checkout؛ مسیرِ پول بی‌مالک نمی‌ماند.
        if (! $quote->claimFor($this->customerId())) {
            return back()->withErrors('استعلامِ این دامنه پیدا نشد. دوباره جستجو کنید.');
        }

        // 🔴 پنجرهٔ اعتبار: قیمتِ کهنه یعنی فروش به نرخِ دیروز و خرید به نرخِ
        // امروز. روی دامنه که حاشیه‌اش کم است، یک جهشِ ارز کلِ سود را می‌خورد.
        if ($quote->honour_until !== null && $quote->honour_until->isPast()) {
            return back()->withErrors('قیمتِ این استعلام منقضی شده است. دوباره جستجو کنید تا قیمتِ روز را ببینید.');
        }

        if ((int) $quote->sell_toman <= 0) {
            return back()->withErrors('برای این دامنه قیمتِ قابلِ اتکایی نداریم. با پشتیبانی تماس بگیرید.');
        }

        /*
        |----------------------------------------------------------------------
        | 🔴 پسوندی که می‌دانیم ثبت نمی‌شود را **نفروش**
        |----------------------------------------------------------------------
        |
        | هم‌خانوادهٔ گیتِ «مشخصاتِ مالک» چند خط پایین‌تر، و از همان درسِ
        | `zhina.shop`: هر چیزی که ثبت را قطعاً شکست می‌دهد باید **پیش از
        | گرفتنِ پول** جلویش گرفته شود، نه ساعت‌ها بعد در صفِ دستی.
        |
        | فرقش با آن گیت این است که این یکی دربارهٔ **ما**ست نه مشتری: وقتی
        | قراردادِ رجیستریِ یک پسوند امضا نشده، هیچ کاری از دستِ مشتری برنمی‌آید
        | و هیچ فرمی را نمی‌تواند پر کند. پس متن هم عذرخواهی است، نه راهنمایی.
        |
        | ⚠️ دروازه فقط با خطای **ساختاری** بسته می‌شود و با اولین ثبتِ موفق
        | خودش باز می‌شود — جزئیات در `TldGate`.
        */
        [, $gateTld] = Domain::splitFqdn((string) $quote->domain);

        /*
        | 🔴 لایهٔ دوم: پسوندی که اصلاً نمی‌فروشیم.
        |
        | گیتِ اصلی حالا در `DomainSearch::shape()` است و اجازه نمی‌دهد استعلامِ
        | تازه‌ای برای `.ir` ساخته شود. ولی این‌جا مسیرِ **پول** است و استعلام از
        | ورودی می‌آید: یک `quote_id`ِ ساخته‌شده **پیش از** آن رفع (یا از یک
        | تبِ باز) هنوز در دیتابیس هست و پنجرهٔ اعتبارش ممکن است باز باشد.
        |
        | بی‌این شرط، همان استعلامِ کهنه یک فاکتورِ ۱۳ میلیونی برای دامنه‌ای
        | می‌ساخت که ثبتش هم نمی‌شود. محافظِ مسیرِ پول نباید به محافظِ مسیرِ
        | نمایش تکیه کند.
        */
        if (! \App\Services\Domain\DomainSearch::sells($gateTld)) {
            return back()->withErrors(
                'پسوندِ «.'.$gateTld.'» فعلاً از این مسیر فروخته نمی‌شود. پولی از شما کسر نشد.'
            );
        }

        if (\App\Services\Domain\TldGate::isBlocked($gateTld)) {
            return back()->withErrors(
                'ثبتِ پسوندِ «.'.$gateTld.'» موقتاً از سمتِ ما مقدور نیست و در حالِ پیگیری است. '
                .'پولی از شما کسر نشد. لطفاً پسوندِ دیگری انتخاب کنید یا کمی بعد دوباره تلاش کنید.'
            );
        }

        $customerId = $this->customerId();
        $years = max(1, (int) ($data['years'] ?? 1));

        /*
        |----------------------------------------------------------------------
        | 🔴 مشخصاتِ مالک **پیش از گرفتنِ پول** سنجیده می‌شود، نه بعدش
        |----------------------------------------------------------------------
        |
        | رخدادِ واقعی (`zhina.shop`، مرداد ۱۴۰۵): مشتری خرید، پول رفت، و دامنه
        | با `provision_status='manual'` پارک شد؛ علتش در ستونِ `provision_error`
        | نوشته بود «مشخصاتِ مالک ناقص است».
        |
        | علتِ ساختاری: این‌جا فقط `$profile === null` سنجیده می‌شد. ولی پروفایل
        | در ثبت‌نام ساخته می‌شود و فقط نام و ایمیل دارد — نشانی و شهر و تلفن
        | ندارد. پس شرط رد می‌شد، فاکتور صادر می‌شد، پول گرفته می‌شد، و شرطِ
        | **واقعی** ساعت‌ها بعد در `DomainRegistrar` می‌شکست. یعنی تنها جایی که
        | کاربر می‌توانست کاری بکند (لحظهٔ خرید) رد شده بود.
        |
        | ⚠️ سنجه **همان تابعی** است که رجیسترار استفاده می‌کند
        | (`profileToCustomer()` → `null` یعنی ناقص). فهرستِ دستیِ موازیِ
        | فیلدهای اجباری یعنی روزی رجیسترار فیلدی اضافه کند و این گیت بی‌صدا
        | کهنه شود — همان الگویی که در این پروژه بارها گران تمام شده.
        */
        $profile = auth('customer')->user()?->defaultProfile();

        /*
        | مشخصاتی که کاربر همین حالا در صفحهٔ تسویه داد، **پیش از** سنجشِ
        | کامل‌بودن ذخیره می‌شوند — وگرنه فرم پر می‌شد و گیت باز هم رد می‌کرد.
        |
        | ⚠️ فقط فیلدِ **پرشده** می‌نویسد. `array_filter` روی رشتهٔ خالی یعنی
        | ارسالِ فرمِ خالی هرگز نشانیِ درستِ قبلی را پاک نمی‌کند.
        */
        if ($profile !== null) {
            $owner = array_filter(
                $request->only(self::OWNER_FIELDS),
                fn ($v) => is_string($v) && trim($v) !== ''
            );

            if ($owner !== []) {
                $profile->fill(array_map('trim', $owner))->save();
                $profile->refresh();
            }
        }

        /*
        | ⚠️ اگر مالکِ ثابتِ شرکت پیکربندی شده باشد، مشخصاتِ مشتری برای **ثبت**
        | لازم نیست و نباید جلوی فروش را بگیرد. هنوز پرسیده می‌شوند (صورت‌حساب
        | و تماس لازمشان دارد) ولی اجباری نیستند.
        */
        $needsOwner = $this->registrar->companyRegistrant() === null;

        if ($needsOwner && ($profile === null || $this->registrar->profileToCustomer($profile) === null)) {
            return redirect()->route('account.domains.checkout', ['quote' => $quote->id])
                ->withErrors(__('ui.dch_need_owner'));
        }

        [$sld, $tld] = Domain::splitFqdn((string) $quote->domain);

        $existing = Domain::where('domain', $quote->domain)->where('registrar', 'openprovider')->first();

        if ($existing !== null && ! $existing->isDead()) {
            return back()->withErrors('این دامنه از قبل در سامانه ثبت شده است.');
        }

        /*
        | نام‌سرورِ انتخابیِ کاربر. کمتر از دو تا = پیش‌فرضِ شرکت.
        |
        | ⚠️ همان قاعدهٔ `DomainRegistrar`: ثبت با کمتر از دو نام‌سرور یعنی
        | دامنه‌ای که به هیچ‌جا اشاره نمی‌کند — مشتری پول داده و سایتش بالا
        | نمی‌آید. پس ورودیِ ناقص **جایگزین** می‌شود، نه اینکه رد شود.
        */
        $ns = array_values(array_filter(array_map(
            fn ($v) => strtolower(trim((string) $v, " \t.")),
            (array) ($data['ns'] ?? [])
        ), fn ($v) => $v !== ''));

        if (count($ns) < 2) {
            $ns = Domain::defaultNameServers();
        }

        $invoice = null;

        DB::transaction(function () use ($quote, $customerId, $years, $sld, $tld, &$invoice, $existing, $ns) {
            $domain = $existing;

            static $hasRenewCost = null;
            $hasRenewCost ??= \Illuminate\Support\Facades\Schema::hasColumn('domains', 'cost_renew_amount');

            $money = [
                'price_toman'   => (int) $quote->sell_toman,
                'renew_toman'   => (int) ($quote->renew_toman ?: $quote->sell_toman),
                'cost_amount'   => (int) $quote->cost_amount,
                'cost_currency' => (string) $quote->cost_currency,
            ] + ($hasRenewCost ? ['cost_renew_amount' => $quote->cost_renew_amount] : []);

            if ($domain === null) {
                $domain = Domain::create([
                    'customer_id'   => $customerId,
                    'domain'        => $quote->domain,
                    'sld'           => $sld,
                    'tld'           => $tld,
                    'registrar'     => 'openprovider',
                    'status'        => 'pending',
                    // ⚠️ `none` نه `pending`: تا فاکتور پرداخت نشده، کرون
                    // نباید برش دارد. `PaymentService` بعد از تسویه پرچم را
                    // `pending` می‌کند.
                    'provision_status' => 'none',
                    'period_years'  => $years,
                    'quote_id'      => $quote->id,
                    'name_servers'  => $ns,
                ] + $money);
            } else {
                /*
                | 🔴 بازمصرفِ ردیفِ مرده باید **کامل** باشد (ممیزیِ شهریور ۱۴۰۵):
                |
                | • `order_type`/`transfer_status` ریست نمی‌شد: اگر ردیفِ قبلی یک
                |   انتقالِ ردشده بود، خریدِ تازه با order_type='transfer' در
                |   **هیچ** صفی نمی‌افتاد — پرداخت‌شده و نامرئی، بی‌رفاند.
                | • قیمت‌ها و بهای تمام‌شدهٔ کهنه می‌ماند: تمدیدِ سال‌های بعد به
                |   نرخِ پارسال و کفِ ارزی با بهای پارسال حساب می‌شد.
                | • `provision_tries` کهنه یعنی اولین شکستِ ثبتِ تازه یکراست
                |   `manual` می‌شد.
                */
                $domain->forceFill([
                    'customer_id' => $customerId, 'status' => 'pending',
                    'order_type' => 'register', 'transfer_status' => null,
                    'provision_status' => 'none', 'provision_tries' => 0,
                    'provision_error' => null, 'period_years' => $years,
                    'quote_id' => $quote->id, 'name_servers' => $ns,
                ] + $money)->save();
            }

            /*
            |------------------------------------------------------------------
            | 🔴 سالِ اول قیمتِ خودش را دارد، سال‌های بعد قیمتِ تمدید
            |------------------------------------------------------------------
            |
            | فرمولِ قبلی `sell_toman * $years` بود — یعنی قیمتِ **سالِ اول** در
            | تعدادِ سال. و قیمتِ سالِ اولِ بیشترِ پسوندها تبلیغاتی است، در حالی
            | که رجیسترار برای سال‌های بعد نرخِ تمدید را می‌گیرد. پس روی هر
            | ثبتِ چندساله ما ضرر می‌کردیم، و هرچه مشتری سالِ بیشتری می‌خرید
            | ضرر بزرگ‌تر می‌شد.
            |
            | نمونهٔ واقعی از همین کاتالوگ (`.shop`): ثبت ۱۹۰٬۰۰۰ و تمدید
            | ۱٬۴۹۰٬۰۰۰ تومان. یک ثبتِ ۳ ساله:
            |
            |     فرمولِ قبلی : ۱۹۰٬۰۰۰ × ۳            =   ۵۷۰٬۰۰۰   ← از مشتری
            |     بهایِ واقعی: ۱۹۰٬۰۰۰ + ۲×۱٬۴۹۰٬۰۰۰   = ۳٬۱۷۰٬۰۰۰   ← به رجیسترار
            |                                    ضرر  ≈ ۲٬۶۰۰٬۰۰۰ تومان
            |
            | ⚠️ `renew_toman` در همان استعلام ذخیره شده و **هم‌لحظه** با
            | `sell_toman` گرفته شده، پس نرخِ ارزِ هر دو یکی است. استعلامِ
            | جداگانه برای تمدید یعنی دو نرخِ متفاوت روی یک فاکتور.
            |
            | ⚠️ اگر `renew_toman` خالی باشد به `sell_toman` برمی‌گردد — یعنی
            | بدترین حالت همان رفتارِ قبلی است، نه چیزی بدتر.
            */
            $firstYear = (int) $quote->sell_toman;
            $renewYear = (int) ($quote->renew_toman ?: $quote->sell_toman);

            $unit = $firstYear + ($renewYear * max(0, $years - 1));
            // ⚠️ همان منبعِ مالیاتِ بقیهٔ فروشگاه — نه محاسبهٔ موازی. دو نرخِ
            // مالیاتِ متفاوت روی یک فاکتور یعنی اختلافِ دفترِ حسابداری.
            $taxPct = CloudStoreController::taxPercent();
            $tax = (int) round($unit * $taxPct / 100);

            $invoice = Invoice::create([
                'customer_id'   => $customerId,
                'domain_id'     => $domain->id,
                'kind'          => 'domain',
                'currency_code' => 'IRT',
                'subtotal'      => $unit,
                'tax'           => $tax,
                'total'         => $unit + $tax,
                'paid'          => 0,
                'status'        => 'unpaid',
                'issued_at'     => now(),
                'note'          => 'ثبتِ دامنهٔ '.$quote->domain,
            ]);

            InvoiceItem::create([
                'invoice_id'  => $invoice->id,
                'title'       => 'ثبتِ دامنهٔ '.$quote->domain,
                'description' => $years.' سال',
                'quantity'    => 1,
                'unit_price'  => $unit,
                'line_total'  => $unit,
                'tax_rate_bp' => (int) ($taxPct * 100),
                'tax_amount'  => $tax,
            ]);
        });

        return redirect()->route('account.invoice', $invoice)
            ->with('ok', 'فاکتورِ دامنه صادر شد. پس از پرداخت، ثبت خودکار انجام می‌شود.');
    }

    // ═══════════════════════ انتقالِ دامنه ═══════════════════════

    /**
     * سفارشِ انتقال — فاکتور می‌سازد، هیچ تماسی با رجیسترار نمی‌زند.
     *
     * ═══ 🔴 چرا کدِ انتقال این‌جا گرفته نمی‌شود ═══
     *
     * انتقال دو مرحله است و این عمدی است:
     *
     *   ۱ این متد  → بررسیِ امکان‌پذیری، فاکتور، پرداخت
     *   ۲ `transferSubmit()` → مشتری کدِ انتقال را وارد می‌کند و همان لحظه
     *      درخواست به رجیسترار می‌رود
     *
     * دلیلش یک قید است، نه سلیقه: رجیسترار در **لحظهٔ ثبتِ درخواست** هزینه را
     * از حسابِ ما برمی‌دارد، پس نمی‌شود پیش از پرداختِ مشتری درخواست داد. و از
     * آن طرف، کدِ انتقال **ذخیره نمی‌شود** (کلیدِ مالکیتِ دامنه است)، پس
     * نمی‌شود موقعِ سفارش گرفتش و بعد از پرداخت مصرفش کرد.
     *
     * تنها ترکیبی که هم پول را امن نگه می‌دارد هم کلید را ذخیره نمی‌کند، همین
     * دو مرحله است — و صفحه صریح به مشتری می‌گوید که مرحلهٔ دوم مانده.
     */
    public function transferOrder(Request $request, \App\Services\Domain\DomainTransfer $transfers): RedirectResponse
    {
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
        ], [], ['domain' => 'نامِ دامنه']);

        [$sld, $tld] = Domain::splitFqdn(strtolower(trim($data['domain'])));

        if ($sld === '' || $tld === '') {
            return back()->withErrors('نامِ دامنه معتبر نیست. مثال: example.com');
        }

        // 🔴 همهٔ گیت‌ها پیش از ساختِ فاکتور — نه بعدش
        $gate = $transfers->eligibility($sld, $tld);

        if (! $gate['ok']) {
            return back()->withErrors($gate['message']);
        }

        /*
        | قیمتِ انتقال از دفترچهٔ پسوندها می‌آید، نه از `domain_quotes`.
        |
        | ⚠️ استعلامِ ذخیره‌شده فقط برای دامنهٔ **آزاد** ساخته می‌شود
        | (`DomainSearch::shape()` فقط در شاخهٔ available یک quote می‌نویسد) و
        | دامنهٔ در حالِ انتقال طبق تعریف آزاد نیست. پس این‌جا هیچ quoteای وجود
        | ندارد که بشود به آن تکیه کرد.
        */
        $book = app(\App\Services\Domain\TldPriceBook::class)->fullForTlds([$tld]);
        $price = (int) data_get($book, $tld.'.transfer', 0);
        $renew = (int) data_get($book, $tld.'.renew', $price);

        if ($price <= 0) {
            return back()->withErrors(
                'برای انتقالِ پسوندِ «.'.$tld.'» قیمتِ قابلِ اتکایی نداریم. با پشتیبانی تماس بگیرید.'
            );
        }

        $customerId = $this->customerId();
        $fqdn = $sld.'.'.$tld;
        $invoice = null;

        DB::transaction(function () use ($customerId, $fqdn, $sld, $tld, $price, $renew, &$invoice) {
            $domain = Domain::create([
                'customer_id'      => $customerId,
                'domain'           => $fqdn,
                'sld'              => $sld,
                'tld'              => $tld,
                'registrar'        => 'openprovider',
                'order_type'       => 'transfer',
                'status'           => Domain::STATUS_TRANSFERRING,
                'transfer_status'  => 'pending',
                // ⚠️ `none` تا فاکتور پرداخت شود — دقیقاً مثلِ مسیرِ ثبت.
                'provision_status' => 'none',
                'period_years'     => 1,
                'price_toman'      => $price,
                'renew_toman'      => $renew,
            ]);

            $taxPct = \App\Http\Controllers\Account\CloudStoreController::taxPercent();
            $tax = (int) round($price * $taxPct / 100);

            $invoice = Invoice::create([
                'customer_id'   => $customerId,
                'domain_id'     => $domain->id,
                'kind'          => 'domain',
                'currency_code' => 'IRT',
                'subtotal'      => $price,
                'tax'           => $tax,
                'total'         => $price + $tax,
                'paid'          => 0,
                'status'        => 'unpaid',
                'issued_at'     => now(),
                'note'          => 'انتقالِ دامنهٔ '.$fqdn,
            ]);

            InvoiceItem::create([
                'invoice_id'  => $invoice->id,
                'title'       => 'انتقالِ دامنهٔ '.$fqdn,
                // ⚠️ «۱ سال تمدید» بخشی از خودِ انتقال است و باید روی فاکتور
                //    دیده شود، وگرنه مشتری فکر می‌کند فقط جابه‌جایی خریده.
                'description' => 'شاملِ یک سال تمدید',
                'quantity'    => 1,
                'unit_price'  => $price,
                'line_total'  => $price,
                'tax_rate_bp' => (int) ($taxPct * 100),
                'tax_amount'  => $tax,
            ]);
        });

        return redirect()->route('account.invoice', $invoice)
            ->with('ok', 'فاکتورِ انتقال صادر شد. پس از پرداخت، کدِ انتقال (EPP) را در همان صفحهٔ دامنه وارد کنید.');
    }

    /**
     * کدِ انتقال را می‌گیرد و همان لحظه درخواست را به رجیسترار می‌فرستد.
     *
     * ⚠️ کد نه ذخیره می‌شود نه لاگ. از `$request` مستقیم به سرویس می‌رود.
     */
    public function transferSubmit(
        Request $request,
        Domain $domain,
        \App\Services\Domain\DomainTransfer $transfers,
    ): RedirectResponse {
        $this->owned($domain);

        if (! $domain->isTransfer()) {
            return back()->withErrors('این دامنه سفارشِ انتقال نیست.');
        }

        if ($domain->transfer_status !== 'pending') {
            return back()->withErrors('این درخواست از قبل به رجیسترار ارسال شده است.');
        }

        /*
        | 🔴 بی‌فاکتورِ پرداخت‌شده هیچ درخواستی نمی‌رود.
        |
        | ثبتِ درخواستِ انتقال نزدِ رجیسترار **همان لحظه** از حسابِ ما پول
        | برمی‌دارد. بی‌این شرط، مشتری می‌توانست فاکتور را نپردازد و با وارد
        | کردنِ کد، انتقال را با هزینهٔ ما شروع کند.
        */
        if (! $domain->hasPaidInvoice()) {
            return back()->withErrors('ابتدا فاکتورِ انتقال را پرداخت کنید.');
        }

        $data = $request->validate([
            'auth_code' => ['required', 'string', 'min:4', 'max:190'],
        ], [], ['auth_code' => 'کدِ انتقال']);

        $res = $transfers->submit($domain, $data['auth_code']);

        // ⚠️ خودِ `$data` هم باید از حافظه برود — همان کد داخلش است
        unset($data);

        if (! $res['ok']) {
            return back()->withErrors($this->safeMessage($res['message']));
        }

        return back()->with('ok',
            'درخواستِ انتقال ثبت شد. تأییدِ رجیسترارِ فعلی معمولاً تا ۵ روزِ کاری طول می‌کشد.');
    }

    // ═══════════════════════ کمکی ═══════════════════════

    private function customerId(): int
    {
        return (int) auth('customer')->id();
    }

    private function owned(Domain $domain): void
    {
        abort_unless((int) $domain->customer_id === $this->customerId(), 404);
    }

    /**
     * ⚠️ پیامِ خامِ رجیسترار ممکن است نام یا شناسهٔ داخلی داشته باشد — همان
     * قاعدهٔ سفیدبرچسبیِ سرورِ ابری. پیامِ عمومی به مشتری، جزئیات به لاگ.
     */
    private function safeMessage(string $raw): string
    {
        \Illuminate\Support\Facades\Log::info('domain op failed', ['msg' => $raw]);

        return 'انجام نشد. اگر تکرار شد با پشتیبانی تماس بگیرید.';
    }
}
