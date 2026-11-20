@extends('panel.layout')
@section('title', __('ui.sr_title'))
@section('noindex', '1')
@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/account-api.css') }}">
<link rel="stylesheet" href="{{ asset_ver('assets/css/hosting-renewal.css') }}">
@endpush
@section('panel')
@php
  $monthlyOption = $options['monthly'] ?? null;
  $cloudBasis = collect($options)->contains(fn ($option) => $option['basis'] === 'cloud_catalog');
  $contractBasis = collect($options)->contains(fn ($option) => $option['basis'] === 'contract');
@endphp
<div class="api-workspace rn-workspace" data-renewal-workspace>
  <header class="aw-hero rn-hero">
    <div class="rn-hero-copy">
      <a class="rn-back" href="{{ $returnUrl }}"><svg class="icon" aria-hidden="true"><use href="#i-arrow"/></svg>{{ __('ui.sr_back') }}</a>
      <p class="aw-eyebrow"><span class="rn-live-dot" aria-hidden="true"></span>{{ __('renewal.eyebrow') }}</p>
      <h1>{{ __('ui.sr_title') }}</h1>
      <p class="aw-lead">{{ __('renewal.lead') }}</p>
      <div class="rn-service">
        <span class="aw-card-icon" aria-hidden="true"><svg class="icon"><use href="#i-box"/></svg></span>
        <div><b>{{ \App\Support\PanelText::text($service->name) }}</b>@if($service->domain)<bdi dir="ltr">{{ $service->domain }}</bdi>@endif</div>
      </div>
    </div>
    <div class="rn-calendar" aria-hidden="true">
      <div class="rn-calendar-ring"></div>
      <div class="rn-calendar-face"><span class="rn-calendar-top"><i></i><i></i></span><svg class="icon"><use href="#i-clock"/></svg><div class="rn-calendar-dates">@foreach(['01','03','06','12'] as $n)<span>{{ $n }}</span>@endforeach</div><span class="rn-calendar-check"><svg class="icon"><use href="#i-check"/></svg></span></div>
    </div>
  </header>

  <section class="rn-overview" aria-label="{{ __('renewal.overview') }}">
    <div><span class="rn-overview-icon"><svg class="icon" aria-hidden="true"><use href="#i-clock"/></svg></span><p>{{ __('ui.sr_current') }}<b>{{ $service->cycleLabel() }}</b></p></div>
    <div><span class="rn-overview-icon"><svg class="icon" aria-hidden="true"><use href="#i-calendar"/></svg></span><p>{{ __('ui.sr_due') }}<b>{{ sdate($service->next_due_at) }}</b></p></div>
    <div><span class="rn-overview-icon"><svg class="icon" aria-hidden="true"><use href="#i-shield"/></svg></span><p>{{ __('renewal.payment_label') }}<b>{{ __('renewal.payment_value') }}</b></p></div>
  </section>

  @if($blocked || $errors->any())
    <div class="aw-alert is-warn" role="alert"><svg class="icon" aria-hidden="true"><use href="#i-info"/></svg><div>@if($blocked)<p>{{ $blocked }}</p>@endif @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div></div>
  @endif

  @if(count($options) === 1)<div class="aw-alert"><svg class="icon" aria-hidden="true"><use href="#i-info"/></svg><p>{{ __('renewal.available_periods_notice') }}</p></div>@endif

  @if($options)
    <section class="rn-periods" aria-labelledby="rn-periods-title">
      <div class="rn-section-head"><div><p class="aw-eyebrow">{{ __('renewal.choose_eyebrow') }}</p><h2 id="rn-periods-title">{{ __('renewal.choose_title') }}</h2></div><span class="aw-badge"><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>{{ __('renewal.no_setup') }}</span></div>
      <div class="rn-plan-grid">
      @foreach($options as $cycle => $option)
        @php
          $months = \App\Models\Service::monthsIn($cycle);
          $comparison = $monthlyOption && $monthlyOption['basis'] === $option['basis'] ? $monthlyOption['price'] * $months : 0;
          $saving = $comparison > $option['price'] ? $comparison - $option['price'] : 0;
          // Show the actual saving after catalog rounding/floors, never an advertised percentage the quote cannot deliver.
          $savingPct = $comparison > 0 ? (int) floor($saving * 100 / $comparison) : 0;
          $current = $cycle === $service->cycle;
        @endphp
        <article class="rn-plan {{ $saving > 0 ? 'has-saving' : '' }}" data-renewal-period="{{ $cycle }}">
          <div class="rn-plan-top"><span class="rn-period-number"><bdi>{{ fa_num($months) }}</bdi><small>{{ trans_choice('renewal.month_unit', $months) }}</small></span>@if($savingPct > 0)<span class="rn-saving-badge">{{ __('renewal.discount', ['percent' => fa_num($savingPct)]) }}</span>@elseif($current)<span class="aw-badge">{{ __('renewal.current_badge') }}</span>@endif</div>
          <h3>{{ \App\Models\Service::labelFor($cycle) }}</h3>
          <p class="rn-plan-caption">{{ trans_choice('renewal.period_caption', $months, ['months' => fa_num($months)]) }}</p>
          <div class="rn-price"><small>{{ __('ui.sr_total') }}</small><strong><bdi>{{ fa_num(number_format($option['total'])) }}</bdi><small>{{ __('renewal.currency') }}</small></strong><span>{{ __('renewal.includes_tax') }}</span></div>
          <dl class="rn-breakdown"><div><dt>{{ __('ui.sr_subtotal') }}</dt><dd>{{ invoice_money($option['price'], $service->currency_code) }}</dd></div><div><dt>{{ __('ui.sr_tax') }}</dt><dd>{{ invoice_money($option['tax'], $service->currency_code) }}</dd></div></dl>
          <p class="rn-saving {{ $saving > 0 ? '' : 'is-flexible' }}">@if($saving > 0)<svg class="icon" aria-hidden="true"><use href="#i-sparkles"/></svg><span>{{ __('renewal.saving') }} <b>{{ invoice_money($saving, $service->currency_code) }}</b><small>{{ __('renewal.compared_monthly') }}</small></span>@else<svg class="icon" aria-hidden="true"><use href="#i-clock"/></svg><span>{{ __('renewal.flexible') }}<small>{{ __('renewal.flexible_note') }}</small></span>@endif</p>
          @if(! $blocked && isset($quotes[$cycle]))
          <form method="post" action="{{ lroute('account.services.renew.store', $service) }}">
            @csrf
            <input type="hidden" name="cycle" value="{{ $cycle }}">
            <input type="hidden" name="quote_hash" value="{{ $renewals->hash($quotes[$cycle]) }}">
            <button class="pnl-btn primary aw-btn rn-select" aria-label="{{ __('renewal.select_period', ['period' => \App\Models\Service::labelFor($cycle)]) }}">{{ __('renewal.select') }}<svg class="icon" aria-hidden="true"><use href="#i-arrow"/></svg></button>
          </form>
          @else
            <span class="rn-unavailable">{{ __('renewal.resolve_first') }}</span>
          @endif
        </article>
      @endforeach
      </div>
    </section>

    <aside class="rn-price-note"><span class="aw-card-icon" aria-hidden="true"><svg class="icon"><use href="#i-info"/></svg></span><div><h2>{{ __('renewal.price_basis') }}</h2><p>{{ __($cloudBasis ? 'renewal.cloud_price_basis' : ($contractBasis ? 'ui.sr_contract' : 'ui.sr_catalog')) }}</p></div></aside>
    <section class="aw-card rn-next" aria-labelledby="rn-next-title"><div class="aw-card-head"><h2 id="rn-next-title">{{ __('renewal.next_title') }}</h2></div><ol class="rn-steps">@foreach(['choose','invoice','activate'] as $step)<li><span>{{ fa_num($loop->iteration) }}</span><div><h3>{{ __('renewal.step_'.$step) }}</h3><p>{{ __('renewal.step_'.$step.'_note') }}</p></div></li>@endforeach</ol></section>
  @else
    <section class="aw-empty rn-empty"><span class="aw-empty-icon" aria-hidden="true"><svg class="icon"><use href="#i-clock"/></svg></span><h2>{{ __('renewal.empty_title') }}</h2><p>{{ $unavailableMessage }}</p><div class="aw-actions"><a class="pnl-btn primary aw-btn" href="{{ lroute('account.ticket.new') }}">{{ __('renewal.contact') }}</a><a class="pnl-btn aw-btn" href="{{ $returnUrl }}">{{ __('ui.sr_back') }}</a></div></section>
  @endif
</div>
@endsection
