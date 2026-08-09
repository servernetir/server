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
        $domains = Domain::where('customer_id', $this->customerId())
            ->alive()
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
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

        return view('account.domain-show', AccountController::shell('domains') + [
            'domain'     => $domain,
            'defaultNs'  => Domain::defaultNameServers(),
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

    // ═══════════════════════ خرید ═══════════════════════

    /**
     * از نتیجهٔ جستجو → دامنهٔ در انتظار + فاکتور.
     *
     * هیچ تماسی با رجیسترار انجام نمی‌شود: ثبت بعد از پرداخت و با کرون است.
     */
    public function order(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'quote_id' => ['required', 'integer'],
            'years'    => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $quote = DomainQuote::find($data['quote_id']);

        if ($quote === null) {
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

        $customerId = $this->customerId();
        $years = max(1, (int) ($data['years'] ?? 1));

        // مشتری باید پروفایلِ مالک داشته باشد — بدونِ نام و نشانی، ثبتِ دامنه
        // نزدِ هیچ رجیستراری ممکن نیست و WHOIS هم قانوناً آن را می‌خواهد.
        $profile = auth('customer')->user()?->defaultProfile();

        if ($profile === null) {
            return redirect()->route('account.profile')
                ->withErrors('برای ثبتِ دامنه اول باید مشخصاتِ مالک را کامل کنید.');
        }

        [$sld, $tld] = Domain::splitFqdn((string) $quote->domain);

        $existing = Domain::where('domain', $quote->domain)->where('registrar', 'openprovider')->first();

        if ($existing !== null && ! $existing->isDead()) {
            return back()->withErrors('این دامنه از قبل در سامانه ثبت شده است.');
        }

        $invoice = null;

        DB::transaction(function () use ($quote, $customerId, $years, $sld, $tld, &$invoice, $existing) {
            $domain = $existing;

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
                    'price_toman'   => (int) $quote->sell_toman,
                    'renew_toman'   => (int) ($quote->renew_toman ?: $quote->sell_toman),
                    'cost_amount'   => (int) $quote->cost_amount,
                    'cost_currency' => (string) $quote->cost_currency,
                    'quote_id'      => $quote->id,
                    'name_servers'  => Domain::defaultNameServers(),
                ]);
            } else {
                $domain->forceFill([
                    'customer_id' => $customerId, 'status' => 'pending',
                    'provision_status' => 'none', 'period_years' => $years,
                    'price_toman' => (int) $quote->sell_toman, 'quote_id' => $quote->id,
                ])->save();
            }

            $unit = (int) $quote->sell_toman * $years;
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
