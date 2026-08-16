<?php

namespace App\Services\Reports;

use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Facades\Schema;

/**
 * گزارشِ کسب‌وکار — «چه چیزی در راه است، چند مشتری داریم، زیرساخت چقدر پر است».
 *
 * ═══ چرا جدا از `/admin/finance` ═══
 *
 * دفترِ مالی به **گذشته** نگاه می‌کند: چه پولی آمد، چه رفت، چقدر ماند. آن
 * سؤالِ حسابدار است و جایش همان‌جاست و این کلاس هرگز دوباره حسابش نمی‌کند —
 * `BusinessLedger` را صدا می‌زند.
 *
 * این‌جا سه سؤالِ دیگر است که دفتر نمی‌تواند جواب دهد، چون هنوز اتفاق
 * نیفتاده‌اند یا اصلاً پول نیستند:
 *
 *   ۱ ماهِ آینده چقدر باید بدهم و چقدر باید بگیرم؟
 *   ۲ چند مشتری گرفتم و چندتاشان واقعاً پول می‌دهند؟
 *   ۳ سرورهایم چقدر جا دارند؟
 *
 * ═══ 🔴 قاعدهٔ حاکم بر کلِ این فایل ═══
 *
 * **فقط عددی که در دیتابیس هست.** هیچ برآورد، هیچ «احتمالاً»، هیچ میانگینِ
 * ماهِ قبل که شبیهِ پیش‌بینی باشد. عددِ ساختگی در گزارشی که تصمیمِ مالی روی
 * آن گرفته می‌شود، از نبودِ گزارش بدتر است — چون به آن اعتماد می‌شود.
 *
 * هر چیزی که نشد، در `blindSpots()` **با نامِ خودش** گفته می‌شود.
 *
 * ⚠️ همهٔ پرس‌وجوها پشتِ `Schema::hasTable` اند: مهاجرت‌های پروداکشن دستی
 * اجرا می‌شوند و یک جدولِ نساخته نباید کلِ صفحه را ۵۰۰ کند.
 */
class BusinessReport
{
    /** پنجره‌هایی که کارفرما می‌تواند بینشان بچرخد */
    public const WINDOWS = [30, 90];

    // ═══════════════════ ۱) پول در راه ═══════════════════

    /**
     * پولی که در `$days` روزِ آینده باید **بگیریم** و باید **بدهیم**.
     *
     * @return array<string,mixed>
     */
    public function forecast(int $days = 30): array
    {
        $until = now()->addDays($days);

        return [
            'days'     => $days,
            'incoming' => [
                'renewals' => $this->serviceRenewals($until),
                'domains'  => $this->domainBillings($until),
                'overdue'  => $this->outstandingInvoices(),
                'hourly'   => $this->hourlyCloudIncome(),
            ],
            'outgoing' => [
                'domains' => $this->domainRegistryCost($until),
                'servers' => $this->serverRent($days),
            ],
        ];
    }

    /**
     * فاکتورهای تمدیدی که تا آن تاریخ صادر می‌شوند.
     *
     * 🔴 شرط‌ها **کپیِ دقیقِ** `services:renew-due` اند و باید بمانند. اگر
     * گزارش شرطِ خودش را بنویسد، روزی یکی‌شان عوض می‌شود و صفحه عددی نشان
     * می‌دهد که هیچ کرونی پشتش نیست — همان تلهٔ «ناظری که از اسکوپِ خودش
     * می‌پرسد نه از اسکوپِ کارگر».
     *
     * ⚠️ سرویسی که همین حالا فاکتورِ بازِ پرداخت‌نشده دارد این‌جا شمرده
     * نمی‌شود، چون در «معوق» آمده. وگرنه یک مبلغ دو بار دیده می‌شد.
     */
    private function serviceRenewals(\Illuminate\Support\Carbon $until): array
    {
        if (! Schema::hasTable('services')) {
            return ['count' => 0, 'amount' => 0, 'rows' => []];
        }

        $rows = Service::query()
            ->with('customer')
            ->where('status', 'active')
            ->whereIn('cycle', array_keys((array) config('billing.cycles', [])))
            ->whereNotNull('next_due_at')
            ->whereDate('next_due_at', '<=', $until->toDateString())
            ->where('currency_code', 'IRT')
            ->orderBy('next_due_at')
            ->get()
            ->filter(fn (Service $s) => ! $this->hasOpenInvoice($s));

        return [
            'count'  => $rows->count(),
            'amount' => (int) $rows->sum(fn (Service $s) => (int) $s->price + $s->taxAmount()),
            'rows'   => $rows->take(12)->values(),
        ];
    }

