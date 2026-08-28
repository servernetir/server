<?php

if (! function_exists('lc')) {
    /**
     * مقدار بومی‌شده‌ی یک آیتم config بر اساس زبان جاری،
     * با fallback به انگلیسی و بعد فارسی (که هیچ صفحه‌ای نشکند).
     */
    function lc(array $item): mixed
    {
        return $item[app()->getLocale()] ?? $item['en'] ?? $item['fa'] ?? null;
    }
}

if (! function_exists('site_contact')) {
    /**
     * اطلاعات تماس با شمارهٔ **زبان‌محور**.
     *
     * فارسی خط ثابت تهران را می‌بیند، en/tr شمارهٔ بین‌المللی را. همان منطقِ
     * `site_price()` و `fa_num()`: تصمیم یک‌جا گرفته می‌شود، نه در تک‌تک
     * نقاط فراخوانی — چون ۱۵ جای مختلف `$contact['phone']` را چاپ می‌کنند و
     * شرطِ زبان دیر یا زود یکی‌شان جا می‌افتاد.
     *
     * 🔴 چرا صرفاً یک شمارهٔ واحد کافی نبود: شمارهٔ `021` را مشتری خارجی
     * نمی‌تواند بگیرد (کد کشور ندارد) و شمارهٔ آمریکایی برای مشتری ایرانی هم
     * گران است هم بی‌اعتمادکننده. هر دو حالت یعنی تماسی که هرگز برقرار نمی‌شود.
     *
     * ⚠️ `$locale` را فقط جایی صریح بده که زبانِ خروجی با زبانِ درخواست یکی
     * نیست — مثلِ `ChatController` که هر سه نسخهٔ پاسخ را می‌سازد و بعد یکی را
     * برمی‌دارد. آن‌جا `app()->getLocale()` جوابِ درست نمی‌دهد.
     *
     * @return array<string, mixed>
     */
    function site_contact(?string $locale = null): array
    {
        $c = (array) config('servernet.contact', []);

        if (($locale ?? app()->getLocale()) !== 'fa') {
            // نبودِ کلیدِ بین‌المللی نباید صفحه را بی‌شماره کند — به فارسی برمی‌گردیم
            $c['phone']      = $c['phone_intl'] ?? $c['phone'] ?? '';
            $c['phone_link'] = $c['phone_intl_link'] ?? $c['phone_link'] ?? '';
        }

        return $c;
    }
}

if (! function_exists('fa_num')) {
    /** تبدیل ارقام لاتین به فارسی */
    function fa_num(string|int|float $value): string
    {
        // فقط در فارسی. رقم فارسی روی صفحهٔ انگلیسی یا ترکی غلط است، و
        // نوشتن شرط زبان در تک‌تک ۵۰ نقطهٔ فراخوانی، دیر یا زود یک‌جا جا
        // می‌افتد — پس تصمیم اینجا گرفته می‌شود.
        if (app()->getLocale() !== 'fa') {
            return (string) $value;
        }

        return strtr((string) $value, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
    }
}

if (! function_exists('avatar_url')) {
    /**
     * نشانی تصویر گراواتار برای یک ایمیل.
     *
     * `d=404` عمدی است: اگر کاربر گراواتار نداشته باشد به‌جای تصویر پیش‌فرضِ
     * بی‌ربط، ۴۰۴ می‌گیریم و قالب روی حرف اول نام می‌ماند. یعنی هیچ‌وقت
     * آواتار ژنریک و بی‌روح نشان داده نمی‌شود.
     *
     * نکته: هش ایمیل به گراواتار می‌رود، پس آن‌ها می‌فهمند این ایمیل روی
     * سایت ما فعال است. برای حساب کاربری قابل قبول است، ولی جای عمومی سایت
     * استفاده نشود.
     *
     * تصویر واقعی گوگل فقط بعد از «ورود با گوگل» در دسترس می‌آید؛ تا آن
     * موقع گراواتار نزدیک‌ترین چیز است.
     */
    function avatar_url(?string $email, int $size = 160): ?string
    {
        if (blank($email)) {
            return null;
        }

        $hash = hash('sha256', mb_strtolower(trim($email)));

        return 'https://www.gravatar.com/avatar/'.$hash.'?s='.$size.'&d=404';
    }
}

if (! function_exists('initials')) {
    /** حرف اول برای آواتار متنی — با نام رسمی، وگرنه با ایمیل */
    function initials(?string $name, ?string $email = null): string
    {
        $source = filled($name) ? $name : (string) $email;
        $source = trim($source);

        return $source === '' ? '؟' : mb_strtoupper(mb_substr($source, 0, 1));
    }
}

if (! function_exists('blog_date')) {
    /** تاریخ پست: شمسی برای فارسی، میلادی خوانا برای en/tr */
    function blog_date(string $iso): string
    {
        if ($iso === '') {
            return '';
        }
        [$gy, $gm, $gd] = array_map('intval', array_pad(explode('-', substr($iso, 0, 10)), 3, 1));

        if (app()->getLocale() !== 'fa') {
            $months = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

            return $gd.' '.($months[$gm] ?? '').' '.$gy;
        }

        /*
         * ⚠️ الگوریتم عمداً این‌جا **تکرار نشده**.
         *
         * تا امروز همان ۲۵ خط دو بار در این فایل بود (این‌جا و در
         * `jalali_ymd()`), و باگِ «روزِ ۳۶۶اُمِ سالِ کبیسه ⇒ ماهِ سیزدهم» در هر
         * دو نسخه بود — یعنی رفعِ یکی، دیگری را سالم نمی‌کرد و همان تاریخ در
         * بلاگ درست و در پنل غلط می‌شد. یک پیاده‌سازی، یک رفع.
         */
        [$jy, $jm, $jd] = jalali_ymd($gy, $gm, $gd);

        $names = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

        return fa_num($jd).' '.($names[$jm] ?? '').' '.fa_num($jy);
    }
}

if (! function_exists('jalali_ymd')) {
    /**
     * میلادی → [jy, jm, jd] (جلالی). همان الگوریتم blog_date، جدا تا هرجا
     * فرمت عددی لازم بود دوباره‌نویسی نشود.
     *
     * @return array{0:int,1:int,2:int}
     */
    function jalali_ymd(int $gy, int $gm, int $gd): array
    {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gy - 1600;
        $gm2 = $gm - 1;
        $gd2 = $gd - 1;
        $g_day_no = 365 * $gy2 + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400);
        $g_day_no += $g_d_m[$gm2] + $gd2;
        if ($gm2 > 1 && (($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0)) {
            $g_day_no++;
        }
        $j_day_no = $g_day_no - 79;
        $j_np = intdiv($j_day_no, 12053);
        $j_day_no %= 12053;
        $jy = 979 + 33 * $j_np + 4 * intdiv($j_day_no, 1461);
        $j_day_no %= 1461;
        if ($j_day_no >= 366) {
            $jy += intdiv($j_day_no - 1, 365);
            $j_day_no = ($j_day_no - 1) % 365;
        }
        $j_days_in_month = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
        $jm = 0;
        for ($i = 0; $i < 12 && $j_day_no >= $j_days_in_month[$i]; $i++) {
            $j_day_no -= $j_days_in_month[$i];
            $jm++;
        }

        /*
         * 🔴 روزِ ۳۶۶اُمِ سالِ کبیسه = **۳۰ اسفند**، نه «۱ ماهِ سیزدهم».
         *
         * جدولِ بالا اسفند را ثابت ۲۹ روز می‌گیرد و جمعش ۳۶۵ می‌شود. پس در
         * سالِ کبیسه (۱۳۹۹، ۱۴۰۳، ۱۴۰۸ …) حلقه هر ۱۲ ماه را مصرف می‌کرد و
         * `$jm` برابرِ ۱۲ می‌شد ⇒ خروجی `[jy, 13, 1]`.
         *
         * پیامدش خاموش بود و سالی یک روز: `blog_date()` نامِ ماه را از
         * `$names[13]` می‌خواند که وجود ندارد و **رشتهٔ خالی** چاپ می‌کرد، و
         * `sdate()` تاریخِ ناموجودِ «۱۴۰۳/۱۳/۰۱» می‌ساخت. هیچ استثنایی، هیچ
         * لاگی — دقیقاً همان الگوی «خرابیِ خاموش»ی که در CLAUDE.md گران تمام
         * شده. برای تقویمِ کسب‌وکار این یعنی رویدادِ ۳۰ اسفند در هیچ خانه‌ای
         * از شبکهٔ ماه نمی‌نشست.
         *
         * شرط فقط در همان یک روز صادق است؛ بقیهٔ سال دست‌نخورده می‌مانَد.
         */
        if ($jm > 11) {
            $jm = 11;
            $j_day_no += 29;   // ۲۹ روزی که اسفند در حلقه مصرف کرد، برمی‌گردد
        }

        return [$jy, $jm + 1, $j_day_no + 1];
    }
}

if (! function_exists('sdate')) {
    /**
     * تاریخ نمایشی: شمسیِ عددی (۱۴۰۳/۰۵/۱۲) برای fa، میلادی برای بقیه.
     *
     * دیتابیس میلادی و UTC می‌ماند؛ این فقط لایهٔ نمایش است. برای fa به وقت
     * تهران تبدیل می‌شود تا ساعت و حتی روز درست باشد (نه UTC).
     *
     * @param  \DateTimeInterface|string|null  $date
     */
    function sdate($date, bool $withTime = false): string
    {
        if ($date === null || $date === '') {
            return '—';
        }

        try {
            $c = $date instanceof \DateTimeInterface
                ? \Illuminate\Support\Carbon::instance($date)
                : \Illuminate\Support\Carbon::parse((string) $date);
        } catch (\Throwable) {
            return '—';
        }

        if (app()->getLocale() !== 'fa') {
            return $c->format($withTime ? 'Y/m/d H:i' : 'Y/m/d');
        }

        $c = $c->copy()->setTimezone('Asia/Tehran');
        [$jy, $jm, $jd] = jalali_ymd((int) $c->format('Y'), (int) $c->format('m'), (int) $c->format('d'));
        $out = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);

        if ($withTime) {
            $out .= ' '.$c->format('H:i');
        }

        return fa_num($out);
    }
}

