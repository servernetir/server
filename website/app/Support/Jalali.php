<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * تبدیلِ شمسی ↔ میلادی برای تقویمِ کسب‌وکار.
 *
 * چرا این کلاس و نه یک پکیج: پروژه از قبل الگوریتمِ میلادی→شمسی را دارد
 * (`jalali_ymd()` در `app/helpers.php`, که `blog_date()` و `sdate()` رویش
 * سوارند). افزودنِ `morilog/jalali` یعنی **دو** الگوریتم در یک کدبیس که
 * می‌توانند یک روز اختلاف بدهند — و آن اختلاف در صفحه‌ای که سررسیدِ فاکتور
 * نشان می‌دهد یعنی مدیر یک روز دیرتر تماس می‌گیرد.
 *
 * پس این‌جا فقط **جهتِ برعکس** (شمسی→میلادی) نوشته شده؛ جهتِ رفت به همان
 * `jalali_ymd()` واگذار می‌شود تا یک منبعِ حقیقت بماند.
 *
 * ⚠️ هیچ متدی این‌جا منطقهٔ زمانی نمی‌سازد. تاریخِ شمسی یک **روزِ تقویمی**
 * است نه یک لحظه؛ لحظه‌سازی کارِ فراخوان است (با `config('calendar.display_timezone')`).
 */
