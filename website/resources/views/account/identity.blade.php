@extends('panel.layout')
@section('title',__('identity.menu'))
@section('noindex','1')
@push('head')
<style>.identity-account form{margin-block:16px}.identity-account label{display:inline-flex;flex-direction:column;gap:6px;margin:6px;max-width:100%}.identity-account input:not([type=checkbox]):not([type=hidden]),.identity-account select{padding:10px 12px;border:1px solid var(--line-2);border-radius:10px;background:var(--bg2);color:var(--text);max-width:100%;font:inherit}.identity-account input:focus,.identity-account select:focus{outline:2px solid var(--cyan);outline-offset:2px}.identity-account code{overflow-wrap:anywhere}.identity-account th,.identity-account td{padding:12px;text-align:start;border-bottom:1px solid var(--line)}.identity-account table{min-width:620px}.identity-account .pnl-sec-b{overflow:auto}</style>
@endpush
@section('panel')
<div class="identity-account">
<div class="pnl-head"><h1>{{ __('identity.menu') }}</h1></div>
<div class="pnl-sec"><div class="pnl-sec-b"><p>{{ __('identity.balance') }}: {{ number_format($balance) }} · {{ __('identity.reserved') }}: {{ number_format($reserved) }} · {{ __('identity.available_balance') }}: {{ number_format($balance-$reserved) }} {{ __('identity.irt') }}</p>
<div style="display:flex;gap:14px;flex-wrap:wrap"><a class="pnl-btn" href="{{ lroute('account.topup') }}">{{ __('identity.topup') }}</a><a class="pnl-btn" href="{{ lroute('account.security') }}#sec-api">{{ __('identity.keys') }}</a><a class="pnl-btn" href="{{ lroute('identity.docs') }}">{{ __('identity.docs') }}</a></div></div></div>
@if(session('ok'))<p role="status">{{ session('ok') }}</p>@endif
@if(!$ready)<p>{{ __('identity.unavailable') }}</p>@elseif(!$eligible)<p>{{ __('identity.kyc_required') }}</p>@else
<section class="pnl-sec"><div class="pnl-sec-h"><h2>{{ __('identity.applications') }}</h2></div><div class="pnl-sec-b">
@foreach($applications as $a)<form method="post" action="{{ lroute('account.identity.application',$a) }}" style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0">@csrf<strong>{{ $a->name }}</strong><label>{{ __('identity.daily_cap') }}<input name="daily_cap_irt" type="number" min="1" value="{{ $a->daily_cap_irt }}" required></label><select name="status"><option value="active" @selected($a->status==='active')>{{ __('identity.available') }}</option><option value="disabled" @selected($a->status==='disabled')>{{ __('identity.disabled') }}</option></select><button class="pnl-btn">{{ __('identity.save') }}</button></form>@endforeach
<form method="post" action="{{ lroute('account.identity.activate') }}">@csrf<label>{{ __('identity.application') }} <input name="name" maxlength="80" required></label><label>{{ __('identity.daily_cap') }} <input type="number" name="daily_cap_irt" min="1" max="9000000000000" required></label><p><label><input type="checkbox" name="accept" value="1" required> {{ __('identity.accept_terms') }}</label> <a href="{{ lroute('identity.terms') }}">{{ __('identity.terms') }}</a></p><button class="pnl-btn primary">{{ __('identity.create') }}</button></form>
</div></section>@endif
<section class="pnl-sec"><div class="pnl-sec-h"><h2>{{ __('identity.catalog') }}</h2></div><div class="pnl-sec-b"><form method="get" action="{{ lroute('account.identity') }}"><label>{{ __('identity.service') }} <select name="service">@foreach($services as $s)<option value="{{ $s['slug'] }}" @selected(($selected['slug']??null)===$s['slug'])>{{ $s['name'] }} — {{ __('identity.'.$s['status']) }}</option>@endforeach</select></label><button class="pnl-btn">{{ __('identity.view') }}</button></form>
@if($selected)
<h3>{{ __('identity.service_'.$selected['slug']) }}</h3>
@php $card=collect($services)->firstWhere('slug',$selected['slug']); @endphp
<p>{{ $card['price']===null ? __('identity.price_pending') : number_format($card['price']).' '.__('identity.irt') }}</p>
@if($card['active'] && $checkout && $applications->where('status','active')->isNotEmpty())
<form method="post" action="{{ lroute('account.identity.inquire') }}" autocomplete="off">@csrf
<input type="hidden" name="service" value="{{ $selected['slug'] }}"><input type="hidden" name="idempotency_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<input type="hidden" name="price_version" value="{{ $checkout['price_version'] }}">
<label>{{ __('identity.application') }}<select name="application_id">@foreach($applications->where('status','active') as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></label>
@foreach($selected['operations'][0]['input']['properties'] as $field=>$spec)
<p><label><code dir="ltr">{{ $field }}</code> @if(($spec['type']??'string')==='boolean')<select name="input[{{ $field }}]"><option value="false">false</option><option value="true">true</option></select>@else<input type="text" name="input[{{ $field }}]" dir="auto" maxlength="{{ $spec['maxLength']??256 }}" @required(in_array($field,$selected['operations'][0]['input']['required']??[],true))>@endif</label></p>
@endforeach
<div class="identity-checkout" aria-label="{{ __('identity.checkout_summary') }}"><h4>{{ __('identity.checkout_summary') }}</h4><p>{{ __('identity.preview_net') }}: {{ number_format($checkout['subtotal_irt']) }} {{ __('identity.irt') }}</p><p>{{ __('identity.preview_tax') }}: {{ number_format($checkout['tax_irt']) }} {{ __('identity.irt') }}</p><p><strong>{{ __('identity.preview_total') }}: {{ number_format($checkout['total_irt']) }} {{ __('identity.irt') }}</strong></p></div>
<label>{{ __('identity.max_charge') }} <input type="number" min="0" name="max_charge_irt" value="{{ $checkout['total_irt'] }}" required></label><p><label><input type="checkbox" name="real_charge" value="1" required> {{ __('identity.real_charge') }}</label></p><button class="pnl-btn primary">{{ __('identity.execute') }}</button></form>
@endif @endif
<p>{{ __('identity.sandbox_help') }}</p><form method="post" action="{{ lroute('account.identity.sandbox') }}">@csrf<button class="pnl-btn">{{ __('identity.sandbox') }}</button></form>
</div></section>
<section class="pnl-sec"><div class="pnl-sec-h"><h2>{{ __('identity.history') }}</h2></div><div class="pnl-sec-b" style="overflow:auto">
<form method="get"><label>{{ __('identity.finance') }}<select name="financial_status"><option value="">—</option>@foreach(['reserved','captured','released','pending_reconciliation','refunded'] as $s)<option value="{{ $s }}">{{ __('identity.state_'.$s) }}</option>@endforeach</select></label><button class="pnl-btn">{{ __('identity.filter') }}</button></form>
<table style="width:100%"><thead><tr>@foreach(['date','service','states','charged','request'] as $h)<th>{{ __('identity.'.$h) }}</th>@endforeach</tr></thead><tbody>
@foreach($history??[] as $r)<tr><td>{{ $r->created_at }}</td><td>{{ __('identity.service_'.$r->service) }}</td><td>{{ __('identity.state_'.$r->processing_status) }} · {{ __('identity.state_'.$r->financial_status) }} · {{ $r->inquiry_outcome ? __('identity.state_'.$r->inquiry_outcome) : '—' }}</td><td>{{ number_format($r->charged_irt) }} {{ __('identity.irt') }}</td><td><a href="{{ lroute('account.identity.result',$r->public_id) }}"><code>{{ $r->public_id }}</code></a></td></tr>@endforeach
</tbody></table>@if($history){{ $history->links() }}@endif
</div></section>
@if($keys->isNotEmpty())
<section class="pnl-sec"><div class="pnl-sec-h"><h2>{{ __('identity.keys') }}</h2></div><div class="pnl-sec-b">
@foreach($keys as $key)<article style="margin:20px 0"><h3>{{ $key->name }}</h3><p><code>{{ implode(', ',array_filter((array)$key->abilities,fn($s)=>str_starts_with($s,'identity:'))) }}</code></p><p>{{ __('identity.last_used') }}: {{ $key->last_used_at??'—' }} · {{ __('ui.sec_api_expiry') }}: {{ $key->expires_at??'—' }}</p>
<form method="post" action="{{ lroute('account.identity.key-update',$key) }}">@csrf<label>{{ __('identity.daily_cap') }}<input type="number" name="daily_spend_cap_irt" min="1" value="{{ $key->daily_spend_cap_irt }}" required></label><label>{{ __('ui.sec_api_cidrs') }}<input name="cidrs" maxlength="500" value="{{ implode(', ',(array)$key->allowed_cidrs) }}" dir="ltr"></label><label>{{ __('ui.sec_api_expiry') }}<input type="number" name="expires_days" min="1" max="1825"></label><button class="pnl-btn">{{ __('identity.save') }}</button></form>
<form method="post" action="{{ lroute('account.security.token.delete',$key) }}">@csrf<button class="pnl-btn">{{ __('identity.revoke') }}</button></form></article>@endforeach
</div></section>@endif
</div>
@endsection