    private function hasOpenInvoice(Service $s): bool
    {
        if (! Schema::hasTable('invoices')) {
            return false;
        }

        return Invoice::where('service_id', $s->id)
            ->whereIn('status', ['unpaid', 'draft', 'partial'])
            ->exists();
    }

    /** تمدیدِ دامنه‌هایی که به مشتری صورت‌حساب می‌کنیم */
    private function domainBillings(\Illuminate\Support\Carbon $until): array
    {
        if (! Schema::hasTable('domains')) {
            return ['count' => 0, 'amount' => 0];
        }

        $q = Domain::query()
            ->where('status', 'active')
            ->where('auto_renew', true)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', $until->toDateString());

        return [
            'count'  => (int) (clone $q)->count(),
            'amount' => (int) (clone $q)->sum('renew_toman'),
        ];
    }

    /** فاکتورِ راکد از چند روز بی‌حرکت «کهنه» شمرده می‌شود */
    private const STALE_DAYS = 7;

    /**
     * پولی که مشتری بدهکار است و هنوز نداده.
     *
     * ⚠️ `total - paid` و نه `total`: فاکتورِ نیمه‌پرداخت وگرنه دو بار شمرده
     * می‌شود، یک‌بار در درآمدِ دفتر و یک‌بار این‌جا.
     *
     * 🔴 **فقط `unpaid`** — و این عمدی است، نه ناقص. تنها وضعیت‌هایی که کدِ
     * این پروژه واقعاً می‌نویسد `unpaid`، `paid`، `canceled` و `refunded` اند.
     * `partial` و `overdue` و `draft` در مهاجرت هستند ولی **هیچ نویسنده‌ای
     * ندارند**؛ افزودنشان به شرط، فقط این توهم را می‌سازد که پوشش کامل‌تر است.
     * فاکتورِ نیمه‌پرداخت `unpaid` می‌مانَد و `paid > 0` می‌گیرد — پس همین شرط
     * می‌گیردش.
     *
     * 🔴 «سررسیدگذشته» بر اساسِ `issued_at` است نه `due_at`. ستونِ `due_at`
     * وجود دارد و ایندکس هم دارد، ولی **هیچ‌جای اپ نوشته نمی‌شود** — پس هر
     * شمارشی روی آن برای همیشه صفر است. صفرِ همیشگی بدترین نوع عدد است:
     * شبیهِ «همه‌چیز مرتب است» به نظر می‌رسد.
     */
    private function outstandingInvoices(): array
    {
        if (! Schema::hasTable('invoices')) {
            return ['count' => 0, 'amount' => 0, 'stale_count' => 0, 'stale_days' => self::STALE_DAYS];
        }

        $open = Invoice::where('status', 'unpaid')->where('currency_code', 'IRT');

        return [
            'count'       => (int) (clone $open)->count(),
            'amount'      => max(0, (int) (clone $open)->sum('total') - (int) (clone $open)->sum('paid')),
            'stale_count' => (int) (clone $open)
                ->whereDate('issued_at', '<', now()->subDays(self::STALE_DAYS)->toDateString())->count(),
            'stale_days'  => self::STALE_DAYS,
        ];
    }

