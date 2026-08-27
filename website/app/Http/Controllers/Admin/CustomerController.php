<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BankTransferReceipt;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\PhoneCall;
use App\Models\Server;
use App\Models\Service;
use App\Services\Domain\Reseller\ResellerProgram;
use App\Services\Notify\CustomerNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * مدیریت مشتریان — سمت کارکنان (جایگزین این بخش از WHMCS).
 *
 * روی گارد «web» می‌نشیند. این‌جا مدیر همهٔ مشتریان را می‌بیند، پروندهٔ کامل
 * هرکدام (هویت، بانک، فاکتور، پرداخت، اعتبار، تیکت) را باز می‌کند و وضعیت
 * حساب را عوض می‌کند. هیچ داده‌ی حساسی (کد ملی، شمارهٔ کامل کارت) این‌جا خام
 * نشان داده نمی‌شود — همان سیاست ذخیره‌سازیِ رمزنگاری‌شده.
 */
class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        // نگهبان: تا جدول customers روی سرور ساخته نشده، پنل نباید ۵۰۰ شود
        if (! Schema::hasTable('customers')) {
            return view('admin.customers', [
                'customers' => collect()->paginate(30),
                'q' => '',
                'status' => 'all',
                'counts' => ['all' => 0, 'active' => 0, 'pending' => 0, 'suspended' => 0],
                'notReady' => true,
                'filters' => ['service' => '', 'verified' => '', 'reseller' => '', 'sort' => 'newest', 'from' => '', 'to' => ''],
            ]);
        }

        /*
        | 🔴 نرمال‌سازیِ ارقام پیش از هر چیز.
        |
        | مدیر شماره را با صفحه‌کلیدِ فارسی می‌زند: «۰۹۱۲…». ستونِ phone لاتین
        | است، پس LIKE هیچ‌وقت نمی‌گرفت و جستجو «خراب» به نظر می‌رسید — بی‌هیچ
        | خطایی، فقط نتیجهٔ خالی.
        */
        $q = trim(strtr((string) $request->query('q', ''), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]));
        $status = (string) $request->query('status', 'all');

        // ── فیلترهای پیشرفته — مقدارِ نامعتبر بی‌اثر است، نه خطا ──
        $fService  = in_array($request->query('service'),  ['with', 'without'], true) ? $request->query('service') : '';
        $fVerified = in_array($request->query('verified'), ['yes', 'no'], true) ? $request->query('verified') : '';
        $fReseller = in_array($request->query('reseller'), ['yes', 'no'], true) ? $request->query('reseller') : '';
        $fSort     = in_array($request->query('sort'), ['newest', 'oldest', 'services', 'invoices'], true)
            ? $request->query('sort') : 'newest';
        $fFrom = $this->validDate($request->query('from'));
        $fTo   = $this->validDate($request->query('to'));

        $query = Customer::query()
            // ⚠️ صریح لازم است: با addSelectِ زیرپرس‌وجو و بدونِ این، ستون‌ها فقط
            // همان زیرپرس‌وجو می‌شد و بقیهٔ فیلدهای مشتری از دست می‌رفت.
            ->select('customers.*')
            ->withCount([
                'invoices',
                'tickets',
                // سرویسِ «فعال» = آنچه واقعاً در دستِ مشتری است (فعال یا در حالِ
                // تحویل). لغوشده/منقضی شمرده نمی‌شود.
                'services as active_services_count' => fn ($q) => $q->whereIn('status', ['active', 'awaiting_provision']),
            ])
            ->with('identityVerification')
            ->orderByDesc('id');

        // تعدادِ دامنه: فعلاً دامنه جدولِ مستقل ندارد و روی خودِ سرویس می‌نشیند،
        // پس دامنه‌های **یکتا**ی سرویس‌های فعال شمرده می‌شود (یک مشتری ممکن است
        // دو سرویس روی یک دامنه داشته باشد).
        if (Schema::hasTable('services')) {
            $query->addSelect([
                'active_domains_count' => Service::selectRaw('COUNT(DISTINCT domain)')
                    ->whereColumn('services.customer_id', 'customers.id')
                    ->whereIn('status', ['active', 'awaiting_provision'])
                    ->whereNotNull('domain')
                    ->where('domain', '!=', ''),
            ]);
        }

        if ($q !== '') {
            /*
            | 🔴 جستجوی چندواژه‌ای: هر واژه باید **جایی** بخورد، نه کلِ عبارت
            | به یک ستون.
            |
            | «علی رضایی» با LIKE تک‌عبارتی هیچ‌وقت پیدا نمی‌شد: نام در
            | first_name است و نام‌خانوادگی در last_name، و رشتهٔ کامل با
            | هیچ‌کدام تطابق ندارد. این دقیقاً همان جستجویی است که مدیر
            | طبیعتاً می‌زند — و «درست نیست»ی که گزارش شد همین بود.
            */
            foreach (preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) as $term) {
                $query->where(function ($w) use ($term) {
                    $w->where('code', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhereHas('identityVerification', function ($iv) use ($term) {
                            $iv->where('first_name', 'like', "%{$term}%")
                                ->orWhere('last_name', 'like', "%{$term}%");
                        });
                });
            }
        }

        if ($fService === 'with') {
            $query->whereHas('services', fn ($s) => $s->whereIn('status', ['active', 'awaiting_provision']));
        } elseif ($fService === 'without') {
            $query->whereDoesntHave('services', fn ($s) => $s->whereIn('status', ['active', 'awaiting_provision']));
        }

        if ($fVerified === 'yes') {
            // ⚠️ وضعیتِ تأیید در این جدول `verified` است نه `approved` — با
            //    مقدارِ اشتباه، فیلتر همیشه خالی برمی‌گشت و «کار می‌کرد».
            $query->whereHas('identityVerification', fn ($iv) => $iv->where('status', 'verified'));
        } elseif ($fVerified === 'no') {
            $query->whereDoesntHave('identityVerification', fn ($iv) => $iv->where('status', 'verified'));
        }

        if ($fReseller !== '' && \Illuminate\Support\Facades\Schema::hasColumn('customers', 'is_reseller')) {
            $query->where('is_reseller', $fReseller === 'yes');
        }

        if ($fFrom !== null) {
            $query->where('customers.created_at', '>=', $fFrom.' 00:00:00');
        }
        if ($fTo !== null) {
            $query->where('customers.created_at', '<=', $fTo.' 23:59:59');
        }

        // مرتب‌سازی — پیشِ‌فرضِ orderByDesc('id') بالاتر خورده؛ این بازنویسی‌اش می‌کند
        match ($fSort) {
            'oldest'   => $query->reorder('customers.id'),
            'services' => $query->reorder('active_services_count', 'desc'),
            'invoices' => $query->reorder('invoices_count', 'desc'),
            default    => null,
        };

        if (in_array($status, ['active', 'pending', 'suspended', 'closed'], true)) {
            $query->where('status', $status);
        }

        return view('admin.customers', [
            'customers' => $query->paginate(30)->withQueryString(),
            'q' => $q,
            'status' => $status,
            'counts' => [
                'all' => Customer::count(),
                'active' => Customer::where('status', 'active')->count(),
                'pending' => Customer::where('status', 'pending')->count(),
                'suspended' => Customer::where('status', 'suspended')->count(),
            ],
            'notReady' => false,
            'filters'  => [
                'service' => $fService, 'verified' => $fVerified, 'reseller' => $fReseller,
                'sort' => $fSort, 'from' => (string) $request->query('from', ''), 'to' => (string) $request->query('to', ''),
            ],
        ]);
    }

    /** تاریخِ معتبرِ Y-m-d یا null — ورودیِ خراب بی‌صدا نادیده. */
    private function validDate(mixed $v): ?string
    {
        return (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
    }

    /**
     * تاریخچهٔ فعالیتِ مشتری، فیلترشده و صفحه‌بندی‌شده.
     *
     * ⚠️ اعتبارسنجیِ دستی و بی‌استثنا. این یک روتِ **نمایشی** است؛ ورودیِ
     * نامعتبر (تاریخِ بی‌معنی، اکشنِ ناموجود) نباید صفحهٔ پروندهٔ مشتری را
     * بخواباند — فقط نادیده گرفته می‌شود.
     *
     * ⚠️ `whereDate` و نه مقایسهٔ رشته‌ای: ستون `datetime` است و مقایسهٔ
     * رشته‌ایِ «تا فلان روز» آخرین روزِ بازه را می‌انداخت — همان باگِ ثبت‌شدهٔ
     * تقویم که فقط روی **مرزِ** بازه دیده می‌شد.
     */
    private function activityQuery(Request $request, Customer $customer)
    {
        if (! Schema::hasTable('activity_logs')) {
            return new LengthAwarePaginator([], 0, 25);
        }

        $q = ActivityLog::where('customer_id', $customer->id);

        if (($a = trim((string) $request->query('act', ''))) !== '') {
            $q->where('action', $a);
        }

        if (($w = trim((string) $request->query('who', ''))) !== '') {
            $q->where('actor', $w);
        }

        if (($t = trim((string) $request->query('q', ''))) !== '') {
            // ⚠️ `like` روی توضیح و IP — همان دو چیزی که مدیر واقعاً دنبالشان می‌گردد
            $q->where(fn ($w2) => $w2->where('description', 'like', '%'.$t.'%')
                ->orWhere('ip', 'like', '%'.$t.'%'));
        }

        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            $raw = trim((string) $request->query($key, ''));

            if ($raw === '') {
                continue;
            }

            try {
                $q->whereDate('created_at', $op, Carbon::parse($raw)->toDateString());
            } catch (\Throwable) {
                // تاریخِ نامعتبر = بی‌فیلتر، نه خطا
            }
        }

        return $q->latest('id')->paginate(self::activityPerPage($request))->withQueryString();
    }

    /**
     * اندازهٔ صفحهٔ تاریخچه.
     *
     * ⚠️ فهرستِ سفید، نه هر عددی از URL. `?per=100000` یعنی کلِ تاریخچهٔ یک
     * مشتریِ قدیمی در یک درخواست از دیتابیس بیرون بیاید و حافظهٔ PHP را پر کند
     * — یک DoSِ رایگان از راهِ یک پارامترِ نمایشی.
     */
    public const ACTIVITY_SIZES = [50, 100, 200];

    private static function activityPerPage(Request $request): int
    {
        $per = (int) $request->query('per', 100);

        return in_array($per, self::ACTIVITY_SIZES, true) ? $per : 100;
    }

    public function show(Request $request, Customer $customer): View
    {
        $load = [
            'identityVerification',
            'bankAccounts',
            'profiles',
            'ipRules',
            'invoices' => fn ($q) => $q->orderByDesc('id')->limit(50),
            'payments' => fn ($q) => $q->orderByDesc('id')->limit(50),
            'creditEntries' => fn ($q) => $q->orderByDesc('id')->limit(50),
            'tickets' => fn ($q) => $q->orderByDesc('last_reply_at')->limit(50),
        ];

        // نگهبان: جدول services تازه اضافه شده؛ روی سروری که هنوز مهاجرت
        // نکرده نباید پرونده ۵۰۰ شود
        if (Schema::hasTable('services')) {
            // invoices و server را با هم می‌آوریم: صفحه برای هر سرویس «آخرین
            // پرداخت» و IP را نشان می‌دهد و بدونِ این، N+1 می‌شد.
            $load['services'] = fn ($q) => $q->with(['invoices', 'server'])->orderByDesc('id');
        }

        $customer->load($load);

        return view('admin.customer', [
            'c' => $customer,
            'creditBalance' => $customer->creditBalance(),
            'services' => $customer->relationLoaded('services') ? $customer->services : collect(),
            /*
            | دامنه‌های همین مشتری — کنارِ سرویس‌ها، چون از دیدِ پشتیبانی هر دو
            | «چیزی که این آدم خریده» هستند و تبِ جدا یعنی جایی که کسی بازش
            | نمی‌کند.
            |
            | ⚠️ نامش `customerDomains` است نه `domains`: خودِ ویو در `@php` یک
            | `$domains` می‌سازد (سرویس‌هایی که فیلدِ دامنه دارند) و متغیرِ
            | کنترلر را **سایه می‌زند**. با نامِ `domains` بلوک بی‌صدا خالی
            | رندر می‌شد — نه خطایی، نه هشداری.
            */
            'customerDomains' => Schema::hasTable('domains')
                ? Domain::where('customer_id', $customer->id)
                    ->orderByDesc('id')->limit(50)->get()
                : collect(),
            /*
            | فعالیت حالا تبِ خودش را دارد: جدول + فیلتر + صفحه‌بندی.
            |
            | ⚠️ فیلتر **سمتِ سرور** است نه مرورگر. تاریخچهٔ یک مشتریِ قدیمی
            | هزاران ردیف می‌شود؛ فرستادنِ همه به مرورگر برای فیلترِ محلی یعنی
            | صفحه‌ای که بارگذاری‌اش دقیقه‌ای طول می‌کشد و حافظه را می‌خورد.
            |
            | ⚠️ `withQueryString()` اجباری است، وگرنه صفحهٔ دومِ نتیجهٔ فیلترشده
            | فیلترها را گم می‌کند و کلِ تاریخچه را نشان می‌دهد — خرابیِ خاموشی
            | که مدیر آن را «فیلتر کار نمی‌کند» می‌بیند.
            */
            'activity' => $this->activityQuery($request, $customer),
            /*
            | 🔴 شمارشِ **کل**، جدا از نتیجهٔ فیلترشده.
            |
            | بجِ کنارِ تب باید همیشه کلِ تاریخچه را بگوید. اگر از `total()`ِ
            | نتیجهٔ فیلترشده بیاید، مدیر فیلتر می‌زند و عددِ تب هم عوض می‌شود —
            | یعنی «این مشتری ۳ رویداد دارد» در حالی که هزار تا دارد.
            */
            'activityTotal' => Schema::hasTable('activity_logs')
                ? ActivityLog::where('customer_id', $customer->id)->count()
                : 0,
            'activityFacets' => Schema::hasTable('activity_logs')
                ? [
                    'actions' => ActivityLog::where('customer_id', $customer->id)
                        ->distinct()->orderBy('action')->pluck('action')->filter()->values(),
                    'actors' => ActivityLog::where('customer_id', $customer->id)
                        ->distinct()->orderBy('actor')->pluck('actor')->filter()->values(),
                ]
                : ['actions' => collect(), 'actors' => collect()],
            'servers' => Schema::hasTable('servers')
                ? Server::where('status', 'active')->orderBy('name')->get()
                : collect(),
            'invoiceTotals' => [
                'count' => $customer->invoices->count(),
                'unpaid' => $customer->invoices->whereIn('status', ['unpaid', 'partial', 'overdue'])->count(),
                'paid' => $customer->invoices->where('status', 'paid')->sum('total'),
            ],
            /*
            | تماس‌های تلفن ابری.
            |
            | ⚠️ نگهبانِ `hasTable` مثلِ بقیهٔ بلوک‌های این صفحه: جدول تازه است و
            | روی سروری که هنوز مهاجرت نکرده، پروندهٔ مشتری نباید ۵۰۰ شود.
            |
            | ⚠️ سقفِ ۵۰ ردیف — یک مشتریِ پرتماس می‌تواند هزاران ردیف داشته باشد
            | و این صفحه از قبل سنگین است.
            */
            'calls' => Schema::hasTable('phone_calls')
                ? PhoneCall::where('customer_id', $customer->id)
                    ->orderByDesc('started_at')->orderByDesc('id')->limit(50)->get()
                : collect(),
            /*
            | 🔴 `answered = false` صریح، نه `!answered`.
            | تماسِ در جریان (`null`) از‌دست‌رفته نیست و نباید بجِ قرمز بگیرد.
            */
            'callsMissed' => Schema::hasTable('phone_calls')
                ? PhoneCall::where('customer_id', $customer->id)
                    ->where('answered', false)->count()
                : 0,
        ]);
    }

    /**
     * تغییر وضعیت حساب مشتری — فعال / معلق / بسته.
     *
     * معلق‌سازی سرویس را قطع نمی‌کند (آن جای دیگری است)، فقط دسترسیِ ورود و
     * خریدِ تازه را می‌بندد. عمل برگشت‌پذیر است، پس تأیید کافی است نه بیشتر.
     */
    public function status(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,pending,suspended,closed'],
        ]);

        $customer->status = $data['status'];
        $customer->save();

        $labels = [
            'active' => 'فعال', 'pending' => 'در انتظار',
            'suspended' => 'معلق', 'closed' => 'بسته',
        ];

        return back()->with('ok', 'وضعیت مشتری به «'.$labels[$data['status']].'» تغییر کرد.');
    }

    /**
     * فعال/غیرفعال کردنِ نمایندگیِ دامنه + تنظیم‌های اختصاصیِ آن مشتری.
     *
     * 🔴 چرا مدیر روشنش می‌کند و نه خودِ مشتری: نمایندگی یک **قرارداد** است.
     * حسابِ نماینده با یک درخواستِ HTTP از اعتبارش دامنهٔ واقعی می‌خرد، و
     * مسئولیتِ سوءاستفاده (فیشینگ/اسپم روی دامنه‌ای که نماینده ثبت کرده) در
     * برابرِ رجیسترار پای ماست. چک‌باکسِ خودسرویس یعنی این مسئولیت را کسی
     * می‌پذیرد که هیچ توافقی امضا نکرده.
     *
     * ⚠️ `reseller_bonus_pct` از **کفِ حاشیه** عبور نمی‌کند — همان گیتی که
     * تخفیفِ سطح را می‌گیرد این را هم می‌گیرد (`ResellerPricing`). پس یک
     * «۴۰٪ به این آقا بده»ی شفاهی نمی‌تواند پسوندِ کم‌حاشیه را زیرِ قیمتِ خرید
     * بفروشد. سقفِ ۵۰ این‌جا فقط محافظِ اشتباهِ تایپی است.
     */
    public function reseller(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'is_reseller' => ['nullable', 'boolean'],
            'level' => ['nullable', 'string', 'max:24'],
            'bonus_pct' => ['nullable', 'integer', 'min:0', 'max:50'],
            'daily_cap_irt' => ['nullable', 'integer', 'min:0'],
        ]);

        $program = app(ResellerProgram::class);
        $on = (bool) ($data['is_reseller'] ?? false);

        $customer->forceFill([
            'is_reseller' => $on,
            'reseller_joined_at' => $on ? ($customer->reseller_joined_at ?? now()) : null,
            'reseller_bonus_pct' => (int) ($data['bonus_pct'] ?? 0),
            'reseller_daily_cap_irt' => (int) ($data['daily_cap_irt'] ?? 0),
        ])->save();

        if (! $on) {
            return back()->with('ok', 'نمایندگیِ دامنه برای این مشتری غیرفعال شد.');
        }

        /*
        | سطحِ دستیِ مدیر مقدم است؛ وگرنه سطح از روی حجمِ واقعی حساب می‌شود.
        |
        | ⚠️ بدونِ این محاسبه، نمایندهٔ تازه‌فعال‌شده با `reseller_level = null`
        | می‌مانَد و `levelByKey(null)` او را روی پلهٔ پایه می‌گذارد — یعنی
        | مشتری‌ای که سال‌ها با ما کار کرده، روزِ اولِ نمایندگی صفر تخفیف
        | می‌گیرد و ما را بدقول می‌داند.
        */
        if (filled($data['level'] ?? null)) {
            $customer->forceFill([
                'reseller_level' => $program->levelByKey($data['level'])['key'],
                'reseller_level_reviewed_at' => now(),
                'reseller_level_locked_until' => null,
            ])->save();
        } else {
            $program->review($customer->refresh());
        }

        $level = $program->currentLevel($customer->refresh());

        return back()->with('ok', 'نمایندگیِ دامنه فعال شد — سطح: '
            .(lc($level['name'] ?? []) ?: $level['key'])
            .' · تخفیف: '.$program->discountPct($customer).'٪');
    }

    /**
     * تغییر رمز عبور مشتری توسط مدیر.
     *
     * رمز به‌صورت متن وارد فرم می‌شود ولی هرگز خام ذخیره نمی‌شود — cast مدل
     * (password => hashed) آن را هش می‌کند. مشتری با پیامک و بله خبردار می‌شود
     * که رمزش عوض شده؛ اگر کار خودش نبوده، فوراً می‌فهمد.
     */
    public function password(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'max:200'],
        ], [], ['password' => 'رمز عبور']);

        $customer->password = $data['password'];   // cast خودش hash می‌کند
        $customer->save();

        try {
            app(CustomerNotifier::class)->event(
                $customer,
                'password_changed',
                [],
                'رمز عبور حساب سرورنت شما توسط پشتیبانی تغییر کرد. اگر این کار را درخواست نکرده‌اید، فوراً با ما تماس بگیرید.',
            );
        } catch (\Throwable) {
            // اعلان نباید تغییر رمز را بشکند
        }

        ActivityLog::record($customer->id, 'password',
            'رمز عبور توسط پشتیبانی تغییر کرد', $request, 'staff');

        return back()->with('ok', 'رمز عبور مشتری تغییر کرد و به او اطلاع داده شد.');
    }

    /**
     * حذف کامل مشتری.
     *
     * ⚠️ بازگشت‌ناپذیر و بدون soft-delete. برای حفظِ منافعِ شرکت، مشتریِ دارای
     * سابقهٔ مالی (فاکتورِ پرداخت‌شده یا ماندهٔ اعتبار) هرگز حذف نمی‌شود — به‌جایش
     * باید حسابش «بسته» شود. حذفِ واقعی فقط برای مشتریِ بدونِ سابقهٔ مالی است و
     * در یک تراکنش انجام می‌شود؛ جدول‌هایِ بدونِ کلیدِ خارجیِ آبشاری دستی پاک
     * می‌شوند تا سطرِ یتیم نماند.
     */
    public function destroy(Request $request, Customer $customer): RedirectResponse
    {
        // حذفِ برگشت‌ناپذیر فقط برای مدیر (نه نویسنده) — مثلِ مدیریتِ کاربرانِ پنل
        abort_unless($request->user()->isAdmin(), 403);

        $hasPaid = $customer->invoices()->where('status', 'paid')->exists();
        // اعتبار در هر ارزی (نه فقط تومان) — برای اطمینان از نبودِ سابقهٔ مالی
        $anyCredit = (int) CreditEntry::where('customer_id', $customer->id)->sum('amount');

        if ($hasPaid || $anyCredit !== 0) {
            return back()->withErrors(
                'این مشتری سابقهٔ مالی (فاکتور پرداخت‌شده یا ماندهٔ اعتبار) دارد و حذف نمی‌شود. '
                .'برای مسدودسازی، وضعیت حساب را روی «بسته» بگذارید.'
            );
        }

        $code = $customer->code;

        DB::transaction(function () use ($customer) {
            // جدول‌های بدونِ FK آبشاری — دستی پاک می‌شوند (services / bank_transfer_receipts / activity_logs)
            if (Schema::hasTable('services')) {
                Service::where('customer_id', $customer->id)->delete();
            }
            if (Schema::hasTable('bank_transfer_receipts')) {
                BankTransferReceipt::where('customer_id', $customer->id)->delete();
            }
            if (Schema::hasTable('activity_logs')) {
                ActivityLog::where('customer_id', $customer->id)->delete();
            }

            // بقیه (فاکتور، آیتم، پرداخت، پروفایل، هویت، تیکت، اعتبار، …) با FK آبشاری پاک می‌شوند
            $customer->delete();
        });

        return redirect()->route('admin.customers')->with('ok', 'مشتری '.$code.' به‌طور کامل حذف شد.');
    }

    /**
     * حذف یک فاکتور توسط مدیر.
     *
     * فقط فاکتورِ پرداخت‌نشده (بدونِ هیچ پولِ نشسته) حذف می‌شود؛ فاکتورِ
     * پرداخت‌شده/جزئی هرگز — تا سابقهٔ مالی/مالیاتی محفوظ بماند. اگر فاکتور
     * برای سرویسی بوده که هنوز فعال نشده، آن سرویس هم لغو می‌شود.
     */
    public function destroyInvoice(Request $request, Invoice $invoice): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        if (! $invoice->isDeletable()) {
            return back()->withErrors('این فاکتور پرداخت‌شده یا جزئی است و حذف نمی‌شود. فقط فاکتورِ پرداخت‌نشده حذف می‌شود.');
        }

        $customerId = $invoice->customer_id;
        $number = $invoice->number;

        // سرویسِ منتظرِ همین فاکتور را هم لغو کن (سرویسِ فعال دست‌نخورده می‌ماند)
        if ($invoice->service_id && Schema::hasTable('services')) {
            $service = Service::find($invoice->service_id);
            if ($service && in_array($service->status, ['pending', 'awaiting_provision'], true)) {
                $service->status = 'cancelled';
                $service->save();
            }
        }

        /*
        | 🔴 همان قاعدهٔ مسیرِ مشتری: دامنهٔ پرداخت‌نشده باید آزاد شود.
        |
        | ⚠️ این‌جا حتی مهم‌تر است، چون `invoices.domain_id` با `nullOnDelete` بسته
        | شده — یعنی با حذفِ فاکتور، تنها نخِ اتصال هم پاره می‌شود و آن ردیفِ
        | دامنه دیگر **هیچ ردی** ندارد که بشود بعداً پیدایش کرد. اگر همین‌جا
        | نبندیمش، آن نام تا ابد در سامانه قفل می‌مانَد.
        */
        if ($invoice->domain_id && Schema::hasTable('domains')) {
            Domain::where('id', $invoice->domain_id)
                ->where('status', 'pending')
                ->where('provision_status', 'none')
                ->update(['status' => 'cancelled', 'updated_at' => now()]);
        }

        // آیتم‌ها و تلاش‌های پرداختِ ناموفق با FK آبشاری پاک می‌شوند
        $invoice->delete();

        return redirect()->route('admin.customer', $customerId)->with('ok', 'فاکتور '.$number.' حذف شد.');
    }
}
