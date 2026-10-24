@extends('layouts.site')
@section('title', __('ui.srv_title').($pagination->currentPage() > 1 ? ' · '.\App\Services\Shop\HardwarePresentation::text('page').' '.fa_num($pagination->currentPage()) : '').' — '.__('ui.brand'))
@section('description', \App\Services\Shop\HardwarePresentation::text('catalog_intro'))
@section('canonical', url()->current().($pagination->currentPage() > 1 ? '?page='.$pagination->currentPage() : ''))
@if($search !== '' || $activeBrand !== 'all' || $comparison->isNotEmpty()) @section('noindex', true) @endif
@include('partials.hardware-assets')
@section('content')

@php
  $condLbl = ['new' => __('ui.srv_new'), 'refurb' => __('ui.srv_refurb'), 'catalog' => \App\Services\Shop\HardwarePresentation::text('configuration')];
@endphp

<section class="srv-hero">
  <div class="container">
    <nav class="blog-crumbs"><a href="{{ lroute('home') }}">{{ __('ui.brand') }}</a><span>/</span><span>{{ __('ui.srv_title') }}</span></nav>
    <h1>{{ __('ui.srv_h1') }}</h1>
    <p class="srv-lead">{{ \App\Services\Shop\HardwarePresentation::text('catalog_intro') }}</p>
  </div>
</section>

<section class="container srv-wrap">

  <form class="hw-search" method="get" action="{{ lroute('servers.index') }}" role="search">
    <label for="hw-server-search">{{ \App\Services\Shop\HardwarePresentation::text('search') }}</label>
    <input id="hw-server-search" type="search" name="q" value="{{ $search }}" maxlength="60">
    @if($activeBrand !== 'all')<input type="hidden" name="brand" value="{{ $activeBrand }}">@endif
    <button class="btn btn-primary" type="submit">{{ __('ui.parts_search') }}</button>
  </form>
  <nav class="srv-filter" aria-label="{{ __('ui.srv_all') }}">
    <a class="srv-chip {{ $activeBrand === 'all' ? 'on' : '' }}" href="{{ lroute('servers.index') }}">{{ __('ui.srv_all') }}</a>
    @foreach($brands as $key => $b)
      <a class="srv-chip {{ $activeBrand === $key ? 'on' : '' }}" href="{{ lroute('servers.index').'?brand='.$key }}"><span class="srv-dot" style="background:{{ $b['color'] }}"></span>{{ $b['label'] }}</a>
    @endforeach
  </nav>
  @include('partials.server-comparison')
  <p class="hw-note">{{ __('ui.parts_count', ['count' => fa_num($pagination->total())]) }}</p>
  <div class="srv-grid" id="srv-grid">
    @foreach($models as $slug => $m)
      {{-- ⚠️ `img_url()` و نه `!empty()`: ردیفی که رشتهٔ «null» دارد از
           `!empty()` رد می‌شود و `src="null"` می‌سازد ⇒ ۴۰۴ روی `/servers/null`. --}}
      @php
        $b = $brands[$m['brand']] ?? ['label' => $m['brand'], 'color' => 'var(--cyan)'];
        $lm = lc($m);
        $thumb = img_url(\App\Services\Shop\HardwarePresentation::metadata($slug)['thumbnail'] ?? null) ?? img_url(((array) ($m['gallery'] ?? []))[0] ?? null);
      @endphp
      <a class="srv-card" @if($activeBrand !== 'all' && $activeBrand !== $m['brand']) hidden @endif data-brand="{{ $m['brand'] }}" href="{{ lroute('servers.show', $slug) }}">
        <div class="srv-thumb">
          @if($thumb)
            <img src="{{ $thumb }}" alt="{{ $lm['name'] }}" width="640" height="480" loading="lazy" decoding="async">
          @else
            <div class="srv-ph"><svg class="icon"><use href="#i-server"/></svg></div>
          @endif
          @if(!empty($m['popular']))<span class="srv-pop">{{ __('ui.srv_popular') }}</span>@endif
          <span class="srv-cond {{ $m['condition'] }}">{{ $condLbl[$m['condition']] ?? '' }}</span>
        </div>
        <div class="srv-body">
          <span class="srv-brand" style="color:{{ $b['color'] }}">{{ $b['label'] }}</span>
          <h3>{{ $lm['name'] }}</h3>
          <p class="srv-tag">{{ $lm['tag'] }}</p>
          <ul class="srv-specs">
            @foreach(array_slice($m['specs'], 0, 3) as $s)
              <li><svg class="icon"><use href="#i-check"/></svg>{{ lc($s) }}</li>
            @endforeach
          </ul>
        </div>
        <div class="srv-foot">
          @if(!empty($m['price_from']['contact']))
            <span class="srv-price contact">{{ __('ui.srv_quote') }}</span>
          @else
            <span class="srv-price"><small>{{ __('ui.srv_from') }}</small> {{ site_price($m['price_from']) }}</span>
          @endif
          <span class="srv-go">{{ __('ui.srv_view') }} <svg class="icon dir"><use href="#i-arrow"/></svg></span>
        </div>
      </a>
    @endforeach
  </div>

  <p class="srv-empty" id="srv-empty" @if($activeBrand === 'all' ? count($models) > 0 : collect($models)->contains(fn ($model) => $model['brand'] === $activeBrand)) hidden @endif>{{ __('ui.srv_none') }}</p>

  @include('partials.hardware-pagination')
  @include('partials.hardware-guide', ['hardwareCategory' => 'server'])

  <div class="srv-cta">
    <div>
      <h2>{{ __('ui.srv_cta_h') }}</h2>
      <p>{{ __('ui.srv_cta_p') }}</p>
    </div>
    <a class="btn btn-primary" href="{{ lroute('contact') }}"><svg class="icon"><use href="#i-headset"/></svg>{{ __('ui.srv_cta_btn') }}</a>
  </div>

</section>


@endsection