    /**
     * 🔴 درآمدی که در هیچ صورتِ سود و زیانی نیست.
     *
     * سرورِ ابریِ ساعتی نه فاکتور می‌سازد نه ردیفِ `payments`: `CloudMeterHourly`
     * مستقیم از اعتبارِ مشتری کم می‌کند. و `BusinessLedger::recordPayment()`
     * برای ثبتِ درآمد به یک **پرداختِ متصل به فاکتور** نیاز دارد.
     *
     * نتیجه: هر تومانی که از سرورِ ساعتی درمی‌آید، در `/admin/finance` و در
     * هر گزارشِ سودی که از دفتر بخواند **دیده نمی‌شود**. این‌جا صریح نشان داده
     * می‌شود، جدا، تا با درآمدِ دفتر جمع نشود و دوباره‌شماری نسازد.
     *
     * ⚠️ `cloud_hourly_convert` هم شمرده می‌شود (تبدیلِ ساعتی به ماهانه، یک
     * ماه یک‌جا) چون آن هم پولِ همین کسب‌وکار است، فقط با ریتمِ دیگر.
     */
    private function hourlyCloudIncome(int $months = 6): array
    {
        if (! Schema::hasTable('credit_ledger')) {
            return ['month' => 0, 'total' => 0, 'has_any' => false];
        }

        $q = fn () => \Illuminate\Support\Facades\DB::table('credit_ledger')
            ->where('currency_code', 'IRT')
            ->where('amount', '<', 0)
            ->whereIn('reason', ['cloud_hourly', 'cloud_hourly_convert']);

        $month = (int) $q()->where('created_at', '>=', now()->startOfMonth())->sum('amount');
        $total = (int) $q()->where('created_at', '>=', now()->subMonthsNoOverflow($months))->sum('amount');

        return [
            'month'   => abs($month),          // ردیف‌ها منفی‌اند (کسر از اعتبار)
            'total'   => abs($total),
            'months'  => $months,
            'has_any' => $total !== 0,
        ];
    }

    /**
     * تنها هزینهٔ آینده‌ای که عددی پشتش هست: تمدیدِ دامنه نزدِ رجیسترار.
     *
     * 🔴 ولی آن عدد بهایِ **ثبتِ اولیه** است، نه بهایِ تمدید. رجیسترار هر دو
     * را می‌دهد و کدِ جستجو هم قیمتِ تمدید را می‌خوانَد، ولی فقط قیمتِ ثبت
     * ذخیره می‌شود و `domains.cost_amount` از روزِ ثبت **یخ می‌زند**.
     *
     * برای اکثرِ پسوندها تمدید **گران‌تر** از ثبت است (سالِ اولِ تبلیغاتی)، پس
     * این عدد یک **کفِ هزینه** است نه خودِ هزینه. صفحه همین را می‌گوید و در
     * `blindSpots()` هم می‌آید — عددِ کم‌برآوردشده‌ای که «قطعی» جا زده شود،
     * بدتر از نبودنش است.
     *
     * ⚠️ تبدیلِ یورو با نرخِ **امروز** است، نه نرخِ روزِ پرداخت. هیچ نرخِ
     * تاریخی‌ای در این پروژه ذخیره نمی‌شود.
     */
    private function domainRegistryCost(\Illuminate\Support\Carbon $until): array
    {
        if (! Schema::hasTable('domains')) {
            return ['count' => 0, 'eur' => 0, 'toman' => 0, 'rate' => 0];
        }

        $rows = Domain::query()
            ->where('status', 'active')
            ->where('auto_renew', true)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', $until->toDateString())
            ->get(['cost_amount', 'cost_currency']);

        // بهایِ تمام‌شده به سنتِ یورو ذخیره می‌شود
        $cents = (int) $rows->where('cost_currency', 'EUR')->sum('cost_amount');
        // ⚠️ همان نرخِ حافظه‌شدهٔ اجارهٔ سرور — وگرنه یک صفحه می‌توانست دو نرخِ
        //    متفاوت داشته باشد و جمع‌هایش با هم نخوانَد.
        $rate = $this->rateFor('EUR');

        return [
            'count' => $rows->count(),
            'eur'   => $cents,
            'toman' => $rate > 0 ? (int) round($cents / 100 * $rate) : 0,
            'rate'  => $rate,
        ];
    }

