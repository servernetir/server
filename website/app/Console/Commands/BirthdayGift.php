<?php

namespace App\Console\Commands;

use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Service;
use App\Support\ErrorTracker;
use App\Support\Jalali;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * هدیهٔ تولد — اعتبارِ مدت‌دار به کیفِ پولِ مشتری.
 *
 * ═══ چرا اعتبار و نه کدِ تخفیف ═══
 *
 * سه دلیل، به ترتیبِ اهمیت:
 *
 *   ۱. کدِ تخفیف در کانال‌های تلگرامی پخش می‌شود؛ اعتبار به حسابِ همان شخص
 *      می‌چسبد و اصلاً قابلِ بازنشر نیست.
 *   ۲. اعتبار روی **همه‌چیز** کار می‌کند — سرورِ ساعتی، تمدیدِ دامنه، هاست.
 *      مشتریِ قدیمیِ ما اغلب فقط تمدید دارد و کوپنِ «خریدِ جدید» به دردش
 *      نمی‌خورد.
 *   ۳. هیچ مدلِ کوپنی در این پروژه وجود ندارد و ساختنش یعنی یک مسیرِ پولیِ
 *      تازه با تمامِ تله‌هایش.
 *
 * ═══ 🔴 چرا تاریخِ «شمسی» مبناست ═══
 *
 * `birth_date` میلادی ذخیره می‌شود، ولی مشتریِ ایرانی تولدش را شمسی
 * می‌شناسد. سالگردِ میلادی و شمسی گاهی یک روز فرق دارند، و پیامکِ تبریکِ
 * «یک روز دیرتر» از نفرستادنش بدتر است.
 *
 * ⚠️ و روزِ شمسی با ساعتِ «تهران» تعیین می‌شود نه UTC. `config/app.timezone`
 * عمداً UTC است، پس کرونی که ۲۱:۳۰ UTC بدود در تهران بامدادِ فرداست — یعنی
 * بی‌این تبدیل، تبریک‌ها یک روز جابه‌جا می‌رفتند.
 *
 * ═══ 🔴 ۳۰ اسفند ═══
 *
 * متولدِ ۳۰ اسفندِ یک سالِ کبیسه، در سال‌های عادی «روزِ تولد ندارد». بی‌محافظ،
 * آن مشتری سه سال از هر چهار سال هیچ تبریکی نمی‌گیرد. پس در سالِ غیرکبیسه،
 * ۲۹ اسفند تولدِ او هم شمرده می‌شود.
 */
class BirthdayGift extends Command
{
    protected $signature = 'birthday:gift
                            {--dry : فقط نشان بده، چیزی ننویس}';

    protected $description = 'اعتبارِ هدیهٔ تولد + بازپس‌گیریِ هدیه‌های منقضی';

    /** دلیلِ ردیفِ هدیه در دفترِ اعتبار — ستون ۳۲ کاراکتر است */
    public const REASON_GIFT = 'gift_birthday';

    /** دلیلِ ردیفِ بازپس‌گیری */
    public const REASON_EXPIRE = 'gift_expired';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');

        /*
        | سوییپ «همیشه» می‌دود، حتی وقتی برنامه خاموش است.
        |
        | اگر پشتِ همان کلید بنشیند، خاموش‌کردنِ برنامه یعنی هدیه‌هایی که
        | قبلاً رفته‌اند تا ابد روی حساب می‌مانند — در حالی که به مشتری پیامک
        | داده‌ایم «تا N روز اعتبار دارد». خاموش‌کردن باید جلوی هدیهٔ «تازه»
        | را بگیرد، نه جلوی وعده‌ای که از قبل داده‌ایم.
        */
        $swept = $this->sweepExpired($dry);

        if (! $this->enabled()) {
            $this->info('برنامهٔ هدیهٔ تولد خاموش است (config/birthday.enabled یا Setting: birthday_enabled).');
            $this->line("هدیه‌های منقضی‌شدهٔ بازپس‌گرفته: {$swept}");

            return self::SUCCESS;
        }

        $gifted = $this->giftToday($dry);

        $this->info("هدیهٔ امروز: {$gifted} · بازپس‌گیری: {$swept}".($dry ? ' (خشک)' : ''));

