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
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=Vazirmatn:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
