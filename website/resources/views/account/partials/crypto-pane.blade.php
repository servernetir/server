{{--
  ═══ پرداخت با رمزارز — یک کارت، انتخابِ ارز داخلِ پنل ═══

  دو حالت:
    · پرداختِ بازی نیست ⇒ انتخابِ ارز/شبکه (فهرستِ کشویی) و «دریافت آدرس».
    · پرداختِ باز هست   ⇒ صفحهٔ پرداخت: مبلغِ دقیق، آدرس، QR، شمارشِ معکوس،
                           و مراحلِ «در انتظار → دیده شد → تأیید».

  ⚠️ پنلِ پرداختِ باز **باز** رندر می‌شود. بعد از «دریافت آدرس» مشتری به همین
     صفحه برمی‌گردد؛ اگر باید دوباره روی کارت کلیک می‌کرد تا آدرسش را ببیند،
     از دیدِ او هیچ اتفاقی نیفتاده بود.
  ⚠️ وقتی پرداخت باز است، عوض‌کردنِ ارز پیشنهاد نمی‌شود: هر ارزِ تازه یک آدرسِ
     دیگر از استخرِ کوچک قفل می‌کند و آدرسِ قبلی تا پایانِ مهلت گیر می‌ماند.
  ⚠️ صفحه **خودش تصمیم نمی‌گیرد** پول رسیده یا نه؛ فقط وضعیتی را که سرور
     می‌گوید نشان می‌دهد. حکم مالِ کرونِ `crypto:watch` است.
--}}
@php
  $cyReady = collect($cryptoAssets)->filter(fn ($s) => $s['state'] !== \App\Services\Payment\CryptoIssuer::BUSY);
  $cyDefault = $cyReady->has('USDT_BEP20') ? 'USDT_BEP20' : $cyReady->keys()->first();