    /**
     * اجارهٔ سرورها در پنجرهٔ پیش‌بینی — بزرگ‌ترین هزینهٔ جاریِ این کسب‌وکار.
     *
     * ═══ چرا «چند بار در پنجره» و نه «ماهانه × ماه» ═══
     *
     * برای پنجرهٔ ۳۰ روزه فرقی ندارد، ولی برای ۹۰ روزه چرا: `days/30` عددِ
     * اعشاری می‌دهد و «سه ماه» را برای سروری که روزِ ۲۸ صورت‌حساب می‌شود هم
     * سه بار می‌شمارد، حتی اگر پنجره فقط دو تا از آن روزها را در بر بگیرد.
     * پس روزهای صورت‌حسابِ **واقعی** در بازه شمرده می‌شوند.
     *
     * ⚠️ سروری که `billing_day` ندارد، ماهی یک‌بار فرض می‌شود (`days/30`
     * رو به پایین، دستِ‌کم یک بار). این یک تخمین است و صفحه می‌گویدش.
     *
     * 🔴 سرورِ بی‌قیمت **صفر شمرده نمی‌شود، شمرده نمی‌شود**. تعدادش جدا
     * برمی‌گردد تا صفحه بتواند بگوید «این عدد ناقص است و چند سرور جا مانده» —
     * وگرنه یک جمعِ کم‌تر از واقع به‌عنوان «هزینهٔ کل» خوانده می‌شود، که دقیقاً
     * همان خوش‌بینی‌ای است که این ستون‌ها برای رفعش ساخته شدند.
     */
    /** @var array<int,array<string,mixed>> نتیجهٔ اجاره، یک بار در هر درخواست */
    private array $rentMemo = [];

    /** @var array<string,int> نرخِ هر ارز، یک بار در هر درخواست */
    private array $rateMemo = [];

    /**
     * نرخِ یک ارز — **یک بار** گرفته می‌شود و بین همهٔ سرورها و همهٔ پاس‌ها
     * مشترک است.
     *
     * 🔴 بی‌این، دو پاسِ همان صفحه می‌توانستند دو نرخِ متفاوت بگیرند: جمعِ
     * بالای صفحه از یک نرخ و هشدارِ پایینِ صفحه از نرخِ دیگر، و صفحه با
     * خودش تناقض پیدا می‌کرد.
     */
    private function rateFor(string $currency): int
    {
        $currency = strtoupper($currency);

        if (! array_key_exists($currency, $this->rateMemo)) {
            try {
                $this->rateMemo[$currency] = $currency === 'EUR'
                    ? (int) app(\App\Services\Cloud\CloudPricing::class)->eurToToman()
                    : (int) (app(\App\Services\ExchangeRate::class)->toToman($currency) ?: 0);
            } catch (\Throwable) {
                $this->rateMemo[$currency] = 0;
            }
        }

        return $this->rateMemo[$currency];
    }

    /** اجارهٔ یک سرور به تومان، با نرخِ مشترکِ همین درخواست */
    private function rentOf(Server $s): ?int
    {
        /*
        | ⚠️ نرخِ **صفر** هم پاس داده می‌شود، نه `null`.
        |
        | `null` یعنی «خودت بگیر»، و اولین نسخه دقیقاً همین را می‌فرستاد وقتی
        | نرخ در دسترس نبود — پس هر سرور دوباره تلاش می‌کرد و صفحه‌ای با چهار
        | سرور، بیست فراخوانِ HTTPِ مسدودکننده می‌زد. صفر یعنی «گرفتم، نبود».
        */
        return $s->monthlyCostToman($this->rateFor((string) ($s->cost_currency ?: 'EUR')));
    }

    private function serverRent(int $days): array
    {
        if (isset($this->rentMemo[$days])) {
            return $this->rentMemo[$days];
        }

        $empty = ['toman' => 0, 'monthly' => 0, 'priced' => 0, 'unpriced' => 0,
                  'free' => 0, 'unconvertible' => 0, 'rows' => []];

        if (! Schema::hasTable('servers') || ! Schema::hasColumn('servers', 'monthly_cost')) {
            return $empty + ['ready' => false];
        }

        $total = 0;
        $monthly = 0;
        $priced = $unpriced = $free = $unconvertible = 0;
        $rows = [];

        foreach (Server::orderBy('name')->get() as $s) {
            if (! $s->hasCost()) {
                $unpriced++;

                continue;
            }

            if ((int) $s->monthly_cost === 0) {
                $free++;
                $priced++;

                continue;
            }

            $toman = $this->rentOf($s);

            if ($toman === null) {
                // قیمت هست ولی نرخِ ارز نبود — نه رایگان، نه نامعلوم
                $unconvertible++;

                continue;
            }

            $priced++;
            $monthly += $toman;

            $hits = $this->billingHits($s->billing_day, $days);
            $total += $toman * $hits;

            $rows[] = [
                'name'    => (string) $s->name,
                'vendor'  => (string) ($s->vendor ?: '—'),
                'monthly' => $toman,
                'hits'    => $hits,
                'day'     => $s->billing_day,
            ];
        }

        return $this->rentMemo[$days] = [
            'ready'         => true,
            'toman'         => $total,
            'monthly'       => $monthly,
            'priced'        => $priced,
            'unpriced'      => $unpriced,
            'free'          => $free,
            'unconvertible' => $unconvertible,
            'rows'          => $rows,
        ];
    }

