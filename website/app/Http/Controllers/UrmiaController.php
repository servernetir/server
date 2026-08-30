<?php

namespace App\Http\Controllers;

/**
 * بخشِ محلی ارومیه — /urmia/*
 *
 * جانشینِ سئوی محلیِ servernet.ir پس از مهاجرت (پنل مهاجرت + ممیزی ۴).
 * محتوا کاملاً config-driven است: فارسی در config/urmia.php (دست‌نخورده)،
 * ترجمه‌های en/tr در config/urmia_i18n.php که این‌جا در لحظهٔ رندر overlay
 * می‌شوند — صفحهٔ تازه یعنی یک آیتمِ config + بلوکِ ترجمه، نه کنترلرِ تازه.
 *
 * 🔵 این بخش از مرداد ۱۴۰۵ سه‌زبانه است (خواستِ مدیر). روت‌ها مثل $site سه
 *    بار ثبت می‌شوند (routes/web.php) و en/tr محتوای واقعاً ترجمه‌شده
 *    می‌گیرند، نه فارسی — UrmiaPagesTest همین را قفل می‌کند.
 */
class UrmiaController extends Controller
{
    /*
    | خواستِ مدیر: صفحات ارومیه همان تلفن و نشانیِ نسخهٔ فارسیِ سایت را نشان
    | بدهند، نه شماره‌ای جدا. پس پیش‌فرض از `servernet.contact` (تلفن) و
    | `company_address()` (نشانیِ /admin/settings — همان منبعِ صفحهٔ تماس)
    | می‌آید؛ URMIA_PHONE/URMIA_ADDRESS فقط overrideِ اختیاری‌اند.
    | این‌جا و نه در config: فایل‌های config در لحظهٔ بوت به config()های دیگر
    | و دیتابیس دسترسیِ قابل‌اتکا ندارند.
    */
    private function identity(): array
    {
        $i = config('urmia.identity');

        $i['phone']      = $i['phone'] ?: config('servernet.contact.phone');
        $i['phone_link'] = config('servernet.contact.phone_link') ?: $i['phone'];
        $i['address']    = $i['address'] ?: company_address();

        // فیلدهای متنی به زبانِ جاری — تلفن/نشانی/geo زبان ندارند
        foreach ((array) config('urmia_i18n.identity') as $k => $v) {
            $i[$k] = lc($v);
        }

        return $i;
    }

    /** رشته‌های رابط، حل‌شده برای زبانِ جاری. */
    private function ui(): array
    {
        return array_map(fn ($v) => lc($v), (array) config('urmia_i18n.ui'));
    }

    /** یک صفحهٔ خدمت، با overlayِ ترجمه برای en/tr (fa عیناً از urmia.php). */
    private function localizePage(string $slug, array $page): array
    {
        $loc = app()->getLocale();
        if ($loc === 'fa') {
            return $page;
        }

        $tr = (array) config('urmia_i18n.pages.'.$slug);
        foreach (['title', 'desc', 'h1', 'lead'] as $k) {
            $page[$k] = $tr[$k][$loc] ?? $page[$k];
        }
        // sections/faq کامل جایگزین می‌شوند: نصفه‌ترجمه یعنی صفحهٔ دوزبانهٔ درهم
        $page['sections'] = $tr['sections'][$loc] ?? $page['sections'];
        $page['faq']      = $tr['faq'][$loc] ?? $page['faq'];

        return $page;
    }

    /** همهٔ صفحات (برای شبکهٔ هاب و لینک‌های مرتبط). */
    private function pages(): array
    {
        $out = [];
        foreach ((array) config('urmia.pages') as $slug => $page) {
            $out[$slug] = $this->localizePage($slug, $page);
        }

        return $out;
    }

    /*
    | شهرها: نامِ لاتین از city_names؛ متنِ en/tr قالبِ مشترکِ city_body است
    | (٪CITY٪ → نامِ همان شهر). متنِ یکتای فارسیِ هر شهر دست‌نخورده می‌ماند.
    */
    private function cities(): array
    {
        $loc = app()->getLocale();
        $out = (array) config('urmia.cities');
        if ($loc === 'fa') {
            return $out;
        }

        $names = (array) config('urmia_i18n.city_names');
        $body  = (array) config('urmia_i18n.city_body.'.$loc);
        foreach ($out as $slug => $c) {
            $name = $names[$slug][$loc] ?? $c['name'];
            $out[$slug]['name'] = $name;
            $out[$slug]['p']    = array_map(fn ($p) => str_replace('%CITY%', $name, $p), $body);
        }

        return $out;
    }

    /** دادهٔ مشترکِ هر سه view. */
    private function shared(): array
    {
        return ['identity' => $this->identity(), 'ui' => $this->ui()];
    }

    public function hub()
    {
        return view('pages.urmia.hub', $this->shared() + [
            'hub'    => array_map(fn ($v) => lc($v), (array) config('urmia_i18n.hub')),
            'pages'  => $this->pages(),
            'cities' => $this->cities(),
        ]);
    }

    public function page(string $slug)
    {
        $page = config('urmia.pages.'.$slug);
        abort_if($page === null, 404);

        return view('pages.urmia.page', $this->shared() + [
            'slug'  => $slug,
            'page'  => $this->localizePage($slug, $page),
            'pages' => $this->pages(),
        ]);
    }

    public function city(string $slug)
    {
        abort_if(config('urmia.cities.'.$slug) === null, 404);

        $cities = $this->cities();

        return view('pages.urmia.city', $this->shared() + [
            'slug'     => $slug,
            'city'     => $cities[$slug],
            'cities'   => $cities,
            'services' => array_map(fn ($s) => ['t' => lc($s['t']), 'd' => lc($s['d'])],
                (array) config('urmia_i18n.city_services')),
            'flow'     => (array) lc(config('urmia_i18n.city_flow')),
        ]);
    }
}
