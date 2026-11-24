<input type="hidden" name="domain_quote_id" id="hosting-domain-quote-id" value="">
<button type="button" class="pnl-btn" id="hosting-domain-check" style="margin-top:10px">{{ __('hosting-domains.check') }}</button>
<p class="co-note" id="hosting-domain-status" role="status" aria-live="polite">{{ __('hosting-domains.hint') }}</p>
<p class="co-note"><a href="{{ lroute('account.profile') }}">{{ __('hosting-domains.profile_link') }}</a></p>
<script>
(function () {
  'use strict';
  function init() {
    var form = document.getElementById('co-form');
    if (!form) return;
    var input = form.querySelector('[name="domain_buy"]'), id = document.getElementById('hosting-domain-quote-id');
    var button = document.getElementById('hosting-domain-check'), status = document.getElementById('hosting-domain-status');
    var submit = form.querySelector('button[type="submit"]'), baseDisabled = submit.disabled;
    var text = @json(__('hosting-domains'));
    var endpoint = @json(lroute('account.order.domain-quote'));
    var currency = {fa: @json(app()->getLocale() === 'fa'), rate: @json(cloud_eur_rate())};
    var quote = null, request = null, serial = 0, timer = null;
    function buying() { var mode = form.querySelector('[name="domain_mode"]:checked'); return mode && mode.value === 'buy'; }
    function name() { return input.value.trim().toLowerCase(); }
    function valid() { return quote && quote.domain === name() && Date.parse(quote.expires_at) > Date.now(); }
    function money(amount) {
      if (currency.fa) return Number(amount).toLocaleString('fa-IR') + ' تومان';
      return '€' + (Number(amount) / currency.rate).toFixed(2);
    }
    function update() {
      var accepted = buying() && valid();
      id.value = accepted ? quote.quote_id : '';
      submit.disabled = baseDisabled || (buying() && !accepted);
      form.dispatchEvent(new CustomEvent('servernet:hosting-domain-price', {bubbles:true, detail:{total_toman:accepted ? quote.total_toman : 0}}));
    }
    function reset() {
      serial++; if (request) request.abort(); request = null;
      clearTimeout(timer); quote = null; button.disabled = false; button.textContent = text.check;
      status.textContent = text.hint; update();
    }
    input.addEventListener('input', reset);
    form.querySelectorAll('[name="domain_mode"]').forEach(function (mode) {
      mode.addEventListener('change', function () { if (!buying() && request) { reset(); } else { update(); } });
    });
    button.addEventListener('click', async function () {
      if (!name() || !buying()) { input.focus(); return; }
      reset(); var mine = serial, requestedName = name(); request = new AbortController();
      button.disabled = true; button.textContent = text.checking; status.textContent = text.checking;
      try {
        var response = await fetch(endpoint, {method:'POST', signal:request.signal, headers:{'Content-Type':'application/json', 'Accept':'application/json', 'X-CSRF-TOKEN':form.querySelector('[name="_token"]').value}, body:JSON.stringify({domain:requestedName})});
        var result = await response.json();
        if (mine !== serial || requestedName !== name() || !buying()) return;
        if (!response.ok || !result.ok || result.domain !== requestedName || !Number.isSafeInteger(result.quote_id)
          || !Number.isSafeInteger(result.total_toman) || result.total_toman < 1 || !(Date.parse(result.expires_at) > Date.now())
          || (!currency.fa && !(currency.rate > 0))) throw new Error('unavailable');
        quote = result;
        status.textContent = text.ready.replace(':years', result.period_years).replace(':price', money(result.price_toman)).replace(':tax', money(result.tax_toman));
        timer = setTimeout(function () { reset(); status.textContent = text.recheck; }, Math.min(2147483647, Math.max(1, Date.parse(result.expires_at) - Date.now())));
        update();
      } catch (error) {
        if (mine === serial && error.name !== 'AbortError') { quote = null; status.textContent = text.unavailable; update(); }
      } finally {
        if (mine === serial) { request = null; button.disabled = false; button.textContent = text.check; }
      }
    });
    form.addEventListener('submit', function (event) {
      if (buying() && !valid()) { event.preventDefault(); status.textContent = text.recheck; button.focus(); }
    });
    update();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true}); else init();
})();
</script>