@endphp
<div class="pm-pane cyx" id="pane-crypto" @unless($cryptoOpen) hidden @endunless>
  <div class="pm-pane-h"><b>{{ __('ui.inv_crypto_pane') }}</b></div>

  @if($cryptoOpen)
    @php
      $cy = $cryptoOpen;
      $cyTotal = max(1, (int) $cy->created_at->diffInSeconds($cy->expires_at));
      $cyLeft = $cy->secondsLeft();
      $cyAddr = (string) $cy->address;
    @endphp
    <div class="cyx-box" id="cy-{{ $cy->id }}"
         data-status-url="{{ lroute('account.invoice.crypto.status', $invoice) }}"
         data-left="{{ $cyLeft }}" data-total="{{ $cyTotal }}" data-status="{{ $cy->status }}">

      <div class="cyx-head">
        <div class="cyx-coin">
          @include('account.partials.crypto-coin', ['code' => $cy->asset, 'size' => 36])
          <div>
            <b dir="ltr">{{ $cy->asset }}</b>
            <span class="cyx-net" dir="ltr">{{ $cy->network }}</span>
          </div>
        </div>
        <div class="cyx-timer" role="timer" aria-live="off">
          <svg viewBox="0 0 60 60" aria-hidden="true">
            <circle cx="30" cy="30" r="26" class="cyx-ring-bg"/>
            <circle cx="30" cy="30" r="26" class="cyx-ring" id="cy-ring"
                    stroke-dasharray="163.36" stroke-dashoffset="{{ round(163.36 * (1 - $cyLeft / $cyTotal), 2) }}"/>
          </svg>
          <div class="cyx-timer-t">
            <b id="cy-clock" dir="ltr">{{ sprintf('%d:%02d', intdiv($cyLeft, 60), $cyLeft % 60) }}</b>
            <small>{{ __('ui.cy_time_left') }}</small>
          </div>
        </div>
      </div>

      <div class="cyx-expired" id="cy-expired" hidden>
        <b>{{ __('ui.cy_expired_title') }}</b>
        <p>{{ __('ui.cy_expired') }}</p>
        <form method="POST" action="{{ lroute('account.invoice.crypto', $invoice) }}">
          @csrf
          @include('account.partials.birthday-price-token', ['invoice' => $invoice])<input type="hidden" name="asset" value="{{ \App\Services\Payment\CryptoIssuer::selectionFor($cy) }}">
          <button type="submit" class="pnl-btn primary">{{ __('ui.cy_new_address') }}</button>
        </form>
      </div>

      <div class="cyx-body">
        <div class="cyx-qr">
          <div class="cyx-qr-img">{!! \App\Services\Security\QrCode::svg($cyAddr, 2, 4) !!}</div>
          <small>{{ __('ui.cy_scan') }}</small>
        </div>

        <div class="cyx-fields">
          {{-- 🔴 مبلغِ دقیق با اثرِ انگشت — همین عدد پرداخت را به فاکتور وصل می‌کند --}}
          <div class="cyx-field">
            <span>{{ __('ui.cy_send_exactly') }}</span>
            <div class="cyx-val">
              <b dir="ltr" class="cyx-amt">{{ $cy->amountHuman() }} <em>{{ $cy->asset }}</em></b>
              <button type="button" class="cyx-copy" data-copy="{{ $cy->amountHuman() }}" aria-label="{{ __('ui.cy_copy') }}">
                <svg class="icon"><use href="#i-copy"/></svg><span>{{ __('ui.cy_copy') }}</span>
              </button>
            </div>
            <small>{{ __('ui.cy_for_invoice', ['amount' => invoice_money($invoice->due(), $invoice->currency_code)]) }}</small>
          </div>

          <div class="cyx-field">
            <span>{{ __('ui.inv_cy_address') }}</span>
            <div class="cyx-val">
              {{-- چهار نویسهٔ اول و آخر برجسته: همان چیزی که مشتری با کیف‌پولش مقایسه می‌کند --}}
              <code dir="ltr" class="cyx-addr"><mark>{{ mb_substr($cyAddr, 0, 4) }}</mark>{{ mb_substr($cyAddr, 4, max(0, mb_strlen($cyAddr) - 8)) }}<mark>{{ mb_substr($cyAddr, -4) }}</mark></code>
              <button type="button" class="cyx-copy" data-copy="{{ $cyAddr }}" aria-label="{{ __('ui.cy_copy') }}">
                <svg class="icon"><use href="#i-copy"/></svg><span>{{ __('ui.cy_copy') }}</span>
              </button>
            </div>
          </div>

          <div class="cyx-warn">
            <svg class="icon"><use href="#i-info"/></svg>
            <span>{{ __('ui.cy_network_only', ['net' => $cy->network]) }}</span>
          </div>
        </div>
      </div>

      <ol class="cyx-steps" id="cy-steps">
        <li class="is-on" data-step="pending"><i></i>{{ __('ui.cy_step_wait') }}</li>
        <li class="{{ $cy->status === 'seen' ? 'is-on' : '' }}" data-step="seen"><i></i>{{ __('ui.cy_step_seen') }}</li>
        <li data-step="confirmed"><i></i>{{ __('ui.cy_step_done') }}</li>
      </ol>

      <p class="cyx-state" id="cy-state" data-seen="{{ __('ui.cy_seen') }}">
        <span class="cyx-pulse" aria-hidden="true"></span>
        <span id="cy-state-t">{{ $cy->status === 'seen' ? __('ui.cy_seen') : __('ui.cy_waiting') }}</span>
      </p>


      <p class="pm-note cyx-tail">{{ __('ui.cy_verify_tail') }}</p>
    </div>

  @elseif($cyDefault !== null)
    <form method="POST" action="{{ lroute('account.invoice.crypto', $invoice) }}" class="cyx-pick" id="cy-pick">
      @csrf
          @include('account.partials.birthday-price-token', ['invoice' => $invoice])
      <label class="cyx-label" for="cy-asset">{{ __('ui.cy_choose_coin') }}</label>

      {{-- بدونِ JS همین select کار می‌کند؛ JS آن را به فهرستِ کشوییِ نشان‌دار تبدیل می‌کند --}}
      <select name="asset" id="cy-asset" class="cyx-native">
        @foreach($cryptoAssets as $code => $spec)
          @php $busy = $spec['state'] === \App\Services\Payment\CryptoIssuer::BUSY; @endphp
          <option value="{{ $code }}" @selected($code === $cyDefault) @disabled($busy)
                  data-asset="{{ $spec['asset'] }}" data-net="{{ $spec['network'] }}" data-window="{{ $spec['window'] }}"
                  data-est="{{ $spec['estimate'] ?? '' }}" data-busy="{{ $busy ? '1' : '' }}">
            {{ $spec['label'] }}{{ $code === 'USDT_BEP20' ? ' · '.__('ui.cy_recommended') : '' }}{{ $busy ? ' · '.__('ui.cy_busy') : '' }}
          </option>
        @endforeach
      </select>

      <div class="cyx-select" id="cy-select" hidden>
        <button type="button" class="cyx-trigger" aria-haspopup="listbox" aria-expanded="false" aria-controls="cy-list">
          <span class="cyx-opt-main" data-slot="main"></span>
          <svg class="icon cyx-chev"><use href="#i-chev"/></svg>
        </button>
        <ul class="cyx-list" id="cy-list" role="listbox" hidden>
          @foreach($cryptoAssets as $code => $spec)
            @php $busy = $spec['state'] === \App\Services\Payment\CryptoIssuer::BUSY; @endphp
            <li role="option" data-value="{{ $code }}" aria-selected="{{ $code === $cyDefault ? 'true' : 'false' }}"
                @if($busy) aria-disabled="true" @endif tabindex="-1">
              <span class="cyx-opt-main">
                @include('account.partials.crypto-coin', ['code' => $spec['asset'], 'size' => 30])
                <span class="cyx-opt-t"><b dir="ltr">{{ $spec['asset'] }}</b><small dir="ltr">{{ $spec['network'] }}@if($code === 'USDT_BEP20') · <span dir="auto">{{ __('ui.cy_recommended') }}</span>@endif</small></span>
              </span>
              @if($busy)
                <span class="cyx-opt-busy">{{ __('ui.cy_busy') }}</span>
              @elseif(! empty($spec['estimate']))
                <span class="cyx-opt-est" dir="ltr">≈ {{ $spec['estimate'] }}</span>
              @endif
            </li>
          @endforeach
        </ul>
      </div>

      <div class="cyx-quote">
        <div><span>{{ __('ui.cy_invoice_amount') }}</span><b>{{ invoice_money($invoice->due(), $invoice->currency_code) }}</b></div>
        <div id="cy-est-row" @if(empty($cryptoAssets[$cyDefault]['estimate'])) hidden @endif>
          <span>{{ __('ui.cy_approx_label') }}</span>
          <b dir="ltr" id="cy-est">≈ {{ $cryptoAssets[$cyDefault]['estimate'] ?? '' }} {{ $cryptoAssets[$cyDefault]['asset'] }}</b>
        </div>
      </div>

      <p class="pm-note" id="cy-intro" data-tpl="{{ __('ui.cy_intro', ['min' => '__MIN__']) }}">{{ __('ui.cy_intro', ['min' => fa_num($cryptoAssets[$cyDefault]['window'])]) }}</p>
      <button type="submit" class="pnl-btn primary cyx-go">{{ __('ui.cy_get_address') }}</button>
    </form>
  @endif
