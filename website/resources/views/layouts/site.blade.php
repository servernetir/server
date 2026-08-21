{{-- $isFa / $faUrl / $enUrl / $homeUrl / $contact / $social از AppServiceProvider می‌آیند --}}
@php
    /*
    | نوارِ «جای مشتری نشسته‌اید» (impersonation) — یک‌جا برای کلِ سایت.
    |
    | اینجا و نه در panel/layout.blade.php: مدیرِ واردشده در حسابِ مشتری در
    | صفحاتِ سایتِ اصلی هم می‌گردد (فروشگاه، کاتالوگ، بلاگ) و باید همه‌جا راهِ
    | بازگشت داشته باشد. خودِ نوار در partials/header.blade.php رندر می‌شود.
    |
    | هر دو شرط لازم است: کلیدِ نشست **و** مشتریِ واقعاً واردشده. اگر نشستِ
    | گاردِ customer از بین رفته باشد، نوارِ بی‌نام و بی‌فایده نباید رندر شود.
    */
    $impCust = session(\App\Http\Controllers\Admin\ImpersonateController::SESSION_KEY)
        ? auth('customer')->user()
        : null;
    $impBar = $impCust !== null;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isFa ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', __('ui.meta_title'))</title>
<meta name="description" content="@yield('description', __('ui.meta_desc'))">
{{--
  🔴 صفحهٔ خطا نباید خودش را canonical کند و نباید hreflang بدهد.
  قبلاً صفحهٔ ۴۰۴ آدرسِ خرابِ خودش را canonical اعلام می‌کرد و به دو زبانِ
  دیگرِ **همان آدرسِ خراب** لینک می‌داد: کاربری که برای نجاتِ خودش زبان را عوض
  می‌کرد، به یک ۴۰۴ِ دیگر می‌رفت — سه بار پشتِ سرِ هم. و گوگل یک صفحهٔ ۴۰۴ِ
  بی‌noindex و خودcanonical را ایندکس‌پذیر می‌دید.
--}}
@hasSection('noindex')
<meta name="robots" content="noindex,follow">
@else
<link rel="canonical" href="@yield('canonical', url()->current())">
@hasSection('faOnly')
{{-- صفحهٔ فقط‌فارسی (مثل /urmia/*): نسخهٔ en/tr ندارد. اگر foreachِ پایین
     اجرا می‌شد، hreflangِ en/tr به «خانهٔ» آن زبان اشاره می‌کرد (fallbackِ
     سوییچر) — یعنی ادعای دروغ به گوگل که ترجمهٔ این صفحه، صفحهٔ اصلی است. --}}
<link rel="alternate" hreflang="fa" href="@yield('canonical', url()->current())">
@else
@foreach($localeUrls as $langCode => $langUrl)
<link rel="alternate" hreflang="{{ $langCode }}" href="{{ $langUrl }}">
@endforeach
<link rel="alternate" hreflang="x-default" href="{{ $localeUrls['fa'] }}">
@endif
@endif
<meta property="og:title" content="@yield('title', __('ui.meta_title'))">
<meta property="og:description" content="@yield('description', __('ui.meta_desc'))">
<meta property="og:type" content="website">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:site_name" content="{{ __('ui.brand') }}">
<meta property="og:locale" content="{{ ['fa' => 'fa_IR', 'en' => 'en_US', 'tr' => 'tr_TR'][app()->getLocale()] ?? 'en_US' }}">
<meta property="og:image" content="{{ asset('assets/img/og.png') }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="@yield('title', __('ui.meta_title'))">
<meta name="twitter:description" content="@yield('description', __('ui.meta_desc'))">
<meta name="twitter:image" content="{{ asset('assets/img/og.png') }}">
<meta name="theme-color" content="#0A0E17">
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ asset('assets/img/og.png') }}">
<link rel="preload" href="{{ asset('assets/font/woff2/IRANSans-web.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="{{ asset('assets/font/woff2/IRANSans-Bold-web.woff2') }}" as="font" type="font/woff2" crossorigin>
<script>(function(){try{var m=document.cookie.match(/(?:^|;\s*)snet-theme=(light|dark)/);var t=m?m[1]:localStorage.getItem('snet-theme');if(t==='light')document.documentElement.dataset.theme='light';}catch(e){}})();</script>
<link rel="stylesheet" href="{{ asset_ver('assets/css/site.css') }}">
{{-- صفحه‌هایی مثل پنل که CSS اختصاصی دارند، از اینجا تزریق می‌کنند --}}
@stack('head')
@php
/*
| دادهٔ ساختاریافتهٔ سازمان — این‌جاست که نشانه‌های اعتماد واقعاً کار می‌کنند.
|
| گوگل و مدل‌های زبانی نامِ ثبتی و نشانی و شناسه را از همین می‌خوانند، نه از
| متنِ فوتر. ولی **فقط اگر پر باشند**: `legalName` یا `address`ِ خالی در
| schema بدتر از نبودنش است، چون داده‌ای می‌سازد که خودش می‌گوید ناقص است.
|
| ⚠️ `company_identity()` خالی‌ها را حذف کرده برمی‌گرداند، پس این‌جا فقط
| بررسیِ وجود لازم است نه دوباره trim.
*/
$org = [
    '@'.'context' => 'https://schema.org',
    '@type' => 'Organization',
    'name' => 'ServerNet',
    'url' => config('app.url'),
    'foundingDate' => (string) config('company.founded', '2009'),
    'sameAs' => array_values($social),
    'contactPoint' => [
        '@type' => 'ContactPoint',
        'telephone' => $contact['phone'],
        'email' => $contact['email'],
        'contactType' => 'customer support',
        'availableLanguage' => ['fa', 'en'],
    ],
];

/*
| ⚠️ همه از `company_value()` — نه `config()`.
|
| این مقدارها حالا از **پنلِ مدیریت** وارد می‌شوند و `.env` فقط راهِ دوم است.
| اگر این‌جا `config()` می‌ماند، مدیر آن‌ها را در پنل پر می‌کرد، روی صفحهٔ تماس
| می‌دیدشان، و در schema هیچ‌کدام نمی‌آمد — یعنی همان‌جایی که گوگل و مدل‌های
| زبانی واقعاً نگاه می‌کنند خالی می‌مانْد، آن هم بی‌هیچ خطایی.
*/
if ($legalName = company_value('legal_name')) {
    $org['legalName'] = $legalName;
}

$street = company_value('address.street');
$city = company_value('address.city');
if ($street !== '' && $city !== '') {
    $org['address'] = array_filter([
        '@type' => 'PostalAddress',
        'streetAddress' => $street,
        'addressLocality' => $city,
        'addressRegion' => company_value('address.province'),
        'postalCode' => company_value('address.postcode'),
        // کشور فیلدِ پنل ندارد: همیشه ایران است و پرسیدنش فقط یک فیلدِ اضافه بود
        'addressCountry' => trim((string) config('company.address.country', 'IR')),
    ], fn ($v) => $v !== '');
}

// شناسهٔ ملی/ثبت — `identifier` جای استانداردِ schema برای همین است
$ids = array_values(array_filter([
    company_value('national_id'),
    company_value('registration_no'),
]));
if ($ids) {
    $org['identifier'] = count($ids) === 1 ? $ids[0] : $ids;
}
@endphp
<script type="application/ld+json">{!! json_encode($org, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
</head>
{{-- imp-on: به بدنه به اندازهٔ ارتفاعِ نوار padding-top می‌دهد تا نوار هیچ
     محتوایی را نپوشاند و padding-topهای موجود (hero ۱۷۰، pnl-wrap ۱۱۸، …)
     دست‌نخورده بمانند. قاعده‌هایش انتهای site.css است. --}}
<body @class(['imp-on' => $impBar])>

<a class="skip-link" href="#main">{{ __('ui.skip') }}</a>
<div id="progress"></div>
<div class="aurora" aria-hidden="true"><div class="blob b1"></div><div class="blob b2"></div><div class="blob b3"></div></div>
<div class="grid-overlay" aria-hidden="true"></div>

@include('partials.icons')
@include('partials.header')

<main id="main">
    @yield('content')
</main>

@include('partials.footer')
@include('partials.chat')

<script src="{{ asset_ver('assets/js/site.js') }}" defer></script>
</body>
</html>
