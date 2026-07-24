@extends('panel.layout')
@section('title', 'فاکتور '.$invoice->number.' — سرورنت')

@section('panel')

<div class="pnl-head">
  <div>
    <h1>فاکتور <span dir="ltr">{{ $invoice->number }}</span></h1>
    <p>{{ sdate($invoice->issued_at ?? $invoice->created_at) }}</p>
  </div>
  <div class="pnl-acts">
    <a class="pnl-btn {{ $invoice->status === 'paid' ? 'primary' : '' }}" href="{{ lroute('account.invoice.print', $invoice) }}" target="_blank" rel="noopener">
      <svg class="icon"><use href="#i-file"/></svg>{{ $invoice->status === 'paid' ? 'دانلود رسید (PDF)' : 'دانلود پیش‌فاکتور (PDF)' }}
    </a>
    <a class="pnl-btn" href="{{ lroute('account.invoices') }}">
      <svg class="icon"><use href="#i-arrow"/></svg>بازگشت
    </a>
    @if($invoice->status !== 'paid' && $invoice->status !== 'canceled' && $invoice->status !== 'void' && $invoice->paid == 0)
      <form method="POST" action="{{ lroute('account.invoice.cancel', $invoice) }}" style="display:inline"
            onsubmit="return confirm('این فاکتورِ در انتظار پرداخت لغو شود؟ اگر مربوط به سرویس باشد، آن سرویس هم غیرفعال می‌شود.')">
        @csrf
        <button type="submit" class="pnl-btn" style="color:var(--danger);border-color:var(--danger-line)">
          <svg class="icon"><use href="#i-x"/></svg>لغو فاکتور
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
    <h2>ردیف‌ها</h2>
    @if($invoice->status === 'paid')
      <span class="pnl-pill ok">پرداخت شد</span>
    @else
      <span class="pnl-pill warn">در انتظار پرداخت</span>
    @endif
  </div>
  <div class="pnl-sec-b flush">
    <div class="pnl-tw">
      <table class="pnl-table">
        <thead>
          <tr><th>شرح</th><th class="num">تعداد</th><th class="num">مبلغ واحد</th><th class="num">جمع</th></tr>
        </thead>
        <tbody>
          @foreach($invoice->items as $item)
            <tr>
              <td>{{ $item->title }}</td>
              <td class="num pnl-num">{{ fa_num($item->quantity) }}</td>
              <td class="num pnl-num">{{ fa_num(number_format($item->unit_price)) }}</td>
              <td class="num pnl-num">{{ fa_num(number_format($item->line_total)) }}</td>
            </tr>
          @endforeach
          <tr><td colspan="3" style="color:var(--muted)">جمع</td>
              <td class="num pnl-num">{{ fa_num(number_format($invoice->subtotal)) }}</td></tr>
          @if($invoice->tax > 0)
            <tr><td colspan="3" style="color:var(--muted)">مالیات بر ارزش افزوده</td>
                <td class="num pnl-num">{{ fa_num(number_format($invoice->tax)) }}</td></tr>
          @endif
          <tr><td colspan="3"><b>قابل پرداخت</b></td>
              <td class="num pnl-num"><b>{{ fa_num(number_format($invoice->total)) }}</b> تومان</td></tr>
          @if($invoice->paid > 0 && $invoice->due() > 0)
            <tr><td colspan="3" style="color:var(--muted)">پرداخت‌شده تا کنون</td>
                <td class="num pnl-num">{{ fa_num(number_format($invoice->paid)) }}</td></tr>
            <tr><td colspan="3"><b>مانده</b></td>
                <td class="num pnl-num"><b>{{ fa_num(number_format($invoice->due())) }}</b></td></tr>
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
  <div class="pnl-sec-h"><h2>پرداخت فاکتور</h2>
    <span class="pnl-num" style="font-size:15px">{{ fa_num(number_format($invoice->due())) }} تومان</span>
  </div>
  <div class="pnl-sec-b">

    {{-- گام ۱: انتخاب روش (کارتی) --}}
    <p class="pm-lead">روش پرداخت را انتخاب کنید:</p>
    <div class="pm-grid">
      @if(isset($gateways['zarinpal']))
        <label class="pm-card" data-m="zarinpal">
          <input type="radio" name="pm" value="zarinpal" hidden>
          <span class="pm-badge zp"><svg class="icon"><use href="#i-coins"/></svg></span>
          <span class="pm-tt"><b>پرداخت آنلاین</b><small>کارت بانکی · زرین‌پال</small></span>
          <span class="pm-tick"><svg class="icon"><use href="#i-check"/></svg></span>
        </label>
      @endif
      @if(isset($gateways['bale']))
        <label class="pm-card" data-m="bale">
          <input type="radio" name="pm" value="bale" hidden>
          <span class="pm-badge bl"><svg class="icon"><use href="#i-bot"/></svg></span>
          <span class="pm-tt"><b>پرداخت با بله</b><small>کیف پول بله · بدون کارت</small></span>
          <span class="pm-tick"><svg class="icon"><use href="#i-check"/></svg></span>
        </label>
      @endif
      <label class="pm-card" data-m="bank">
        <input type="radio" name="pm" value="bank" hidden>
        <span class="pm-badge bk"><svg class="icon"><use href="#i-db"/></svg></span>
        <span class="pm-tt"><b>واریز به حساب</b><small>کارت به کارت / شبا</small></span>
        <span class="pm-tick"><svg class="icon"><use href="#i-check"/></svg></span>
      </label>
      <label class="pm-card is-off" data-m="crypto" title="به‌زودی">
        <input type="radio" name="pm" value="crypto" hidden disabled>
        <span class="pm-badge cr"><svg class="icon"><use href="#i-coins"/></svg></span>
        <span class="pm-tt"><b>رمزارز</b><small>به‌زودی فعال می‌شود</small></span>
        <span class="pm-soon">به‌زودی</span>
      </label>
    </div>

    {{-- گام ۲: جزئیاتِ روشِ انتخاب‌شده (پیش‌فرض پنهان) --}}
    <div class="pm-hint" id="pm-hint">
      <svg class="icon"><use href="#i-info"/></svg> پس از انتخاب روش، اطلاعات پرداخت این‌جا نمایش داده می‌شود.
    </div>

    @if(isset($gateways['zarinpal']))
      <form class="pm-pane" id="pane-zarinpal" method="POST" action="{{ lroute('account.invoice.pay', $invoice) }}" hidden>
        @csrf<input type="hidden" name="gateway" value="zarinpal">
        <div class="pm-pane-h"><b>پرداخت آنلاین با زرین‌پال</b></div>
        <p class="pm-note">به درگاه امنِ زرین‌پال منتقل می‌شوید و مبلغ را با کارت بانکی پرداخت می‌کنید. پس از پرداخت، فاکتور به‌صورت خودکار تسویه می‌شود.</p>
        <button type="submit" class="pnl-btn primary" style="justify-content:center">پرداخت {{ fa_num(number_format($invoice->due())) }} تومان</button>
      </form>
    @endif

    @if(isset($gateways['bale']))
      <form class="pm-pane" id="pane-bale" method="POST" action="{{ lroute('account.invoice.pay', $invoice) }}" hidden>
        @csrf<input type="hidden" name="gateway" value="bale">
        <div class="pm-pane-h"><b>پرداخت با کیف پول بله</b></div>
        <p class="pm-note">به ربات پرداخت بله منتقل می‌شوید و بدون نیاز به کارت، از موجودی کیف پول بله پرداخت می‌کنید.</p>
        <button type="submit" class="pnl-btn primary" style="justify-content:center">ادامه در بله</button>
      </form>
    @endif

    <div class="pm-pane" id="pane-bank" hidden>
      <div class="pm-pane-h"><b>واریز به حساب شرکت</b></div>
      @if($pendingBank)
        <div class="pm-note" style="color:var(--warn)">
          رسید واریز شما با شناسهٔ <b dir="ltr">{{ $pendingBank->reference }}</b> ثبت شده و در انتظار تأیید پشتیبانی است.
        </div>
      @elseif($bank === null)
        <div class="pm-note">این روش فعلاً در دسترس نیست.</div>
      @else
        <div class="bank-box">
          @if($bank['holder'])<div><span>به نام</span><b>{{ $bank['holder'] }}</b></div>@endif
          @if($bank['bank'])<div><span>بانک</span><b>{{ $bank['bank'] }}</b></div>@endif
          @if($bank['card'])<div><span>شمارهٔ کارت</span><b dir="ltr" class="copyable">{{ $bank['card'] }}</b></div>@endif
          @if($bank['sheba'])<div><span>شبا</span><b dir="ltr" class="copyable">IR{{ ltrim($bank['sheba'],'IRir') }}</b></div>@endif
          @if($bank['account'])<div><span>شمارهٔ حساب</span><b dir="ltr" class="copyable">{{ $bank['account'] }}</b></div>@endif
          @if($bank['note'])<div><span>توضیح</span><b>{{ $bank['note'] }}</b></div>@endif
        </div>
        <p class="pm-note">
          مبلغ <b class="pnl-num">{{ fa_num(number_format($invoice->due())) }}</b> تومان را واریز کنید، سپس شناسهٔ پیگیری/پرداخت را این‌جا ثبت کنید:
        </p>
        <form method="POST" action="{{ lroute('account.invoice.bank', $invoice) }}" style="display:flex;flex-direction:column;gap:10px">
          @csrf
          <input type="text" name="reference" required maxlength="120" dir="ltr" placeholder="شناسهٔ پرداخت / شمارهٔ پیگیری" class="bank-input">
          <input type="text" name="paid_from" maxlength="120" dir="ltr" placeholder="شمارهٔ کارت مبدأ (اختیاری)" class="bank-input">
          <button type="submit" class="pnl-btn" style="justify-content:center">ثبت رسید واریز</button>
        </form>
      @endif
    </div>

  </div>
