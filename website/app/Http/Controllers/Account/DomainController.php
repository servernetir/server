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
                    'name_servers'  => $ns,
                ]);
            } else {
                $domain->forceFill([
                    'customer_id' => $customerId, 'status' => 'pending',
                    'provision_status' => 'none', 'period_years' => $years,
                    'price_toman' => (int) $quote->sell_toman, 'quote_id' => $quote->id,
                    'name_servers' => $ns,
                ])->save();
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
