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
      <svg class="icon"><use href="#i-file"/></svg>{{ $invoice->status === 'paid' ? 'دانلود رسید (PDF)' : 'دانلود فاکتور (PDF)' }}
    </a>
    <a class="pnl-btn" href="{{ lroute('account.invoices') }}">
      <svg class="icon"><use href="#i-arrow"/></svg>بازگشت
    </a>
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
  <div class="pnl-sec-h"><h2>انتخاب روش پرداخت</h2>
    <span class="pnl-num" style="font-size:15px">{{ fa_num(number_format($invoice->due())) }} تومان</span>
  </div>
  <div class="pnl-sec-b" style="display:flex;flex-direction:column;gap:14px">

    {{-- ۱) پرداخت آنلاین + بله --}}
    @if(count($gateways) > 0)
      <form method="POST" action="{{ lroute('account.invoice.pay', $invoice) }}" class="pay-card">
        @csrf
        <div class="pay-card-h"><svg class="icon"><use href="#i-coins"/></svg><b>پرداخت آنلاین</b></div>
        <div class="pay-methods">
          @foreach($gateways as $key => $g)
            <label class="pay-radio">
              <input type="radio" name="gateway" value="{{ $key }}" {{ $loop->first ? 'checked' : '' }}>
              <span>{{ ['zarinpal'=>'زرین‌پال — کارت بانکی', 'bale'=>'بله — کیف پول (بدون کارت)'][$key] ?? $key }}</span>
            </label>
          @endforeach
        </div>
        <button type="submit" class="pnl-btn primary" style="justify-content:center">پرداخت آنلاین</button>
      </form>
    @endif

    {{-- ۲) واریز به حساب --}}
    <div class="pay-card">
      <div class="pay-card-h"><svg class="icon"><use href="#i-db"/></svg><b>واریز به حساب (کارت به کارت / شبا)</b></div>
      @if($pendingBank)
        <div style="font-size:13px;color:var(--warn);line-height:2">
          رسید واریز شما با شناسهٔ <b dir="ltr">{{ $pendingBank->reference }}</b> ثبت شده و در انتظار تأیید پشتیبانی است.
        </div>
      @elseif($bank === null)
        <div style="font-size:13px;color:var(--muted)">این روش فعلاً در دسترس نیست.</div>
      @else
        <div class="bank-box">
          @if($bank['holder'])<div><span>به نام</span><b>{{ $bank['holder'] }}</b></div>@endif
          @if($bank['bank'])<div><span>بانک</span><b>{{ $bank['bank'] }}</b></div>@endif
          @if($bank['card'])<div><span>شمارهٔ کارت</span><b dir="ltr" class="copyable">{{ $bank['card'] }}</b></div>@endif
          @if($bank['sheba'])<div><span>شبا</span><b dir="ltr" class="copyable">IR{{ ltrim($bank['sheba'],'IRir') }}</b></div>@endif
          @if($bank['account'])<div><span>شمارهٔ حساب</span><b dir="ltr" class="copyable">{{ $bank['account'] }}</b></div>@endif
          @if($bank['note'])<div><span>توضیح</span><b>{{ $bank['note'] }}</b></div>@endif
        </div>
        <p style="font-size:12.5px;color:var(--muted);line-height:2;margin:10px 0 4px">
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

    {{-- ۳) کریپتو — به‌زودی --}}
    <div class="pay-card disabled">
      <div class="pay-card-h"><svg class="icon"><use href="#i-coins"/></svg><b>پرداخت با رمزارز (کریپتو)</b>
        <span class="pnl-pill mute" style="margin-inline-start:auto">به‌زودی</span>
      </div>
      <div style="font-size:12.5px;color:var(--dim)">این روش به‌زودی اضافه می‌شود.</div>
    </div>

  </div>
</section>

<style>
.pay-card{ border:1px solid var(--line); border-radius:14px; padding:15px; background:var(--surface-2); }
.pay-card.disabled{ opacity:.6; }
.pay-card-h{ display:flex; align-items:center; gap:9px; margin-bottom:12px; font-size:14px; }
.pay-card-h .icon{ width:18px; height:18px; color:var(--muted); }
.pay-methods{ display:flex; flex-direction:column; gap:9px; margin-bottom:14px; }
.pay-radio{ display:flex; align-items:center; gap:9px; cursor:pointer; border:1px solid var(--line); border-radius:11px; padding:11px 14px; font-size:13px; }
.pay-radio:has(input:checked){ border-color:var(--info); background:rgba(34,211,238,.06); }
.pay-radio input{ accent-color:#22D3EE; }
.bank-box{ display:grid; gap:8px; background:var(--surface); border:1px solid var(--line); border-radius:12px; padding:13px 15px; }
.bank-box > div{ display:flex; justify-content:space-between; gap:12px; font-size:13px; }
.bank-box span{ color:var(--muted); }
.bank-box b{ color:var(--text); }
.bank-input{ background:var(--surface); border:1px solid var(--line); border-radius:10px; padding:10px 13px; font:inherit; font-size:13px; color:var(--text); }
.copyable{ cursor:copy; }
</style>
<script>
document.querySelectorAll('.copyable').forEach(function(el){
  el.addEventListener('click', function(){
    var t=(this.textContent||'').replace(/\s/g,'');
    if(navigator.clipboard) navigator.clipboard.writeText(t);
    var o=this.textContent; this.textContent='کپی شد ✓'; var s=this;
    setTimeout(function(){ s.textContent=o; }, 1200);
  });
});
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
