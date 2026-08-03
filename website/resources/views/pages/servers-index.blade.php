@extends('layouts.site')
@section('title', __('ui.srv_title').' — '.__('ui.brand'))
@section('description', __('ui.srv_sub'))
@section('content')

@php
  $condLbl = ['new' => __('ui.srv_new'), 'refurb' => __('ui.srv_refurb')];
@endphp

<section class="srv-hero">
  <div class="container">
    <nav class="blog-crumbs"><a href="{{ lroute('home') }}">{{ __('ui.brand') }}</a><span>/</span><span>{{ __('ui.srv_title') }}</span></nav>
    <h1>{{ __('ui.srv_h1') }}</h1>
    <p class="srv-lead">{{ __('ui.srv_lead') }}</p>
  </div>
</section>

<section class="container srv-wrap">

  <div class="srv-filter" id="srv-filter">
    <button class="srv-chip on" data-brand="all">{{ __('ui.srv_all') }}</button>
    @foreach($brands as $key => $b)
      <button class="srv-chip" data-brand="{{ $key }}"><span class="srv-dot" style="background:{{ $b['color'] }}"></span>{{ $b['label'] }}</button>
    @endforeach
  </div>

  <div class="srv-grid" id="srv-grid">
    @foreach($models as $slug => $m)
      @php $b = $brands[$m['brand']] ?? ['label' => $m['brand'], 'color' => 'var(--cyan)']; $lm = lc($m); @endphp
      <a class="srv-card" data-brand="{{ $m['brand'] }}" href="{{ lroute('servers.show', $slug) }}">
        <div class="srv-thumb">
          @if(!empty($m['gallery']))
            <img src="{{ $m['gallery'][0] }}" alt="{{ $lm['name'] }}" loading="lazy">
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

  <p class="srv-empty" id="srv-empty" hidden>{{ __('ui.srv_none') }}</p>

  <div class="srv-cta">
    <div>
      <h2>{{ __('ui.srv_cta_h') }}</h2>
      <p>{{ __('ui.srv_cta_p') }}</p>
    </div>
    <a class="btn btn-primary" href="{{ lroute('contact') }}"><svg class="icon"><use href="#i-headset"/></svg>{{ __('ui.srv_cta_btn') }}</a>
  </div>

</section>

<script>
(function(){
  var f = document.getElementById('srv-filter'), grid = document.getElementById('srv-grid'), empty = document.getElementById('srv-empty');
  if (!f || !grid) return;
  f.addEventListener('click', function(e){
    var btn = e.target.closest('.srv-chip'); if (!btn) return;
    var brand = btn.getAttribute('data-brand');
    f.querySelectorAll('.srv-chip').forEach(function(c){ c.classList.toggle('on', c === btn); });
    var shown = 0;
    grid.querySelectorAll('.srv-card').forEach(function(card){
      var ok = brand === 'all' || card.getAttribute('data-brand') === brand;
      card.hidden = !ok; if (ok) shown++;
    });
    if (empty) empty.hidden = shown > 0;
  });
})();
</script>
@endsection
