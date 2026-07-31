@extends('layouts.site')
@php $lm = lc($model); @endphp
@section('title', $lm['name'].' — '.__('ui.srv_title').' — '.__('ui.brand'))
@section('description', $lm['tag'].' — '.$lm['hero_d'])
@section('content')

@php
  $imgs = !empty($model['gallery']) ? $model['gallery'] : ['/assets/servers/placeholder.svg'];
  $condLbl = ['new' => __('ui.srv_new'), 'refurb' => __('ui.srv_refurb')];
  $bc = $brand['color'] ?? 'var(--cyan)';
  // اسکیمای Product برای سئو — schema_ld خودش @context/@type را می‌گذارد.
  $ld = [
    'name' => $lm['name'], 'description' => $lm['hero_d'],
    'brand' => ['@'.'type' => 'Brand', 'name' => $brand['label'] ?? $model['brand']],
    'category' => __('ui.srv_title'),
  ];
  if (empty($model['price_from']['contact'])) {
    $ld['offers'] = ['@'.'type' => 'Offer', 'priceCurrency' => app()->getLocale() === 'fa' ? 'IRR' : 'EUR',
      'price' => app()->getLocale() === 'fa' ? ($model['price_from']['irt'] ?? 0) : ($model['price_from']['eur'] ?? 0),
      'availability' => 'https://schema.org/InStock'];
  }
@endphp
<script type="application/ld+json">{!! schema_ld($ld, 'Product') !!}</script>

<section class="container sd-wrap">
  <nav class="blog-crumbs" style="margin-bottom:16px">
    <a href="{{ lroute('home') }}">{{ __('ui.brand') }}</a><span>/</span>
    <a href="{{ lroute('servers.index') }}">{{ __('ui.srv_title') }}</a><span>/</span>
    <span>{{ $lm['name'] }}</span>
  </nav>

  <div class="sd-top">
    {{-- گالری --}}
    <div class="sd-gallery">
      <div class="sd-main" id="sd-main" role="button" tabindex="0" aria-label="{{ __('ui.srv_zoom') }}">
        <img id="sd-main-img" src="{{ $imgs[0] }}" alt="{{ $lm['name'] }}">
        <span class="sd-zoom"><svg class="icon"><use href="#i-search"/></svg></span>
      </div>
      @if(count($imgs) > 1)
      <div class="sd-thumbs">
        @foreach($imgs as $i => $src)
          <button type="button" class="sd-thumb @if($i === 0) on @endif" data-src="{{ $src }}"><img src="{{ $src }}" alt="" loading="lazy"></button>
        @endforeach
      </div>
      @else
      <p class="sd-nophoto">{{ __('ui.srv_photos_soon') }}</p>
      @endif
    </div>

    {{-- خلاصه و خرید --}}
    <div class="sd-buy">
      <span class="sd-brand" style="color:{{ $bc }}">{{ $brand['label'] ?? $model['brand'] }}</span>
      <h1 class="sd-name">{{ $lm['name'] }}</h1>
      <p class="sd-tag">{{ $lm['tag'] }}</p>
      <div class="sd-badges">
        <span class="srv-cond {{ $model['condition'] }}">{{ $condLbl[$model['condition']] ?? '' }}</span>
        @if(!empty($model['popular']))<span class="srv-pop static">{{ __('ui.srv_popular') }}</span>@endif
      </div>
      <p class="sd-desc">{{ $lm['hero_d'] }}</p>

      <div class="sd-pricebox">
        @if(!empty($model['price_from']['contact']))
          <span class="sd-price contact">{{ __('ui.srv_quote') }}</span>
        @else
          <span class="sd-price"><small>{{ __('ui.srv_from') }}</small> {{ site_price($model['price_from']) }}</span>
          <small class="sd-pricenote">{{ __('ui.srv_price_note') }}</small>
        @endif
      </div>

      <div class="sd-ctas">
        <a class="btn btn-primary" href="{{ lroute('contact') }}"><svg class="icon"><use href="#i-headset"/></svg>{{ __('ui.srv_order') }}</a>
        @if(!empty($contact['phone_link']))
          <a class="btn btn-glass" href="tel:{{ $contact['phone_link'] }}"><svg class="icon"><use href="#i-phone"/></svg>{{ __('ui.srv_call') }}</a>
        @endif
      </div>
      <p class="sd-deliver"><svg class="icon"><use href="#i-info"/></svg>{{ __('ui.srv_deliver') }}</p>
    </div>
  </div>

  {{-- مشخصات --}}
  <div class="sd-specs">
    <h2>{{ __('ui.srv_specs_h') }}</h2>
    <table>
      <tbody>
        @foreach($model['specs'] as $s)
          <tr><th>{{ lc($s['label']) }}</th><td>{{ lc($s) }}</td></tr>
        @endforeach
      </tbody>
    </table>
  </div>

  {{-- توضیحِ بلند --}}
  <div class="sd-about">
    <h2>{{ __('ui.srv_about_h') }}</h2>
    <p>{{ $lm['desc'] }}</p>
  </div>

  {{-- مرتبط --}}
  @if($related->isNotEmpty())
  <div class="sd-related">
    <h2>{{ __('ui.srv_related') }}</h2>
    <div class="srv-grid">
      @foreach($related as $rslug => $rm)
        @php $rb = ($brands = config('servers.brands'))[$rm['brand']] ?? ['label' => $rm['brand'], 'color' => 'var(--cyan)']; $rlm = lc($rm);
             $rimg = $rm['gallery'][0] ?? '/assets/servers/placeholder.svg'; @endphp
        <a class="srv-card" href="{{ lroute('servers.show', $rslug) }}">
          <div class="srv-thumb"><img src="{{ $rimg }}" alt="{{ $rlm['name'] }}" loading="lazy"></div>
          <div class="srv-body">
            <span class="srv-brand" style="color:{{ $rb['color'] }}">{{ $rb['label'] }}</span>
            <h3>{{ $rlm['name'] }}</h3>
            <p class="srv-tag">{{ $rlm['tag'] }}</p>
          </div>
          <div class="srv-foot">
            @if(!empty($rm['price_from']['contact']))<span class="srv-price contact">{{ __('ui.srv_quote') }}</span>
            @else<span class="srv-price"><small>{{ __('ui.srv_from') }}</small> {{ site_price($rm['price_from']) }}</span>@endif
            <span class="srv-go">{{ __('ui.srv_view') }} <svg class="icon dir"><use href="#i-arrow"/></svg></span>
          </div>
        </a>
      @endforeach
    </div>
  </div>
  @endif
