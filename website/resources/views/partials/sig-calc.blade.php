{{-- امضای «ماشین‌حساب سود نمایندگی» --}}
@php
    $l = lc($sig);
    $cost = $isFa ? $sig['cost_irt'] : $sig['cost_eur'];
    $defPrice = $isFa ? $sig['def_price_irt'] : $sig['def_price_eur'];
    $priceStep = $isFa ? 10000 : 0.5;
    $priceMax = $isFa ? 1000000 : 20;
@endphp
<div class="sig-panel reveal">
  <div class="section-head">
    <span class="badge">{{ __('ui.hp_sig_badge') }}</span>
    <h2>{{ $l['t'] }}</h2>
    <p>{{ $l['d'] }}</p>
  </div>
  <div class="sig-calc" id="reseller-calc"
       data-cost="{{ $cost }}" data-cur="{{ $l['cur'] }}" data-fa="{{ $isFa ? 1 : 0 }}">
    <div class="calc-controls">
      <label>{{ $l['accounts'] }} <b id="calc-acc-val">{{ $isFa ? fa_num($sig['def_accounts']) : $sig['def_accounts'] }}</b></label>
      <input type="range" id="calc-acc" min="5" max="{{ $sig['max'] }}" step="5" value="{{ $sig['def_accounts'] }}">
      <label>{{ $l['price'] }} <b id="calc-price-val"></b></label>
      <input type="range" id="calc-price" min="{{ $cost * 2 }}" max="{{ $priceMax }}" step="{{ $priceStep }}" value="{{ $defPrice }}">
    </div>
    <div class="calc-result">
      <small>{{ $l['profit'] }}</small>
      <b class="calc-profit" id="calc-profit"></b>
      <span>{{ $l['cur'] }} / {{ __('ui.bill_monthly') }}</span>
      <span class="calc-year">{{ $l['year'] }}: <b id="calc-year"></b> {{ $l['cur'] }}</span>
    </div>
  </div>
</div>
<script>
(function () {
  const box = document.getElementById('reseller-calc');
  if (!box) return;
  const cost = parseFloat(box.dataset.cost), fa = box.dataset.fa === '1';
  const acc = document.getElementById('calc-acc'), price = document.getElementById('calc-price');
  const faNum = (s) => fa ? String(s).replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]) : String(s);
  const fmt = (n) => faNum(new Intl.NumberFormat('en-US', { maximumFractionDigits: fa ? 0 : 1 }).format(n));
  function update() {
    const a = parseInt(acc.value, 10), p = parseFloat(price.value);
    const profit = Math.max(0, a * (p - cost));
    document.getElementById('calc-acc-val').textContent = faNum(a);
    document.getElementById('calc-price-val').textContent = fmt(p) + ' ' + box.dataset.cur;
    document.getElementById('calc-profit').textContent = fmt(profit);
    document.getElementById('calc-year').textContent = fmt(profit * 12);
  }
  acc.addEventListener('input', update);
  price.addEventListener('input', update);
  update();
})();
</script>