    /** چند بار روزِ صورت‌حساب در `$days` روزِ آینده می‌افتد */
    private function billingHits(?int $day, int $days): int
    {
        if ($day === null || $day < 1 || $day > 28) {
            return max(1, intdiv($days, 30));
        }

        $hits = 0;
        $cursor = now()->startOfDay();
        $end = now()->addDays($days)->startOfDay();

        // ⚠️ حداکثر ۱۲ ماه جلو می‌رود؛ پنجره‌ها ۳۰ و ۹۰ روزه‌اند و این فقط
        //    محافظِ حلقهٔ بی‌پایان است اگر روزی پنجرهٔ بزرگ‌تری اضافه شود.
        for ($i = 0; $i <= 12; $i++) {
            $due = $cursor->copy()->addMonthsNoOverflow($i)->startOfMonth()->addDays($day - 1);

            /*
            | 🔴 مرزِ بالا **انحصاری** است: بازه یعنی [امروز، امروز+N).
            |
            | با `gt()` روزی که دقیقاً روی لبه می‌افتاد هم شمرده می‌شد، پس یک
            | پنجرهٔ ۳۰ روزه در عمل ۳۱ روز را می‌گرفت و اجارهٔ ماهانه را **دو
            | بار** حساب می‌کرد. برای سروری با اجارهٔ ۳۹٫۹۰ یورو یعنی صد درصد
            | بیش‌برآورد، فقط در یک روزِ خاصِ ماه — پس شبیهِ یک هزینهٔ واقعی به
            | نظر می‌رسید، نه یک باگ.
            */
            if ($due->gte($end)) {
                break;
            }

            if ($due->gte($cursor)) {
                $hits++;
            }
        }

        return $hits;
    }

    // ═══════════════════ ۲) مشتری ═══════════════════

