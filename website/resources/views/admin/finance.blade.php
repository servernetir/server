@extends('admin.layout')
@section('title', 'مالی و سود')
@section('nav_finance', 'on')
@section('content')

@php
  $s = $summary;
  // کمک‌تابع نمایش تومان با جداکنندهٔ فارسی
  $t = fn ($n) => fa_num(number_format((int) $n)).' ت';
  $maxTrend = max(1, collect($trend)->flatMap(fn ($m) => [$m['revenue'], $m['expense']])->max() ?: 1);
  $catLabels = [
    'server'=>'سرور و زیرساخت','api_kyc'=>'احراز هویت (زحل)','api_sms'=>'پیامک',
    'domain_wholesale'=>'خرید عمده دامنه','payment_fee'=>'کارمزد درگاه','salary'=>'حقوق',
    'marketing'=>'بازاریابی','other'=>'سایر',
  ];
@endphp

@if($errors->any())<div class="ad-note" style="border-color:#ff6b6b;color:#ff6b6b">{{ $errors->first() }}</div>@endif

@unless($ready)
  <div class="ad-note" style="border-color:#fbbf24;color:#fbbf24;line-height:2">
    جدول دفتر مالی هنوز روی این سرور ساخته نشده است. برای فعال شدن داشبورد،
    یک بار <a href="/system/migrate" style="color:#22d3ee">مهاجرت دیتابیس</a> را اجرا کنید.
    تا آن موقع اعداد صفر نمایش داده می‌شوند.
  </div>
@endunless

{{-- ══ بازهٔ زمانی ══ --}}
<div class="ad-toolbar">
  <div class="ad-tabs">
    <a href="/admin/finance?range=all"   class="{{ $range === 'all' ? 'on' : '' }}">کل تاریخچه</a>
    <a href="/admin/finance?range=year"  class="{{ $range === 'year' ? 'on' : '' }}">امسال</a>
    <a href="/admin/finance?range=month" class="{{ $range === 'month' ? 'on' : '' }}">این ماه</a>
  </div>
</div>

{{-- ══ چهار عدد اصلی ══ --}}
<div class="fin-kpis">
  <div class="fin-kpi">
    <span class="fin-kpi-l">نقدینگی فعلی</span>
    <b class="fin-kpi-v" style="color:{{ $s['cash'] >= 0 ? '#34d399' : '#ff6b6b' }}">{{ $t($s['cash']) }}</b>
    <small>هر چه وارد شد منهای هر چه خارج شد</small>
  </div>
  <div class="fin-kpi">
    <span class="fin-kpi-l">سود خالص</span>
    <b class="fin-kpi-v" style="color:{{ $s['net_profit'] >= 0 ? '#34d399' : '#ff6b6b' }}">{{ $t($s['net_profit']) }}</b>
    <small>درآمد منهای هزینه · حاشیه {{ fa_num($s['margin']) }}٪</small>
  </div>
  <div class="fin-kpi">
    <span class="fin-kpi-l">بازده سرمایه (ROI)</span>
    <b class="fin-kpi-v" style="color:{{ $s['roi'] >= 0 ? '#22d3ee' : '#ff6b6b' }}">{{ fa_num($s['roi']) }}٪</b>
    <small>سود نسبت به سرمایهٔ خالص</small>
  </div>
  <div class="fin-kpi">
    <span class="fin-kpi-l">بدهی مالیاتی</span>
    <b class="fin-kpi-v" style="color:#fbbf24">{{ $t($s['tax_liability']) }}</b>
    <small>مالیات گرفته منهای پرداخت‌شده</small>
  </div>
</div>

