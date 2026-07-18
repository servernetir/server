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
        return strtr((string) $value, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
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

if (! function_exists('site_price')) {
    /** قیمت نمایشی بر اساس زبان جاری: تومان برای fa، یورو برای en */
    function site_price(array $item): string
    {
        if (app()->getLocale() === 'fa') {
            return fa_num(number_format($item['irt'])).' تومان';
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