</div>

<script>
(function () {
  var COPIED = @json(__('ui.inv_copied'));
  var faDigits = @json(app()->getLocale() === 'fa');

  // ── کپی ──
  document.querySelectorAll('.cyx-copy').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = b.dataset.copy || '';
      if (navigator.clipboard) navigator.clipboard.writeText(t).catch(function () {});
      var label = b.querySelector('span'), old = label ? label.textContent : '';
      b.classList.add('is-done'); if (label) label.textContent = COPIED;
      setTimeout(function () { b.classList.remove('is-done'); if (label) label.textContent = old; }, 1400);
    });
  });

  // ── فهرستِ کشوییِ انتخابِ ارز ──
  var native = document.getElementById('cy-asset'), wrap = document.getElementById('cy-select');
  if (native && wrap) {
    var trigger = wrap.querySelector('.cyx-trigger'), list = wrap.querySelector('.cyx-list');
    var opts = Array.prototype.slice.call(list.querySelectorAll('[role="option"]'));
    var est = document.getElementById('cy-est'), estRow = document.getElementById('cy-est-row');
    var intro = document.getElementById('cy-intro');
    function num(n) { return faDigits ? String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }) : String(n); }

    function pick(code, focus) {
      var o = list.querySelector('[data-value="' + code + '"]');
      if (!o || o.getAttribute('aria-disabled') === 'true') return;
      native.value = code;
      opts.forEach(function (x) { x.setAttribute('aria-selected', x === o ? 'true' : 'false'); });
      trigger.querySelector('[data-slot="main"]').innerHTML = o.querySelector('.cyx-opt-main').innerHTML;
      var sel = native.options[native.selectedIndex];
      if (est) { est.textContent = '≈ ' + (sel.dataset.est || '') + ' ' + sel.dataset.asset; estRow.hidden = !sel.dataset.est; }
      if (intro) intro.textContent = intro.dataset.tpl.replace('__MIN__', num(sel.dataset.window));
      if (focus) trigger.focus();
    }
    function open(v) {
      list.hidden = !v; trigger.setAttribute('aria-expanded', v ? 'true' : 'false'); wrap.classList.toggle('is-open', v);
      if (v) (list.querySelector('[aria-selected="true"]') || opts[0]).focus();
    }

    native.hidden = true; wrap.hidden = false; pick(native.value);
    trigger.addEventListener('click', function () { open(list.hidden); });
    opts.forEach(function (o) {
      o.addEventListener('click', function () { if (o.getAttribute('aria-disabled') !== 'true') { pick(o.dataset.value, true); open(false); } });
      o.addEventListener('keydown', function (e) {
        var i = opts.indexOf(o);
        if (e.key === 'ArrowDown') { e.preventDefault(); (opts[i + 1] || opts[0]).focus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); (opts[i - 1] || opts[opts.length - 1]).focus(); }
        else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); o.click(); }
        else if (e.key === 'Escape') { open(false); trigger.focus(); }
      });
    });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) open(false); });
  }

  // ── شمارشِ معکوس + وضعیتِ زنده ──
  var box = document.querySelector('.cyx-box');
  if (!box) return;

  var left = parseInt(box.dataset.left, 10) || 0, total = parseInt(box.dataset.total, 10) || 1;
  var clock = document.getElementById('cy-clock'), ring = document.getElementById('cy-ring');
  var stateT = document.getElementById('cy-state-t'), stateEl = document.getElementById('cy-state');
  var steps = document.getElementById('cy-steps'), expired = document.getElementById('cy-expired');

  function paint() {
    var m = Math.floor(left / 60), s = left % 60;
    clock.textContent = m + ':' + (s < 10 ? '0' : '') + s;
    ring.setAttribute('stroke-dashoffset', (163.36 * (1 - left / total)).toFixed(2));
    box.classList.toggle('is-late', left > 0 && left <= 300);
  }
  function step(name) {
    var on = true;
    steps.querySelectorAll('li').forEach(function (li) {
      li.classList.toggle('is-on', on);
      li.classList.toggle('is-now', li.dataset.step === name);
      if (li.dataset.step === name) on = false;
    });
  }
  function expire() {
    clearInterval(tick); clearInterval(poll);
    box.classList.add('is-expired'); expired.hidden = false; stateEl.hidden = true;
  }

  // تراکنشِ دیده‌شده با تمام‌شدنِ ساعتِ مرورگر منقضی نمی‌شود — حکم با سرور است.
  var seen = box.dataset.status === 'seen';
  step(seen ? 'seen' : 'pending');
  paint();
  if (left <= 0 && !seen) { expire(); return; }

  var tick = setInterval(function () { if (left > 0) { left--; paint(); } else if (!seen) { expire(); } }, 1000);
  var poll = setInterval(function () {
    fetch(box.dataset.statusUrl, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (typeof j.seconds_left === 'number') { left = j.seconds_left; paint(); }
        if (j.status === 'seen') { seen = true; step('seen'); stateT.textContent = stateEl.dataset.seen; }
        if (j.invoice_paid || j.status === 'confirmed') {
          step('confirmed'); clearInterval(poll); clearInterval(tick);
          setTimeout(function () { location.reload(); }, 900);
        } else if (j.status === 'expired' && !j.invoice_paid) { left = 0; expire(); }
      })
      .catch(function () { /* قطعیِ شبکه نباید صفحه را بشکند */ });
  }, 7000);
})();
</script>