if (! function_exists('stime')) {
    /** میان‌بر: تاریخ + ساعتِ شمسی */
    function stime($date): string
    {
        return sdate($date, true);
    }
}

if (! function_exists('sdate_full')) {
    /**
     * تاریخِ گفتاری: «سه‌شنبه ۲۸ مرداد ۱۴۰۵ · ۱۱:۵۸».
     *
     * ═══ چرا جدا از `sdate()` ═══
     *
     * `sdate()` عددی است (۱۴۰۵/۰۵/۲۸) و برای **جدول** درست است: کوتاه، هم‌عرض،
     * و قابلِ مرور. ولی کاربردِ تاریخِ تماس فرق دارد — کارفرما آن را **پشتِ
     * تلفن می‌خوانَد**: «شما سه‌شنبه ۲۸ مرداد تماس گرفته بودید». برای گفتن،
     * «۱۴۰۵/۰۵/۲۸» باید در ذهن ترجمه شود و روزِ هفته اصلاً در آن نیست.
     *
     * ⚠️ روزِ هفته **پس از** انتقال به وقتِ تهران گرفته می‌شود. تماسِ ۲۱:۳۰
     * به‌وقتِ UTC، به‌وقتِ تهران بامدادِ **فردا**ست — یعنی هم روزش عوض می‌شود
     * هم نامِ روزِ هفته‌اش. همان تلهٔ ثبت‌شدهٔ تقویم در CLAUDE.md.
     *
     * ⚠️ برخلافِ `sdate()` به `app()->getLocale()` نگاه **نمی‌کند** — و این
     * عمدی است.
     *
     * روت‌های `/admin/*` بیرونِ closureِ `$site`اند و هیچ middlewareِ `locale`
     * رویشان نمی‌دود، پس زبانِ پنل هرچه `APP_LOCALE` در `.env` باشد همان است.
     * یعنی `sdate()` در پنل به یک متغیرِ محیطی بند است که اصلاً دربارهٔ پنل
     * نیست: اگر روزی `APP_LOCALE=en` شود، کلِ تاریخ‌های پنل بی‌هیچ خطایی
     * میلادی می‌شوند. (همان نقصِ ثبت‌شده در CLAUDE.md دربارهٔ
     * `TicketReplyService`، از درِ دیگر.)
     *
     * این تابع یک کارِ مشخص دارد: جمله‌ای که کارفرما **پشتِ تلفن می‌گوید**.
     * آن جمله فارسی است، مستقل از زبانِ سایت. پس شاخهٔ زبان ندارد.
     *
     * @param  \DateTimeInterface|string|null  $date
     */
    function sdate_full($date, bool $withTime = true): string
    {
        if ($date === null || $date === '') {
            return '—';
        }

        try {
            $c = $date instanceof \DateTimeInterface
                ? \Illuminate\Support\Carbon::instance($date)
                : \Illuminate\Support\Carbon::parse((string) $date);
        } catch (\Throwable) {
            return '—';
        }

        $c = $c->copy()->setTimezone('Asia/Tehran');
        [$jy, $jm, $jd] = jalali_ymd((int) $c->format('Y'), (int) $c->format('m'), (int) $c->format('d'));

        $out = \App\Support\Jalali::WEEKDAY_NAMES[\App\Support\Jalali::weekdayIndex($c)]
            .' '.$jd.' '.\App\Support\Jalali::monthName($jm).' '.$jy;

        if ($withTime) {
            $out .= ' · '.$c->format('H:i');
        }

        /*
        | ⚠️ `fa_num()` این‌جا کار نمی‌کند: خودش هم به `getLocale()` نگاه می‌کند
        | و زیرِ `APP_LOCALE=en` ارقام را لاتین برمی‌گردانَد — یعنی همان
        | وابستگی‌ای که این تابع برای نداشتنش نوشته شد، از درِ پشتی برمی‌گشت و
        | خروجی «چهارشنبه 28 مرداد 1405» می‌شد.
        */
        return strtr($out, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
    }
}

if (! function_exists('site_price')) {
    /** قیمت نمایشی بر اساس زبان جاری: تومان برای fa، یورو برای en */
    function site_price(array $item): string
    {
        /*
        | ⚠️ «قیمتِ نبود» در **هر سه** زبان یک‌جور رفتار می‌کند.
        |
        | شاخهٔ یورویی محافظ گرفت ولی شاخهٔ فارسی `?? 0` ماند و بی‌سروصدا
        | «۰ تومان» چاپ می‌کرد — یعنی «رایگان». همان چیزی که چند خط پایین‌تر
        | صریح ممنوع شده، فقط با ارزِ دیگر. (هیچ پلنی در config قیمتِ صفر
        | ندارد؛ صفر یعنی «نمی‌دانیم»، نه «مجانی».)
        */
        $irt = (int) ($item['irt'] ?? 0);

        if (app()->getLocale() === 'fa') {
            if ($irt <= 0) {
                return '—';
            }

            // قیمتِ تومانی با نرخِ زندهٔ یورو مقیاس می‌شود (پیش‌فرض: بدونِ تغییر)
            return fa_num(number_format(price_toman($irt))).' تومان';
        }

        /*
        | 🔴 `eur` ممکن است **نباشد**.
        |
        | قیمتِ دامنه زنده از رجیسترار می‌آید و فقط تومانی است، پس
        | `CatalogController` عمداً `unset($p['eur'])` می‌کند. نسخهٔ قبلی
        | مستقیم `$item['eur']` را می‌خواند و صفحهٔ ثبتِ دامنه روی `/en` و
        | `/tr` می‌شکست — ۵۳ خطا در ردیاب، در حالی که نسخهٔ فارسی سالم بود و
        | کسی متوجه نمی‌شد.
        |
        | ⚠️ عددِ صفر برنمی‌گردانیم: «€۰» یعنی رایگان، و آن از خطا بدتر است.
        | وقتی قیمتِ تومانی هست، با نرخِ زنده تبدیلش می‌کنیم — همان کاری که
        | `cloud_price()` می‌کند. نرخ که نبود، «—» صادقانه‌تر از عددِ ساختگی است.
        */
        if (! array_key_exists('eur', $item) || $item['eur'] === null) {
            $irt = (int) ($item['irt'] ?? 0);

            if ($irt <= 0) {
                return '—';
            }

            return cloud_price($irt);
        }

        $eur = $item['eur'];

        return '€'.($eur == (int) $eur ? number_format($eur) : number_format($eur, 2));
    }
}

if (! function_exists('site_price_yearly')) {
    /** قیمت ماهانه‌ی معادل با تخفیف پرداخت سالانه */
    function site_price_yearly(array $item): string
    {
        $factor = 1 - config('servernet.yearly_discount', 0) / 100;

        // ⚠️ همان محافظ: آیتمی که `eur` ندارد نباید این‌جا بشکند
        $out = ['irt' => (int) round((int) ($item['irt'] ?? 0) * $factor, -4)];

        if (isset($item['eur'])) {
            $out['eur'] = round($item['eur'] * $factor, 2);
        }

        return site_price($out);
    }
}

if (! function_exists('whmcs_price')) {
    /**
     * فرمت قیمتی که از WHMCS آمده، با ارز خود WHMCS.
     * فارسی: «۲,۱۷۵,۰۰۰ ریال» — لاتین: «€12.90»
     */
    function whmcs_price(float $amount, array $currency): string
    {
        $formatted = number_format($amount);
        $label = trim(($currency['prefix'] ?? '').($currency['suffix'] ?? ''));

        if (app()->getLocale() === 'fa') {
            return trim(fa_num($formatted).' '.$label);
        }

        // نمادهای لاتین (€, $) قبل از عدد
        return preg_match('/^[\x20-\x7E]+$/', $label)
            ? $label.$formatted
            : trim($formatted.' '.$label);
    }
}

if (! function_exists('lroute')) {
    /** آدرس روت داخلی با پیشوند زبان جاری (fa بدون پیشوند، en./tr. با پیشوند) */
    function lroute(string $name, mixed $params = []): string
    {
        $prefix = \App\Providers\AppServiceProvider::LOCALES[app()->getLocale()] ?? '';

        return route($prefix.$name, $params);
    }
}

if (! function_exists('pending_order_label')) {
    /**
     * نامِ چیزی که کاربر داشت می‌خرید، اگر ورود وسطِ راه سبزش کرده باشد.
     *
     * ═══ چرا لازم شد ═══
     *
     * ممیزیِ بیرونی گفت کلیک روی «انتخاب پلن» نیت را دور می‌ریزد. نیمی‌اش
     * درست بود: نشست از طریقِ `url.intended` مقصد را نگه می‌دارد و
     * `LoginController` هم `intended()` می‌زند — ولی صفحهٔ ورود **هیچ‌جا این
     * را نمی‌گفت**. کاربر یک دیوارِ ورودِ بی‌نشانه می‌دید و نتیجه می‌گرفت
     * انتخابش گم شده. (نیمهٔ واقعاً خرابش ثبت‌نام بود که اصلاً `intended` را
     * رعایت نمی‌کرد.)
     *
     * ⚠️ منبع عمداً همان `url.intended` است، نه یک پارامترِ URL: تنها چیزی که
     * واقعاً تعیین می‌کند کاربر بعد از ورود کجا می‌رود همان است. اگر متن از
     * جای دیگری بیاید، روزی وعده‌ای می‌دهد که ریدایرکت به آن عمل نمی‌کند.
     *
     * ⚠️ نبودِ محصول یعنی `null` و بخش اصلاً رندر نمی‌شود — نه جای خالی، نه
     * اسلاگِ خام. اسلاگ برای کاربر معنایی ندارد.
     */
    function pending_order_label(): ?string
    {
        $intended = session('url.intended');
        if (! is_string($intended) || $intended === '') {
            return null;
        }

        if (! preg_match('~/account/order/([a-z0-9\-]+)~i', $intended, $m)) {
            return null;
        }

        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('products')) {
                return null;
            }

            return \App\Models\Product::where('slug', $m[1])->value('name');
        } catch (\Throwable) {
            // صفحهٔ ورود حق ندارد به‌خاطرِ یک قطعیِ گذرای دیتابیس ۵۰۰ شود
            return null;
        }
    }
}

