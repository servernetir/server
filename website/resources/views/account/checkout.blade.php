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

@php
  // ماتریسِ قیمت برای همهٔ ترکیب‌های (مکان × دوره) تا انتخابِ کاربر بدونِ رفت‌وبرگشت
  // به سرور، مبلغ‌ها را زنده به‌روز کند. مبلغِ نهایی هنگام ثبت، سمتِ سرور دوباره
  // محاسبه می‌شود — این‌ها فقط نمایشی‌اند.
  $lineTax  = fn (int $a) => (int) round($a * $product->tax_percent / 100);
  $setupFee = $product->effectiveSetup();
  $defCycle = in_array(config('billing.default_cycle'), $cycles, true) ? config('billing.default_cycle') : ($cycles[0] ?? 'monthly');
  $defCountry = old('country', $countries[0] ?? null);
  $defCycle = old('cycle', $defCycle);

  $priceMatrix = [];
  foreach (($countries ?: [null]) as $cc) {
      foreach ($cycles as $cy) {
          $cyclePrice = $product->priceForCycle($cy, $cc);
          $priceMatrix[$cc ?: '-'][$cy] = [
              'cycle' => $cyclePrice,
              'first' => $cyclePrice + $setupFee + $lineTax($cyclePrice) + $lineTax($setupFee),
              'per'   => $product->monthlyEquivalent($cy, $cc),
              'save'  => $product->savingPct($cy),
          ];
      }
  }
  $initial = $priceMatrix[$defCountry ?: '-'][$defCycle] ?? reset($priceMatrix[array_key_first($priceMatrix)]);
@endphp

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
        <span>مبلغِ دوره <i id="sum-cyclename">{{ \App\Models\Service::labelFor($defCycle) }}</i></span>
        <b class="pnl-num" id="sum-cycle">{{ fa_num(number_format($initial['cycle'])) }} تومان</b>
      </div>
      <div class="co-price-row">
        <span>معادلِ ماهانه</span>
        <b class="pnl-num" id="sum-per">{{ fa_num(number_format($initial['per'])) }} تومان</b>
      </div>
      @if($setupFee > 0)
        <div class="co-price-row"><span>هزینهٔ راه‌اندازی (یک‌بار)</span><b class="pnl-num">{{ fa_num(number_format($setupFee)) }} تومان</b></div>
      @endif
      <div class="co-price-row"><span>مالیات بر ارزش افزوده</span><b class="pnl-num">{{ fa_num($product->tax_percent) }}٪</b></div>
      <div class="co-price-row co-total"><span>پرداختِ همین حالا</span><b class="pnl-num" id="sum-first">{{ fa_num(number_format($initial['first'])) }} تومان</b></div>
      <p class="co-note" id="sum-renew">پس از آن، هر <span>{{ \App\Models\Service::labelFor($defCycle) }}</span> همین مبلغِ دوره تمدید می‌شود.</p>
    </div>
  </section>

  {{-- فرمِ سفارش --}}
  <section class="pnl-sec co-form">
    <div class="pnl-sec-h"><h2>تنظیماتِ سرویس</h2></div>
    <div class="pnl-sec-b">
      <form method="POST" action="{{ lroute('account.order.place', $product->slug) }}" id="co-form">
        @csrf

        {{-- ۱) محلِ سرور --}}
        @if(count($countries) === 0)
          <p class="co-warn">⚠️ در حال حاضر سرورِ آماده‌ای برای این پکیج نداریم. لطفاً با پشتیبانی تماس بگیرید.</p>
        @else
          <p class="co-q">سرورِ شما در کدام کشور باشد؟</p>
          <div class="co-opts co-locs">
            @foreach($countries as $cc)
              @php $loc = config('billing.locations.'.$cc, []); @endphp
              <label class="co-opt @if($cc === $defCountry) on @endif" data-loc="{{ $cc }}">
                <input type="radio" name="country" value="{{ $cc }}" @checked($cc === $defCountry) @if(count($countries) === 1) readonly @endif>
                <span class="co-flag">{{ $loc['flag'] ?? '🌐' }}</span>
                <span class="co-tt">
                  <b>{{ $loc['label']['fa'] ?? $cc }}@if(!empty($loc['city']['fa'])) — {{ $loc['city']['fa'] }}@endif</b>
                  <small>{{ $loc['note']['fa'] ?? '' }}</small>
                </span>
              </label>
            @endforeach
          </div>
        @endif

        {{-- ۲) دورهٔ پرداخت --}}
        <p class="co-q">دورهٔ پرداخت را انتخاب کنید</p>
        <div class="co-cycles">
          @foreach($cycles as $cy)
            @php $row = $priceMatrix[$defCountry ?: '-'][$cy] ?? null; @endphp
            <label class="co-cyc @if($cy === $defCycle) on @endif" data-cyc="{{ $cy }}">
              <input type="radio" name="cycle" value="{{ $cy }}" @checked($cy === $defCycle)>
              <span class="co-cyc-t">{{ \App\Models\Service::labelFor($cy) }}</span>
              <span class="co-cyc-p" data-p>{{ fa_num(number_format($row['cycle'] ?? 0)) }}</span>
              <span class="co-cyc-m" data-m>ماهی {{ fa_num(number_format($row['per'] ?? 0)) }}</span>
              @if(($row['save'] ?? 0) > 0)
                <span class="co-cyc-save">{{ fa_num($row['save']) }}٪ ارزان‌تر</span>
              @endif
            </label>
          @endforeach
        </div>

        {{-- ۳) دامنه --}}
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

        <button type="submit" class="pnl-btn primary" style="justify-content:center;width:100%;margin-top:8px" @if(count($countries) === 0) disabled @endif>
          ادامه و پرداخت — <span id="co-btn-total">{{ fa_num(number_format($initial['first'])) }}</span> تومان
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
/* رادیو را «پنهانِ دیداری» می‌کنیم نه display:none — با display:none از ترتیبِ
   Tab حذف می‌شد و کاربرِ صفحه‌کلید نمی‌توانست انتخاب کند. */