    /**
     * رشدِ مشتری.
     *
     * ⚠️ «مشتری» و «مشتریِ پولی» دو عددِ متفاوت‌اند و هر دو نشان داده می‌شوند.
     * ثبت‌نام رایگان است، پس شمارشِ کلِ ردیف‌ها به‌تنهایی خوش‌بینانه است و
     * تصمیمِ بازاریابی را خراب می‌کند.
     *
     * @return array<string,mixed>
     */
    public function customers(int $months = 12): array
    {
        if (! Schema::hasTable('customers')) {
            return ['total' => 0, 'new_30' => 0, 'paying' => 0, 'trend' => [],
                    'active_services' => 0, 'churn_30' => 0, 'abandoned' => 0, 'abandoned_cost' => 0];
        }

        /*
        | 🔴 `pending` یعنی **ثبت‌نامِ نیمه‌کاره**، نه مشتری.
        |
        | ردیف در `RegisterController` درست **پیش از** استعلامِ هویت ساخته
        | می‌شود و فقط پس از تکمیل `active` می‌شود. پس شمردنش به‌عنوان مشتری،
        | هم رشد را بزرگ‌تر از واقع نشان می‌دهد و هم — بدتر — پنهان می‌کند که
        | هرکدام از این ردیف‌ها **پولِ واقعی سوزانده‌اند**: شاهکار + استعلامِ
        | هویت پیش از رها شدن انجام شده‌اند.
        |
        | پس جدا شمرده می‌شود و هزینه‌اش هم نشان داده می‌شود.
        */
        $real = fn () => Customer::where('status', '!=', 'pending');

        $trend = [];

        /*
        | ⚠️ برچسبِ ماه **شمسی** است ولی مرزِ بازه **میلادی**: ردیف‌ها بر اساسِ
        | ماهِ میلادی گروه می‌شوند و برچسبِ شمسیِ روزِ اولِ همان ماه را می‌گیرند.
        | یعنی «مرداد» این‌جا دقیقاً مردادِ تقویم نیست.
        |
        | این عمدی است: مرزِ شمسی وسطِ ماهِ میلادی می‌افتد و برای یک نمودارِ
        | **روند** (بالا می‌رویم یا پایین) هیچ فرقی نمی‌کند، در حالی که
        | پرس‌وجوی مرزِ شمسی روی MariaDB یعنی تبدیلِ تاریخ در SQL — همان جایی
        | که این پروژه قبلاً یک روز اختلاف خورده. برچسب برای خواندن است، نه
        | برای حسابداری.
        */
        for ($i = $months - 1; $i >= 0; $i--) {
            $m     = now()->subMonthsNoOverflow($i)->startOfMonth();
            $j     = jalali_ymd((int) $m->year, (int) $m->month, (int) $m->day);

            $trend[] = [
                'label' => fa_num($j[0].'/'.str_pad((string) $j[1], 2, '0', STR_PAD_LEFT)),
                'count' => $real()->whereBetween('created_at', [
                    $m->copy()->startOfMonth(), $m->copy()->endOfMonth(),
                ])->count(),
            ];
        }

        $abandoned = Customer::where('status', 'pending')->count();

        return [
            'total'  => $real()->count(),
            'new_30' => $real()->where('created_at', '>=', now()->subDays(30))->count(),

            'abandoned' => $abandoned,

            /*
            | تعرفه از `service_costs` می‌آید، نه عددِ سخت‌کد: کارفرما خودش
            | آن‌جا نگهشان می‌دارد و اگر زحل قیمت را عوض کند، همان‌جا عوض
            | می‌شود. `amountFor()` اگر جدول نباشد به config می‌افتد.
            */
            'abandoned_cost' => $abandoned * (
                \App\Models\ServiceCost::amountFor('shahkar')
                + \App\Models\ServiceCost::amountFor('identity')
            ),

            /*
            | «پولی» = دستِ‌کم یک فاکتورِ پرداخت‌شده. ملاکِ سرویسِ فعال نیست:
            | سرویسِ دستیِ رایگان و سرویسِ تحویل‌نشده هم فعال‌اند.
            */
            'paying' => Schema::hasTable('invoices')
                ? Invoice::where('status', 'paid')->distinct()->count('customer_id')
                : 0,

            'active_services' => Schema::hasTable('services')
                ? Service::whereNotIn('status', Service::DEAD_STATUSES)->count()
                : 0,

            'churn_30' => Schema::hasTable('services')
                ? Service::whereIn('status', Service::DEAD_STATUSES)
                    ->where('updated_at', '>=', now()->subDays(30))->count()
                : 0,

            'trend' => $trend,
        ];
    }

    // ═══════════════════ ۳) زیرساخت ═══════════════════

