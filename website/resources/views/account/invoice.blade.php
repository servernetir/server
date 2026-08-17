@extends('panel.layout')
@section('title', __('ui.inv_invoice').' '.$invoice->number.' — '.__('ui.inv_brand'))

@section('panel')

<div class="pnl-head">
  <div>
    <h1>{{ __('ui.inv_invoice') }} <span dir="ltr">{{ $invoice->number }}</span></h1>
    <p>{{ sdate($invoice->issued_at ?? $invoice->created_at) }}</p>
    {{-- 🔴 مهلتِ پرداخت باید **دیده شود**.
         فاکتوری که بی‌خبر لغو شود، از فاکتورِ معلق بدتر است: مشتری برمی‌گردد،
         چیزی پیدا نمی‌کند و فکر می‌کند سامانه سفارشش را گم کرده. تاریخ فقط
         وقتی نشان داده می‌شود که واقعاً معنا دارد — پرداخت‌نشده و مهلت‌دار. --}}
    @if($invoice->status === 'unpaid' && $invoice->paid == 0 && $invoice->due_at)
      <p class="pnl-sub" @style(['color:var(--danger)' => $invoice->due_at->isPast()])>
        @if($invoice->due_at->isPast())
          {{ __('ui.inv_due_passed') }}
        @else
          {{ __('ui.inv_due_until') }} {{ sdate($invoice->due_at, true) }}
        @endif
      </p>
    @endif
  </div>
  <div class="pnl-acts">
    <a class="pnl-btn {{ $invoice->status === 'paid' ? 'primary' : '' }}" href="{{ lroute('account.invoice.print', $invoice) }}" target="_blank" rel="noopener">
      <svg class="icon"><use href="#i-file"/></svg>{{ $invoice->status === 'paid' ? __('ui.inv_download_receipt') : __('ui.inv_download_proforma') }}
    </a>
    <a class="pnl-btn" href="{{ lroute('account.invoices') }}">
      <svg class="icon"><use href="#i-arrow"/></svg>{{ __('ui.inv_back') }}
    </a>
    @if($invoice->status !== 'paid' && $invoice->status !== 'canceled' && $invoice->status !== 'void' && $invoice->paid == 0)
      <form method="POST" action="{{ lroute('account.invoice.cancel', $invoice) }}" style="display:inline"
            data-confirm="{{ __('ui.inv_cancel_confirm') }}" data-confirm-danger data-confirm-title="{{ __('ui.inv_cancel') }}" data-confirm-ok="{{ __('ui.inv_cancel_ok') }}">
        @csrf
        <button type="submit" class="pnl-btn" style="color:var(--danger);border-color:var(--danger-line)">
          <svg class="icon"><use href="#i-x"/></svg>{{ __('ui.inv_cancel') }}
        </button>
      </form>
    @endif
  </div>
</div>

@if($errors->any())
  <div class="pnl-sec" style="border-color:var(--danger-line)">
    <div class="pnl-sec-b" style="color:var(--danger);font-size:13.5px;line-height:2">
      @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
  </div>
@endif

