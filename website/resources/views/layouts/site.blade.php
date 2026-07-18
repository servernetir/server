{{-- $isFa / $faUrl / $enUrl / $homeUrl / $contact / $social از AppServiceProvider می‌آیند --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isFa ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', __('ui.meta_title'))</title>
<meta name="description" content="@yield('description', __('ui.meta_desc'))">
<link rel="canonical" href="{{ url()->current() }}">
@foreach($localeUrls as $langCode => $langUrl)
<link rel="alternate" hreflang="{{ $langCode }}" href="{{ $langUrl }}">
@endforeach
<link rel="alternate" hreflang="x-default" href="{{ $localeUrls['fa'] }}">
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
<script>(function(){try{if(localStorage.getItem('snet-theme')==='light')document.documentElement.dataset.theme='light';}catch(e){}})();</script>
<link rel="stylesheet" href="{{ asset('assets/css/site.css') }}?v={{ filemtime(public_path('assets/css/site.css')) }}">
<script type="application/ld+json">{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'Organization',
    'name' => 'ServerNet',
    'url' => config('app.url'),
    'foundingDate' => '2009',
    'sameAs' => array_values($social),
    'contactPoint' => [
        '@type' => 'ContactPoint',
        'telephone' => $contact['phone'],
        'email' => $contact['email'],
        'contactType' => 'customer support',
        'availableLanguage' => ['fa', 'en'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
</head>
<body>

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

<script src="{{ asset('assets/js/site.js') }}?v={{ filemtime(public_path('assets/js/site.js')) }}" defer></script>
</body>
</html>