<style>
.cyx .pm-pane-h{ margin-bottom:14px; }
.cyx-coin-ic{ flex:none; display:block; border-radius:50%; }
.cyx-coin-fallback{ display:grid; place-items:center; background:var(--surface); color:var(--text); font-weight:700; }

/* ── انتخابِ ارز ── */
.cyx-label{ display:block; font-size:12.5px; color:var(--muted); margin-bottom:8px; }
.cyx-native{ width:100%; padding:11px 13px; border-radius:12px; border:1px solid var(--line); background:var(--surface); color:var(--text); font:inherit; }
.cyx-select{ position:relative; }
.cyx-trigger{ width:100%; display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 14px;
  border-radius:14px; border:1px solid var(--line); background:var(--surface); color:var(--text); font:inherit; cursor:pointer;
  transition:border-color .18s, box-shadow .18s; }
.cyx-trigger:hover, .cyx-select.is-open .cyx-trigger{ border-color:var(--brand); box-shadow:0 0 0 3px color-mix(in srgb, var(--brand) 18%, transparent); }
.cyx-chev{ width:16px; height:16px; color:var(--muted); transition:transform .2s; }
.cyx-select.is-open .cyx-chev{ transform:rotate(180deg); }
.cyx-opt-main{ display:flex; align-items:center; gap:11px; min-width:0; }
.cyx-opt-t{ display:flex; flex-direction:column; align-items:flex-start; line-height:1.3; }
.cyx-opt-t b{ font-size:14px; }
.cyx-opt-t small{ font-size:11px; color:var(--muted); border:1px solid var(--line); border-radius:6px; padding:0 6px; margin-top:2px; }
.cyx-list{ position:absolute; inset-inline:0; top:calc(100% + 6px); z-index:20; margin:0; padding:6px; list-style:none;
  border-radius:14px; border:1px solid var(--line); background:var(--bg2, var(--surface)); box-shadow:0 18px 40px rgba(0,0,0,.35);
  animation:pmIn .16s ease; }