<section class="pnl-sec">
  <div class="pnl-sec-h">
    <h2>{{ __('ui.inv_items') }}</h2>
    @if($invoice->status === 'paid')
      <span class="pnl-pill ok">{{ __('ui.inv_paid_pill') }}</span>
    @else
      <span class="pnl-pill warn">{{ __('ui.inv_unpaid_pill') }}</span>
    @endif
  </div>
  <div class="pnl-sec-b flush">
    <div class="pnl-tw">
      <table class="pnl-table">
        <thead>
          <tr><th>{{ __('ui.inv_col_desc') }}</th><th class="num">{{ __('ui.inv_col_qty') }}</th><th class="num">{{ __('ui.inv_col_unit') }}</th><th class="num">{{ __('ui.inv_col_linetotal') }}</th></tr>
        </thead>
        <tbody>
          @foreach($invoice->items as $item)
            <tr>
              <td>{{ $item->title }}</td>
              <td class="num pnl-num">{{ fa_num($item->quantity) }}</td>
              <td class="num pnl-num">{{ invoice_money($item->unit_price, $invoice->currency_code) }}</td>
              <td class="num pnl-num">{{ invoice_money($item->line_total, $invoice->currency_code) }}</td>
            </tr>
          @endforeach
          <tr><td colspan="3" style="color:var(--muted)">{{ __('ui.inv_subtotal') }}</td>
              <td class="num pnl-num">{{ invoice_money($invoice->subtotal, $invoice->currency_code) }}</td></tr>
          @if($invoice->tax > 0)
            <tr><td colspan="3" style="color:var(--muted)">{{ __('ui.inv_tax') }}</td>
                <td class="num pnl-num">{{ invoice_money($invoice->tax, $invoice->currency_code) }}</td></tr>
          @endif
          <tr><td colspan="3"><b>{{ __('ui.inv_payable') }}</b></td>
              <td class="num pnl-num"><b>{{ invoice_money($invoice->total, $invoice->currency_code) }}</b></td></tr>
          @if($invoice->paid > 0 && $invoice->due() > 0)
            <tr><td colspan="3" style="color:var(--muted)">{{ __('ui.inv_paid_so_far') }}</td>
                <td class="num pnl-num">{{ invoice_money($invoice->paid, $invoice->currency_code) }}</td></tr>
            <tr><td colspan="3"><b>{{ __('ui.inv_remaining') }}</b></td>
                <td class="num pnl-num"><b>{{ invoice_money($invoice->due(), $invoice->currency_code) }}</b></td></tr>
          @endif
        </tbody>
      </table>
    </div>
  </div>
</section>

@if($invoice->isPayable())
@if(session('ok'))
  <div class="pnl-sec" style="border-color:var(--ok-line)">
    <div class="pnl-sec-b" style="color:var(--ok);font-size:13.5px;line-height:2">{{ session('ok') }}</div>
  </div>
@endif

