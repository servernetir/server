{{-- امضای «تاخیر شبکه» — چیپ‌های پینگ --}}
<div class="sig-panel reveal">
  <div class="section-head">
    <span class="badge">{{ __('ui.hp_sig_badge') }}</span>
    <h2>{{ lc($sig)['t'] }}</h2>
    <p>{{ lc($sig)['d'] }}</p>
  </div>
  <div class="sig-pings">
    @foreach($sig['items'] as $item)
    <div class="ping-chip">
      <b><svg class="icon"><use href="#i-pin"/></svg>{{ lc($item) }}</b>
      <i>{{ $item['ms'] }}</i>
      <small>ping avg</small>
    </div>
    @endforeach
  </div>
</div>
