{{-- امضای «ترمینال نمایشی» --}}
<div class="sig-panel sig-term reveal">
  <div class="section-head">
    <span class="badge">{{ __('ui.hp_sig_badge') }}</span>
    <h2>{{ lc($sig)['t'] }}</h2>
    <p>{{ lc($sig)['d'] }}</p>
  </div>
  <div class="terminal" aria-hidden="true">
    <div class="bar"><i class="r"></i><i class="y"></i><i class="g"></i><span>servernet ~ </span></div>
    <div class="body">
      @foreach($sig['lines'] as [$cls, $text])
      <div class="ln {{ $cls }}">{{ $text }}</div>
      @endforeach
    </div>
  </div>
</div>
