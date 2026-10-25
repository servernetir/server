@extends('admin.layout')
@section('title', 'تراکنش‌ها و اعتبار')
@section('nav_transactions', 'on')
@section('content')
@include('admin.partials.finance-periods')

@php
  $money = function ($amount, $cur = 'IRT') {
    if ($cur === 'IRT') return fa_num(number_format((int) $amount)).' ت';
    if ($cur === 'EUR') return '€'.number_format($amount / 100, 2);
    return fa_num(number_format((int) $amount)).' '.$cur;
  };
  $payStatus = ['paid'=>['موفق','#34d399'],'pending'=>['در انتظار','#fbbf24'],'redirected'=>['هدایت‌شده','#22d3ee'],'failed'=>['ناموفق','#ff6b6b'],'canceled'=>['لغو','var(--dim)'],'expired'=>['منقضی','var(--dim)']];
  $gwLabel = ['zarinpal'=>'زرین‌پال','bale'=>'بله','bank_transfer'=>'واریز به حساب'];
  $reasonLabel = ['topup'=>'افزایش اعتبار','invoice'=>'پرداخت فاکتور','refund'=>'بازگشت وجه','adjustment'=>'اصلاح دستی'];
@endphp

@unless($ready)
  <div class="ad-note" style="border-color:#fbbf24;color:#fbbf24;line-height:2">
    جدول پرداخت‌ها/اعتبار هنوز روی این سرور ساخته نشده است. یک بار مهاجرت دیتابیس را اجرا کنید.
  </div>
@endunless

<p class="ad-note">{{ __('finance.receipt_scope') }}</p>
<form method="get" action="{{ route('admin.transactions') }}" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:18px;align-items:end">
<label>{{ __('finance.search') }}<input class="ad-input" name="q" value="{{ $q??'' }}" maxlength="150"></label>
<label>{{ __('finance.from') }}<input type="date" name="from" class="ad-input" value="{{ $filters['from']??'' }}"></label><label>{{ __('finance.to') }}<input type="date" name="to" class="ad-input" value="{{ $filters['to']??'' }}"></label>
<label>{{ __('finance.gateway') }}<select name="gateway" class="ad-input"><option value="all">{{ __('finance.all') }}</option>@foreach($gateways as $g)<option value="{{ $g }}" @selected($gateway===$g)>{{ $g }}</option>@endforeach</select></label>
<label>{{ __('finance.currency') }}<select name="currency" class="ad-input"><option value="">{{ __('finance.all') }}</option>@foreach(array_keys(\App\Services\Finance\AccountingMoney::EXPONENTS) as $c)<option @selected(($filters['currency']??'')===$c)>{{ $c }}</option>@endforeach</select></label>
<input type="hidden" name="status" value="{{ $status }}"><button class="ad-btn">{{ __('finance.apply') }}</button><a href="{{ route('admin.transactions.export',request()->query()) }}">{{ __('finance.export') }}</a><a href="{{ route('admin.transactions') }}">{{ __('finance.reset') }}</a></form>
{{-- ══ آمار کلیدی ══ --}}
<div class="tx-kpis">
  <div class="tx-kpi accent">
    <span class="tx-kpi-l">اعتبار کل مشتریان (بدهی ما)</span>
    <b class="tx-kpi-v">{{ $money($kpis['credit']) }}</b>
    <small>مجموع موجودیِ کیف پولِ همهٔ مشتریان — پولی که باید بابتش خدمت بدهیم</small>
  </div>
  <div class="tx-kpi">
    <span class="tx-kpi-l">مجموع افزایش اعتبار</span>
    <b class="tx-kpi-v">{{ $money($kpis['topups']) }}</b>
    <small>وصول شارژهای تومان در فیلتر انتخابی؛ درآمد فروش نیست</small>
  </div>
  <div class="tx-kpi">
    <span class="tx-kpi-l">مجموع پرداخت‌های موفق</span>
    <b class="tx-kpi-v">{{ $money($kpis['paidSum']) }}</b>
    <small>وصول بیرونی در فیلتر انتخابی؛ تسویه اعتبار و هدیه جداست</small>
  </div>
  <div class="tx-kpi">
    <span class="tx-kpi-l">مشتریانِ دارای اعتبار</span>
    <b class="tx-kpi-v">{{ fa_num($kpis['creditCustomers']) }}</b>
    <small>تعداد مشتریان با مانده مثبت در حداقل یک ارز تا انتهای بازه</small>
  </div>
