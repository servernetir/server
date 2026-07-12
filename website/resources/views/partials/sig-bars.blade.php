{{-- امضای «مقایسه کارایی» — نوارهای انیمیشنی --}}
<div class="sig-panel reveal">
  <div class="section-head">
    <span class="badge">{{ __('ui.hp_sig_badge') }}</span>
    <h2>{{ lc($sig)['t'] }}</h2>
    <p>{{ lc($sig)['d'] }}</p>
  </div>
  <div class="sig-bars">
    @foreach($sig['items'] as $item)
    <div class="bar-row {{ ($item['hl'] ?? false) ? 'hl' : '' }}">
      <span class="bar-label">{{ lc($item) }}</span>
      <div class="bar-track"><i style="--w:{{ $item['w'] }}"></i></div>
      <b class="bar-val">{{ $item['val'] }}</b>
    </div>
    @endforeach
  </div>
</div>
