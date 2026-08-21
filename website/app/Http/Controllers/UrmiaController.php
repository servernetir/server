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
    public function hub()
    {
        return view('pages.urmia.hub', [
            'identity' => config('urmia.identity'),
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
            'identity' => config('urmia.identity'),
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
            'identity' => config('urmia.identity'),
            'cities'   => config('urmia.cities'),
        ]);
    }
}