</section>

<style>
.pm-lead{ font-size:13px; color:var(--muted); margin-bottom:12px; }
.pm-grid{ display:grid; grid-template-columns:repeat(2,1fr); gap:12px; }
.pm-card{ position:relative; display:flex; align-items:center; gap:12px; cursor:pointer;
  border:1.5px solid var(--line); border-radius:15px; padding:14px 15px; background:var(--surface-2);
  transition:border-color .18s, background .18s, transform .12s, box-shadow .18s; }
.pm-card:hover:not(.is-off){ transform:translateY(-1px); border-color:var(--line-2,#2b3548); }
.pm-card:has(input:checked){ border-color:var(--brand,#22D3EE); background:rgba(34,211,238,.07);
  box-shadow:0 6px 20px rgba(34,211,238,.10); }
.pm-card.is-off{ cursor:not-allowed; opacity:.55; }
.pm-badge{ flex:none; width:42px; height:42px; border-radius:12px; display:grid; place-items:center; }
.pm-badge .icon{ width:20px; height:20px; color:#fff; }
.pm-badge.zp{ background:linear-gradient(135deg,#f4b740,#e08a1e); }
.pm-badge.bl{ background:linear-gradient(135deg,#22c55e,#15a34a); }
.pm-badge.bk{ background:linear-gradient(135deg,#38bdf8,#2563eb); }
.pm-badge.cr{ background:linear-gradient(135deg,#94a3b8,#64748b); }
.pm-tt{ display:flex; flex-direction:column; gap:2px; min-width:0; }
.pm-tt b{ font-size:13.5px; color:var(--text); }
.pm-tt small{ font-size:11.5px; color:var(--muted); }
.pm-tick{ margin-inline-start:auto; width:22px; height:22px; border-radius:50%; border:1.5px solid var(--line);
  display:grid; place-items:center; flex:none; transition:.18s; }
.pm-tick .icon{ width:13px; height:13px; color:transparent; }
.pm-card:has(input:checked) .pm-tick{ border-color:var(--brand,#22D3EE); background:var(--brand,#22D3EE); }
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
      var o=this.textContent; this.textContent='کپی شد ✓'; var s=this;
      setTimeout(function(){ s.textContent=o; }, 1200);
    });
  });
})();
</script>
@endif

@if($invoice->payments->isNotEmpty())
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>تلاش‌های پرداخت</h2></div>
  <div class="pnl-sec-b flush">
    <div class="pnl-tw">
      <table class="pnl-table">
        <thead><tr><th>تاریخ</th><th>درگاه</th><th>وضعیت</th><th>پیگیری</th><th class="num">مبلغ</th></tr></thead>
        <tbody>
          @foreach($invoice->payments->sortByDesc('id') as $p)
            <tr>
              <td>{{ stime($p->created_at) }}</td>
              <td>{{ $p->gateway }}</td>
              <td>
                @if($p->status === 'paid')<span class="pnl-pill ok">موفق</span>
                @elseif($p->status === 'canceled')<span class="pnl-pill mute">لغو شد</span>
                @elseif($p->status === 'failed')<span class="pnl-pill danger">ناموفق</span>
                @else<span class="pnl-pill warn">ناتمام</span>@endif
              </td>
              <td dir="ltr" style="font-size:12px">{{ $p->ref_id ?: '—' }}</td>
              <td class="num pnl-num">{{ fa_num(number_format($p->amount)) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
</section>
@endif

@endsection
