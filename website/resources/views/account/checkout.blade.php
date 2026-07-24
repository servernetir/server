@extends('panel.layout')
@section('title', 'خرید '.$product->name.' — سرورنت')

@section('panel')

<div class="pnl-head">
  <div>
    <h1 class="dash-h">تکمیل خرید</h1>
    <p>پکیج انتخاب‌شده را نهایی کنید؛ پس از پرداخت، سرویس خودکار ساخته و تحویل می‌شود.</p>
  </div>
</div>

@if($errors->any())
  <div class="pnl-sec" style="border-color:var(--danger-line)"><div class="pnl-sec-b" style="color:var(--danger);font-size:13.5px;line-height:2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>
@endif

<div class="co-wrap">
  {{-- خلاصهٔ پکیج --}}
  <section class="pnl-sec co-summary">
    <div class="pnl-sec-h"><h2>{{ $product->name }}</h2></div>
    <div class="pnl-sec-b">
      @if($product->description)<p class="co-desc">{{ $product->description }}</p>@endif
      @if(!empty($product->specs))
        <ul class="co-specs">
          @foreach($product->specs as $spec)
            <li><svg class="icon"><use href="#i-check"/></svg><span>{{ $spec['label'] }}@if(!empty($spec['value'])): <b>{{ $spec['value'] }}</b>@endif</span></li>
          @endforeach
        </ul>
      @endif
      <div class="co-price-row">
        <span>هزینهٔ دوره‌ای ({{ $product->cycleLabel() }})</span>
        <b class="pnl-num">{{ fa_num(number_format($product->recurringTotal())) }} تومان</b>
      </div>
      @if($product->effectiveSetup() > 0)
        <div class="co-price-row"><span>هزینهٔ راه‌اندازی (یک‌بار)</span><b class="pnl-num">{{ fa_num(number_format($product->effectiveSetup())) }} تومان</b></div>
      @endif
      <div class="co-price-row co-total"><span>پرداختِ اولین صورت‌حساب</span><b class="pnl-num">{{ fa_num(number_format($product->firstTotal())) }} تومان</b></div>
    </div>
  </section>

  {{-- فرمِ دامنه + پرداخت --}}
  <section class="pnl-sec co-form">
    <div class="pnl-sec-h"><h2>دامنهٔ سرویس</h2></div>
    <div class="pnl-sec-b">
      <form method="POST" action="{{ lroute('account.order.place', $product->slug) }}" id="co-form">
        @csrf
        <p class="co-q">برای این هاست، دامنه‌ای دارید یا می‌خواهید تهیه کنید؟</p>

        <div class="co-opts">
          <label class="co-opt on" data-m="have">
            <input type="radio" name="domain_mode" value="have" checked>
            <span class="co-ic"><svg class="icon"><use href="#i-globe"/></svg></span>
            <span class="co-tt"><b>دامنه دارم</b><small>دامنهٔ خودم را وصل می‌کنم</small></span>
          </label>
          <label class="co-opt" data-m="buy">
            <input type="radio" name="domain_mode" value="buy">
            <span class="co-ic"><svg class="icon"><use href="#i-tag"/></svg></span>
            <span class="co-tt"><b>می‌خواهم دامنه بخرم</b><small>دامنهٔ دلخواه را برایم ثبت کنید</small></span>
          </label>
          <label class="co-opt" data-m="subdomain">
            <input type="radio" name="domain_mode" value="subdomain">
            <span class="co-ic"><svg class="icon"><use href="#i-zap"/></svg></span>
            <span class="co-tt"><b>زیردامنهٔ رایگان</b><small>فعلاً روی servernet.cloud</small></span>
          </label>
        </div>

        {{-- ورودی‌ها بر اساس انتخاب --}}
        <div class="co-field" data-for="have">
          <label>دامنهٔ شما
            <input type="text" name="domain" dir="ltr" placeholder="your-domain.com" value="{{ old('domain') }}">
          </label>
        </div>
        <div class="co-field" data-for="buy" hidden>
          <label>دامنه‌ای که می‌خواهید
            <input type="text" name="domain_buy" dir="ltr" placeholder="new-domain.com">
          </label>
          <p class="co-note">پس از پرداخت، ثبتِ این دامنه توسط پشتیبانی انجام و روی هاست تنظیم می‌شود.</p>
        </div>
        <div class="co-field" data-for="subdomain" hidden>
          <label>زیردامنهٔ دلخواه
            <div class="co-sub"><input type="text" name="subdomain" dir="ltr" placeholder="mysite"><span dir="ltr">.servernet.cloud</span></div>
          </label>
        </div>

        <button type="submit" class="pnl-btn primary" style="justify-content:center;width:100%;margin-top:8px">
          ادامه و پرداخت — {{ fa_num(number_format($product->firstTotal())) }} تومان
        </button>
        <p class="co-note" style="text-align:center">پیش‌فاکتور صادر می‌شود؛ پس از پرداخت، سرویس خودکار ساخته می‌شود.</p>
      </form>
    </div>
  </section>