</div>

@if($creditByCurrency->count() > 1)
  <div class="ad-note" style="line-height:2">
    اعتبار به تفکیک ارز:
    @foreach($creditByCurrency as $cur => $bal)
      <b style="margin-inline-start:10px">{{ $money($bal, $cur) }}</b>
    @endforeach
  </div>
@endif

{{-- ══ وصول به تفکیک ارز (گزارشِ چندارزی) ══ --}}
@if($revenueByCurrency->isNotEmpty())
<div class="ad-panel">
  <div class="ad-panel-h"><h3>درآمد به تفکیک ارز</h3></div>
  <table class="ad-table">
    <thead><tr><th>ارز</th><th>وصول بیرونی (پرداخت موفق)</th><th>تعداد تراکنش</th></tr></thead>
    <tbody>
      @foreach($revenueByCurrency as $row)
      <tr>
        <td><b>{{ $row->currency_code === 'IRT' ? 'تومان (IRT)' : $row->currency_code }}</b></td>
        <td style="color:#34d399;font-weight:700">{{ $money($row->total, $row->currency_code) }}</td>
        <td dir="ltr" style="color:var(--muted)">{{ fa_num($row->cnt) }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
  <p style="padding:10px 16px 14px;margin:0;font-size:12px;color:var(--dim)">تومان و یورو جدا نمایش داده می‌شوند؛ چون واحدشان هم‌مقیاس نیست، جمعِ خام معنا ندارد. این وصول نقدی ثبت‌شده است؛ افزایش اعتبار را هم شامل می‌شود و برابر درآمد فروش نیست.</p>
</div>
@endif

{{-- ══ مشتریانِ دارای اعتبار ══ --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h3>مشتریانِ دارای اعتبار</h3></div>
  @if($topCredit->isEmpty())
    <p style="padding:16px;color:var(--dim)">هیچ مشتری‌ای اعتبار ندارد.</p>
  @else
    <table class="ad-table">
      <thead><tr><th>مشتری</th><th>ارز</th><th>موجودی اعتبار</th></tr></thead>
      <tbody>
        @foreach($topCredit as $row)
        <tr @if($row->customer) onclick="location='/admin/customers/{{ $row->customer_id }}'" style="cursor:pointer" @endif>
          <td>{{ $row->customer?->displayName() ?? '—' }} <span dir="ltr" style="color:var(--dim);font-size:12px">{{ $row->customer?->code }}</span></td>
          <td>{{ $row->currency_code }}</td>
          <td style="color:#34d399;font-weight:700">{{ $money($row->bal, $row->currency_code) }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>

{{-- ══ تراکنش‌های پرداخت ══ --}}
<div class="ad-panel">
  @php
    $txTab = fn ($st) => '/admin/transactions?'.http_build_query(array_filter(
        array_replace(request()->except(['page','credit_page']), ['status' => $st]), fn ($v) => $v !== ''));
    $txInp = 'background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:7px 10px;font:inherit;font-size:12.5px';
  @endphp
  <div class="ad-panel-h" style="flex-wrap:wrap;gap:10px"><h3>تراکنش‌های پرداخت</h3>

    <div class="ad-tabs">
      <a href="{{ $txTab('all') }}" class="{{ $status === 'all' ? 'on' : '' }}">همه</a>
      <a href="{{ $txTab('paid') }}" class="{{ $status === 'paid' ? 'on' : '' }}">موفق</a>
      <a href="{{ $txTab('pending') }}" class="{{ $status === 'pending' ? 'on' : '' }}">در انتظار</a>
      <a href="{{ $txTab('failed') }}" class="{{ $status === 'failed' ? 'on' : '' }}">ناموفق</a>
    </div>
  </div>
  @if($payments->isEmpty())
    <p style="padding:16px;color:var(--dim)">تراکنشی با این فیلتر نیست.</p>
  @else
    <div style="overflow-x:auto">
    <table class="ad-table">
      <thead><tr><th>تاریخ</th><th>مشتری</th><th>درگاه</th><th>مبلغ</th><th>وضعیت</th><th>پیگیری / کارت</th></tr></thead>
      <tbody>
        @foreach($payments as $p)
        <tr>
          <td dir="ltr" style="color:var(--muted);white-space:nowrap">{{ stime($p->paid_at ?? $p->created_at) }}</td>
          <td @if($p->customer) style="cursor:pointer" onclick="location='/admin/customers/{{ $p->customer_id }}'" @endif>
            {{ $p->customer?->displayName() ?? '—' }}
          </td>
          <td>{{ $gwLabel[$p->gateway] ?? $p->gateway }}</td>
          <td style="white-space:nowrap">{{ $money($p->amount, $p->currency_code ?? 'IRT') }}</td>
          <td>
            @php $ps = $payStatus[$p->status] ?? [$p->status, 'var(--muted)']; @endphp
            <span class="ad-badge" style="background:{{ $ps[1] }}22;color:{{ $ps[1] }}">{{ $ps[0] }}</span>
          </td>
          <td dir="ltr" style="color:var(--muted);font-size:12px">
            {{ $p->ref_id ?: $p->external_ref ?: '—' }}
            @if($p->card_mask)<div style="color:var(--dim)">{{ $p->card_mask }}</div>@endif
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    <div style="padding:10px 4px 0">{{ $payments->onEachSide(1)->links() }}</div>
  @endif
</div>

{{-- ══ دفترِ اعتبار (ریز) ══ --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h3>دفترِ اعتبار — گردشِ کیف پول</h3></div>
  @if($credit->isEmpty())
    <p style="padding:16px;color:var(--dim)">هنوز گردشِ اعتباری ثبت نشده.</p>
  @else
    <div style="overflow-x:auto">
    <table class="ad-table">
      <thead><tr><th>تاریخ</th><th>مشتری</th><th>بابت</th><th>تغییر</th><th>موجودی پس از آن</th></tr></thead>
      <tbody>
        @foreach($credit as $e)
        <tr>
          <td dir="ltr" style="color:var(--muted);white-space:nowrap">{{ stime($e->created_at) }}</td>
          <td @if($e->customer) style="cursor:pointer" onclick="location='/admin/customers/{{ $e->customer_id }}'" @endif>{{ $e->customer?->displayName() ?? '—' }}</td>
          <td>{{ $reasonLabel[$e->reason] ?? $e->reason }}</td>
          <td style="white-space:nowrap;font-weight:700;color:{{ $e->amount >= 0 ? '#34d399' : '#ff6b6b' }}">
            {{ $e->amount >= 0 ? '+' : '−' }}{{ $money(abs($e->amount), $e->currency_code) }}
          </td>
          <td dir="ltr" style="color:var(--muted)">{{ $money($e->balance_after, $e->currency_code) }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
    </div>
    @if($credit instanceof \Illuminate\Contracts\Pagination\Paginator){{ $credit->links() }}@endif
  @endif
</div>

<style>
.tx-kpis{ display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:18px }
@media(max-width:900px){ .tx-kpis{ grid-template-columns:repeat(2,1fr) } }
@media(max-width:520px){ .tx-kpis{ grid-template-columns:1fr } }
.tx-kpi{ background:var(--surface); border:1px solid var(--line); border-radius:14px; padding:16px 18px }
.tx-kpi.accent{ background:linear-gradient(135deg,rgba(34,211,238,.10),var(--surface)); border-color:rgba(34,211,238,.3) }
.tx-kpi-l{ display:block; font-size:12.5px; color:var(--muted); margin-bottom:8px }
.tx-kpi-v{ display:block; font-size:22px; font-weight:800; color:var(--text); font-variant-numeric:tabular-nums; letter-spacing:.3px }
.tx-kpi.accent .tx-kpi-v{ color:#22d3ee }
.tx-kpi small{ display:block; margin-top:6px; font-size:11px; color:var(--dim); line-height:1.7 }
</style>
@endsection