<div class="fin-cols">

  {{-- ستون چپ: سود و زیان + سرمایه + مالیات --}}
  <div>
    {{-- صورت سود و زیان --}}
    <div class="ad-panel">
      <div class="ad-panel-h"><h2>سود و زیان</h2></div>
      <table class="fin-pl">
        <tr>
          <td>درآمد فروش</td>
          <td class="fin-num" style="color:#34d399">{{ $t($s['revenue']) }}</td>
          <td class="fin-src">از {{ fa_num($s['revenue_count']) }} پرداخت</td>
        </tr>
        <tr>
          <td>هزینه‌ها</td>
          <td class="fin-num" style="color:#ff6b6b">− {{ $t($s['expense']) }}</td>
          <td class="fin-src">{{ fa_num($s['expense_count']) }} ردیف</td>
        </tr>
        <tr class="fin-total">
          <td>سود خالص</td>
          <td class="fin-num" style="color:{{ $s['net_profit'] >= 0 ? '#34d399' : '#ff6b6b' }}">{{ $t($s['net_profit']) }}</td>
          <td class="fin-src">حاشیه {{ fa_num($s['margin']) }}٪</td>
        </tr>
      </table>

      {{-- تفکیک هزینه --}}
      @if($s['expense'] > 0)
        <div class="fin-cat">
          <div class="fin-cat-h">تفکیک هزینه</div>
          @foreach($s['by_category'] as $cat => $amt)
            @php $pct = round($amt / max(1, $s['expense']) * 100); @endphp
            <div class="fin-cat-row">
              <span>{{ $catLabels[$cat] ?? $cat }}</span>
              <span class="fin-cat-bar"><i style="width:{{ $pct }}%"></i></span>
              <span class="fin-num">{{ $t($amt) }}</span>
            </div>
          @endforeach
        </div>
      @endif
    </div>

    {{-- سرمایه --}}
    <div class="ad-panel" style="margin-top:16px">
      <div class="ad-panel-h"><h2>سرمایه</h2></div>
      <table class="fin-pl">
        <tr><td>سرمایه‌گذاری‌شده</td><td class="fin-num" style="color:#8b5cf6">{{ $t($s['capital']) }}</td><td></td></tr>
        <tr><td>برداشت‌شده</td><td class="fin-num">− {{ $t($s['withdrawal']) }}</td><td></td></tr>
        <tr class="fin-total"><td>سرمایهٔ خالص در کسب‌وکار</td><td class="fin-num">{{ $t($s['net_capital']) }}</td><td></td></tr>
      </table>
    </div>

    {{-- مالیات --}}
    <div class="ad-panel" style="margin-top:16px">
      <div class="ad-panel-h"><h2>مالیات ارزش افزوده</h2></div>
      <table class="fin-pl">
        <tr><td>گرفته‌شده از مشتری</td><td class="fin-num" style="color:#fbbf24">{{ $t($s['tax_collected']) }}</td><td class="fin-src">خودکار از فاکتورها</td></tr>
        <tr><td>پرداخت‌شده به دولت</td><td class="fin-num">− {{ $t($s['tax_paid']) }}</td><td></td></tr>
        <tr class="fin-total"><td>بدهی به دولت</td><td class="fin-num" style="color:#fbbf24">{{ $t($s['tax_liability']) }}</td><td></td></tr>
      </table>
      <p style="padding:0 16px 14px;margin:0;font-size:12px;color:var(--dim);line-height:1.9">
        مالیات پول شما نیست؛ از مشتری می‌گیرید و به دولت می‌دهید. برای همین در «سود» نمی‌آید.
      </p>
    </div>
  </div>

  {{-- ستون راست: نمودار + ثبت دستی --}}
  <div>
    {{-- روند ماهانه --}}
    <div class="ad-panel">
      <div class="ad-panel-h"><h2>روند ۶ ماه اخیر</h2></div>
      <div class="fin-chart">
        @foreach($trend as $m)
          <div class="fin-bar-group" title="{{ $m['label'] }} · سود {{ $t($m['profit']) }}">
            <div class="fin-bars">
              <div class="fin-bar rev" style="height:{{ round($m['revenue'] / $maxTrend * 100) }}%"></div>
              <div class="fin-bar exp" style="height:{{ round($m['expense'] / $maxTrend * 100) }}%"></div>
            </div>
            <span class="fin-bar-x">{{ fa_num($m['label']) }}</span>
          </div>
        @endforeach
      </div>
      <div class="fin-legend">
        <span><i style="background:#34d399"></i> درآمد</span>
        <span><i style="background:#ff6b6b"></i> هزینه</span>
      </div>
    </div>

    {{-- ثبت دستی --}}
    <div class="ad-panel" style="margin-top:16px">
      <div class="ad-panel-h"><h2>ثبت رویداد مالی</h2></div>
      <form method="post" action="/admin/finance" class="fin-form">
        @csrf
        <label>نوع</label>
        <select name="kind" id="fin-kind" class="ad-input">
          <option value="capital">سرمایه‌گذاری (پول گذاشتم)</option>
          <option value="expense">هزینه</option>
          <option value="withdrawal">برداشت (پول برداشتم)</option>
          <option value="tax_paid">پرداخت مالیات به دولت</option>
        </select>

        <div id="fin-cat-wrap" style="display:none">
          <label>دستهٔ هزینه</label>
          <select name="category" class="ad-input">
            @foreach($categories as $c)
              <option value="{{ $c }}">{{ $catLabels[$c] ?? $c }}</option>
            @endforeach
          </select>
        </div>

        <label>مبلغ (تومان)</label>
        <input type="text" name="amount" id="fin-amount" class="ad-input" dir="ltr" inputmode="numeric" placeholder="۵۰۰٬۰۰۰" required>
        <input type="hidden" name="amount" disabled>

        <label>تاریخ</label>
        <input type="date" name="occurred_at" class="ad-input" value="{{ now()->format('Y-m-d') }}">

        <label>توضیح (اختیاری)</label>
        <input type="text" name="note" class="ad-input" maxlength="255" placeholder="مثلاً: شارژ سرور هتزنر">

        <button type="submit" class="fin-submit">ثبت</button>
      </form>
    </div>
  </div>