if (! function_exists('console_lroute')) {
    /**
     * همان lroute ولی روی میزبان کنسول.
     *
     * برای لینک‌هایی که از سایت اصلی به پنل می‌روند (دکمهٔ ورود در منو). با
     * این، کاربر مستقیم به console.servernet.cloud می‌رود و یک پرش ریدایرکت
     * اضافه ندارد. ConsoleHost هم پشتیبان است: اگر لینکی جا ماند و به دامنهٔ
     * اصلی رفت، آن‌جا ۳۰۱ به کنسول می‌خورد.
     */
    function console_lroute(string $name, mixed $params = []): string
    {
        $prefix = \App\Providers\AppServiceProvider::LOCALES[app()->getLocale()] ?? '';
        // route(..., absolute:false) مسیر بدون میزبان می‌دهد؛ میزبان کنسول را جلو می‌گذاریم
        $path = route($prefix.$name, $params, false);

        return 'https://console.servernet.cloud'.$path;
    }
}

if (! function_exists('img_url')) {
    /*
    |--------------------------------------------------------------------------
    | 🔴 نشانیِ تصویر — یا یک آدرسِ واقعی، یا `null`. هرگز رشتهٔ «null».
    |--------------------------------------------------------------------------
    |
    | ۴۰۴هایی که در ردیابِ خطا دیده شد یک سازوکارِ **مشترک** داشتند:
    |
    |     /null            ← از  /blog?tag=…  و صفحهٔ ورودِ کنسول
    |     /cloud/null      ← از  /cloud/<slug>
    |     /servers/null    ← از  /servers/<slug>
    |     /en/blog/null    ← از  /en/blog/<slug>
    |
    | همه با **حلِ نسبیِ** یک نشانیِ خامِ «null» توضیح داده می‌شوند: مرورگر
    | `src="null"` را کنارِ پوشهٔ سندِ جاری حل می‌کند. یعنی یک مقدارِ خراب، در
    | هر صفحه یک ۴۰۴ِ متفاوت می‌سازد — و همین باعث شد شبیهِ چند باگِ جدا به‌نظر
    | برسد.
    |
    | چرا `!empty()` جلویش را نمی‌گرفت: مقدارِ خراب **نال نیست**، رشتهٔ
    | چهارحرفیِ `"null"` است (از سریال‌سازیِ JS، ایمپورت، یا ستونی که یک بار
    | با `(string) null`ِ جاوااسکریپتی پر شده). و `!empty("null")` **درست**
    | است، پس از هر گیتِ موجود بی‌صدا رد می‌شود.
    |
    | ⚠️ عمداً نشانی را **اعتبارسنجی نمی‌کند** — کارش فقط تشخیصِ «مقدارِ غایب»
    | است. اعتبارسنجیِ واقعیِ URL کارِ `SafeUrl` است و جای دیگری انجام می‌شود.
    */
    /** @param  mixed  $value */
    function img_url($value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $v = trim($value);

        if ($v === '') {
            return null;
        }

        // متنِ «هیچ» به چند شکل از سریال‌سازی بیرون می‌آید؛ هیچ‌کدام آدرس نیست.
        return in_array(strtolower($v), ['null', 'undefined', 'nan', 'none', 'nil', 'false'], true)
            ? null
            : $v;
    }
}

if (! function_exists('blog_related_product')) {
    /**
     * محصولِ فروختنیِ متناظر با یک دستهٔ بلاگ — پلِ بلاگ→محصول (ممیزی ۳).
     *
     * ═══ چرا این تابع وجود دارد ═══
     *
     * سه ممیزیِ پیاپی یک عدد را تکرار کردند: ۱۰۷ پست، **صفر** لینک به محصول.
     * ویجتِ «مطالب مرتبط» پویا شد ولی هر لینکش از بلاگ شروع می‌شد و در بلاگ
     * تمام می‌شد — «جزیره جاده‌کشی شد؛ پل هنوز ندارد». این تابع پل است: قالبِ
     * تک‌پست با آن، بالای مطالب مرتبط یک بلاکِ «سرویس مرتبط» می‌سازد. چون در
     * **قالب** است نه در متنِ پست‌ها، هر ۱۰۷ پستِ موجود و هر پستِ آینده
     * خودبه‌خود می‌گیرندش — هیچ ویرایشِ دستی‌ای در کار نیست.
     *
     * ⚠️ عنوان/توضیح از configِ **خودِ محصول** خوانده می‌شود (نه کپی در
     * config/blog.php) تا با تغییرِ نامِ محصول، انکرِ لینک خودش به‌روز شود —
     * انکرِ توصیفی همان چیزی است که ممیزی صریح خواست.
     *
     * ⚠️ نگاشتِ ناقص یا محصولِ حذف‌شده ⇒ `null` و بلاک اصلاً رندر نمی‌شود.
     * لینکِ مرده بدتر از نبودِ لینک است (قاعدهٔ ثبت‌شدهٔ همین پروژه).
     *
     * @return array{href:string,title:string,desc:string}|null
     */
    function blog_related_product(?string $blogCategory): ?array
    {
        $map = $blogCategory !== null && $blogCategory !== ''
            ? (array) config('blog.category_products.'.$blogCategory)
            : [];

        /*
        | زنجیرهٔ fallback (ممیزی ۴): دستهٔ بی‌نگاشت/ناشناخته ⇒ hubِ خطِ
        | محصولِ پرچم‌دار. دورِ چهارم ۲۳ پست را شمرد که «مدلِ نگاشت برایشان
        | جوابی نداشت» و صفر لینک رندر می‌کردند — حالا هیچ پستی نمی‌تواند.
        */
        if ($map === []) {
            $map = (array) config('blog.category_products_fallback');
        }

        if ($map === []) {
            return null;
        }

        $kind = (string) ($map['kind'] ?? '');
        $slug = (string) ($map['slug'] ?? '');

        if ($kind === 'hosting') {
            $p = config('hosting.products.'.$slug);

            return is_array($p) ? [
                'href'  => lroute('hosting', $slug),
                'title' => (string) (lc($p)['t'] ?? $slug),
                'desc'  => (string) (lc($p)['hero_d'] ?? ''),
            ] : null;
        }

        if ($kind === 'catalog') {
            $cat = (string) ($map['category'] ?? '');
            $p = config("catalog.$cat.$slug");

            return is_array($p) ? [
                'href'  => lroute('catalog', ['category' => $cat, 'slug' => $slug]),
                'title' => (string) (lc($p)['t'] ?? $slug),
                'desc'  => (string) (lc($p)['hero_d'] ?? ''),
            ] : null;
        }

        if ($kind === 'solution') {
            $s = config('solutions.'.$slug);

            if (! is_array($s)) {
                return null;
            }

            $L = (array) lc($s);

            /*
            | راهکارها کلیدِ `t` ندارند؛ نامِ کوتاهشان بخشِ اولِ `meta_t` است
            | («خدمات سئو — رشد ارگانیک…» ⇒ «خدمات سئو»). اگر الگو نبود، کلِ
            | meta_t می‌آید — طولانی ولی درست، هرگز اسلاگِ خام.
            */
            $title = trim((string) ($L['t'] ?? ''));

            if ($title === '') {
                $meta = (string) ($L['meta_t'] ?? '');
                $title = trim(explode('—', $meta)[0]) ?: $slug;
            }

            return [
                'href'  => lroute('solution', $slug),
                'title' => $title,
                'desc'  => (string) ($L['lead'] ?? ($L['meta_d'] ?? '')),
            ];
        }

        return null;
    }
}