<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>{{ __('ui.inv_pay_heading') }}</h2>
    <span class="pnl-num" style="font-size:15px">{{ invoice_money($invoice->due(), $invoice->currency_code) }}</span>
  </div>
  <div class="pnl-sec-b">

    {{-- گام ۱: انتخاب روش (کارتی) --}}
    <p class="pm-lead">{{ __('ui.inv_choose_method') }}</p>
    <div class="pm-grid">
      @if(app()->getLocale() === 'fa')
        @if(isset($gateways['zarinpal']))
          <label class="pm-card" data-m="zarinpal">
            <input type="radio" name="pm" value="zarinpal" hidden>
            <span class="pm-badge zp"><svg class="icon"><use href="#i-coins"/></svg></span>
            <span class="pm-tt"><b>{{ __('ui.inv_zp_title') }}</b><small>{{ __('ui.inv_zp_sub') }}</small></span>
            <span class="pm-tick"><svg class="icon"><use href="#i-check"/></svg></span>
          </label>
        @endif
        @if(isset($gateways['bale']))
          <label class="pm-card" data-m="bale">
            <input type="radio" name="pm" value="bale" hidden>
            <span class="pm-badge bl"><svg class="icon"><use href="#i-bot"/></svg></span>
            <span class="pm-tt"><b>{{ __('ui.inv_bale_title') }}</b><small>{{ __('ui.inv_bale_sub') }}</small></span>
            <span class="pm-tick"><svg class="icon"><use href="#i-check"/></svg></span>
          </label>
        @endif
        <label class="pm-card" data-m="bank">
          <input type="radio" name="pm" value="bank" hidden>
          <span class="pm-badge bk"><svg class="icon"><use href="#i-db"/></svg></span>
          <span class="pm-tt"><b>{{ __('ui.inv_bank_title') }}</b><small>{{ __('ui.inv_bank_sub') }}</small></span>
          <span class="pm-tick"><svg class="icon"><use href="#i-check"/></svg></span>
        </label>
      @endif
      {{-- حوالهٔ ارزی و رمزارز — هر مقصدی که مدیر در /admin/payment-accounts
           فعال کرده. هر دو **آفلاین**اند: مشتری می‌فرستد، شناسه ثبت می‌کند،
           مدیر تأیید می‌کند. تا تأیید نشود هیچ سرویسی فعال نمی‌شود.

           ⚠️ اگر هیچ حسابی ثبت نشده باشد، به‌جای کارتِ خرابِ بی‌مقصد، همان
           «به‌زودی» را نشان می‌دهیم — گزینه‌ای که پول را به ناکجا بفرستد از
           نبودِ گزینه بدتر است. --}}
      @forelse($offline as $acc)
        <label class="pm-card" data-m="off{{ $acc->id }}">
          <input type="radio" name="pm" value="off{{ $acc->id }}" hidden>
          <span class="pm-badge {{ $acc->isCrypto() ? 'cy' : 'bk' }}">
            <svg class="icon"><use href="#i-{{ $acc->isCrypto() ? 'coins' : 'db' }}"/></svg>
          </span>
          <span class="pm-tt">
            <b>{{ $acc->isCrypto() ? __('ui.inv_crypto') : __('ui.inv_wire_title') }}</b>
            <small dir="ltr">{{ $acc->displayLabel() }}</small>
          </span>
          <span class="pm-tick"><svg class="icon"><use href="#i-check"/></svg></span>
        </label>
      @empty
        @if(app()->getLocale() !== 'fa')
          <label class="pm-card is-off" data-m="soon" title="{{ __('ui.inv_soon') }}">
            <input type="radio" name="pm" value="soon" hidden disabled>
            <span class="pm-badge cr"><svg class="icon"><use href="#i-coins"/></svg></span>
            <span class="pm-tt"><b>{{ __('ui.inv_wire_title') }}</b><small>{{ __('ui.inv_soon_activate') }}</small></span>
            <span class="pm-soon">{{ __('ui.inv_soon') }}</span>
          </label>
        @endif
      @endforelse

      {{-- رمزارز — درگاهِ خودمان.
           ⚠️ دارایی‌ای که **قیمتش را نداریم** اصلاً این‌جا نیست؛ حدس زدنِ نرخ
           ممنوع است. ولی «همهٔ آدرس‌ها مشغول‌اند» فرق دارد و باید **گفته** شود،
           نه اینکه روش پرداخت بی‌صدا غیب شود — کارفرما دقیقاً همان سکوت را دید
           و فکر کرد قابلیت اصلاً کار نمی‌کند. --}}
      @foreach($cryptoAssets as $code => $spec)
        @if($spec['state'] === \App\Services\Payment\CryptoIssuer::BUSY)
          <label class="pm-card is-off" data-m="cy{{ $code }}" title="{{ __('ui.cy_busy_hint') }}">
            <input type="radio" name="pm" value="cy{{ $code }}" hidden disabled>
            <span class="pm-badge cr"><svg class="icon"><use href="#i-coins"/></svg></span>
            <span class="pm-tt"><b>{{ __('ui.inv_crypto') }}</b><small>{{ __('ui.cy_busy_hint') }}</small></span>
            <span class="pm-soon">{{ __('ui.cy_busy') }}</span>
          </label>
        @else
          <label class="pm-card" data-m="cy{{ $code }}">
            <input type="radio" name="pm" value="cy{{ $code }}" hidden
                   @if($cryptoOpen && $cryptoOpen->asset === $code) checked @endif>
            <span class="pm-badge cy"><svg class="icon"><use href="#i-coins"/></svg></span>
            <span class="pm-tt"><b>{{ __('ui.inv_crypto') }}</b><small dir="ltr">{{ $spec['label'] }}</small></span>
            <span class="pm-tick"><svg class="icon"><use href="#i-check"/></svg></span>
          </label>
        @endif
      @endforeach
    </div>

    {{-- گام ۲: جزئیاتِ روشِ انتخاب‌شده (پیش‌فرض پنهان) --}}
    <div class="pm-hint" id="pm-hint" @if($cryptoOpen) hidden @endif>
      <svg class="icon"><use href="#i-info"/></svg> {{ __('ui.inv_pm_hint') }}
    </div>

    @if(app()->getLocale() === 'fa')
      @if(isset($gateways['zarinpal']))
        <form class="pm-pane" id="pane-zarinpal" method="POST" action="{{ lroute('account.invoice.pay', $invoice) }}" hidden>
          @csrf<input type="hidden" name="gateway" value="zarinpal">
          <div class="pm-pane-h"><b>{{ __('ui.inv_zp_pane_title') }}</b></div>
          <p class="pm-note">{{ __('ui.inv_zp_note') }}</p>
          <button type="submit" class="pnl-btn primary" style="justify-content:center">{{ __('ui.inv_pay_btn') }} {{ invoice_money($invoice->due(), $invoice->currency_code) }}</button>
        </form>
      @endif

      @if(isset($gateways['bale']))
        <form class="pm-pane" id="pane-bale" method="POST" action="{{ lroute('account.invoice.pay', $invoice) }}" hidden>
          @csrf<input type="hidden" name="gateway" value="bale">
          <div class="pm-pane-h"><b>{{ __('ui.inv_bale_pane_title') }}</b></div>
          <p class="pm-note">{{ __('ui.inv_bale_note') }}</p>
          @php $baleUser = config('services.bale.username'); @endphp
          @if($baleUser)
            <div class="bale-hint">
              {!! __('ui.inv_bale_hint') !!}
              <a href="https://ble.ir/{{ $baleUser }}" target="_blank" rel="noopener" class="bale-bot-btn">
                <svg class="icon"><use href="#i-bot"/></svg> {{ __('ui.inv_bale_bot_link') }}
              </a>
            </div>
          @endif
          <button type="submit" class="pnl-btn primary" style="justify-content:center">{{ __('ui.inv_bale_continue') }}</button>
        </form>
      @endif

      <div class="pm-pane" id="pane-bank" hidden>
        <div class="pm-pane-h"><b>{{ __('ui.inv_bank_pane_title') }}</b></div>
        @if($pendingBank)
          <div class="pm-note" style="color:var(--warn)">
            {!! __('ui.inv_bank_pending', ['ref' => '<b dir="ltr">'.e($pendingBank->reference).'</b>']) !!}
          </div>
        @elseif($bank === null)
          <div class="pm-note">{{ __('ui.inv_bank_unavailable') }}</div>
        @else
          <div class="bank-box">
            @if($bank['holder'])<div><span>{{ __('ui.inv_bank_holder') }}</span><b>{{ $bank['holder'] }}</b></div>@endif
            @if($bank['bank'])<div><span>{{ __('ui.inv_bank_name') }}</span><b>{{ $bank['bank'] }}</b></div>@endif
            @if($bank['card'])<div><span>{{ __('ui.inv_bank_card') }}</span><b dir="ltr" class="copyable">{{ $bank['card'] }}</b></div>@endif
            @if($bank['sheba'])<div><span>{{ __('ui.inv_bank_sheba') }}</span><b dir="ltr" class="copyable">IR{{ ltrim($bank['sheba'],'IRir') }}</b></div>@endif
            @if($bank['account'])<div><span>{{ __('ui.inv_bank_account') }}</span><b dir="ltr" class="copyable">{{ $bank['account'] }}</b></div>@endif
            @if($bank['note'])<div><span>{{ __('ui.inv_bank_note') }}</span><b>{{ $bank['note'] }}</b></div>@endif
          </div>
          <p class="pm-note">
            {!! __('ui.inv_bank_deposit_instr', ['amount' => '<b class="pnl-num">'.invoice_money($invoice->due(), $invoice->currency_code).'</b>']) !!}
          </p>
          <form method="POST" action="{{ lroute('account.invoice.bank', $invoice) }}" style="display:flex;flex-direction:column;gap:10px">
            @csrf
            <input type="text" name="reference" required maxlength="120" dir="ltr" placeholder="{{ __('ui.inv_bank_ref_ph') }}" class="bank-input">
            <input type="text" name="paid_from" maxlength="120" dir="ltr" placeholder="{{ __('ui.inv_bank_from_ph') }}" class="bank-input">
            <button type="submit" class="pnl-btn" style="justify-content:center">{{ __('ui.inv_bank_submit') }}</button>
          </form>
        @endif
      </div>
    @endif

    {{-- پنلِ هر مقصدِ ارزی/رمزارزی --}}
    @foreach($offline as $acc)
      <div class="pm-pane" id="pane-off{{ $acc->id }}" hidden>
        <div class="pm-pane-h"><b>{{ $acc->isCrypto() ? __('ui.inv_crypto_pane') : __('ui.inv_wire_pane') }}</b></div>

        @if($pendingBank)
          <div class="pm-note" style="color:var(--warn)">
            {!! __('ui.inv_bank_pending', ['ref' => '<b dir="ltr">'.e($pendingBank->reference).'</b>']) !!}
          </div>
        @else
          <div class="bank-box">
            @if($acc->isCrypto())
              {{-- 🔴 شبکه **بالای** آدرس و برجسته: انتقالِ روی شبکهٔ اشتباه
                   برگشت‌ناپذیر است و کاربر معمولاً آدرس را کپی می‌کند و می‌رود. --}}
              <div><span>{{ __('ui.inv_cy_asset') }}</span><b dir="ltr">{{ strtoupper($acc->currency_code) }}</b></div>
              <div><span>{{ __('ui.inv_cy_network') }}</span><b dir="ltr">{{ $acc->network }}</b></div>
              <div><span>{{ __('ui.inv_cy_address') }}</span><b dir="ltr" class="copyable">{{ $acc->address }}</b></div>
            @else
              @if($acc->holder)<div><span>{{ __('ui.inv_bank_holder') }}</span><b dir="ltr">{{ $acc->holder }}</b></div>@endif
              @if($acc->bank_name)<div><span>{{ __('ui.inv_bank_name') }}</span><b dir="ltr">{{ $acc->bank_name }}</b></div>@endif
              @if($acc->iban)<div><span>IBAN</span><b dir="ltr" class="copyable">{{ $acc->iban }}</b></div>@endif
              @if($acc->swift)<div><span>SWIFT / BIC</span><b dir="ltr" class="copyable">{{ $acc->swift }}</b></div>@endif
              @if($acc->account_no)<div><span>{{ __('ui.inv_bank_account') }}</span><b dir="ltr" class="copyable">{{ $acc->account_no }}</b></div>@endif
              @if($acc->country)<div><span>{{ __('ui.inv_wire_country') }}</span><b dir="ltr">{{ $acc->country }}</b></div>@endif
            @endif
            <div><span>{{ __('ui.inv_wire_currency') }}</span><b dir="ltr">{{ strtoupper($acc->currency_code) }}</b></div>
            @if($acc->note)<div><span>{{ __('ui.inv_bank_note') }}</span><b>{{ $acc->note }}</b></div>@endif
          </div>

          @if($acc->isCrypto())
            <p class="pm-note" style="color:var(--warn)">{{ __('ui.inv_cy_warn') }}</p>
          @endif

          <p class="pm-note">
            {!! __('ui.inv_bank_deposit_instr', ['amount' => '<b class="pnl-num">'.invoice_money($invoice->due(), $invoice->currency_code).'</b>']) !!}
          </p>

          <form method="POST" action="{{ lroute('account.invoice.bank', $invoice) }}" style="display:flex;flex-direction:column;gap:10px">
            @csrf
            <input type="hidden" name="payment_account_id" value="{{ $acc->id }}">
            <input type="text" name="reference" required maxlength="120" dir="ltr" class="bank-input"
                   placeholder="{{ $acc->isCrypto() ? __('ui.inv_cy_txid_ph') : __('ui.inv_wire_ref_ph') }}">
            {{-- مبلغِ فرستاده‌شده جداست چون می‌تواند با ارزِ فاکتور فرق کند --}}
            <input type="text" name="sent_amount" inputmode="decimal" dir="ltr" class="bank-input"
                   placeholder="{{ __('ui.inv_wire_amount_ph', ['cur' => strtoupper($acc->currency_code)]) }}">
            <input type="text" name="paid_from" maxlength="120" dir="ltr" class="bank-input"
                   placeholder="{{ $acc->isCrypto() ? __('ui.inv_cy_from_ph') : __('ui.inv_wire_from_ph') }}">
            <button type="submit" class="pnl-btn" style="justify-content:center">{{ __('ui.inv_bank_submit') }}</button>
          </form>
        @endif
      </div>
    @endforeach

    {{-- ═══ رمزارز: دستور پرداخت ═══
         ⚠️ دارایی «مشغول» پنل ندارد، چون کارتش هم غیرفعال است و چیزی برای
            انجام‌دادن نیست؛ توضیحش روی خودِ کارت است. --}}
    @foreach($cryptoAssets as $code => $spec)
      @continue($spec['state'] === \App\Services\Payment\CryptoIssuer::BUSY)
      @php $cyIsOpen = $cryptoOpen && $cryptoOpen->asset === $code; @endphp
      {{-- ⚠️ پنلِ پرداختِ باز **باز** باز می‌شود. بعد از «دریافت آدرس» مشتری به
           همین صفحه برمی‌گردد؛ اگر باز هم باید روی کارت کلیک کند تا آدرس را
           ببیند، از دیدِ او هیچ اتفاقی نیفتاده است. --}}
      <div class="pm-pane" id="pane-cy{{ $code }}" @unless($cyIsOpen) hidden @endunless>
        <div class="pm-pane-h"><b>{{ __('ui.inv_crypto_pane') }} — <span dir="ltr">{{ $spec['label'] }}</span></b></div>

        @if($cyIsOpen)
          @php $cy = $cryptoOpen; @endphp
          <div class="cy-box" id="cy-{{ $cy->id }}"
               data-status-url="{{ lroute('account.invoice.crypto.status', $invoice) }}"
               data-left="{{ $cy->secondsLeft() }}">

            {{-- ⚠️ شبکه **بالای** آدرس و برجسته: انتقال روی شبکهٔ اشتباه
                 برگشت‌ناپذیر است و کاربر معمولاً آدرس را کپی می‌کند و می‌رود. --}}
            <div class="cy-row"><span>{{ __('ui.inv_cy_network') }}</span><b dir="ltr">{{ $cy->network }}</b></div>
            <div class="cy-row"><span>{{ __('ui.cy_send_exactly') }}</span>
              <b dir="ltr" class="copyable cy-amt">{{ $cy->amountHuman() }} {{ $cy->asset }}</b></div>
            <div class="cy-row cy-addr"><span>{{ __('ui.inv_cy_address') }}</span>
              <b dir="ltr" class="copyable">{{ $cy->address }}</b></div>

            <p class="cy-timer">{{ __('ui.cy_time_left') }} <b id="cy-clock">—</b></p>
            <p class="cy-state" id="cy-state">{{ __('ui.cy_waiting') }}</p>
            <p class="pm-note" style="color:var(--warn);margin-top:12px">{{ __('ui.inv_cy_warn') }}</p>
            <p class="pm-note">{{ __('ui.cy_verify_tail') }}</p>
          </div>
        @else
          <p class="pm-note">{{ __('ui.cy_intro', ['min' => fa_num($spec['window'])]) }}</p>
          <form method="POST" action="{{ lroute('account.invoice.crypto', $invoice) }}">
            @csrf<input type="hidden" name="asset" value="{{ $code }}">
            <button type="submit" class="pnl-btn primary" style="justify-content:center">{{ __('ui.cy_get_address') }}</button>
          </form>
        @endif
      </div>
    @endforeach

  </div>
