@extends('panel.layout')
@section('title', __('marketing.title'))
@push('head')<link rel="stylesheet" href="{{ asset_ver('assets/css/campaigns.css') }}">@endpush
@section('panel')
<div class="pnl-page-head"><h1>{{ __('marketing.title') }}</h1></div>
@if(session('ok'))<div class="pnl-sec"><div class="pnl-sec-b" role="status">{{ session('ok') }}</div></div>@endif
@if($errors->any())<div class="pnl-sec"><div class="pnl-sec-b" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div></div>@endif
@if(!$ready)<p>{{ __('marketing.not_ready') }}</p>
@else
<div class="cmp-grid">
@forelse($offers as $offer)
@php
  $red = $redemptions->get($offer->id);
  $state = $offer->revoked_at ? 'revoked' : (in_array($red?->status, ['committed','refunded'],true) ? 'used' : (!$offer->expires_at->isFuture() ? 'expired_state' : ($red && in_array($red->status,['reserved','reconciliation'],true) ? 'reserved_state' : 'active')));
  $rule = $offer->snapshot['rule'];
  $expiry = $offer->expires_at->copy()->setTimezone($offer->snapshot['timezone']);
@endphp
<article class="cmp-offer">
  <span class="cmp-status">{{ __('marketing.'.$state) }}</span>
  <h2>{{ $offer->snapshot['message']['service_name'] }}</h2>
  <p class="cmp-value">{{ $rule['type'] === 'percent' ? number_format($rule['value']/100,2).'%' : invoice_money($rule['value'],$rule['currency']) }}</p>
  <dl><dt>{{ __('marketing.cap') }}</dt><dd>{{ invoice_money($rule['max_discount'],$rule['currency']) }}</dd>
    <dt>{{ __('marketing.minimum') }}</dt><dd>{{ invoice_money($rule['min_purchase'],$rule['currency']) }}</dd>
    <dt>{{ __('marketing.expires') }}</dt><dd><time datetime="{{ $offer->expires_at->toIso8601String() }}">{{ blog_date($expiry->toDateString()) }} · {{ $expiry->format('H:i') }} ({{ $offer->snapshot['timezone'] }})</time></dd>
  </dl>
  <code dir="ltr">{{ $offer->code }}</code>
  <p>{{ __('marketing.no_cash') }}</p>
  @if($state === 'active')<a class="pnl-btn primary" href="{{ lroute('account.offers.open', $offer) }}">{{ __('marketing.use') }}</a>@endif
</article>
@empty<p>{{ __('marketing.empty') }}</p>@endforelse
</div>
{{ $offers->links() }}
<section class="pnl-sec"><div class="pnl-sec-h"><h2>{{ __('marketing.preferences') }}</h2></div><div class="pnl-sec-b">
<form method="post" action="{{ lroute('account.offers.preferences') }}" class="cmp-form">
@csrf
<label>{{ __('marketing.consent') }}<select name="consent">
@foreach(['unknown'=>'unknown_consent','granted'=>'yes_consent','withdrawn'=>'no_consent'] as $key=>$label)
<option value="{{ $key }}" @selected(old('consent',$preference->consent ?: 'unknown') === $key)>{{ __('marketing.'.$label) }}</option>
@endforeach
</select></label>
<p>{{ __('marketing.consent_help') }}</p>
<p>{{ __('marketing.birthday_help') }}</p>
<div class="cmp-grid">
<label>{{ __('marketing.calendar') }}<select name="birth_calendar"><option value="">—</option>@foreach(['jalali','gregorian'] as $calendar)<option value="{{ $calendar }}" @selected(old('birth_calendar',$preference->birth_calendar) === $calendar)>{{ __('marketing.'.$calendar) }}</option>@endforeach</select></label>
<label>{{ __('marketing.month') }}<input type="number" name="birth_month" min="1" max="12" value="{{ old('birth_month',$preference->birth_month) }}"></label>
<label>{{ __('marketing.day') }}<input type="number" name="birth_day" min="1" max="31" value="{{ old('birth_day',$preference->birth_day) }}"></label>
<label>{{ __('marketing.year') }}<input type="number" name="birth_year" min="1200" max="2200" value="{{ old('birth_year',$preference->birth_year) }}"></label>
</div>
<button class="pnl-btn primary" type="submit">{{ __('marketing.save') }}</button>
</form></div></section>
@endif
@endsection