.co-opt input, .co-cyc input{ position:absolute; width:1px; height:1px; opacity:0; margin:0; pointer-events:none; }
.co-opt:has(input:focus-visible), .co-cyc:has(input:focus-visible){ outline:2px solid #22D3EE; outline-offset:2px; }
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
.co-warn{ font-size:13px; color:var(--warn); line-height:2; margin:0 0 12px; }
.co-price-row i{ font-style:normal; color:var(--text); }

/* محلِ سرور */
.co-flag{ font-size:24px; line-height:1; width:36px; text-align:center; flex:0 0 auto; }

/* دورهٔ پرداخت — کارتی، با معادلِ ماهانه و برچسبِ تخفیف */
.co-cycles{ display:grid; grid-template-columns:repeat(2,1fr); gap:10px; margin-bottom:16px; }
@media(max-width:420px){ .co-cycles{ grid-template-columns:1fr; } }
.co-cyc{ position:relative; display:flex; flex-direction:column; gap:2px; cursor:pointer;
  border:1.5px solid var(--line); border-radius:13px; padding:12px 13px; transition:border-color .16s, background .16s; }
.co-cyc.on{ border-color:#22D3EE; background:rgba(34,211,238,.06); }
.co-cyc-t{ font-size:13px; font-weight:700; color:var(--text); }
.co-cyc-p{ font-size:15px; font-weight:700; color:var(--text); font-variant-numeric:tabular-nums; }
.co-cyc-m{ font-size:11px; color:var(--muted); font-variant-numeric:tabular-nums; }
.co-cyc-save{ position:absolute; top:-9px; inset-inline-end:10px; font-size:10.5px; font-weight:700;
  color:#04121a; background:linear-gradient(135deg,#34D399,#22D3EE); padding:2px 8px; border-radius:20px; }
.co-cyc.on .co-cyc-p{ color:#22D3EE; }
</style>
@php $priceJson = $priceMatrix; $cycleLabels = collect($cycles)->mapWithKeys(fn ($c) => [$c => \App\Models\Service::labelFor($c)])->all(); @endphp
<script>
(function(){
  // ── انتخابِ دامنه (رادیوهای domain_mode) ──
  var domOpts  = document.querySelectorAll('.co-opt[data-m]');
  var fields   = document.querySelectorAll('.co-field');
  document.querySelectorAll('.co-opt[data-m] input').forEach(function(r){
    r.addEventListener('change', function(){
      domOpts.forEach(function(o){ o.classList.remove('on'); });
      this.closest('.co-opt').classList.add('on');
      fields.forEach(function(f){ f.hidden = f.getAttribute('data-for') !== r.value; });
    });
  });

  // ── قیمتِ زنده بر اساس (مکان × دوره) ──
  var PRICES = @json($priceJson);
  var LABELS = @json($cycleLabels);
  var faN = function(x){ return String(x).replace(/[0-9]/g, function(g){ return '۰۱۲۳۴۵۶۷۸۹'[g]; }); };
  var money = function(n){ return faN(Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',')); };

  var pick = function(){
    var c = document.querySelector('input[name="country"]:checked');
    var y = document.querySelector('input[name="cycle"]:checked');
    var key = (c && c.value) ? c.value : '-';
    var bucket = PRICES[key] || PRICES['-'] || PRICES[Object.keys(PRICES)[0]] || {};
    return { cycle: y ? y.value : null, row: y ? bucket[y.value] : null, bucket: bucket };
  };

  var set = function(id, txt){ var el = document.getElementById(id); if (el) el.textContent = txt; };

  var render = function(){
    var s = pick();
    // کارت‌های دوره را با قیمتِ مکانِ انتخابی به‌روز کن
    document.querySelectorAll('.co-cyc').forEach(function(card){
      var row = s.bucket[card.getAttribute('data-cyc')];
      if (!row) return;
      var p = card.querySelector('[data-p]'), m = card.querySelector('[data-m]');
      if (p) p.textContent = money(row.cycle);
      if (m) m.textContent = 'ماهی ' + money(row.per);
    });
    if (!s.row) return;
    set('sum-cycle', money(s.row.cycle) + ' تومان');
    set('sum-per',   money(s.row.per)   + ' تومان');
    set('sum-first', money(s.row.first) + ' تومان');
    set('co-btn-total', money(s.row.first));
    set('sum-cyclename', LABELS[s.cycle] || '');
    var rn = document.querySelector('#sum-renew span');
    if (rn) rn.textContent = LABELS[s.cycle] || '';
  };

  // انتخابِ کارتیِ مکان و دوره
  document.querySelectorAll('.co-locs input[name="country"]').forEach(function(r){
    r.addEventListener('change', function(){
      document.querySelectorAll('.co-locs .co-opt').forEach(function(o){ o.classList.remove('on'); });
      this.closest('.co-opt').classList.add('on');
      render();
    });
  });
  document.querySelectorAll('.co-cyc input[name="cycle"]').forEach(function(r){
    r.addEventListener('change', function(){
      document.querySelectorAll('.co-cyc').forEach(function(o){ o.classList.remove('on'); });
      this.closest('.co-cyc').classList.add('on');
      render();
    });
  });

  render();
})();
</script>
@endsection
