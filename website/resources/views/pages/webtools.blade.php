@extends('layouts.site')

@php
    $total = collect($categories)->sum(fn ($c) => count($c['tools'] ?? []));
@endphp

@section('title', __('ui.wt_title').' — '.__('ui.brand'))
@section('description', __('ui.wt_sub'))

@section('content')

<section class="hero hero-sub docs-hero" style="padding-bottom:32px">
  <div class="container">
    <span class="badge reveal"><svg class="icon"><use href="#i-wrench"/></svg>{{ __('ui.wt_badge') }}</span>
    <h1 class="reveal" style="transition-delay:.06s">{{ __('ui.wt_h1a') }} <span class="grad">{{ __('ui.wt_h1b') }}</span></h1>
    <p class="hero-lead reveal" style="transition-delay:.12s;max-width:660px">{{ __('ui.wt_sub') }}</p>

    <div class="wt-privacy reveal" style="transition-delay:.16s">
      <svg class="icon"><use href="#i-lock"/></svg>{{ __('ui.wt_privacy') }}
    </div>

    <div class="docs-search reveal" style="transition-delay:.2s">
      <svg class="icon"><use href="#i-search"/></svg>
      <input type="text" id="wt-q" placeholder="{{ __('ui.wt_search_ph', ['n' => $isFa ? fa_num($total) : $total]) }}" aria-label="{{ __('ui.wt_search_ph', ['n' => $total]) }}" autocomplete="off">
    </div>
  </div>
</section>

<section class="section" style="padding-top:8px;padding-bottom:78px">
  <div class="container">
    @foreach($categories as $key => $cat)
    <div class="wt-cat reveal" data-cat="{{ $key }}">
      <div class="wt-cat-h">
        <span class="wt-cat-ic"><svg class="icon"><use href="#i-{{ $cat['icon'] }}"/></svg></span>
        <div>
          <h2>{{ lc($cat)['t'] }}</h2>
          <p>{{ lc($cat)['d'] }}</p>
        </div>
        <span class="wt-cat-n">{{ $isFa ? fa_num(count($cat['tools'])) : count($cat['tools']) }}</span>
      </div>

      <div class="wt-grid">
        @foreach($cat['tools'] as $slug => $tool)
        <a class="wt-card" href="{{ lroute('webtools', $slug) }}"
           data-name="{{ lc($tool)['t'] }}" data-desc="{{ lc($tool)['d'] }}">
          <span class="wt-card-ic"><svg class="icon"><use href="#i-{{ $tool['icon'] }}"/></svg></span>
          <b>{{ lc($tool)['t'] }}</b>
          <small>{{ lc($tool)['d'] }}</small>
        </a>
        @endforeach
      </div>
    </div>
    @endforeach

    <p class="docs-empty" id="wt-noresult" hidden>{{ __('ui.wt_noresult') }}</p>
  </div>
</section>

<script>
(function () {
  const q = document.getElementById('wt-q');
  if (!q) return;
  const cats = [...document.querySelectorAll('.wt-cat')];
  const none = document.getElementById('wt-noresult');

  q.addEventListener('input', () => {
    const t = q.value.trim().toLowerCase();
    let shown = 0;
    cats.forEach(cat => {
      let hit = 0;
      cat.querySelectorAll('.wt-card').forEach(card => {
        const hay = ((card.dataset.name || '') + ' ' + (card.dataset.desc || '')).toLowerCase();
        const ok = !t || hay.includes(t);
        card.hidden = !ok; if (ok) hit++;
      });
      cat.hidden = hit === 0; if (hit) shown++;
    });
    if (none) none.hidden = shown > 0;
  });
})();
</script>
@endsection