</div>

{{-- ══ چرا مشتری‌ها می‌روند ══
     دادهٔ خودِ مشتری در لحظهٔ حذفِ سرور. کدِ پایدار ذخیره می‌شود، پس شمردنی است.
     ⚠️ اگر مهاجرت اجرا نشده باشد $churn خالی است و این بخش کامل غیب می‌شود —
        نه خطا، نه بخشِ نیمه‌کاره. --}}
@if($churn !== [])
  <div class="ad-panel fin-churn" style="margin-top:16px">
    <div class="ad-panel-h">
      <h2>چرا مشتری‌ها سرورشان را حذف می‌کنند</h2>
      <span style="font-size:12px;color:var(--dim)">
        {{ fa_num($churn['answered']) }} پاسخ از {{ fa_num($churn['total']) }} حذف
      </span>
    </div>

    @if($churn['total'] === 0)
      <p style="padding:20px;color:var(--dim)">هنوز هیچ سرویسی حذف نشده است.</p>
    @else
      <div class="fin-cat">
        @if($churn['rows'] === [])
          <div class="fin-cat-h">هیچ‌کس دلیلش را نگفته است. پرسش اختیاری است، پس این خودش یک عدد است نه خطا.</div>
        @else
          <div class="fin-cat-h">درصدها نسبت به پاسخ‌های داده‌شده است، نه کلِ حذف‌ها.</div>
          @foreach($churn['rows'] as $r)
            <div class="fin-cat-row">
              <span>{{ $r['label'] }}</span>
              <div class="fin-cat-bar"><i style="width:{{ $r['pct'] }}%"></i></div>
              <span class="fin-num">{{ fa_num($r['count']) }} · {{ fa_num($r['pct']) }}٪</span>
            </div>
          @endforeach
        @endif

        @if($churn['silent'] > 0)
          <div class="fin-cat-row" style="opacity:.65">
            <span>بی‌پاسخ (دلیلی انتخاب نشد)</span>
            <div class="fin-cat-bar"></div>
            <span class="fin-num">{{ fa_num($churn['silent']) }}</span>
          </div>
        @endif
      </div>

      @if($churn['notes']->isNotEmpty())
        <div class="fin-cat">
          <div class="fin-cat-h">توضیح‌های آزادِ اخیر</div>
          @foreach($churn['notes'] as $n)
            <p style="margin:0 0 8px;font-size:12.5px;line-height:1.8">
              <span style="color:var(--dim)">{{ $n->cancelled_at ? sdate($n->cancelled_at) : '—' }}
                @if($n->terminate_reason) · {{ \App\Models\Service::terminateReasonLabel($n->terminate_reason) }}@endif
                —</span>
              {{ $n->terminate_reason_note }}
            </p>
          @endforeach
        </div>
      @endif
    @endif
  </div>
