@extends('panel.layout')
@section('title', __('sms.title'))
@section('noindex','1')
@push('head')<link rel="stylesheet" href="{{ asset_ver('assets/css/sms-resale.css') }}">@endpush
@section('panel')
<div class="sms-product sms-workspace"><h1>{{ __('sms.title') }}</h1><p>{{ __('sms.credit_separate') }}</p>
@if($subscription)
<section class="sms-section"><h2>{{ __('sms.plan_'.$subscription->plan) }}</h2><p>{{ __('sms.state_'.$subscription->state) }}</p><dl><div><dt>{{ __('sms.username') }}</dt><dd><bdi>{{ $subscription->username }}</bdi></dd></div><div><dt>{{ __('sms.expiry') }}</dt><dd>{{ $subscription->confirmed_until?->format('Y-m-d') ?? '—' }}</dd></div><div><dt>{{ __('sms.credit') }}</dt><dd>{{ $subscription->credit_irr !== null ? $subscription->credit_irr.' '.__('sms.rial') : '—' }}</dd></div><div><dt>{{ __('sms.checked') }}</dt><dd>{{ $subscription->checked_at?->toIso8601String() ?? '—' }}</dd></div></dl>
@if($subscription->pending_invoice_id)<a class="sms-button" href="{{ lroute('account.invoice', $subscription->pending_invoice_id) }}">{{ __('sms.invoice') }}</a>@endif
@if($subscription->upstream_user_id)<a class="sms-button" href="{{ $panelUrl }}" target="_blank" rel="noopener noreferrer">{{ __('sms.open_panel') }} ↗</a><p>{{ __('sms.login_help') }}</p>@endif
<p>{{ __('sms.pending_help') }}</p></section>
@if($subscription->state==='active' && !$subscription->pending_invoice_id)<section class="sms-section"><h2>{{ __('sms.upgrade') }}</h2><p>{{ __('sms.upgrade_help') }}</p><form method="post" action="{{ lroute('account.sms.upgrade') }}">@csrf<label>{{ __('sms.plans') }}<select name="plan">@foreach($plans as $plan)@if($plan['first_irt']>config('sms_resale.plans.'.$subscription->plan.'.first_irt'))<option value="{{ $plan['key'] }}" @disabled(!$plan['available'])>{{ __('sms.plan_'.$plan['key']) }}</option>@endif @endforeach</select></label><button class="sms-button" type="submit">{{ __('sms.upgrade') }}</button></form></section>@endif
@else
<section class="sms-section"><h2>{{ __('sms.choose') }}</h2><p>{{ __('sms.identity_help') }}</p><form method="post" action="{{ lroute('account.sms.order') }}">@csrf
<label>{{ __('sms.plans') }}<select name="plan" required>@foreach($plans as $plan)<option value="{{ $plan['key'] }}" @selected(request('plan')===$plan['key']) @disabled(!$plan['available'])>{{ __('sms.plan_'.$plan['key']) }} · {{ number_format($plan['first_irt']) }} {{ __('sms.toman') }}</option>@endforeach</select></label>
<label>{{ __('sms.national_code') }}<input name="national_code" inputmode="numeric" pattern="[0-9]{10}" maxlength="10" required autocomplete="off"></label><label>{{ __('sms.birth_date') }}<input name="birth_date" type="date" required autocomplete="off"></label>
<label class="sms-consent"><input name="consent" type="checkbox" value="1" required><span>{{ __('sms.consent') }}</span></label><p>{{ __('sms.initial_topup') }}</p><button class="sms-button" type="submit" @disabled(!collect($plans)->contains('available', true))>{{ __('sms.order') }}</button></form>
@if(!collect($plans)->contains('available', true))<p>{{ __('sms.unavailable') }}</p>@endif</section>
@endif
<a href="{{ lroute('sms.home') }}">{{ __('sms.pricing_link') }}</a></div>
@endsection
