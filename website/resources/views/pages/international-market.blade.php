@php
    use App\Http\Middleware\ConsoleHost;
    use App\Support\InternationalMarket;

    $page = $marketContent['pages'][$marketPage];
    $organizationId = rtrim(config('app.url'), '/').'/#organization';
    $schema = [
        'name' => $page['title'].' | ServerNet',
        'description' => $page['description'],
        'url' => $marketCanonical,
        'inLanguage' => $marketLocale,
        'publisher' => ['@type' => 'Organization', '@id' => $organizationId, 'name' => 'ServerNet'],
    ];
@endphp
<!doctype html>
<html lang="{{ $marketLocale }}" dir="{{ $marketLanguage['dir'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0a1424">
    <title>{{ $page['title'] }} | ServerNet</title>
    <meta name="description" content="{{ $page['description'] }}">
    <link rel="canonical" href="{{ $marketCanonical }}">
    @foreach($marketAlternates as $alternateLocale => $alternateUrl)
    <link rel="alternate" hreflang="{{ $alternateLocale }}" href="{{ $alternateUrl }}">
    @endforeach
    {{-- The full fa/en/tr product is not yet an equivalent localized pilot.
         Never announce a fallback URL as a translation to a search engine. --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="ServerNet">
    <meta property="og:title" content="{{ $page['title'] }} | ServerNet">
    <meta property="og:description" content="{{ $page['description'] }}">
    <meta property="og:url" content="{{ $marketCanonical }}">
    <meta property="og:locale" content="{{ $marketLanguage['og'] }}">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="stylesheet" href="{{ asset('assets/css/international-market.css') }}">
    <script type="application/ld+json">{!! schema_ld($schema, 'WebPage') !!}</script>
</head>
<body class="market">
    <a class="market-skip" href="#market-content">{{ $marketContent['skip'] }}</a>
    <header class="market-header">
        <div class="market-wrap market-header-inner">
            <a class="market-brand" href="{{ InternationalMarket::url($marketLocale, 'home') }}">
                <img class="market-brand-mark" src="{{ asset('favicon.svg') }}" width="34" height="34" alt=""> ServerNet
            </a>
            <nav class="market-languages" aria-label="{{ $marketContent['lang_label'] }}">
                @foreach(['fa', 'en', 'tr'] as $legacyLocale)
                    <a lang="{{ $legacyLocale }}" href="{{ ConsoleHost::siteUrl($legacyLocale === 'fa' ? '/' : '/'.$legacyLocale) }}">{{ InternationalMarket::LANGUAGES[$legacyLocale]['label'] }}</a>
                @endforeach
                @foreach($marketAlternates as $code => $alternate)
                    <a lang="{{ $code }}" href="{{ $alternate }}" @if($code === $marketLocale) aria-current="page" @endif>{{ InternationalMarket::LANGUAGES[$code]['label'] }}</a>
                @endforeach
            </nav>
            <a class="market-login" href="{{ $marketLoginUrl }}">{{ $marketContent['login'] }}</a>
        </div>
        <nav class="market-wrap market-nav" aria-label="{{ $marketContent['nav_label'] }}">
            @foreach($marketContent['pages'] as $key => $navPage)
                <a href="{{ InternationalMarket::url($marketLocale, $key) }}" @if($key === $marketPage) aria-current="page" @endif>{{ $navPage['nav'] }}</a>
            @endforeach
        </nav>
    </header>
    <main id="market-content" class="market-wrap">
        <section class="market-hero">
            <div class="market-hero-content">
                <p class="market-eyebrow">{{ $page['eyebrow'] }}</p>
                <h1>{{ $page['title'] }}</h1>
                <p class="market-description">{{ $page['description'] }}</p>
                <p class="market-lead">{{ $page['lead'] }}</p>
                <a class="market-cta" href="{{ $marketCatalogUrl }}">{{ $marketContent['catalog_cta'] }} <span aria-hidden="true">↗</span></a>
                <p class="market-handoff">{{ $marketContent['catalog_note'] }}</p>
            </div>
            <div class="market-graphic" aria-hidden="true">
                <div class="market-orb"><span></span><span></span><span></span><span></span></div>
                <div class="market-graphic-label">SERVERNET <span>·</span> CLOUD</div>
            </div>
        </section>
        <section class="market-points" aria-label="{{ $page['title'] }}">
            @foreach($page['sections'] as $index => $point)
                <article class="market-point">
                    <span class="market-index" aria-hidden="true">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                    <h2>{{ $point['title'] }}</h2>
                    <p>{{ $point['body'] }}</p>
                </article>
            @endforeach
        </section>
        <section class="market-related">
            <h2>{{ $marketPage === 'home' ? $marketContent['product_heading'] : $marketContent['related_heading'] }}</h2>
            <div class="market-grid">
                @foreach($marketContent['pages'] as $key => $other)
                    @continue($key === 'home' || $key === $marketPage)
                    <article class="market-card">
                        <p class="market-eyebrow">{{ $other['eyebrow'] }}</p>
                        <h3><a href="{{ InternationalMarket::url($marketLocale, $key) }}">{{ $other['title'] }}</a></h3>
                        <p>{{ $other['description'] }}</p>
                        <a class="market-detail" href="{{ InternationalMarket::url($marketLocale, $key) }}">{{ $marketContent['details'] }} <span aria-hidden="true">↗</span></a>
                    </article>
                @endforeach
            </div>
        </section>
        <section class="market-faq">
            <h2>{{ $marketContent['faq_heading'] }}</h2>
            @foreach($page['faq'] as $question)
                <article class="market-answer">
                    <h3>{{ $question['q'] }}</h3>
                    <p>{{ $question['a'] }}</p>
                </article>
            @endforeach
        </section>
    </main>
    <footer class="market-footer">
        <div class="market-wrap market-footer-inner">
            <p><strong>ServerNet</strong><br>{{ $marketContent['footer_note'] }}</p>
            <nav aria-label="ServerNet">
                @foreach(['contact', 'terms', 'privacy', 'status'] as $key)
                    <a href="{{ ConsoleHost::siteUrl(route('en.'.$key, [], false)) }}">{{ $marketContent[$key] }}</a>
                @endforeach
            </nav>
        </div>
    </footer>
</body>
</html>