</section>

@if($cryptoOpen)
<script>
/* شمارش معکوس + تشخیصِ خودکارِ واریز.
   ⚠️ صفحه **خودش تصمیم نمی‌گیرد** پول رسیده یا نه — فقط وضعیتی را که سرور
      می‌گوید نشان می‌دهد. حکم مالِ کرونی است که زنجیره را می‌خواند. */
(function () {
  var box = document.querySelector('.cy-box');
  if (!box) return;

  var left = parseInt(box.dataset.left, 10) || 0;
  var clock = document.getElementById('cy-clock');
  var state = document.getElementById('cy-state');

  function paint() {
    var m = Math.floor(left / 60), s = left % 60;
    clock.textContent = m + ':' + (s < 10 ? '0' : '') + s;
  }

  var tick = setInterval(function () {
    if (left > 0) { left--; paint(); }
    else { clearInterval(tick); clearInterval(poll); state.textContent = clock.dataset.expired || 'expired'; }
  }, 1000);
  paint();

  var poll = setInterval(function () {
    fetch(box.dataset.statusUrl, {headers: {'Accept': 'application/json'}})
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (j.status === 'seen') { state.textContent = state.dataset.seen || state.textContent; }
        if (j.invoice_paid || j.status === 'confirmed') {
          clearInterval(poll); clearInterval(tick);
          location.reload();
        }
      })
      .catch(function () { /* قطعیِ شبکه نباید صفحه را بشکند */ });
  }, 7000);
})();
</script>
@endif