if (! function_exists('blog_guides')) {
    /**
     * ۳ پستِ تازهٔ یک دستهٔ بلاگ برای بلاکِ «راهنماها»ی صفحاتِ محصول —
     * پلِ محصول→بلاگ (ممیزی ۳). جهتِ معکوس بدونِ هیچ ویرایشِ دستی بسته می‌شود.
     *
     * ⚠️ اگر دسته پستِ کافی نداشت، تازه‌ترین‌های کلِ بلاگ پُر می‌کنند — همان
     * قاعدهٔ `BlogRepository::related()`: بلاکِ خالی از بلاکِ نه‌چندان‌مرتبط
     * بدتر است. و اگر بلاگ کلاً خالی بود (نصبِ تازه)، آرایهٔ خالی برمی‌گردد و
     * قالب بخش را اصلاً رندر نمی‌کند.
     *
     * @return array<int,array<string,mixed>>
     */
    function blog_guides(?string $blogCategory, int $n = 3, ?string $seed = null): array
    {
        try {
            $repo = app(\App\Services\BlogRepository::class);

            /*
            | پخشِ لینک (ممیزی ۶): «۳۶ پست یتیم» چون هر ۱۳۳ صفحهٔ محصول همان
            | ۳ پستِ تازهٔ هر دسته را لینک می‌دادند و بقیهٔ پست‌ها هیچ‌وقت نوبتشان
            | نمی‌شد. حالا نقطهٔ شروعِ فهرست از hashِ **همین صفحه** می‌آید —
            | قطعی (هر بار همان؛ کش و خزنده گیج نمی‌شوند) ولی بینِ صفحه‌ها
            | متفاوت، پس در مجموع همهٔ پست‌های دسته لینک می‌گیرند. (و انکرها
            | عنوانِ خودِ پست‌اند، نه متنِ ثابت — ریسکِ یکنواختیِ انکر هم نیست.)
            */
            $rotate = function (array $list) use ($seed): array {
                $c = count($list);

                if ($c <= 1 || $seed === null || $seed === '') {
                    return $list;
                }

                $off = crc32($seed) % $c;

                return array_merge(array_slice($list, $off), array_slice($list, 0, $off));
            };

            $pool = $blogCategory ? $rotate($repo->byCategory($blogCategory)) : [];
            $out = array_slice($pool, 0, $n);

            if (count($out) < $n) {
                $have = array_column($out, 'slug');

                foreach ($rotate($repo->index()) as $p) {
                    if (count($out) >= $n) {
                        break;
                    }

                    if (! in_array($p['slug'] ?? '', $have, true)) {
                        $out[] = $p;
                    }
                }
            }

            return $out;
        } catch (\Throwable) {
            // صفحهٔ محصول حق ندارد به‌خاطرِ بلاگ (جدولِ مهاجرت‌نخورده) ۵۰۰ شود
            return [];
        }
    }
}

if (! function_exists('whmcs_url')) {
    /** آدرس WHMCS متناسب با زبان جاری (fa → my.servernet.ir / en → my.servernet.cloud) */
    function whmcs_url(string $path = ''): string
    {
        $base = config('servernet.whmcs.'.app()->getLocale()) ?? config('servernet.whmcs.en');

        return rtrim($base, '/').($path !== '' ? '/'.ltrim($path, '/') : '');
    }
}

if (! function_exists('buy_url')) {
    function buy_url(int $pid): string
    {
        return whmcs_url('cart.php?a=add&pid='.$pid);
    }
}

if (! function_exists('cloud_buy_url')) {
    /**
     * لینکِ خریدِ سرورِ مجازی — **درونِ سایتِ خودمان**.
     *
     * ⚠️ چرا لازم شد: دکمه‌های «انتخاب» صفحاتِ بازاریابی به سبدِ خریدِ WHMCS
     * بیرونی می‌رفتند — همان سیستمی که داریم جایش را می‌گیریم. یعنی مشتری از
     * سایتِ ما بیرون پرت می‌شد به سیستمی که سرورِ مجازیِ تازه در آن وجود ندارد.
     *
     * حالا به سرورسازِ پنل می‌رود. پشتیبانِ «کاتالوگِ خالی» هم **دیگر WHMCS
     * نیست**: خزندهٔ ممیزیِ سوم نشان داد `my.servernet.cloud` اصلاً resolve
     * نمی‌شود و `cart.php` روی `my.servernet.ir` به هر درخواست ۵۰۰ می‌دهد —
     * یعنی همان پشتیبانی که قرار بود «دکمهٔ مرده» را نجات دهد، خودش مرده بود.
     * فروشگاهِ کنسول حتی با کاتالوگِ خالی یک صفحهٔ زنده با حالتِ خالیِ صریح
     * است؛ از دامنهٔ بی‌DNS بهتر.
     */
    function cloud_buy_url(?string $locationCode = null, ?string $planSlug = null): string
    {
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('cloud_plans')
                && \App\Models\CloudPlan::query()->sellable()->exists()) {
                $q = array_filter(['location' => $locationCode, 'plan' => $planSlug]);

                return lroute('account.cloud.store').($q !== [] ? '?'.http_build_query($q) : '');
            }
        } catch (\Throwable) {
            // پایین به پشتیبان می‌رویم
        }

        return lroute('account.cloud.store');
    }
}

