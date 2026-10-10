@extends('panel.layout')
@section('title', __('panel.builder_title'))

@section('panel')

<div class="pnl-head">
  <div>
    <h1 class="dash-h">{{ __('ui.bcp_h') }}</h1>
    <p>{{ __('ui.bcp_sub') }}</p>
  </div>
</div>

@if($errors->any())
  <div class="pnl-sec" style="border-color:var(--danger-line)"><div class="pnl-sec-b" style="color:var(--danger);font-size:13.5px;line-height:2">@foreach($errors->all() as $e)<div>{{ \App\Support\PanelText::text($e) }}</div>@endforeach</div></div>
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
    <div class="pnl-sec-h"><h2>{{ __('panel.builder_order', ['ref' => $ref]) }}</h2></div>
    <div class="pnl-sec-b" style="font-size:13.5px;line-height:2.2">
      <div>{{ __('ui.bcp_host_pkg') }} <b>{{ $product->displayName() }}</b> — {{ invoice_money($hostPrice) }} / {{ \App\Models\Service::labelFor('monthly') }}</div>

      @if($check !== null)
        <div>{{ __('ui.lbl_domain') }} <b dir="ltr">{{ $domain }}</b> {{ __('ui.bcp_domain_free') }} — {{ invoice_money($domPrice) }} / {{ \App\Models\Service::labelFor('yearly') }}</div>
        <div style="color:var(--muted)">{{ __('panel.builder_dns') }}</div>

        <div style="margin-top:12px;border-top:1px solid var(--line);padding-top:12px">
          {{ __('ui.inv_subtotal') }}: {{ invoice_money($subtotal) }}
          · {{ __('ui.inv_tax') }} ({{ fa_num($taxPct) }}{{ __('ui.cs_js_pct') }}): {{ invoice_money($tax) }}
          · <b>{{ __('ui.inv_payable') }}: {{ invoice_money($subtotal + $tax) }}</b>
        </div>

        <form method="post" action="{{ lroute('account.builder.order') }}" style="margin-top:14px">
          @csrf
          <input type="hidden" name="ref" value="{{ $ref }}">
          <input type="hidden" name="plan" value="{{ $product->slug }}">
          <input type="hidden" name="quote_id" value="{{ $check['quote_id'] }}">
          <button class="btn btn-primary" type="submit">{{ __('ui.bcp_pay_btn') }}</button>
        </form>
      @else
        <div style="color:var(--danger)">{{ \App\Support\PanelText::text($quoteErr) }}</div>
        <a class="btn btn-glass" style="margin-top:10px"
           href="{{ lroute('account.builder.checkout') }}?ref={{ $ref }}&plan={{ $product->slug }}&domain={{ urlencode($domain) }}">{{ __('ui.bcp_retry') }}</a>
      @endif
    </div>
  </section>
</div>

@endsection