.cyx-list li{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:9px 10px; border-radius:10px; cursor:pointer; outline:0; }
.cyx-list li:hover, .cyx-list li:focus{ background:var(--surface-2); }
.cyx-list li[aria-selected="true"]{ background:color-mix(in srgb, var(--brand) 12%, transparent); }
.cyx-list li[aria-disabled="true"]{ opacity:.5; cursor:not-allowed; }
.cyx-opt-est{ font-size:12.5px; color:var(--muted); font-variant-numeric:tabular-nums; }
.cyx-opt-busy{ font-size:10.5px; color:var(--warn); border:1px solid currentColor; border-radius:20px; padding:2px 8px; }
.cyx-quote{ margin:14px 0 4px; border:1px solid var(--line); border-radius:12px; padding:4px 14px; background:var(--surface); }
.cyx-quote > div{ display:flex; justify-content:space-between; gap:12px; padding:9px 0; font-size:13px; }
.cyx-quote > div + div{ border-top:1px dashed var(--line); }
.cyx-quote span{ color:var(--muted); }
.cyx-quote b{ font-variant-numeric:tabular-nums; }
.cyx-go{ width:100%; justify-content:center; }

/* ── صفحهٔ پرداخت ── */
.cyx-box{ border:1px solid var(--line); border-radius:18px; padding:18px; background:var(--surface);
  box-shadow:0 1px 0 rgba(255,255,255,.03) inset; }
.cyx-head{ display:flex; align-items:center; justify-content:space-between; gap:14px; padding-bottom:14px; border-bottom:1px solid var(--line); }
.cyx-coin{ display:flex; align-items:center; gap:11px; }
.cyx-coin b{ display:block; font-size:16px; }
.cyx-net{ display:inline-block; margin-top:3px; font-size:11px; font-weight:700; letter-spacing:.3px; color:var(--brand);
  background:color-mix(in srgb, var(--brand) 13%, transparent); border-radius:6px; padding:1px 7px; }
.cyx-timer{ display:flex; align-items:center; gap:10px; }
.cyx-timer svg{ width:52px; height:52px; transform:rotate(-90deg); }
.cyx-ring-bg{ fill:none; stroke:var(--line); stroke-width:5; }
.cyx-ring{ fill:none; stroke:var(--brand); stroke-width:5; stroke-linecap:round; transition:stroke-dashoffset 1s linear, stroke .3s; }
.cyx-timer-t{ display:flex; flex-direction:column; }
.cyx-timer-t b{ font-size:20px; font-variant-numeric:tabular-nums; line-height:1.1; }
.cyx-timer-t small{ font-size:11px; color:var(--muted); }
.cyx-box.is-late .cyx-ring{ stroke:var(--warn); }
.cyx-box.is-late .cyx-timer-t b{ color:var(--warn); }