</section>

{{-- لایت‌باکسِ گالری --}}
<div class="sd-lightbox" id="sd-lightbox" hidden>
  <button type="button" class="sd-lb-close" id="sd-lb-close" aria-label="{{ __('ui.close') }}"><svg class="icon"><use href="#i-x"/></svg></button>
  <img id="sd-lb-img" src="" alt="{{ $lm['name'] }}">
</div>

<script>
(function(){
  var main = document.getElementById('sd-main-img');
  var thumbs = document.querySelectorAll('.sd-thumb');
  thumbs.forEach(function(t){
    t.addEventListener('click', function(){
      var src = t.getAttribute('data-src'); if (!src || !main) return;
      main.src = src;
      thumbs.forEach(function(x){ x.classList.toggle('on', x === t); });
    });
  });
  // لایت‌باکس
  var lb = document.getElementById('sd-lightbox'), lbImg = document.getElementById('sd-lb-img');
  var open = function(){ if (!lb || !main) return; lbImg.src = main.src; lb.hidden = false; document.body.style.overflow = 'hidden'; };
  var close = function(){ if (!lb) return; lb.hidden = true; document.body.style.overflow = ''; };
  var m = document.getElementById('sd-main');
  if (m) { m.addEventListener('click', open); m.addEventListener('keydown', function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } }); }
  document.getElementById('sd-lb-close')?.addEventListener('click', close);
  lb?.addEventListener('click', function(e){ if (e.target === lb) close(); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') close(); });
})();
</script>
@endsection