<style>
.cy-box{ border:1px solid var(--line); border-radius:14px; padding:16px; background:var(--surface-2); }
.cy-row{ display:flex; justify-content:space-between; gap:14px; padding:9px 0; border-bottom:1px dashed var(--line); font-size:13px; }
.cy-row:last-of-type{ border-bottom:0; }
.cy-row span{ color:var(--muted); flex:none; }
.cy-row b{ overflow-wrap:anywhere; text-align:end; }
.cy-addr b{ font-family:ui-monospace,monospace; font-size:13.5px; }
.cy-amt{ font-size:16px; }
.cy-timer{ margin:14px 0 4px; font-size:13px; color:var(--muted); }
.cy-timer b{ font-size:17px; color:var(--text); font-variant-numeric:tabular-nums; }
.cy-state{ margin:0; font-size:12.5px; color:var(--muted); }
.pm-lead{ font-size:13px; color:var(--muted); margin-bottom:12px; }
.pm-grid{ display:grid; grid-template-columns:repeat(2,1fr); gap:12px; }
.pm-card{ position:relative; display:flex; align-items:center; gap:12px; cursor:pointer;
  border:1.5px solid var(--line); border-radius:15px; padding:14px 15px; background:var(--surface-2);
  transition:border-color .18s, background .18s, transform .12s, box-shadow .18s; }