if (! function_exists('schema_ld')) {
    /**
     * ساخت JSON-LD معتبر برای schema.org.
     *
     * چرا این هلپر لازم است: اگر کلید '@context' مستقیماً داخل فایل Blade نوشته شود،
     * کامپایلر Blade آن را به‌عنوان دایرکتیو @context تفسیر و حذف می‌کند و خروجی بدون
     * @context تولید می‌شود — یعنی داده‌ی ساختاریافته برای گوگل بی‌اعتبار است.
     * چون این تابع در فایل PHP تعریف شده، Blade هرگز این رشته را نمی‌بیند.
     */
    function schema_ld(array $data, string $type): string
    {
        $payload = array_merge(
            ['@'.'context' => 'https://schema.org', '@'.'type' => $type],
            array_filter($data, fn ($v) => $v !== null && $v !== '' && $v !== [])
        );

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

if (! function_exists('schema_offer_extras')) {
    /**
     * فیلدهای مشترکی که Search Console در گزارشِ «Merchant listings» برای هر
     * Offer بدونشان هشدار می‌دهد: validFrom، hasMerchantReturnPolicy و
     * shippingDetails (ممیزی ۲۴ اوت ۲۰۲۶ — ۶۷ آیتم، سه هشدار در هر آیتم).
     *
     * سرویس‌ها دیجیتال‌اند و کالایی پست نمی‌شود؛ ارسال = صفر-هزینه/آنی، و
     * ضمانتِ بازگشتِ وجه همان وعدهٔ ۱۴ روزه‌ای است که FAQ و صفحهٔ terms به
     * مشتری می‌دهند — این‌جا فقط نشانه‌گذاری می‌شود، نه وعدهٔ تازه.
     */
    function schema_offer_extras(string $currency): array
    {
        return [
            // اولِ ماه، نه now(): اسکیما نباید هر روز عوض شود (pagecache و
            // خزشِ مجدد بی‌دلیل).
            'validFrom' => now()->startOfMonth()->toDateString(),
            'hasMerchantReturnPolicy' => [
                '@type' => 'MerchantReturnPolicy',
                'applicableCountry' => 'IR',
                'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
                'merchantReturnDays' => 14,
                'returnFees' => 'https://schema.org/FreeReturn',
            ],
            'shippingDetails' => [
                '@type' => 'OfferShippingDetails',
                'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => 0, 'currency' => $currency],
                'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => 'IR'],
                'deliveryTime' => [
                    '@type' => 'ShippingDeliveryTime',
                    'handlingTime' => ['@type' => 'QuantitativeValue', 'minValue' => 0, 'maxValue' => 0, 'unitCode' => 'DAY'],
                    'transitTime' => ['@type' => 'QuantitativeValue', 'minValue' => 0, 'maxValue' => 0, 'unitCode' => 'DAY'],
                ],
            ],
        ];
    }
}

if (! function_exists('word_count_fa')) {
    /** شمارش کلمه که با فارسی و ترکی هم کار می‌کند (str_word_count فقط لاتین را می‌شمارد) */
    function word_count_fa(string $text): int
    {
        return (int) preg_match_all('/[\p{L}\p{N}]+/u', strip_tags($text));
    }
}

if (! function_exists('price_factor')) {
    /**
     * ضریبِ قیمت‌گذاری — قیمتِ پایهٔ تومانی را با نرخِ زندهٔ یورو مقیاس می‌کند.
     *
     * قیمت‌های پایه (تومان) لنگرند؛ وقتی مدیر «نرخِ مبنا» را تنظیم کند
     * (pricing_baseline_rate = نرخِ یورویی که این قیمت‌ها با آن درست‌اند)، همهٔ
     * قیمت‌ها خودکار با نرخِ روزِ یورو بالا/پایین می‌روند. تا وقتی مبنا تنظیم
     * نشده، ضریب = ۱ است و هیچ قیمتی عوض نمی‌شود (پیش‌فرضِ امن). محاسبه یک‌بار
     * در هر درخواست کش می‌شود.
     */
    function price_factor(): float
    {
        static $f = null;
        if ($f !== null) {
            return $f;
        }

        $baseline = (int) \App\Models\Setting::get('pricing_baseline_rate', '0');
        if ($baseline <= 0) {
            return $f = 1.0;
        }

        $override = (int) \App\Models\Setting::get('pricing_rate_override', '0');
        $rate = $override > 0
            ? $override
            : (app(\App\Services\ExchangeRate::class)->toToman('EUR') ?: $baseline);

        $margin = (float) \App\Models\Setting::get('price_margin_pct', '0');

        return $f = ($rate / $baseline) * (1 + $margin / 100);
    }
}

if (! function_exists('price_toman')) {
    /** قیمتِ پایهٔ تومان → قیمتِ نهاییِ گردشده (به نزدیک‌ترین ۱۰٬۰۰۰ تومان) */
    function price_toman(int|float $baseToman): int
    {
        $v = (int) round($baseToman * price_factor(), -4);

        return $v > 0 ? $v : (int) $baseToman;   // اگر گرد شدن صفر داد، خودِ پایه
    }
}

if (! function_exists('cloud_price')) {
    /**
     * قیمتِ سرورِ ابری در ارزِ درستِ زبانِ جاری.
     *
     * ورودی همیشه **تومانِ نهایی** است (خروجیِ `CloudStore::priceForCycle`، که
     * از `cloud_plans.price_irt` می‌آید و حاشیهٔ سود رویش خورده). پس این‌جا
     * دیگر `price_factor` نمی‌خورد — فقط نمایش:
     *   · فارسی → همان تومان.
     *   · انگلیسی/ترکی → یورو، با نرخِ زندهٔ همان کلاسی که قیمت‌ها را ساخته
     *     (`CloudPricing::eurToToman`)، تا عددِ نمایش با بهایِ واقعی هم‌خوان بماند.
     *
     * ⚠️ درگاهِ زندهٔ یورو هنوز نیست؛ این فقط **نمایش** است. مشتریِ en/tr مبلغ را
     * به یورو می‌بیند ولی روش‌های پرداختِ یورو/کریپتو «به‌زودی»‌اند (بی‌شارژِ
     * تومانِ ناخواسته). فارسی مثلِ همیشه تومان می‌پردازد.
     */
    function cloud_price(int|float $toman): string
    {
        $t = (int) round($toman);

        if (app()->getLocale() === 'fa') {
            return fa_num(number_format($t)).' تومان';
        }

        $rate = cloud_eur_rate();

        if ($rate > 0) {
            return '€'.number_format($t / $rate, 2);
        }

        return number_format($t);   // نرخ نبود: عددِ خام، بی‌واحدِ گمراه‌کننده
    }
}

if (! function_exists('cloud_hourly_price')) {
    /**
     * نرخِ **ساعتی** به ارزِ زبانِ جاری — با دقتِ زیرِ سنت.
     *
     * `cloud_price` یورو را به ۲ رقمِ اعشار گرد می‌کند؛ برای نرخِ ساعتیِ
     * زیرِ ده سنت این یعنی پنهان‌شدنِ عددِ واقعی: sn-svc-72 با €0.0106/h
     * در پنل «€0.01 /hr» دیده می‌شد و کارفرما به‌حق گفت «غیرمنطقی است».
     * زیرِ €0.10 چهار رقم، بالاترش دو رقم؛ صفرهای انتهایی حذف می‌شوند.
     */
    function cloud_hourly_price(int|float $toman): string
    {
        $t = (int) round($toman);

        if (app()->getLocale() === 'fa') {
            return fa_num(number_format($t)).' تومان';
        }

        $rate = cloud_eur_rate();

        if ($rate > 0) {
            $eur = $t / $rate;
            $s = number_format($eur, $eur < 0.1 ? 4 : 2, '.', '');

            return '€'.(str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s);
        }

        return number_format($t);
    }
}

if (! function_exists('invoice_money')) {
    /**
     * مبلغِ فاکتور/سرویس در ارزِ **خودِ فاکتور**، با نمایشِ زبان‌محور.
     *
     * · فاکتورِ یورو (EUR) → همیشه «€X» (واحدِ فرعی سنت است، پس ÷۱۰۰).
     * · فاکتورِ تومانی (IRT) → فارسی «تومان»؛ en/tr «€» با نرخِ زنده
     *   (`cloud_price`). ⚠️ این تبدیل فقط **نمایشی** است: شارژِ واقعیِ فاکتورِ
     *   IRT تومان است. برای همین در مسیرِ پرداختِ en/tr فقط روش‌های «به‌زودی»
     *   (یورو/کریپتو) نشان داده می‌شوند تا کسی یوروببیند و تومان شارژ نشود.
     */
    function invoice_money(int|float $amount, string $currency = 'IRT'): string
    {
        if (strtoupper(trim($currency)) === 'EUR') {
            return '€'.number_format($amount / 100, 2);
        }

        return cloud_price($amount);
    }
}

if (! function_exists('cloud_eur_rate')) {
    /** نرخِ یورو→تومانِ همان کلاسی که قیمتِ ابری را می‌سازد (۰ اگر نبود). */
    function cloud_eur_rate(): int
    {
        try {
            return app(\App\Services\Cloud\CloudPricing::class)->eurToToman();
        } catch (\Throwable) {
            return 0;
        }
    }
}

if (! function_exists('part_price')) {
    /**
     * قیمتِ یک قطعهٔ سرور — **از یورو**، به زبانِ کاربر.
     *
     * 🔴 چرا مبنا یورو است و نه تومان:
     *
     * قطعهٔ سرور از بازارِ جهانی خریده می‌شود و قیمتِ واقعی‌اش یورویی است.
     * ذخیرهٔ عددِ تومانی یعنی با هر جهشِ ارز، کلِ کاتالوگ باید دستی به‌روز
     * شود — و در عمل نمی‌شود، پس فروشگاه زیرِ قیمتِ خرید می‌فروشد بی‌آنکه
     * کسی بفهمد. با مبنای یورو، یک نرخ عوض می‌شود و همه‌چیز درست می‌مانَد.
     *
     * فارسی تومان می‌بیند (با نرخِ زندهٔ همان تنظیماتی که سرورِ ابری استفاده
     * می‌کند)، انگلیسی و ترکی یورو.
     *
     * ⚠️ نرخ که نبود، `null` برمی‌گردد نه عددِ خام. قیمتِ بی‌نرخ یعنی
     * فروشِ احتمالی زیرِ قیمتِ خرید؛ «استعلام کنید» صادقانه‌تر است. همان
     * تصمیمی که `site_price()` هم می‌گیرد.
     *
     * @param  int|null  $eurCents  قیمت به **سنتِ یورو**
     */
    function part_price(?int $eurCents): ?string
    {
        if ($eurCents === null || $eurCents <= 0) {
            return null;
        }

        $eur = $eurCents / 100;

        if (app()->getLocale() !== 'fa') {
            return '€'.number_format($eur, 2);
        }

        $rate = cloud_eur_rate();

        if ($rate <= 0) {
            return null;
        }

        /*
        | ⚠️ گردکردن به ۱۰٬۰۰۰ تومان عمدی است.
        |
        | نرخِ ارز چند بار در روز تکان می‌خورد و عددِ دقیق یعنی قیمتِ صفحه هر
        | ساعت چند تومان جابه‌جا شود — که هم بدقواره است هم بی‌اعتمادکننده.
        | گردکردن قیمت را پایدار نگه می‌دارد بی‌آنکه معنایش عوض شود.
        */
        $toman = (int) round($eur * $rate, -4);

        return fa_num(number_format($toman)).' تومان';
    }
}

if (! function_exists('public_asset_path')) {
    /**
     * مسیرِ **واقعیِ** یک فایلِ استاتیک روی دیسک — یا `null` اگر نبود.
     *
     * 🔴 چرا این‌جا و نه یک `is_file(public_path(...))`ِ ساده:
     * روی پروداکشن اپ بیرونِ webroot است (`servernet_app/`) و `public_html/`
     * نقشِ `public/` را دارد، پس `public_path()` به پوشه‌ای اشاره می‌کند که
     * **اصلاً وجود ندارد** و `is_file()` همیشه false می‌دهد. یک بار همین باعث
     * شد مهرِ نسخهٔ همهٔ CSS/JSها ثابت بماند و هر تغییرِ ظاهری روی سایتِ زنده
     * نامرئی شود. `DOCUMENT_ROOT` جوابِ درست و قابل‌حمل را می‌دهد: محلی همان
     * `public/` است و روی cPanel همان `public_html/`.
     *
     * ⚠️ این قاعده دقیقاً یک جا زندگی می‌کند. هر جای دیگری که بخواهد بپرسد
     * «آیا این فایلِ استاتیک هست؟» باید از همین بپرسد، وگرنه روی پروداکشن
     * بی‌صدا «نیست» می‌شنود — همان باگ با لباسِ تازه.
     */
    function public_asset_path(string $rel): ?string
    {
        $rel = ltrim($rel, '/');

        /*
         * 🔴 ترتیب مهم است و یک بار برعکس بود.
         *
         * `DOCUMENT_ROOT` **اول** می‌آید چون همان پوشه‌ای است که وب‌سرور واقعاً
         * سرو می‌کند. `public_path()` روی پروداکشن `servernet_app/public` است —
         * جایی که یک **نسخهٔ دومِ کهنه** از دارایی‌ها می‌تواند بماند (از یک
         * چک‌اوت یا آپلودِ انبوهِ قدیمی).
         *
         * وقتی آن نسخه اول امتحان می‌شد، `asset_ver()` مهرِ نسخه را از فایلی
         * می‌ساخت که **هیچ‌کس دانلودش نمی‌کند**. نشانه‌اش روی سایت زنده دیده شد:
         * `site.css` و `tools.js` هر دو `?v=1786410974` می‌گرفتند در حالی که
         * فایل‌های واقعی در `public_html/` تازه آپلود شده بودند و mtimeشان
         * ۱۷۸۶۷۲۷۵۴۳ و ۱۷۸۶۷۲۷۵۴۴ بود. دو فایلِ متفاوت با یک مهرِ یکسان، امضای
         * همان نسخهٔ دوم است که یک‌جا نوشته شده بود.
         *
         * پیامدش: تغییرِ CSS آپلود می‌شد، مهر عوض **نمی‌شد**، و مرورگرِ
         * بازدیدکنندهٔ قبلی نسخهٔ کهنه را نگه می‌داشت — همان «کدِ ۲۰۰ یعنی هیچ».
         *
         * ⚠️ قاعدهٔ کلی: مهرِ نسخه باید **فایلی را توصیف کند که بازدیدکننده
         * می‌گیرد**، نه فایلی که تصادفاً هم‌نام است.
         */
        $candidates = [];

        $docroot = rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
        if ($docroot !== '') {
            $candidates[] = $docroot.'/'.$rel;
        }

        // محلی (و در تست، که DOCUMENT_ROOT ندارد) هنوز همین جواب می‌دهد
        $candidates[] = public_path($rel);

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}

if (! function_exists('flag_codes')) {
    /**
     * کدِ کشورِ همهٔ پرچم‌هایی که واقعاً روی دیسک داریم.
     *
     * 🔴 چرا فهرست و نه یک `flag_url($cc)`ِ ساده: مصرف‌کننده جاوااسکریپت است و
     * مرورگر نمی‌تواند پوشه را بخواند. بی‌این فهرست، کارتِ نتیجه یا باید
     * `<img>`ِ ۴۰۴ بزند و بعد جبران کند، یا هر کشوری را اموجی نشان دهد — و
     * اموجیِ پرچم روی ویندوز دو حرف می‌شود، یعنی همان باگی که SVG برای رفعش
     * آمده بود. (پرسشِ «این یک فایل هست یا نه» جای دیگری زندگی می‌کند:
     * `CloudLocation::flagSvgFor()`؛ این‌جا فقط از همان پوشه فهرست می‌گیریم.)
     *
     * ⚠️ همان قاعدهٔ `public_asset_path()`: روی پروداکشن `public_path()` به
     * پوشه‌ای اشاره می‌کند که وجود ندارد، پس `DOCUMENT_ROOT` نامزدِ دوم است.
     */
    function flag_codes(): array
    {
        static $codes = null;
        if ($codes !== null) {
            return $codes;
        }

        $rel = \App\Models\CloudLocation::FLAG_DIR;
        $candidates = [public_path($rel)];
        $docroot = rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
        if ($docroot !== '') {
            $candidates[] = $docroot.'/'.$rel;
        }

        foreach ($candidates as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $found = [];
            foreach ((array) glob($dir.'/*.svg') as $file) {
                $cc = strtolower(basename((string) $file, '.svg'));
                if (preg_match('/^[a-z]{2}$/', $cc)) {
                    $found[] = $cc;
                }
            }
            if ($found !== []) {
                sort($found);

                return $codes = $found;
            }
        }

        return $codes = [];
    }
}

if (! function_exists('asset_ver')) {
    /**
     * آدرسِ فایلِ استاتیک با مهرِ نسخه — **امن در برابرِ فایلِ نبود**.
     *
     * ⚠️ چرا لازم است: قبلاً ویوها مستقیم `filemtime(public_path(...))` می‌زدند.
     * اگر فایلی روی سرور نبود (دپلوی فایل‌به‌فایل است و یک فایل جا افتاده بود، یا
     * مسیرِ public روی cPanel فرق داشت)، PHP اخطار می‌داد، لاراول اخطار را به
     * ErrorException تبدیل می‌کرد و **کلِ صفحه ۵۰۰** می‌شد — فقط به‌خاطرِ یک
     * لینکِ CSS. حالا اگر فایل نبود، نسخه از هشِ نام ساخته می‌شود و صفحه سالم
     * می‌ماند (فایلِ نبود در مرورگر ۴۰۴ می‌دهد، ولی صفحه بالا می‌آید).
     */
    function asset_ver(string $rel): string
    {
        $rel = ltrim($rel, '/');

        /*
         * 🔴 چرا مسیر از `public_asset_path()` می‌آید و نه از `public_path()`:
         * روی پروداکشن آن پوشه وجود ندارد و `is_file()` همیشه false می‌داد، پس
         * نسخه همیشه همان هشِ **ثابتِ** `md5($rel)` می‌شد — یعنی مرورگر و
         * Cloudflare هر CSS/JS را برای همیشه کش می‌کردند و هر تغییرِ ظاهری روی
         * سایتِ زنده بی‌اثر می‌مانْد. توضیحِ کاملِ قاعده بالای همان تابع است.
         */
        $abs = public_asset_path($rel);
        $stamp = $abs !== null ? @filemtime($abs) : false;

        // فایلِ نبود هنوز صفحه را ۵۰۰ نمی‌کند — همان قاعدهٔ بالا، دست‌نخورده.
        return asset($rel).'?v='.($stamp !== false ? $stamp : substr(md5($rel), 0, 8));
    }
}

if (! function_exists('ua_parse')) {
    /**
     * تجزیهٔ user-agent به مرورگر + سیستم‌عامل + نوعِ دستگاه — سبک و بدون وابستگی.
     *
     * برای نمایشِ لاگِ ورودِ کاربر («با چه مرورگر/سیستمی وارد شدی»). دقتِ کامل
     * هدف نیست؛ پوششِ حالت‌های رایج کافی است. خروجی:
     *   ['browser'=>string, 'os'=>string, 'device'=>'mobile'|'tablet'|'desktop'|'bot',
     *    'icon'=>string, 'label'=>string]
     */
    function ua_parse(?string $ua): array
    {
        $ua = trim((string) $ua);
        if ($ua === '') {
            return ['browser' => '', 'os' => '', 'device' => 'desktop', 'icon' => 'i-monitor', 'label' => '—'];
        }

        // مرورگر — ترتیب مهم است (Edge خودش را Chrome هم معرفی می‌کند)
        $browser = '';
        $map = [
            'Edg'            => 'Edge',
            'OPR'            => 'Opera',
            'Opera'          => 'Opera',
            'SamsungBrowser' => 'Samsung Internet',
            'YaBrowser'      => 'Yandex',
            'Vivaldi'        => 'Vivaldi',
            'Brave'          => 'Brave',
            'CriOS'          => 'Chrome',
            'FxiOS'          => 'Firefox',
            'Firefox'        => 'Firefox',
            'Chrome'         => 'Chrome',
            'Safari'         => 'Safari',
            'MSIE'           => 'Internet Explorer',
            'Trident'        => 'Internet Explorer',
        ];
        foreach ($map as $needle => $name) {
            if (stripos($ua, $needle) !== false) {
                $browser = $name;
                break;
            }
        }

        // سیستم‌عامل
        $os = '';
        if (preg_match('/Windows NT/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            $os = 'iOS';
        } elseif (preg_match('/Android/i', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/Mac OS X|Macintosh/i', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/Ubuntu/i', $ua)) {
            $os = 'Ubuntu';
        } elseif (preg_match('/Linux/i', $ua)) {
            $os = 'Linux';
        } elseif (preg_match('/CrOS/i', $ua)) {
            $os = 'ChromeOS';
        }

        // نوع دستگاه + آیکن
        $device = 'desktop';
        if (preg_match('/bot|crawl|spider|slurp|curl|wget|python-requests|headless/i', $ua)) {
            $device = 'bot';
        } elseif (preg_match('/iPad|Tablet/i', $ua) || (preg_match('/Android/i', $ua) && ! preg_match('/Mobile/i', $ua))) {
            $device = 'tablet';
        } elseif (preg_match('/Mobile|iPhone|iPod|Android.*Mobile/i', $ua)) {
            $device = 'mobile';
        }
        $icon = match ($device) {
            'mobile' => 'i-smartphone',
            'bot'    => 'i-bot',
            default  => 'i-monitor',   // desktop + tablet
        };

        // برچسبِ خوانا
        $parts = array_filter([$browser, $os]);
        $label = $parts ? implode(' · ', $parts) : '—';

        return compact('browser', 'os', 'device', 'icon', 'label');
    }
}

if (! function_exists('schema_price_irr')) {
    /**
     * 🔴 قیمت برای `schema.org/Offer` — **همیشه** ریال، همیشه از این‌جا.
     *
     * ═══ باگی که این تابع را لازم کرد ═══
     *
     * `schema.org` کدِ ISO برای «تومان» ندارد، پس ناچاریم `IRR` (ریال) اعلام
     * کنیم. ولی سایت **دو قرارداد متناقض** داشت:
     *
     *   CloudCatalogController  →  price_n * 10   ✅ ریالِ واقعی
     *   hosting.blade.php       →  عددِ تومان      ❌ ده برابر کمتر
     *   server-detail.blade.php →  عددِ تومان      ❌ + بدونِ priceValidUntil
     *
     * و `hosting.blade.php` ویوِ **مشترکِ** کاتالوگ است، یعنی این اشتباه روی
     * کلِ `/hosting/*`، `/vps/*`، `/dedicated/*`، `/services/*` نشسته بود —
     * پردرآمدترین صفحات سایت.
     *
     * ⚠️ چرا «ظاهراً بی‌ضرر» نبود: `/llms.txt` به مدل‌های زبانی می‌گفت «قیمتِ
     * ایرانی به تومان است». مدلی که این را اطاعت کند، عددِ **درستِ** صفحاتِ
     * ابری (۸٬۵۰۰٬۰۰۰ ریال) را ۸٬۵۰۰٬۰۰۰ **تومان** نقل می‌کند — ده برابر
     * گران‌تر، دقیقاً در لحظه‌ای که خریدار دارد ما را با رقبا مقایسه می‌کند.
     * و این کانالِ ازدست‌رفته در هیچ آنالیتیکسی دیده نمی‌شود: نه بازدیدی ثبت
     * می‌شود نه سبدِ رهاشده‌ای. مشتری اصلاً به سایت نمی‌رسد.
     *
     * ⚠️ هر جای تازه‌ای که `Offer` می‌سازی، **این** را صدا بزن. عددِ خام
     * ننویس؛ همان کاری بود که سه ویو کردند و سه‌تایی با هم نخواندند.
     */
    function schema_price_irr(int $toman): string
    {
        return (string) ($toman * 10);
    }
}

if (! function_exists('site_social')) {
    /**
     * شبکه‌های اجتماعی برای زبانِ جاری.
     *
     * 🔴 سه صفحهٔ اینستاگرام داریم و هر کدام به زبانِ خودش می‌نویسد. تا امروز
     * هر سه نسخهٔ سایت به صفحهٔ فارسی لینک می‌دادند، یعنی بازدیدکنندهٔ ترک یا
     * انگلیسی روی صفحه‌ای می‌افتاد که یک کلمه‌اش را نمی‌فهمد. آن از نداشتنِ
     * لینک بدتر است: کلیک کرده و برنمی‌گردد.
     *
     * ⚠️ همان الگوی `site_contact()`: نبودِ نسخهٔ زبانی به فارسی برمی‌گردد، تا
     * جای خالی هرگز لینکِ شکسته نسازد.
     *
     * @return array<string,string>
     */
    function site_social(?string $locale = null): array
    {
        $s = (array) config('servernet.social', []);
        $loc = $locale ?? app()->getLocale();

        $out = [
            'linkedin'  => (string) ($s['linkedin'] ?? ''),
            'instagram' => (string) ($s['instagram'] ?? ''),
        ];

        if ($loc !== 'fa' && filled($s['instagram_'.$loc] ?? null)) {
            $out['instagram'] = (string) $s['instagram_'.$loc];
        }

        return array_filter($out, fn ($v) => $v !== '');
    }
}

if (! function_exists('social_profiles')) {
    /**
     * **همهٔ** پروفایل‌های رسمی — برای `sameAs`ِ دادهٔ ساختاریافته.
     *
     * 🔴 عمداً با `site_social()` فرق دارد. آن‌جا سؤال «کاربر کجا برود؟» است و
     * پاسخ یکی است؛ این‌جا سؤال «این سازمان کدام حساب‌ها را دارد؟» است و پاسخ
     * همهٔ آن‌هاست. فهرستِ کاملِ `sameAs` همان چیزی است که به گوگل می‌فهماند این
     * سه حساب یک شرکت‌اند، نه سه شرکت.
     *
     * @return array<int,string>
     */
    function social_profiles(): array
    {
        return array_values(array_unique(array_filter(
            array_map('strval', (array) config('servernet.social', [])),
            fn ($v) => $v !== ''
        )));
    }
}
if (! function_exists('company_value')) {
    /**
     * یک فیلدِ هویتِ شرکت — **تنها منبعِ خواندن**.
     *
     * 🔴 اول پنلِ مدیریت، بعد `.env`. دلیلش همان دلیلِ نمادِ اعتماد است:
     * شمارهٔ ثبت و شناسهٔ ملی و نشانی کارِ **اداری**اند نه دیپلوی، و کسی که
     * آن‌ها را از روزنامهٔ رسمی برمی‌دارد لزوماً به `.env` سرور دسترسی ندارد.
     *
     * ⚠️ `.env` عمداً به‌عنوانِ راهِ دوم می‌مانَد تا روی نصبی که جدولِ
     * `settings` ندارد هم کار کند.
     *
     * 🔴 و چرا **همه‌جا** باید از همین بخوانند، از جمله JSON-LD:
     *
     * پیش از این، دادهٔ ساختاریافتهٔ `site.blade.php` مستقیم `config('company.…')`
     * را می‌خواند. اگر آن را جا می‌گذاشتم، مدیر مقدارها را در پنل وارد می‌کرد،
     * روی صفحهٔ تماس می‌دیدشان، و **در schema هیچ‌کدام نمی‌آمد** — یعنی دقیقاً
     * همان‌جایی که گوگل و مدل‌های زبانی نگاه می‌کنند خالی می‌مانْد و هیچ خطایی
     * هم نمی‌داد.
     */
    function company_value(string $field): string
    {
        /*
        | نگاشتِ نامِ منطقی ⇒ کلیدِ پنل. جدا نگه‌داشتنش عمدی است: نامِ فیلد در
        | `config/company.php` تودرتوست (`address.street`) و کلیدِ جدولِ
        | `settings` نمی‌تواند نقطه داشته باشد.
        */
        static $map = [
            'legal_name'       => 'company_legal_name',
            'registration_no'  => 'company_reg_no',
            'national_id'      => 'company_national_id',
            'economic_code'    => 'company_economic_code',
            'address.street'   => 'company_address',
            'address.city'     => 'company_city',
            'address.province' => 'company_province',
            'address.postcode' => 'company_postcode',
        ];

        if (isset($map[$field])) {
            try {
                $fromPanel = trim((string) \App\Models\Setting::get($map[$field], ''));

                if ($fromPanel !== '') {
                    return $fromPanel;
                }
            } catch (\Throwable) {
                /*
                | 🔴 بلعِ عمدی، و توضیحش **داخلِ** بدنه است نه بالای `try`.
                |
                | این تابع در هر صفحهٔ سایت صدا زده می‌شود؛ یک قطعیِ گذرای
                | دیتابیس نباید کلِ سایت را ۵۰۰ کند. شکست بی‌ردّ نمی‌مانَد:
                | مقدار به `.env` برمی‌گردد و اگر آن هم خالی باشد بخش اصلاً
                | رندر نمی‌شود — یعنی هیچ‌وقت مقدارِ نادرست چاپ نمی‌شود.
                |
                | ⚠️ جای این توضیح تصادفی نیست. `SwallowedFailuresRatchetTest`
                | بدنهٔ خالی را می‌شمارد، و نسخهٔ اولِ همین کد که توضیح را بالای
                | `try` گذاشته بود، نگهبانِ خودم را قرمز کرد. بردنِ توضیح به
                | داخل هم شمارش را درست می‌کند و هم دلیل را جایی می‌گذارد که
                | خواننده واقعاً می‌بیندش.
                */
            }
        }

        return trim((string) config('company.'.$field, ''));
    }
}

if (! function_exists('company_identity')) {
    /**
     * هویتِ حقوقیِ شرکت — فقط چیزهایی که **واقعاً** پر شده‌اند.
     *
     * 🔴 چرا فیلترِ خالی این‌جاست و نه در هر ویو:
     *
     * ممیزی گلوگاه را «لایهٔ اعتماد» خواند، ولی نشانهٔ اعتماد نوشتنی نیست — یا
     * شمارهٔ ثبتِ واقعی هست یا نیست. خطرِ واقعی این است که کسی برای «کامل
     * دیده‌شدنِ» فوتر یک جای‌نگهدار بگذارد: «شماره ثبت: —» یا نمادی که به
     * صفحهٔ نامعتبر می‌رود. خریدارِ ایرانی نمادِ اعتماد را **کلیک می‌کند**؛
     * نمادِ بی‌اعتبار همان لحظه کلِ سایت را مشکوک می‌کند.
     *
     * پس یک در ورودی بیشتر نیست و همان‌جا خالی‌ها حذف می‌شوند. ویو فقط روی
     * چیزی حلقه می‌زند که برگشته.
     *
     * @return array<int,array{label:string,value:string}>  فقط پرشده‌ها
     */
    function company_identity(): array
    {
        /*
         * ⚠️ کلیدِ ترجمه هم از همین‌جا می‌آید، نه از ternayِ تودرتو در Blade.
         * نسخهٔ اولِ همین کار آن را در ویو حساب می‌کرد و یک نگاشتِ سه‌شرطیِ
         * ناخوانا ساخت که با افزودنِ فیلدِ چهارم بی‌صدا غلط می‌شد.
         */
        $map = [
            'legal_name'      => 'ui.trust_legal_name',
            'registration_no' => 'ui.trust_reg_no',
            'national_id'     => 'ui.trust_national',
            'economic_code'   => 'ui.trust_economic',
        ];

        $out = [];

        foreach ($map as $key => $label) {
            $v = company_value($key);
            if ($v !== '') {
                $out[] = ['label' => $label, 'value' => $v];
            }
        }

        return $out;
    }
}

if (! function_exists('company_address')) {
    /**
     * نشانیِ ثبت‌شده به‌صورتِ یک رشته — یا `null` اگر خیابان و شهر نباشد.
     *
     * ⚠️ کشور به‌تنهایی «نشانی» نیست. پیش‌فرضِ `IR` همیشه پر است، پس اگر
     * شرطِ خالی‌بودن را روی کلِ آرایه بگذاریم، فوتر برای همیشه «ایران» را
     * به‌عنوانِ نشانی نشان می‌دهد — که بدتر از نداشتنِ نشانی است.
     */
    function company_address(): ?string
    {
        $street = company_value('address.street');
        $city = company_value('address.city');

        // خیابان و شهر هر دو لازم‌اند تا «نشانی» معنا بدهد
        if ($street === '' || $city === '') {
            return null;
        }

        $parts = array_filter([
            $street,
            $city,
            company_value('address.province'),
            company_value('address.postcode'),
        ], fn ($v) => $v !== '');

        return implode('، ', $parts);
    }
}

if (! function_exists('trust_seals')) {
    /**
     * مهرهای اعتمادِ **فعال** — نماد و ساماندهی.
     *
     * ⚠️ فقط نسخهٔ تصویری ساخته می‌شود. CSP این پروژه `script-src 'self'` و
     * `frame-src 'self' …` دارد، پس کدِ اسکریپتی/آی‌فریمیِ این دو مرجع
     * **بی‌هیچ خطایی** رندر نمی‌شود — همان تلهٔ ثبت‌شده در CLAUDE.md.
     * `img-src` اما هر https را می‌پذیرد، پس `<a><img>` کار می‌کند.
     *
     * ⚠️ هر دو مقدار (`id` و `code`) لازم است. با یکی، آدرسِ ساخته‌شده به
     * صفحهٔ نامعتبر می‌رود — بدترین حالتِ ممکن برای یک مهرِ اعتماد.
     *
     * @return array<int,array{key:string,href:string,src:string,alt:string}>
     */
    function trust_seals(): array
    {
        $out = [];

        /*
         * ⚠️ اول پنلِ مدیریت، بعد `.env`.
         *
         * گرفتنِ نمادِ اعتماد یک کارِ **اداری** است نه دیپلوی — کسی که کدش را
         * می‌گیرد لزوماً به `.env` سرور دسترسی ندارد. پس مدیر آن را در
         * `/admin/settings` کنارِ مهرِ شرکت وارد می‌کند. `.env` راهِ دوم
         * می‌مانَد تا روی نصبی که جدولِ `settings` ندارد هم کار کند.
         *
         * `Setting::get()` روی نصبِ بی‌جدول آرایهٔ خالی می‌دهد نه استثنا، ولی
         * `catch` هست چون این تابع در **فوترِ هر صفحه** صدا زده می‌شود: یک
         * قطعیِ گذرای دیتابیس نباید کلِ سایت را ۵۰۰ کند.
         */
        $fromPanel = function (string $key): string {
            try {
                return trim((string) \App\Models\Setting::get($key, ''));
            } catch (\Throwable) {
                return '';
            }
        };

        $enamadId = $fromPanel('enamad_id') ?: trim((string) config('company.enamad.id', ''));
        $enamadCode = $fromPanel('enamad_code') ?: trim((string) config('company.enamad.code', ''));

        if ($enamadId !== '' && $enamadCode !== '') {
            $out[] = [
                'key'  => 'enamad',
                'href' => 'https://trustseal.enamad.ir/?id='.rawurlencode($enamadId).'&Code='.rawurlencode($enamadCode),
                'src'  => 'https://trustseal.enamad.ir/logo.aspx?id='.rawurlencode($enamadId).'&Code='.rawurlencode($enamadCode),
                'alt'  => __('ui.trust_enamad'),
            ];
        }

        $samId = $fromPanel('samandehi_id') ?: trim((string) config('company.samandehi.id', ''));
        $samCode = $fromPanel('samandehi_code') ?: trim((string) config('company.samandehi.code', ''));

        if ($samId !== '' && $samCode !== '') {
            $out[] = [
                'key'  => 'samandehi',
                'href' => 'https://logo.samandehi.ir/Verify.aspx?id='.rawurlencode($samId).'&p='.rawurlencode($samCode),
                'src'  => 'https://logo.samandehi.ir/logo.aspx?id='.rawurlencode($samId).'&p='.rawurlencode($samCode),
                'alt'  => __('ui.trust_samandehi'),
            ];
        }

        return $out;
    }
}

if (! function_exists('article_faq_ld')) {
    /**
     * استخراجِ بلوکِ «پرسش‌های پرتکرار» از متنِ مقاله و ساختِ FAQPage JSON-LD.
     *
     * ═══ چرا از خودِ متن و نه یک ستونِ جدا ═══
     *
     * پرسش‌ها را نویسنده در همان بدنه می‌نویسد. اگر برای schema یک ستونِ جدا
     * بسازیم، دو نسخه از یک محتوا داریم و روزی که یکی ویرایش شود، گوگل
     * پاسخی را نشان می‌دهد که دیگر در صفحه نیست — و آن نقضِ صریحِ قواعدِ
     * structured data است (جریمه‌اش حذفِ کلِ rich resultِ دامنه است، نه یک صفحه).
     *
     * پس تنها منبع، خودِ HTML است. اگر بخشِ پرسش نبود، خروجی رشتهٔ خالی است و
     * قالب هیچ تگی چاپ نمی‌کند — schemaِ خالی بدتر از نبودنش است.
     *
     * ⚠️ هر سه زبان الگوی عنوانِ خودشان را دارند. تطبیق روی **همهٔ** آنهاست،
     * نه فقط فارسی: نسخهٔ انگلیسی هم باید rich result بگیرد.
     */
    function article_faq_ld(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $heads = 'پرسش‌های پرتکرار|پرسش‌های متداول|سوالات متداول'
            .'|frequently asked questions|faq|common questions'
            .'|sıkça sorulan sorular|sss';

        // بدنه را از عنوانِ پرسش‌ها تا انتها (یا تا h2ِ بعدی) جدا کن
        if (! preg_match('~<h2[^>]*>\s*(?:'.$heads.')\s*</h2>(.*?)(?=<h2\b|$)~isu', $html, $m)) {
            return '';
        }

        // هر <h3> یک پرسش است و هرچه تا h3ِ بعدی بیاید پاسخِ آن
        if (! preg_match_all('~<h3[^>]*>(.*?)</h3>(.*?)(?=<h3\b|$)~isu', $m[1], $qa, PREG_SET_ORDER)) {
            return '';
        }

        $items = [];
        foreach ($qa as $pair) {
            $q = trim(html_entity_decode(strip_tags($pair[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $a = trim(html_entity_decode(strip_tags($pair[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $a = trim(preg_replace('~\s+~u', ' ', $a));

            // پرسشِ بی‌پاسخ در schema یعنی آیتمِ نامعتبر و ردِ کلِ صفحه
            if ($q === '' || mb_strlen($a) < 20) {
                continue;
            }

            $items[] = [
                '@'.'type' => 'Question',
                'name' => mb_substr($q, 0, 300),
                'acceptedAnswer' => [
                    '@'.'type' => 'Answer',
                    'text' => mb_substr($a, 0, 1200),
                ],
            ];
        }

        return $items === [] ? '' : schema_ld(['mainEntity' => $items], 'FAQPage');
    }
}