final class Jalali
{
    /** روزهای هر ماهِ شمسی — اسفند در سالِ کبیسه ۳۰ روز است */
    private const J_DAYS_IN_MONTH = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];

    private const G_DAYS_IN_MONTH = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    /** @var list<string> */
    public const MONTH_NAMES = [
        'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
        'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
    ];

    /**
     * نامِ روزهای هفته، از **شنبه**.
     *
     * ⚠️ هفتهٔ ایرانی شنبه شروع می‌شود، نه یکشنبه و نه دوشنبه. اگر این را با
     * `Carbon::startOfWeek()` بسازی، لاراول دوشنبه می‌دهد و کلِ شبکهٔ ماه دو
     * ستون جابه‌جا می‌شود — خطایی که با چشم دیده نمی‌شود چون تقویم همچنان
     * «درست به‌نظر می‌رسد».
     *
     * @var list<string>
     */
    public const WEEKDAY_NAMES = ['شنبه', 'یک‌شنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه'];

    /**
     * میلادی → شمسی. صرفاً پوششی روی کمک‌تابعِ موجود، تا مصرف‌کننده‌های این
     * کلاس مجبور نباشند بین «تابعِ سراسری» و «کلاس» جابه‌جا شوند.
     *
     * @return array{0:int,1:int,2:int} [jy, jm, jd]
     */
    public static function fromGregorian(int $gy, int $gm, int $gd): array
    {
        return jalali_ymd($gy, $gm, $gd);
    }

    /**
     * شمسی → میلادی. معکوسِ دقیقِ همان الگوریتمِ `jalali_ymd()`.
     *
     * @return array{0:int,1:int,2:int} [gy, gm, gd]
     */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $jy -= 979;
        $jm -= 1;
        $jd -= 1;

        $dayNo = 365 * $jy + intdiv($jy, 33) * 8 + intdiv(($jy % 33) + 3, 4);

        for ($i = 0; $i < $jm; $i++) {
            $dayNo += self::J_DAYS_IN_MONTH[$i];
        }

        $dayNo += $jd + 79;

        $gy = 1600 + 400 * intdiv($dayNo, 146097);
        $dayNo %= 146097;

        $leap = true;
        if ($dayNo >= 36525) {
            $dayNo--;
            $gy += 100 * intdiv($dayNo, 36524);
            $dayNo %= 36524;

            if ($dayNo >= 365) {
                $dayNo++;
            } else {
                $leap = false;
            }
        }

        $gy += 4 * intdiv($dayNo, 1461);
        $dayNo %= 1461;

        if ($dayNo >= 366) {
            $leap = false;
            $dayNo--;
            $gy += intdiv($dayNo, 365);
            $dayNo %= 365;
        }

        $gm = 0;
        while ($gm < 12) {
            $len = self::G_DAYS_IN_MONTH[$gm] + ($gm === 1 && $leap ? 1 : 0);
            if ($dayNo < $len) {
                break;
            }
            $dayNo -= $len;
            $gm++;
        }

        return [$gy, $gm + 1, $dayNo + 1];
    }

    /**
     * رشتهٔ شمسیِ ورودیِ کاربر/API → [jy, jm, jd].
     *
     * جداکنندهٔ `-` و `/` هر دو، و ارقامِ فارسی/عربی هم پذیرفته می‌شوند —
     * چون کاربرِ این پنل با صفحه‌کلیدِ فارسی تایپ می‌کند و `1405/05/12` که
     * با ارقامِ فارسی نوشته شده باید کار کند، نه اینکه بی‌صدا رد شود.
     *
     * @return array{0:int,1:int,2:int}|null نال یعنی «تاریخ نیست»
     */
    public static function parse(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $ascii = self::toAsciiDigits($value);

        if (! preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', trim($ascii), $m)) {
            return null;
        }

        [$jy, $jm, $jd] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > self::daysInMonth($jy, $jm)) {
            return null;
        }

        return [$jy, $jm, $jd];
    }

    /** آیا سالِ شمسی کبیسه است (اسفندِ ۳۰ روزه)؟ */
    public static function isLeap(int $jy): bool
    {
        /*
         * عمداً بدونِ فرمولِ مستقل: ۳۰ اسفند را به میلادی می‌بریم و برمی‌گردانیم.
         * اگر همان روز برگشت، آن سال کبیسه است.
         *
         * چرا این‌طور: هر فرمولِ کبیسهٔ جداگانه می‌تواند با الگوریتمِ تبدیل یک
         * سال اختلاف پیدا کند، و آن اختلاف فقط در یک روزِ خاصِ سال دیده می‌شود
         * — یعنی باگی که سالی یک‌بار ظاهر می‌شود و هیچ‌وقت بازتولید نمی‌شود.
         */
        [$gy, $gm, $gd] = self::toGregorian($jy, 12, 30);

        return self::fromGregorian($gy, $gm, $gd) === [$jy, 12, 30];
    }

    /** تعدادِ روزهای یک ماهِ شمسی */
    public static function daysInMonth(int $jy, int $jm): int
    {
        if ($jm < 1 || $jm > 12) {
            return 0;
        }

        if ($jm === 12) {
            return self::isLeap($jy) ? 30 : 29;
        }

        return self::J_DAYS_IN_MONTH[$jm - 1];
    }

    /** نامِ ماه؛ ماهِ نامعتبر رشتهٔ خالی می‌دهد نه استثنا */
    public static function monthName(int $jm): string
    {
        return self::MONTH_NAMES[$jm - 1] ?? '';
    }

    /**
     * شمارهٔ ستونِ یک روز در شبکهٔ ماه: ۰ = شنبه … ۶ = جمعه.
     */
    public static function weekdayIndex(Carbon $date): int
    {
        // Carbon: 0=یکشنبه … 6=شنبه. شنبه باید صفر شود، پس +1 و mod 7.
        return ((int) $date->dayOfWeek + 1) % 7;
    }

    /**
     * تاریخِ شمسی → لحظهٔ **آغازِ** آن روز در منطقهٔ زمانیِ نمایش.
     */
    public static function startOfDay(int $jy, int $jm, int $jd, string $timezone): Carbon
    {
        [$gy, $gm, $gd] = self::toGregorian($jy, $jm, $jd);

        $moment = Carbon::create($gy, $gm, $gd, 0, 0, 0, $timezone);

        // `Carbon::create()` روی ورودیِ نامعتبر `false` می‌دهد نه استثنا. با
        // ورودیِ اعتبارسنجی‌شده هرگز رخ نمی‌دهد، ولی نوعِ بازگشتیِ اعلام‌شده
        // اجازهٔ `false` ندارد و یک TypeErrorِ مبهم بدترین راهِ فهمیدنش است.
        return $moment instanceof Carbon ? $moment : Carbon::now($timezone)->startOfDay();
    }

    /**
     * یک لحظه → تاریخِ شمسیِ همان لحظه **به وقتِ نمایش**.
     *
     * @return array{0:int,1:int,2:int}
     */
    public static function ofMoment(Carbon $moment, string $timezone): array
    {
        $local = $moment->copy()->setTimezone($timezone);

        return self::fromGregorian(
            (int) $local->format('Y'),
            (int) $local->format('m'),
            (int) $local->format('d'),
        );
    }

    /**
     * n ماهِ شمسی جلو رفتن، با **نگه‌داشتنِ روزِ ماه**.
     *
     * 🔴 چرا نمی‌شود با `Carbon::addMonths()` این کار را کرد: آن ماهِ **میلادی**
     * را جلو می‌برد. «پنجمِ هر ماه» برای کارفرمایی که اجاره‌اش را ۵ مرداد
     * می‌دهد یعنی ۵ شهریور، نه «۲۷ ژوئیه + ۳۰ روز». چون ماه‌های شمسی ۳۱ و ۳۰
     * و ۲۹ روزه‌اند و با میلادی هم‌مرز نیستند، جلوبردنِ میلادی بعد از چند ماه
     * یکی‌دو روز جابه‌جا می‌شود — و یادآوریِ اجاره آرام‌آرام از روزش می‌افتد.
     *
     * ⚠️ روزی که در ماهِ مقصد وجود ندارد **کوتاه** می‌شود، نه اینکه به ماهِ بعد
     * سر برود: ۳۱ فروردین + ۶ ماه = ۳۰ مهر (نه ۱ آبان). وگرنه یادآوریِ «۳۱ هر
     * ماه» در ماه‌های ۳۰روزه یک روز جلو می‌پرید و در اسفند دو روز.
     *
     * @return array{0:int,1:int,2:int}
     */
    public static function addMonths(int $jy, int $jm, int $jd, int $months): array
    {
        $total = ($jy * 12) + ($jm - 1) + $months;
        $ny = intdiv($total, 12);
        $nm = ($total % 12) + 1;

        return [$ny, $nm, min($jd, self::daysInMonth($ny, $nm))];
    }

    /**
     * n سالِ شمسی جلو رفتن.
     *
     * ⚠️ ۳۰ اسفندِ سالِ کبیسه در سالِ عادی وجود ندارد و به ۲۹ کوتاه می‌شود.
     *
     * @return array{0:int,1:int,2:int}
     */
    public static function addYears(int $jy, int $jm, int $jd, int $years): array
    {
        $ny = $jy + $years;

        return [$ny, $jm, min($jd, self::daysInMonth($ny, $jm))];
    }

    /** `1405-05-12` — قالبِ سیمیِ API (همیشه ارقامِ لاتین) */
    public static function format(int $jy, int $jm, int $jd): string
    {
        return sprintf('%04d-%02d-%02d', $jy, $jm, $jd);
    }

    /** ارقامِ فارسی/عربی → لاتین */
    private static function toAsciiDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }
}
