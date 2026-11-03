@extends('admin.layout')
@section('title', __('sms.title'))
@section('nav_sms','on')
@section('content')
<div class="ad-panel" style="padding:24px"><h1>{{ __('sms.title') }}</h1><p>{{ __('sms.admin_notice') }}</p>@if(!$controls->ready())<p>{{ __('sms.schema_required') }}</p>@endif
<form method="post" action="{{ route('admin.sms.save') }}">@csrf
@foreach(['api_key','parent_id','cost_irr','tax_percent','start_acl','growth_acl','pro_acl','commercial_evidence','native_billing_evidence','automation_evidence','verified_at','valid_until'] as $key)<p><label>{{ __('sms.setting_'.$key) }}<input name="{{ $key }}" type="{{ $key==='api_key'?'password':'text' }}" value="{{ $key==='api_key'?'':$controls->get($key) }}" autocomplete="off" style="max-width:100%"></label></p>@endforeach
@foreach(['automation_enabled','sales_enabled','start_tariff_verified','growth_tariff_verified','pro_tariff_verified'] as $key)<p><label><input type="checkbox" name="{{ $key }}" value="1" @checked($controls->get($key)==='1')>{{ __('sms.setting_'.$key) }}</label></p>@endforeach
<button class="btn" type="submit">{{ __('sms.save') }}</button></form>
<form method="post" action="{{ route('admin.sms.preflight') }}">@csrf<button class="btn" type="submit">{{ __('sms.preflight') }}</button></form>
<p>{{ __('sms.setting_preflight_at') }}: {{ $controls->get('preflight_at') ?? '—' }}</p>
@if($subscriptions)<div style="overflow:auto"><table><thead><tr><th>ID</th><th>{{ __('sms.plans') }}</th><th>{{ __('sms.state') }}</th><th>{{ __('sms.checked') }}</th></tr></thead><tbody>@foreach($subscriptions as $s)<tr><td>{{ $s->id }}</td><td>{{ __('sms.plan_'.$s->plan) }}</td><td>{{ __('sms.state_'.$s->state) }} @if($s->error_code)<code>{{ $s->error_code }}</code>@endif</td><td>{{ $s->checked_at }}</td></tr>@endforeach</tbody></table></div>{{ $subscriptions->links() }}@endif</div>
@endsection
