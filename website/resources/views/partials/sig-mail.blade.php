{{-- امضای «تحویل ایمیل به Inbox» --}}
<div class="sig-panel reveal">
  <div class="section-head">
    <span class="badge">{{ __('ui.hp_sig_badge') }}</span>
    <h2>{{ lc($sig)['t'] }}</h2>
    <p>{{ lc($sig)['d'] }}</p>
  </div>
  <div class="sig-mail">
    <div class="mail-head"><svg class="icon"><use href="#i-mail"/></svg>Inbox — {{ $isFa ? 'صندوق ورودی مشتری شما' : ($loc === 'tr' ? 'Müşterinizin gelen kutusu' : "Your customer's inbox") }}</div>
    @foreach($sig['rows'] as $row)
    <div class="mail-row">
      <span class="m-ava">{{ $row['a'] }}</span>
      <span class="m-txt"><b dir="ltr">{{ lc($row)['n'] }}</b><small>{{ lc($row)['s'] }}</small></span>
      <span class="m-tag">Inbox ✓</span>
    </div>
    @endforeach
    <div class="mail-foot">
      @foreach($sig['checks'] as $check)
      <span><svg class="icon"><use href="#i-check"/></svg>{{ $check }}</span>
      @endforeach
    </div>
  </div>
</div>