        return self::SUCCESS;
    }

    /** برنامه فقط با تصمیمِ صریح روشن می‌شود — نه با پیش‌فرض */
    private function enabled(): bool
    {
        $setting = \App\Models\Setting::get('birthday_enabled');

        if ($setting !== null && $setting !== '') {
            return filter_var($setting, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) config('birthday.enabled', false);
    }

    // ───────────────────────── هدیه ─────────────────────────

    private function giftToday(bool $dry): int
    {
        $tz = (string) config('calendar.display_timezone', 'Asia/Tehran');
        [$jy, $jm, $jd] = Jalali::ofMoment(now(), $tz);

        $cap = (int) config('birthday.daily_cap', 50);
        $done = 0;

        foreach ($this->birthdaysOn($jy, $jm, $jd) as $customer) {
            if ($done >= $cap) {
                /*
                | 🔴 رد شدن از سقف = توقفِ «پرصدا»، نه ادامهٔ خاموش.
                |
                | تنها راه‌هایی که این عدد پر می‌شود ایمپورتِ انبوهِ داده یا
                | خرابیِ پرس‌وجوست. هر دو یعنی داریم پولِ واقعی توزیع می‌کنیم
                | بی‌آنکه بدانیم چرا.
                */
                ErrorTracker::note('notify',
                    "هدیهٔ تولد به سقفِ روزانه ({$cap}) خورد و متوقف شد — "
                    .'یعنی امروز غیرعادی زیاد تولد پیدا شد. پیش از بالابردنِ سقف، فهرست را ببین.');
                break;
            }

            if ($this->alreadyGifted($customer, $jy)) {
                continue;
            }

            $amount = $this->giftFor($customer);

            if ($amount <= 0) {
                continue;
            }

            $this->line("  {$customer->code} — ".number_format($amount).' تومان');

            if (! $dry) {
                $this->award($customer, $amount, $jy);
            }

            $done++;
        }

        return $done;
    }

    /**
     * مشتری‌هایی که امروز تولدشان است.
     *
     * ⚠️ ماه/روزِ شمسی با ماه/روزِ میلادیِ ستون یکی نیست، پس فیلترِ SQL ممکن
     * نیست و تبدیل باید در PHP انجام شود. برای اینکه این کار جدولِ کاملِ
     * مشتری‌ها را نخوانَد، فقط ردیف‌هایی برداشته می‌شوند که تاریخِ تولد دارند
     * و حسابشان فعال است.
     *
     * @return Collection<int,Customer>
     */
    private function birthdaysOn(int $jy, int $jm, int $jd): Collection
    {
        $needActive = (bool) config('birthday.require_active_service', true);

        $q = Customer::query()
            ->where('status', 'active')
            ->whereHas('profiles', fn ($p) => $p->whereNotNull('birth_date'))
            ->with('profiles');

        if ($needActive) {
            $q->whereHas('services', fn ($s) => $s->whereIn('status', Service::ACTIVE_STATUSES));
        }

        return $q->get()->filter(function (Customer $c) use ($jy, $jm, $jd) {
            $b = $c->defaultProfile()?->birth_date;

            if ($b === null) {
                return false;
            }

            [, $bm, $bd] = Jalali::fromGregorian(
                (int) $b->format('Y'),
                (int) $b->format('m'),
                (int) $b->format('d'),
            );

            if ($bm === $jm && $bd === $jd) {
                return true;
            }

            // متولدِ ۳۰ اسفند در سالِ غیرکبیسه: ۲۹ اسفند تولدش شمرده می‌شود
            return $bm === 12 && $bd === 30
                && $jm === 12 && $jd === 29
                && ! Jalali::isLeap($jy);
        })->values();
    }

    /**
     * آیا امسال هدیه‌اش را گرفته؟
     *
     * ⚠️ مبنا خودِ دفترِ اعتبار است، نه یک ستونِ تازه: ردیفِ مالی هرگز پاک
     * نمی‌شود، پس این بررسی نمی‌تواند با ریستِ یک ستون بی‌اعتبار شود. سالِ
     * شمسی داخلِ کروشه در `note` می‌نشیند تا پرس‌وجو ساده و خوانا بمانَد.
     */
    private function alreadyGifted(Customer $customer, int $jy): bool
    {
        return CreditEntry::query()
            ->where('customer_id', $customer->id)
            ->where('reason', self::REASON_GIFT)
            ->where('note', 'like', "%[{$jy}]%")
            ->exists();
    }

    /**
     * مبلغِ هدیه بر اساسِ خریدِ ۱۲ ماهِ گذشته.
     *
     * 🔴 معیار «مبلغ» است نه «تعدادِ فاکتور» — همان استدلالِ برنامهٔ نمایندگی:
     * با معیارِ تعدادی، مشتریِ ماهانه از مشتریِ سالانه بالاتر می‌نشیند در حالی
     * که پولِ کمتری داده.
     */
    private function giftFor(Customer $customer): int
    {
        $paid = (int) DB::table('invoices')
            ->where('customer_id', $customer->id)
            ->where('status', 'paid')
            ->where('currency_code', 'IRT')
            ->where('created_at', '>=', now()->subDays(365))
            ->sum('total');

        $tiers = (array) config('birthday.tiers', []);

        usort($tiers, fn ($a, $b) => ($a['min_paid_irt'] ?? 0) <=> ($b['min_paid_irt'] ?? 0));

        $gift = 0;

        foreach ($tiers as $tier) {
            if ($paid >= (int) ($tier['min_paid_irt'] ?? 0)) {
                $gift = (int) ($tier['gift_irt'] ?? 0);
            }
        }

        return $gift;
    }

    private function award(Customer $customer, int $amount, int $jy): void
    {
        $days = (int) config('birthday.expires_days', 30);
        $balance = $customer->creditBalance('IRT');

        $gift = CreditEntry::create([
            'customer_id'   => $customer->id,
            'currency_code' => 'IRT',
            'amount'        => $amount,
            'balance_after' => $balance + $amount,
            'reason'        => self::REASON_GIFT,
            'note'          => "هدیهٔ تولد [{$jy}] — تا {$days} روز معتبر",
        ]);

        try {
            app(\App\Services\Notify\Notifier::class)->fire(
                'birthday',
                $customer,
                [
                    'name'   => $this->firstName($customer),
                    'credit' => number_format($amount),
                    'days'   => (string) $days,
                ],
                'تولدتان مبارک! '.number_format($amount).' تومان اعتبارِ هدیه به کیفِ پولِ شما '
                ."اضافه شد و تا {$days} روز اعتبار دارد.",
            );
        } catch (\Throwable $e) {
            /*
            | اعلانِ ناموفق هدیه را پس نمی‌گیرد: پول در دفتر نشسته و مشتری در
            | پنل می‌بیندش. ولی بی‌صدا هم نمی‌مانَد — هدیه‌ای که کسی از آن خبردار
            | نشود هزینه‌ای است بی‌هیچ اثری.
            */
            ErrorTracker::note('notify', $e, ['event' => 'birthday', 'credit' => $gift->id]);
        }
    }

    /** نامِ کوچک — از ثبت‌احوال، وگرنه از پروفایل */
    private function firstName(Customer $customer): string
    {
        $iv = $customer->identityVerification;

        if ($iv !== null && filled($iv->first_name)) {
            return (string) $iv->first_name;
        }

        return (string) ($customer->defaultProfile()?->first_name ?: 'دوستِ');
    }

    // ───────────────────────── انقضا ─────────────────────────

    /**
     * بازپس‌گیریِ هدیه‌های منقضی.
     *
     * 🔴 چرا ستونِ `expires_at` به دفتر اضافه «نشد»: `creditBalance()` یک
     * `SUM`ِ سادهٔ کلِ دفتر است و همه‌جا — مترِ ساعتی، تسویه، فروشگاه — از آن
     * می‌پرسند. افزودنِ شرطِ انقضا به آن پرس‌وجو یعنی دست‌بردن در حساس‌ترین
     * محاسبهٔ پولیِ سامانه برای یک قابلیتِ جانبی. این‌جا به‌جایش یک ردیفِ منفیِ
     * صریح نوشته می‌شود: دفتر همچنان جمعِ ساده می‌مانَد، اثرش در گزارش‌های
     * مالی دیده می‌شود، و برگرداندنش فقط حذفِ یک ردیف است.
     *
     * ⚠️ بازپس‌گیری `min(هدیه، موجودی)` است. اگر مشتری خرجش کرده باشد چیزی پس
     * گرفته نمی‌شود و موجودی هرگز منفی نمی‌شود — یعنی این کار در بدترین حالت
     * فقط همان چیزی را می‌گیرد که خودمان داده بودیم.
     */
    private function sweepExpired(bool $dry): int
    {
        $days = (int) config('birthday.expires_days', 30);
        $cut = now()->subDays($days);

        $expired = CreditEntry::query()
            ->where('reason', self::REASON_GIFT)
            ->where('created_at', '<=', $cut)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('credit_ledger as e')
                    ->whereColumn('e.source_id', 'credit_ledger.id')
                    ->where('e.source_type', CreditEntry::class)
                    ->where('e.reason', self::REASON_EXPIRE);
            })
            ->with('customer')
            ->get();

        $done = 0;

        foreach ($expired as $gift) {
            $customer = $gift->customer;

            if ($customer === null) {
                continue;
            }

            $balance = $customer->creditBalance('IRT');
            $take = min((int) $gift->amount, max(0, $balance));

            $this->line("  انقضا: هدیهٔ #{$gift->id} — بازپس‌گیری ".number_format($take).' تومان');

            if (! $dry) {
                /*
                | ⚠️ حتی وقتی چیزی برای گرفتن نیست، ردیفِ «صفر» نوشته می‌شود.
                | آن ردیف مُهرِ «رسیدگی شد» است؛ بی‌آن، همین هدیه هر روز دوباره
                | بررسی می‌شود و پرس‌وجوی سوییپ سال‌به‌سال سنگین‌تر می‌شود.
                */
                CreditEntry::create([
                    'customer_id'   => $customer->id,
                    'currency_code' => 'IRT',
                    'amount'        => -$take,
                    'balance_after' => $balance - $take,
                    'reason'        => self::REASON_EXPIRE,
                    'source_type'   => CreditEntry::class,
                    'source_id'     => $gift->id,
                    'note'          => $take > 0
                        ? "انقضای هدیهٔ تولد (#{$gift->id}) پس از {$days} روز"
                        : "هدیهٔ تولد (#{$gift->id}) پیش از انقضا خرج شده بود",
                ]);
            }

            $done++;
        }

        return $done;
    }
}
