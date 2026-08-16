@extends('panel.layout')
@section('title', 'تسویهٔ سایت‌ساز')

@section('panel')

<div class="pnl-head">
  <div>
    <h1 class="dash-h">استقرار سایت شما روی زیرساخت سرورنت</h1>
    <p>هاست + دامنه در یک فاکتور — پس از پرداخت، همه‌چیز خودکار انجام می‌شود.</p>
  </div>
</div>

@if($errors->any())
  <div class="pnl-sec" style="border-color:var(--danger-line)"><div class="pnl-sec-b" style="color:var(--danger);font-size:13.5px;line-height:2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>
@endif

@php
  $lineTax    = fn (int $a) => (int) round($a * $taxPct / 100);
  $hostPrice  = $product->priceForCycle('monthly');
  $domPrice   = (int) ($check['price_toman'] ?? 0);
  $subtotal   = $hostPrice + $domPrice;
  $tax        = $lineTax($hostPrice) + $lineTax($domPrice);
@endphp

<div class="co-wrap">
  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>سفارش سایت‌ساز — مرجع {{ $ref }}</h2></div>
    <div class="pnl-sec-b" style="font-size:13.5px;line-height:2.2">
      <div>پکیج هاست: <b>{{ $product->name }}</b> — {{ fa_num(number_format($hostPrice)) }} تومان / ماهانه</div>

      @if($check !== null)
        <div>دامنهٔ <b dir="ltr">{{ $domain }}</b> برای ثبت آزاد است —
          {{ fa_num(number_format($domPrice)) }} تومان / سالانه</div>
        <div style="color:var(--muted)">نیم‌سرورها به‌صورت خودکار روی نیم‌سرورهای سرورنت تنظیم می‌شود و پس از پرداخت،
          سایتِ ساخته‌شده بدونِ هیچ اقدامی از سمتِ شما روی هاست مستقر و در دسترس قرار می‌گیرد.</div>

        <div style="margin-top:12px;border-top:1px solid var(--line);padding-top:12px">
          جمع: {{ fa_num(number_format($subtotal)) }} تومان
          · مالیات ({{ fa_num($taxPct) }}٪): {{ fa_num(number_format($tax)) }} تومان
          · <b>قابل پرداخت: {{ fa_num(number_format($subtotal + $tax)) }} تومان</b>
        </div>

        <form method="post" action="{{ lroute('account.builder.order') }}" style="margin-top:14px">
          @csrf
          <input type="hidden" name="ref" value="{{ $ref }}">
          <input type="hidden" name="plan" value="{{ $product->slug }}">
          <input type="hidden" name="quote_id" value="{{ $check['quote_id'] }}">
          <button class="btn btn-primary" type="submit">صدور پیش‌فاکتور و پرداخت</button>
        </form>
      @else
        <div style="color:var(--danger)">{{ $quoteErr }}</div>
        <a class="btn btn-glass" style="margin-top:10px"
           href="{{ lroute('account.builder.checkout') }}?ref={{ $ref }}&plan={{ $product->slug }}&domain={{ urlencode($domain) }}">تلاش دوباره</a>
      @endif
    </div>
  </section>
</div>

@endsection