.cyx-body{ display:grid; grid-template-columns:auto 1fr; gap:18px; padding:16px 0 6px; align-items:start; }
.cyx-qr{ display:flex; flex-direction:column; align-items:center; gap:7px; }
.cyx-qr-img{ background:#fff; border-radius:14px; padding:8px; line-height:0; box-shadow:0 6px 18px rgba(0,0,0,.18); }
.cyx-qr-img svg{ width:156px; height:156px; display:block; }
.cyx-qr small{ font-size:11px; color:var(--muted); }
.cyx-fields{ display:flex; flex-direction:column; gap:12px; min-width:0; }
.cyx-field > span{ display:block; font-size:12px; color:var(--muted); margin-bottom:5px; }
.cyx-field > small{ display:block; font-size:11.5px; color:var(--muted); margin-top:5px; }
.cyx-val{ display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 12px;
  border:1px solid var(--line); border-radius:12px; background:var(--surface-2); }
.cyx-amt{ font-size:20px; font-variant-numeric:tabular-nums; overflow-wrap:anywhere; }
.cyx-amt em{ font-style:normal; font-size:13px; color:var(--muted); }
.cyx-addr{ font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:13px; overflow-wrap:anywhere; color:var(--text); }
.cyx-addr mark{ background:none; color:var(--brand); font-weight:700; }
.cyx-copy{ flex:none; display:inline-flex; align-items:center; gap:5px; border:1px solid var(--line); background:var(--surface);
  color:var(--text); border-radius:9px; padding:6px 10px; font:inherit; font-size:12px; cursor:pointer; transition:.15s; }
.cyx-copy .icon{ width:14px; height:14px; }
.cyx-copy:hover{ border-color:var(--brand); color:var(--brand); }
.cyx-copy.is-done{ border-color:#22c55e; color:#22c55e; }
.cyx-warn{ display:flex; gap:8px; align-items:flex-start; font-size:12px; line-height:1.9; color:var(--warn);
  border:1px solid var(--warn-line, rgba(251,191,36,.3)); background:rgba(251,191,36,.06); border-radius:11px; padding:9px 11px; }
.cyx-warn .icon{ width:15px; height:15px; flex:none; margin-top:4px; }

.cyx-steps{ display:flex; list-style:none; margin:14px 0 0; padding:0; gap:6px; }
.cyx-steps li{ flex:1; display:flex; flex-direction:column; gap:7px; font-size:11.5px; color:var(--muted); }
.cyx-steps li i{ height:4px; border-radius:4px; background:var(--line); transition:background .3s; }
.cyx-steps li.is-on{ color:var(--text); }
.cyx-steps li.is-on i{ background:var(--brand); }
.cyx-steps li.is-now i{ background:linear-gradient(90deg, var(--brand), color-mix(in srgb, var(--brand) 35%, transparent), var(--brand));
  background-size:200% 100%; animation:cyxFlow 1.6s linear infinite; }
.cyx [hidden]{ display:none !important; }
.cyx-state{ display:flex; align-items:center; gap:9px; margin:12px 0 0; font-size:12.5px; color:var(--muted); }
.cyx-pulse{ width:9px; height:9px; border-radius:50%; background:var(--brand); flex:none; animation:cyxPulse 1.6s ease-out infinite; }
.cyx-tail{ margin:10px 0 0; }

.cyx-expired{ margin:0 0 14px; text-align:center; border:1px dashed var(--line); border-radius:14px; padding:16px; }
.cyx-expired b{ display:block; font-size:14px; margin-bottom:6px; }
.cyx-expired p{ font-size:12.5px; color:var(--muted); margin:0 0 12px; }
.cyx-box.is-expired .cyx-body, .cyx-box.is-expired .cyx-steps{ opacity:.35; filter:grayscale(1); pointer-events:none; }
.cyx-box.is-expired .cyx-ring{ stroke:var(--danger, #ef4444); }

@keyframes cyxPulse{ 0%{ box-shadow:0 0 0 0 color-mix(in srgb, var(--brand) 55%, transparent); } 100%{ box-shadow:0 0 0 9px transparent; } }
@keyframes cyxFlow{ to{ background-position:-200% 0; } }
@media (prefers-reduced-motion: reduce){ .cyx-pulse, .cyx-steps li.is-now i{ animation:none; } .cyx-ring{ transition:none; } }
@media (max-width:560px){
  .cyx-body{ grid-template-columns:1fr; }
  .cyx-qr-img svg{ width:180px; height:180px; }
  .cyx-head{ flex-wrap:wrap; }
}
</style>