</div>

<style>
.co-wrap{ display:grid; grid-template-columns:1fr 1fr; gap:16px; align-items:start; }
@media(max-width:760px){ .co-wrap{ grid-template-columns:1fr; } }
.co-desc{ font-size:13px; color:var(--muted); line-height:2; margin:0 0 12px; }
.co-specs{ list-style:none; margin:0 0 14px; padding:0; display:flex; flex-direction:column; gap:8px; }
.co-specs li{ display:flex; align-items:flex-start; gap:8px; font-size:13px; color:var(--muted); }
.co-specs .icon{ width:15px; height:15px; color:var(--ok); flex:0 0 auto; margin-top:2px; }
.co-specs b{ color:var(--text); }
.co-price-row{ display:flex; justify-content:space-between; align-items:center; padding:9px 0; font-size:13px; color:var(--muted); border-top:1px solid var(--line); }
.co-price-row b{ color:var(--text); font-size:14px; }
.co-total{ border-top:2px solid var(--line); margin-top:4px; }
.co-total span{ color:var(--text); font-weight:600; }
.co-total b{ font-size:17px; color:var(--brand,#22D3EE); }
.co-q{ font-size:13.5px; color:var(--text); margin:0 0 12px; }
.co-opts{ display:flex; flex-direction:column; gap:10px; margin-bottom:14px; }
.co-opt{ display:flex; align-items:center; gap:12px; cursor:pointer; border:1.5px solid var(--line); border-radius:13px; padding:12px 14px; transition:.16s; }
.co-opt input{ display:none; }
.co-opt.on{ border-color:var(--brand,#22D3EE); background:rgba(34,211,238,.06); }
.co-ic{ width:36px; height:36px; border-radius:10px; display:grid; place-items:center; background:var(--surface); border:1px solid var(--line); flex:0 0 auto; }
.co-ic .icon{ width:17px; height:17px; color:var(--info); }
.co-tt{ display:flex; flex-direction:column; gap:1px; }
.co-tt b{ font-size:13.5px; color:var(--text); }
.co-tt small{ font-size:11.5px; color:var(--muted); }
.co-field label{ display:flex; flex-direction:column; gap:6px; font-size:12.5px; color:var(--muted); }
.co-field input{ background:var(--surface); border:1px solid var(--line); border-radius:11px; padding:11px 13px; font:inherit; font-size:14px; color:var(--text); }
.co-sub{ display:flex; align-items:center; gap:0; }
.co-sub input{ border-radius:0 11px 11px 0; flex:1; }
.co-sub span{ background:var(--surface-2); border:1px solid var(--line); border-inline-start:0; border-radius:11px 0 0 11px; padding:11px 12px; font-size:13px; color:var(--muted); }
.co-note{ font-size:12px; color:var(--muted); line-height:1.9; margin:10px 0 0; }
</style>
<script>
(function(){
  var opts = document.querySelectorAll('.co-opt');
  var fields = document.querySelectorAll('.co-field');
  document.querySelectorAll('.co-opt input').forEach(function(r){
    r.addEventListener('change', function(){
      opts.forEach(function(o){ o.classList.remove('on'); });
      this.closest('.co-opt').classList.add('on');
      fields.forEach(function(f){ f.hidden = f.getAttribute('data-for') !== r.value; });
    });
  });
})();
</script>
@endsection
