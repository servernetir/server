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