@endif

{{-- ══ آخرین ردیف‌ها ══ --}}
<div class="ad-panel" style="margin-top:16px">
  <div class="ad-panel-h"><h2>آخرین رویدادها</h2></div>
  @if($recent->isEmpty())
    <p style="padding:20px;color:var(--dim)">هنوز رویدادی ثبت نشده. با «ثبت رویداد مالی» شروع کنید یا منتظر اولین پرداخت مشتری بمانید.</p>
  @else
    <table class="ad-table">
      <thead><tr><th>تاریخ</th><th>نوع</th><th>شرح</th><th>منبع</th><th class="fin-num">مبلغ</th><th></th></tr></thead>
      <tbody>
        @php $kindLabel = ['capital'=>'سرمایه','revenue'=>'درآمد','tax_collected'=>'مالیات گرفته','expense'=>'هزینه','tax_paid'=>'مالیات داده','withdrawal'=>'برداشت','refund'=>'بازگشت وجه']; @endphp
        @foreach($recent as $e)
          <tr>
            <td dir="ltr">{{ sdate($e->occurred_at) }}</td>
            <td>{{ $kindLabel[$e->kind] ?? $e->kind }}{{ $e->category ? ' · '.($catLabels[$e->category] ?? $e->category) : '' }}</td>
            <td>{{ $e->note ?: '—' }}</td>
            <td>{{ $e->isAuto() ? 'خودکار' : 'دستی' }}</td>
            <td class="fin-num" style="color:{{ $e->direction === 'in' ? '#34d399' : '#ff6b6b' }}">
              {{ $e->direction === 'in' ? '+' : '−' }} {{ $t($e->amount) }}
            </td>
            <td>
              @unless($e->isAuto())
                <form method="post" action="/admin/finance/{{ $e->id }}/delete" data-confirm="حذف این ردیف؟" data-confirm-danger>
                  @csrf<button type="submit" style="background:none;border:0;color:#ff6b6b;cursor:pointer">حذف</button>
                </form>
              @endunless
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>

@include('admin.partials.finance-styles')

<script>
(function () {
  // دستهٔ هزینه فقط وقتی نوع = هزینه است
  var kind = document.getElementById('fin-kind'), wrap = document.getElementById('fin-cat-wrap');
  function sync() { wrap.style.display = kind.value === 'expense' ? 'block' : 'none'; }
  kind.addEventListener('change', sync); sync();

  // مبلغ: نمایش با جداکننده، ارسال با رقم لاتین خام
  var view = document.getElementById('fin-amount');
  var real = view.parentNode.querySelector('input[type=hidden]');
  real.name = 'amount'; real.disabled = false; view.removeAttribute('name');
  var toEn = function (s) { return s.replace(/[۰-۹]/g, function (d) { return d.charCodeAt(0) - 1776; }); };
  view.addEventListener('input', function () {
    var d = toEn(this.value).replace(/[^0-9]/g, '').slice(0, 13);
    real.value = d;
    this.value = d ? Number(d).toLocaleString('en-US').replace(/[0-9]/g, function (x) { return '۰۱۲۳۴۵۶۷۸۹'[x]; }) : '';
  });
  view.form.addEventListener('submit', function () { if (!real.value) view.focus(); });
})();
</script>

@endsection
