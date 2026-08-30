@extends('layouts.site')

@php
    $t = lc($tool);
    $c = lc($cat);
    $url = url()->current();
@endphp

@section('title', $t['t'].' — '.__('ui.wt_free').' — '.__('ui.brand'))
@section('description', $t['d'])

@section('content')

<section class="wt-wrap">
  <div class="container">

    <nav class="blog-crumbs">
      <a href="{{ lroute('home') }}">{{ __('ui.bl_home') }}</a><span>/</span>
      <a href="{{ lroute('webtools.index') }}">{{ __('ui.wt_title') }}</a><span>/</span>
      <span>{{ $c['t'] }}</span>
    </nav>

    <div class="wt-layout">
    <div class="wt-main">

    <div class="wt-head">
      <span class="wt-head-ic"><svg class="icon"><use href="#i-{{ $tool['icon'] }}"/></svg></span>
      <div>
        <h1>{{ $t['t'] }}</h1>
        <p>{{ $t['d'] }}</p>
      </div>
      <span class="wt-free"><svg class="icon"><use href="#i-check"/></svg>{{ __('ui.wt_free') }}</span>
    </div>

    <div class="wt-note">
      <svg class="icon"><use href="#i-lock"/></svg>
      <span>{{ __('ui.wt_privacy') }}</span>
    </div>

    {{-- ============ خودِ ابزار ============ --}}
    <div class="wt-app">
      @includeIf('webtools.'.$slug)
    </div>

    {{-- ============ محتوای صفحه (برای کاربر و برای گوگل) ============ --}}
    @if($seo['intro'] || count($seo['steps']) || count($seo['faq']))
    <div class="wt-content">
      @if($seo['intro'])
      <section class="wt-sec">
        <h2>{{ __('ui.wt_about', ['tool' => $t['t']]) }}</h2>
        <p>{{ $seo['intro'] }}</p>
      </section>
      @endif

      @if(count($seo['steps']))
      <section class="wt-sec">
        <h2>{{ __('ui.wt_howto', ['tool' => $t['t']]) }}</h2>
        <ol class="wt-steps">
          @foreach($seo['steps'] as $step)
          <li><span>{{ $loop->iteration }}</span><p>{{ $step }}</p></li>
          @endforeach
        </ol>
      </section>
      @endif

      @if(count($seo['faq']))
      <section class="wt-sec">
        <h2>{{ __('ui.wt_faq') }}</h2>
        <div class="wt-faq">
          @foreach($seo['faq'] as $item)
          <details>
            <summary>{{ $item['q'] }}</summary>
            <p>{{ $item['a'] }}</p>
          </details>
          @endforeach
        </div>
      </section>
      @endif
    </div>
    @endif

    {{-- ابزارهای هم‌گروه --}}
    @if(count($siblings))
    <div class="wt-more">
      <h2>{{ __('ui.wt_related') }}</h2>
      <div class="wt-grid">
        @foreach($siblings as $sSlug => $sTool)
        <a class="wt-card" href="{{ lroute('webtools', $sSlug) }}">
          <span class="wt-card-ic"><svg class="icon"><use href="#i-{{ $sTool['icon'] }}"/></svg></span>
          <b>{{ lc($sTool)['t'] }}</b>
          <small>{{ lc($sTool)['d'] }}</small>
        </a>
        @endforeach
      </div>
      <a class="btn btn-glass wt-all" href="{{ lroute('webtools.index') }}">{{ __('ui.wt_all') }}<svg class="icon dir"><use href="#i-arrow"/></svg></a>
    </div>
    @endif

    </div>{{-- /.wt-main --}}

    @include('partials.webtool-sidebar')

    </div>{{-- /.wt-layout --}}

  </div>
</section>

<script type="application/ld+json">{!! json_encode([
    '@'.'context' => 'https://schema.org', '@type' => 'WebApplication',
    'name' => $t['t'], 'description' => $t['d'], 'url' => $url,
    'applicationCategory' => 'DeveloperApplication',
    'operatingSystem' => 'Any', 'inLanguage' => app()->getLocale(),
    'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'IRR'],
    'publisher' => ['@type' => 'Organization', 'name' => 'ServerNet', 'url' => config('app.url')],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>

{{-- پرسش‌های متداول — گوگل این را به شکل آکاردئون در نتایج نشان می‌دهد --}}
@if(count($seo['faq']))
<script type="application/ld+json">{!! json_encode([
    '@'.'context' => 'https://schema.org', '@type' => 'FAQPage',
    'mainEntity' => array_map(fn ($f) => [
        '@type' => 'Question', 'name' => $f['q'],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
    ], $seo['faq']),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endif

{{-- راهنمای گام‌به‌گام --}}
@if(count($seo['steps']))
<script type="application/ld+json">{!! json_encode([
    '@'.'context' => 'https://schema.org', '@type' => 'HowTo',
    'name' => __('ui.wt_howto', ['tool' => $t['t']]),
    'totalTime' => 'PT2M',
    'step' => array_map(fn ($s, $i) => [
        '@type' => 'HowToStep', 'position' => $i + 1, 'text' => $s,
    ], $seo['steps'], array_keys($seo['steps'])),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endif

{{-- مسیر راهنما — تا گوگل به جای آدرس خام، مسیر دسته را نشان دهد --}}
<script type="application/ld+json">{!! json_encode([
    '@'.'context' => 'https://schema.org', '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => __('ui.bl_home'), 'item' => lroute('home')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => __('ui.wt_title'), 'item' => lroute('webtools.index')],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $t['t'], 'item' => $url],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>

<script>
/* کمکی مشترک همه‌ی ابزارها: کپی در کلیپ‌بورد با بازخورد */
window.wtCopy = function (btn, text) {
  navigator.clipboard.writeText(text).then(() => {
    const old = btn.textContent;
    btn.textContent = btn.dataset.done || '✓';
    btn.classList.add('ok');
    setTimeout(() => { btn.textContent = old; btn.classList.remove('ok'); }, 1400);
  }).catch(() => {});
};
</script>
@endsection
