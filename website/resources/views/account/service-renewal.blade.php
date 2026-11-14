@extends('panel.layout')
@section('title', __('ui.sr_title'))
@section('panel')
<div class="pnl-head"><div><h1>{{ __('ui.sr_title') }}</h1><p>{{ \App\Support\PanelText::text($service->name) }} · <b dir="ltr">{{ $service->domain }}</b></p></div></div>
<section class="pnl-sec"><div class="pnl-sec-b">
  <p>{{ __('ui.sr_intro') }}</p>
  <p>{{ __('ui.sr_current') }}: <b>{{ $service->cycleLabel() }}</b> · {{ __('ui.sr_due') }}: <b>{{ sdate($service->next_due_at) }}</b></p>
  <p>{{ __('ui.sr_notice') }}</p>
  @if($blocked)<p role="alert">{{ $blocked }}</p>@endif
  @if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
</div></section>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr));gap:16px">
@forelse($options as $cycle => $option)
  <section class="pnl-sec" style="margin:0"><div class="pnl-sec-b">
    <h2>{{ \App\Models\Service::labelFor($cycle) }}</h2>
    <p>{{ __($option['basis'] === 'contract' ? 'ui.sr_contract' : 'ui.sr_catalog') }}</p>
    <p>{{ __('ui.sr_subtotal') }}: <b>{{ invoice_money($option['price'], $service->currency_code) }}</b></p>
    <p>{{ __('ui.sr_tax') }}: {{ invoice_money($option['tax'], $service->currency_code) }}</p>
    <p>{{ __('ui.sr_total') }}: <b>{{ invoice_money($option['total'], $service->currency_code) }}</b></p>
    @if(! $blocked && isset($quotes[$cycle]))
    <form method="post" action="{{ lroute('account.services.renew.store', $service) }}">
      @csrf
      <input type="hidden" name="cycle" value="{{ $cycle }}">
      <input type="hidden" name="quote_hash" value="{{ $renewals->hash($quotes[$cycle]) }}">
      <button class="pnl-btn primary">{{ __('ui.sr_select') }}</button>
    </form>
    @endif
  </div></section>
@empty
  <p>{{ __('ui.sr_unavailable') }}</p>
@endforelse
</div>
<p><a class="pnl-btn" href="{{ lroute('account.hosting') }}">{{ __('ui.sr_back') }}</a></p>
@endsection
