@extends('layouts.site')

@section('title', __('ui.os_badge').' — '.$product->name.' — '.__('ui.brand'))
@section('description', __('ui.os_lead'))

{{-- خلاصهٔ سفارشِ پیش از ورود (ممیزی ۴ — «اگر فقط یک کار در ۳۰ روز»).
     بی‌نشست، بی‌console: قیمتِ همهٔ دوره‌ها، جمعِ کل با مالیات، ضمانت.
     فقط دکمهٔ پرداخت به console می‌رود. کلاس‌های CSS همه موجودند (hero-sub،
     sla-table، badge، btn) — کلاسِ تازه ممنوع، بی‌خطا بی‌استایل می‌شود. --}}

@section('content')

<section class="hero hero-sub">
  <div class="container">
    <div class="hero-sub-inner">
      <span class="badge reveal"><span class="pulse"></span><span>{{ __('ui.os_badge') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ $product->name }}</h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ __('ui.os_lead') }}</p>
    </div>
  </div>
</section>

<section class="section" style="padding-top:10px">
  <div class="container" style="max-width:820px">

    @if(is_array($product->specs) && count($product->specs))
    <div class="sla-doc reveal" style="margin-bottom:22px">
      <h2 style="font-size:20px">{{ __('ui.os_specs') }}</h2>
      <ul>
        @foreach($product->specs as $spec)
        @if(is_string($spec) && $spec !== '')<li>{{ $spec }}</li>@endif
        @endforeach
      </ul>
    </div>
    @endif

    <div class="sla-doc reveal">
      <h2 style="font-size:20px">{{ __('ui.os_cycles_t') }}</h2>

      <div style="overflow-x:auto">
        <table class="sla-table">
          <thead>
            <tr>
              <th>{{ __('ui.os_col_cycle') }}</th>
              <th>{{ __('ui.os_col_monthly') }}</th>
              <th>{{ __('ui.os_col_saving') }}</th>
              <th>{{ __('ui.os_col_total') }}</th>
            </tr>
          </thead>
          <tbody>
            @foreach($rows as $r)
            <tr>
              <td><b>{{ $r['label'] }}</b></td>
              <td>{{ cloud_price($r['monthly']) }}{{ $r['months'] > 0 ? ' /'.__('ui.mo') : '' }}</td>
              <td>@if($r['saving'] > 0)<b>{{ $isFa ? fa_num($r['saving']) : $r['saving'] }}٪</b>@else — @endif</td>
              <td><b>{{ cloud_price($r['grand']) }}</b></td>
            </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      <ul style="margin-top:14px">
        @if($product->setup_fee > 0)
        <li>{{ __('ui.os_setup') }}: <b>{{ cloud_price($product->effectiveSetup()) }}</b></li>
        @endif
        @if($product->tax_percent > 0)
        <li>{{ __('ui.os_tax_note', ['p' => $isFa ? fa_num($product->tax_percent) : $product->tax_percent]) }}</li>
        @endif
        <li>{{ __('ui.hp_inc5') }}</li>
        <li>{{ __('ui.os_cycle_note') }}</li>
      </ul>

      <div style="margin-top:22px;display:flex;gap:12px;flex-wrap:wrap;align-items:center">
        {{-- تنها نقطهٔ عبور به console — و آگاهانه: کاربر همه‌چیز را دیده --}}
        <a class="btn btn-primary" href="{{ console_lroute('account.order', $product->slug) }}">{{ __('ui.os_continue') }}<svg class="icon dir" style="width:16px;height:16px"><use href="#i-arrow"/></svg></a>
        <span style="font-size:13px;color:var(--muted)">{{ __('ui.os_login_note') }}</span>
      </div>
    </div>

  </div>
</section>
@endsection
