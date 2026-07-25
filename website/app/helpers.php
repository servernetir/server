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

        // تبدیل میلادی به شمسی (جلالی)
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
        $jd = $j_day_no + 1;
        $names = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

        return fa_num($jd).' '.($names[$jm + 1] ?? '').' '.fa_num($jy);
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

if (! function_exists('site_price')) {
    /** قیمت نمایشی بر اساس زبان جاری: تومان برای fa، یورو برای en */
    function site_price(array $item): string
    {
        if (app()->getLocale() === 'fa') {
            // قیمتِ تومانی با نرخِ زندهٔ یورو مقیاس می‌شود (پیش‌فرض: بدونِ تغییر)
            return fa_num(number_format(price_toman((int) ($item['irt'] ?? 0)))).' تومان';
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

        return site_price([
            'irt' => (int) round($item['irt'] * $factor, -4),
            'eur' => round($item['eur'] * $factor, 2),
        ]);
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
        $path = public_path($rel);
        $stamp = is_file($path) ? @filemtime($path) : false;

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