    /**
     * وضعیتِ منابع.
     *
     * 🔴 این **تخصیص** است، نه مصرف. `servers` فقط `active_accounts` و
     * `max_accounts` دارد؛ هیچ ستونی برای دیسک و رم و پهنای‌باندِ مصرفی وجود
     * ندارد و هیچ کرونی هم چنین چیزی از WHM نمی‌خوانَد.
     *
     * ⚠️ پس صفحه **نباید** بگوید «۴۰٪ دیسک پر است». می‌گوید «۴۰٪ ظرفیتِ حساب
     * پر است» — که عددِ واقعی و تصمیم‌سازی است (کِی سرورِ بعدی را بخرم)، ولی
     * چیزِ دیگری است. یکی‌گرفتنشان یعنی روزی سروری پر شود و گزارش سبز باشد.
     *
     * @return array<string,mixed>
     */
    public function infrastructure(): array
    {
        $servers = [];

        if (Schema::hasTable('servers')) {
            foreach (Server::orderBy('type')->orderBy('name')->get() as $s) {
                $max  = $s->max_accounts !== null ? (int) $s->max_accounts : null;
                $used = (int) $s->active_accounts;

                $servers[] = [
                    'name'    => (string) $s->name,
                    'type'    => $s->typeLabel(),
                    'country' => $s->locationLabel(),
                    'status'  => (string) $s->status,
                    'used'    => $used,
                    'max'     => $max,
                    'pct'     => ($max !== null && $max > 0) ? min(100, (int) round($used / $max * 100)) : null,
                    'full'    => $s->isFull(),
                    // null = اجاره‌اش وارد نشده. صفحه این را قرمز نشان می‌دهد،
                    // چون همان سرورهاست که جمعِ هزینه را ناقص می‌کنند.
                    /*
                    | ⚠️ دو دلیلِ کاملاً متفاوت برای «عدد ندارم»، و صفحه باید
                    | فرقشان را بگوید: «اجاره وارد نشده» دربارهٔ سروری که
                    | مبلغش **ثبت شده** ولی نرخِ ارز نبود، یک دروغ است — و
                    | مدیر بیهوده صفحهٔ سرورها را باز می‌کند.
                    */
                    'cost'         => method_exists($s, 'monthlyCostToman') ? $this->rentOf($s) : null,
                    'cost_unknown' => ! (method_exists($s, 'hasCost') && $s->hasCost()),
                ];
            }
        }

        $cloud = ['total' => 0, 'running' => 0, 'off' => 0, 'error' => 0, 'vcpu' => 0, 'ram_gb' => 0, 'disk_gb' => 0];

        if (Schema::hasTable('cloud_instances')) {
            $rows = \App\Models\CloudInstance::whereNotIn('status', ['deleted'])->get();

            $cloud['total']   = $rows->count();
            $cloud['running'] = $rows->where('status', 'running')->count();
            $cloud['off']     = $rows->where('status', 'off')->count();
            $cloud['error']   = $rows->where('status', 'error')->count();

            /*
            | مشخصات از عکسِ لحظه‌ایِ `specs` می‌آید، نه از `cloud_plans`:
            | `cloud_instances` هیچ ستونی به پلن ندارد و `provider_ref`ش
            | شناسهٔ **سرور** است نه پلن، پس اتصالِ مطمئنی وجود ندارد.
            */
            foreach ($rows as $r) {
                $spec = (array) ($r->specs ?? []);
                $cloud['vcpu']    += (int) ($spec['vcpu'] ?? 0);
                $cloud['ram_gb']  += (int) round(((int) ($spec['ram_mb'] ?? 0)) / 1024);
                $cloud['disk_gb'] += (int) ($spec['disk_gb'] ?? 0);
            }
        }

        return [
            'servers' => $servers,
            'cloud'   => $cloud,
            'stuck'   => Schema::hasTable('services')
                ? Service::whereIn('provision_status', ['failed', 'manual'])
                    ->whereNotIn('status', Service::DEAD_STATUSES)->count()
                : 0,
        ];
    }

    // ═══════════════════ آنچه نمی‌دانیم ═══════════════════

