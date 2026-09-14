<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\GiftCoupon;
use App\Models\Service;
use App\Models\Setting;
use App\Support\ErrorTracker;
use App\Support\Jalali;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * هدیهٔ تولد — کوپنِ قفل‌شده به مشتری، با پنجرهٔ ساعتی.
 *
 * ═══ 🔴 چرا کوپن و نه اعتبارِ کیفِ پول ═══
 *
 * نسخهٔ اول اعتبار به کیفِ پول می‌ریخت و پس از انقضا پس می‌گرفت. آن طرح یک
 * نقصِ واقعی داشت که کارفرما گرفتش: بازپس‌گیری فرقِ «هدیه را خرج کرد» با
 * «پولِ خودش را خرج کرد» را نمی‌فهمید، پس مشتری‌ای که موجودی داشت و
 * به‌خاطرِ پیامِ ما خرید می‌کرد، فردا از پولِ **خودش** کم می‌شد. دلیلِ کامل
 * در مهاجرتِ `create_gift_coupons_table`.
 *
 * کوپن چیزی به کیفِ پول اضافه نمی‌کند، پس چیزی هم برای پس‌گرفتن نیست؛
 * منقضی‌شدنش یعنی فقط استفاده نشد. و همین، کلِ منطقِ سوییپ را حذف کرد.
 *
 * ═══ 🔴 چرا تاریخِ «شمسی» مبناست ═══
 *
 * `birth_date` میلادی ذخیره می‌شود، ولی مشتریِ ایرانی تولدش را شمسی
 * می‌شناسد. سالگردِ میلادی و شمسی گاهی یک روز فرق دارند، و پیامکِ تبریکِ
 * «یک روز دیرتر» از نفرستادنش بدتر است.
 *
 * ⚠️ و روزِ شمسی با ساعتِ «تهران» تعیین می‌شود نه UTC. `config/app.timezone`
 * عمداً UTC است، پس کرونی که ۲۱:۳۰ UTC بدود در تهران بامدادِ فرداست.
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

    protected $description = 'صدورِ کوپنِ هدیهٔ تولد (قفل به مشتری، مدت‌دار)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');

        if (! $this->enabled()) {
            $this->info('برنامهٔ هدیهٔ تولد خاموش است (Setting: birthday_enabled یا config/birthday.enabled).');

            return self::SUCCESS;
        }

        $issued = $this->issueToday($dry);

        $this->info("کوپنِ صادرشدهٔ امروز: {$issued}".($dry ? ' (خشک)' : ''));

        return self::SUCCESS;
    }

    // ───────────────────────── تنظیمات ─────────────────────────

    /**
     * تنظیماتِ پنل بر فایلِ config می‌چربد.
     *
     * ⚠️ رشتهٔ خالی «صفر» نیست، «ست‌نشده» است. یکی‌گرفتنشان یعنی یک فیلدِ
     * خالی در فرمِ تنظیمات، مبلغِ هدیه را بی‌صدا صفر می‌کرد و برنامه بی‌آنکه
     * خطایی بدهد هیچ کوپنی صادر نمی‌کرد.
     */
    private function setting(string $key, int|bool $fallback): int|bool
    {
        $raw = Setting::get($key);

        if ($raw === null || trim((string) $raw) === '') {
            return $fallback;
        }

        return is_bool($fallback)
            ? filter_var($raw, FILTER_VALIDATE_BOOLEAN)
            : (int) $raw;
    }

    private function enabled(): bool
    {
        return (bool) $this->setting('birthday_enabled', (bool) config('birthday.enabled', false));
    }

    // ───────────────────────── صدور ─────────────────────────

    private function issueToday(bool $dry): int
    {
        $tz = (string) config('calendar.display_timezone', 'Asia/Tehran');
        [$jy, $jm, $jd] = Jalali::ofMoment(now(), $tz);

        $amount = (int) $this->setting('birthday_amount_irt', (int) config('birthday.amount_irt', 0));
        $hours  = (int) $this->setting('birthday_valid_hours', (int) config('birthday.valid_hours', 24));
        $floor  = (int) $this->setting('birthday_min_invoice', (int) config('birthday.min_invoice_irt', 0));
        $cap    = (int) config('birthday.daily_cap', 50);

        if ($amount <= 0 || $hours <= 0) {
            /*
            | مبلغِ صفر یعنی پیکربندی ناقص است، نه «هدیهٔ صفر تومانی». پیامکِ
            | «۰ تومان هدیه گرفتید» از نفرستادن بدتر است.
            */
            ErrorTracker::noteOnce('notify',
                'هدیهٔ تولد روشن است ولی مبلغ یا مدتش معتبر نیست '
                ."(مبلغ={$amount}، ساعت={$hours}) — هیچ کوپنی صادر نشد.",
                3600, ['area' => 'birthday']);

            return 0;
        }

        $done = 0;

        foreach ($this->birthdaysOn($jy, $jm, $jd) as $customer) {
            if ($done >= $cap) {
                /*
                | 🔴 رد شدن از سقف = توقفِ «پرصدا»، نه ادامهٔ خاموش. تنها
                | راه‌هایی که این عدد پر می‌شود ایمپورتِ انبوهِ داده یا خرابیِ
                | پرس‌وجوست، و هر دو یعنی داریم پولِ واقعی توزیع می‌کنیم
                | بی‌آنکه بدانیم چرا.
                */
                ErrorTracker::note('notify',
                    "هدیهٔ تولد به سقفِ روزانه ({$cap}) خورد و متوقف شد — "
                    .'امروز غیرعادی زیاد تولد پیدا شد. پیش از بالابردنِ سقف، فهرست را ببین.');
                break;
            }

            if ($this->alreadyIssued($customer, $jy)) {
                continue;
            }

            $this->line("  {$customer->code} — ".number_format($amount).' تومان');

            if (! $dry) {
                $this->issue($customer, $amount, $hours, $floor);
            }

            $done++;
        }

        return $done;
    }

    /**
     * مشتری‌هایی که امروز تولدشان است.
     *
     * ⚠️ ماه/روزِ شمسی با ماه/روزِ میلادیِ ستون یکی نیست، پس فیلترِ SQL ممکن
     * نیست و تبدیل باید در PHP انجام شود. برای اینکه جدولِ کاملِ مشتری‌ها
     * خوانده نشود، فقط ردیف‌هایی برداشته می‌شوند که تاریخِ تولد دارند و
     * حسابشان فعال است.
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
     * آیا امسال کوپنش صادر شده؟
     *
     * ⚠️ کوپنِ **مصرف‌شده یا منقضی هم** حساب می‌شود. شرطِ «فقط کوپنِ زنده» یعنی
     * مشتری‌ای که کوپنش را همان روز خرج کرده، اجرای بعدیِ کرون در همان روز
     * کوپنِ دومی می‌گرفت.
     */
    private function alreadyIssued(Customer $customer, int $jy): bool
    {
        return GiftCoupon::query()
            ->where('customer_id', $customer->id)
            ->where('reason', 'birthday')
            ->where('created_at', '>=', now()->subDays(300))
            ->exists();
    }

    private function issue(Customer $customer, int $amount, int $hours, int $floor): void
    {
        $coupon = GiftCoupon::create([
            'customer_id'   => $customer->id,
            'code'          => GiftCoupon::freshCode(),
            'currency_code' => 'IRT',
            'amount'        => $amount,
            /*
            | ⚠️ کفِ فاکتور روی **خودِ کوپن** ذخیره می‌شود، نه فقط در تنظیمات:
            | تغییرِ بعدیِ تنظیمات نباید شرطِ کوپنی را عوض کند که مشتری از
            | قبل در دست دارد.
            */
            'min_invoice'   => $floor,
            'reason'        => 'birthday',
            'expires_at'    => now()->addHours($hours),
        ]);

        try {
            app(\App\Services\Notify\Notifier::class)->fire(
                'birthday',
                $customer,
                [
                    'name'   => $this->firstName($customer),
                    'credit' => number_format($amount),
                    'code'   => $coupon->code,
                    'hours'  => (string) $hours,
                ],
                'تولدتان مبارک! کدِ هدیهٔ '.number_format($amount).' تومانیِ شما: '.$coupon->code
                ."\nاین کد تا {$hours} ساعت اعتبار دارد و روی فاکتورِ خودتان قابلِ استفاده است.",
            );
        } catch (\Throwable $e) {
            /*
            | اعلانِ ناموفق کوپن را باطل نمی‌کند: کد در پنلِ مشتری دیده
            | می‌شود. ولی بی‌صدا هم نمی‌مانَد — کوپنی که کسی از آن خبردار نشود
            | تا ۲۴ ساعت بعد بی‌مصرف منقضی می‌شود.
            */
            ErrorTracker::note('notify', $e, ['event' => 'birthday', 'coupon' => $coupon->id]);
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
}
