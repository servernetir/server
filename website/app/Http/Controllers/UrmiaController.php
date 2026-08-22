<?php

namespace App\Http\Controllers;

/**
 * بخشِ محلی ارومیه — /urmia/*
 *
 * جانشینِ سئوی محلیِ servernet.ir پس از مهاجرت (پنل مهاجرت + ممیزی ۴).
 * محتوا کاملاً config-driven است (config/urmia.php)، مثل بقیهٔ صفحات
 * بازاریابی — صفحهٔ تازه یعنی یک آیتمِ config، نه کنترلرِ تازه.
 *
 * 🔴 این روت‌ها عمداً بیرونِ closureِ `$site` و فقط با locale:fa ثبت شده‌اند؛
 *    /en/urmia/* باید ۴۰۴ بماند (UrmiaPagesTest). دلیل در سربرگِ config.
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

        return $i;
    }

    public function hub()
    {
        return view('pages.urmia.hub', [
            'identity' => $this->identity(),
            'pages'    => config('urmia.pages'),
            'cities'   => config('urmia.cities'),
        ]);
    }

    public function page(string $slug)
    {
        $page = config('urmia.pages.'.$slug);
        abort_if($page === null, 404);

        return view('pages.urmia.page', [
            'slug'     => $slug,
            'page'     => $page,
            'identity' => $this->identity(),
            'pages'    => config('urmia.pages'),
        ]);
    }

    public function city(string $slug)
    {
        $city = config('urmia.cities.'.$slug);
        abort_if($city === null, 404);

        return view('pages.urmia.city', [
            'slug'     => $slug,
            'city'     => $city,
            'identity' => $this->identity(),
            'cities'   => config('urmia.cities'),
        ]);
    }
}