    /**
     * 🔴 فهرستِ صریحِ چیزهایی که این صفحه **نمی‌تواند** بگوید.
     *
     * چرا روی خودِ صفحه و نه در یک سند: گزارشی که فقط چیزهای دانستنی را نشان
     * دهد، ناخواسته ادعا می‌کند بقیه‌اش صفر است. کارفرما «هزینهٔ ماهِ آینده:
     * ۲ میلیون» را می‌بیند و نمی‌داند اجارهٔ سرورها اصلاً در آن نیست.
     *
     * @return array<int,array{title:string,why:string}>
     */
    public function blindSpots(): array
    {
        $spots = [];

        /*
        | 🔴 این نقطه‌کور **شرطی** است، نه ثابت.
        |
        | ستون‌های هزینه از مرداد ۱۴۰۵ اضافه شدند، ولی ستونِ خالی همان‌قدر
        | خطرناک است که ستونِ نبود — با یک تفاوتِ بدتر: حالا یک عددِ «هزینهٔ
        | در راه» روی صفحه هست که **معتبر به نظر می‌رسد**. پس تا وقتی حتی یک
        | سرور بی‌قیمت باشد، صفحه باید صریح بگوید جمع ناقص است و کدام‌ها جا
        | مانده‌اند.
        */
        $rent = Schema::hasTable('servers') && Schema::hasColumn('servers', 'monthly_cost')
            ? $this->serverRent(30)
            : ['ready' => false, 'unpriced' => 0, 'unconvertible' => 0];

        if (! ($rent['ready'] ?? false)) {
            $spots[] = [
                'title' => 'اجارهٔ سرورها — بزرگ‌ترین هزینهٔ جاریِ کسب‌وکار',
                'why'   => 'ستون‌های هزینه هنوز روی این نصب ساخته نشده‌اند. تا اجرای '
                    .'مهاجرت، اجارهٔ سرورها از نظرِ مالی نامرئی است و «هزینهٔ در راه» '
                    .'فقط دامنه‌هاست.',
            ];
        } elseif ((($rent['unpriced'] ?? 0) + ($rent['unconvertible'] ?? 0)) > 0) {
            $spots[] = [
                'title' => 'اجارهٔ '.fa_num((string) (($rent['unpriced'] ?? 0) + ($rent['unconvertible'] ?? 0))).' سرور در جمع نیست',
                'why'   => 'جمعِ «هزینهٔ در راه» فقط سرورهایی را دارد که مبلغشان پر شده. '
                    .'برای بقیه در «سرورهای تحویل» اجارهٔ ماهانه را بزنید، وگرنه این '
                    .'عدد کم‌تر از واقع می‌مانَد و سودِ محاسبه‌شده خوش‌بینانه است.',
            ];
        }

        if (($rent['unconvertible'] ?? 0) > 0) {
            $spots[] = [
                'title' => 'نرخِ یورو در دسترس نبود',
                'why'   => fa_num((string) $rent['unconvertible']).' سرور مبلغِ ارزی دارد '
                    .'ولی تبدیل به تومان انجام نشد، پس در جمع نیامده‌اند.',
            ];
        }

        return array_merge($spots, [
            [
                'title' => 'هزینهٔ ماهانهٔ هر سرورِ ابری',
                'why'   => 'بهایِ تمام‌شده روی «پلن» است، ولی سرورِ ساخته‌شده ستونی به پلنش '
                    .'ندارد و قیمتِ پلن هم در هر همگام‌سازی بازنویسی می‌شود. پس بهایِ روزِ '
                    .'فروش دیگر بازیابی‌شدنی نیست و حدس‌زدنش همان‌قدر بد است که ننوشتنش.',
            ],
            [
                'title' => 'بهایِ تمدیدِ دامنه (عددِ بالا کفِ هزینه است)',
                'why'   => 'فقط قیمتِ **ثبتِ اولیه** ذخیره می‌شود و از آن روز ثابت می‌مانَد. '
                    .'تمدید معمولاً گران‌تر است، پس رقمِ «هزینهٔ در راه» کم‌برآورد است.',
            ],
            [
                'title' => 'سود و زیانِ واقعی',
                'why'   => 'در «مالی و سود» درآمد **خودکار** ثبت می‌شود ولی هزینه فقط '
                    .'دستی. پس آن سود، درآمد منهای چیزی است که یادتان مانده وارد کنید — '
                    .'نه بهایِ تمام‌شدهٔ واقعی.',
            ],
            [
                'title' => 'مصرفِ واقعیِ دیسک، رم و پهنای‌باند',
                'why'   => 'هیچ ستونی برای مصرف در پایگاه داده نیست و هیچ کرونی از سرورها '
                    .'نمی‌پرسد. درصدهای بالا **ظرفیتِ حساب** است، نه پر بودنِ دیسک.',
            ],
            [
                'title' => 'بازگشتِ وجه',
                'why'   => 'بازپرداخت‌ها به‌صورتِ **اعتبارِ فروشگاهی** برمی‌گردند، نه خروجِ '
                    .'پول. پس نه در هزینه دیده می‌شوند نه از درآمد کم می‌شوند.',
            ],
        ]);
    }
}