.pm-card:hover:not(.is-off){ transform:translateY(-1px); border-color:var(--line-2); }
.pm-card:has(input:checked){ border-color:var(--brand); background:rgba(34,211,238,.07);
  box-shadow:0 6px 20px rgba(34,211,238,.10); }
.pm-card.is-off{ cursor:not-allowed; opacity:.55; }
.pm-badge{ flex:none; width:42px; height:42px; border-radius:12px; display:grid; place-items:center; }
.pm-badge .icon{ width:20px; height:20px; color:#fff; }
.pm-badge.zp{ background:linear-gradient(135deg,#f4b740,#e08a1e); }
.pm-badge.bl{ background:linear-gradient(135deg,#22c55e,#15a34a); }
.pm-badge.bk{ background:linear-gradient(135deg,#38bdf8,#2563eb); }
.pm-badge.cr{ background:linear-gradient(135deg,#94a3b8,#64748b); }
.pm-badge.cy{ background:linear-gradient(135deg,#26a17b,#1a7f5e); }   /* تتر */
.pm-tt{ display:flex; flex-direction:column; gap:2px; min-width:0; }
.pm-tt b{ font-size:13.5px; color:var(--text); }
.pm-tt small{ font-size:11.5px; color:var(--muted); }
.pm-tick{ margin-inline-start:auto; width:22px; height:22px; border-radius:50%; border:1.5px solid var(--line);
  display:grid; place-items:center; flex:none; transition:.18s; }
.pm-tick .icon{ width:13px; height:13px; color:transparent; }
.pm-card:has(input:checked) .pm-tick{ border-color:var(--brand); background:var(--brand); }
.pm-card:has(input:checked) .pm-tick .icon{ color:#04121a; }
.pm-soon{ margin-inline-start:auto; font-size:10.5px; color:var(--muted); border:1px solid var(--line);
  border-radius:20px; padding:3px 9px; flex:none; }
.pm-hint{ display:flex; align-items:center; gap:8px; margin-top:16px; padding:13px 15px;
  border:1px dashed var(--line); border-radius:12px; color:var(--muted); font-size:12.5px; }
.pm-hint .icon{ width:16px; height:16px; flex:none; }
.pm-pane{ margin-top:16px; border:1px solid var(--line); border-radius:15px; padding:16px; background:var(--surface-2);
  animation:pmIn .22s ease; }
.pm-pane-h{ font-size:14px; margin-bottom:10px; }
.pm-note{ font-size:12.5px; color:var(--muted); line-height:2; margin:0 0 12px; }
.bale-hint{ font-size:12.5px; color:var(--muted); line-height:2; margin:0 0 14px; padding:12px 14px;
  border:1px solid var(--warn-line,rgba(251,191,36,.3)); border-radius:12px; background:rgba(251,191,36,.06); }
.bale-hint b{ color:var(--text); }
.bale-bot-btn{ display:inline-flex; align-items:center; gap:8px; margin-top:10px; text-decoration:none;
  background:linear-gradient(135deg,#22c55e,#15a34a); color:#fff; font-size:13px; font-weight:700;
  border-radius:11px; padding:10px 16px; }
.bale-bot-btn .icon{ width:17px; height:17px; }
@keyframes pmIn{ from{ opacity:0; transform:translateY(6px) } to{ opacity:1; transform:none } }
.bank-box{ display:grid; gap:8px; background:var(--surface); border:1px solid var(--line); border-radius:12px; padding:13px 15px; margin-bottom:4px; }
.bank-box > div{ display:flex; justify-content:space-between; gap:12px; font-size:13px; }
.bank-box span{ color:var(--muted); }
.bank-box b{ color:var(--text); }
.bank-input{ background:var(--surface); border:1px solid var(--line); border-radius:10px; padding:10px 13px; font:inherit; font-size:13px; color:var(--text); }
.copyable{ cursor:copy; }
@media(max-width:560px){ .pm-grid{ grid-template-columns:1fr } }
</style>
<script>
(function(){
  var COPIED = @json(__('ui.inv_copied').' ✓');
  var cards = document.querySelectorAll('.pm-card input'), hint = document.getElementById('pm-hint');
  cards.forEach(function(r){
    r.addEventListener('change', function(){
      document.querySelectorAll('.pm-pane').forEach(function(p){ p.hidden = true; });
      if(hint) hint.hidden = true;
      var pane = document.getElementById('pane-'+this.value);
      if(pane){ pane.hidden = false; pane.scrollIntoView({behavior:'smooth', block:'nearest'}); }
    });
  });
  document.querySelectorAll('.copyable').forEach(function(el){
    el.addEventListener('click', function(){
      var t=(this.textContent||'').replace(/\s/g,'');
      if(navigator.clipboard) navigator.clipboard.writeText(t);
      var o=this.textContent; this.textContent=COPIED; var s=this;
      setTimeout(function(){ s.textContent=o; }, 1200);
    });
  });
})();
</script>
@endif

@if($invoice->payments->isNotEmpty())
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>{{ __('ui.inv_attempts') }}</h2></div>
  <div class="pnl-sec-b flush">
    <div class="pnl-tw">
      <table class="pnl-table">
        <thead><tr><th>{{ __('ui.inv_col_date') }}</th><th>{{ __('ui.inv_col_gateway') }}</th><th>{{ __('ui.inv_col_status') }}</th><th>{{ __('ui.inv_col_ref') }}</th><th class="num">{{ __('ui.inv_col_amount') }}</th></tr></thead>
        <tbody>
          @foreach($invoice->payments->sortByDesc('id') as $p)
            <tr>
              <td>{{ stime($p->created_at) }}</td>
              <td>{{ $p->gateway }}</td>
              <td>
                @if($p->status === 'paid')<span class="pnl-pill ok">{{ __('ui.inv_pay_success') }}</span>
                @elseif($p->status === 'canceled')<span class="pnl-pill mute">{{ __('ui.inv_pay_canceled') }}</span>
                @elseif($p->status === 'failed')<span class="pnl-pill danger">{{ __('ui.inv_pay_failed') }}</span>
                @else<span class="pnl-pill warn">{{ __('ui.inv_pay_incomplete') }}</span>@endif
              </td>
              <td dir="ltr" style="font-size:12px">{{ $p->ref_id ?: '—' }}</td>
              <td class="num pnl-num">{{ invoice_money($p->amount, $invoice->currency_code) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
</section>
@endif

@endsection
