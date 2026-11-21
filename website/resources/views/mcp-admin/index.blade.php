@extends('admin.layout')
@section('title', __('mcp_admin.title'))
@section('content')
<div class="card" style="padding:26px;max-width:1000px;margin:auto">
<h1>{{ __('mcp_admin.title') }}</h1><p>{{ __('mcp_admin.intro') }}</p>
<p><strong>{{ $enabled ? __('mcp_admin.enabled') : __('mcp_admin.disabled') }}</strong></p>
<p>{{ __('mcp_admin.endpoint') }}</p><pre dir="ltr">{{ config('mcp_admin.resource') }}</pre>
<p>{{ __('mcp_admin.setup') }}</p>
@if(!$enabled && $owner)
<form method="post" action="/admin/mcp/enable">@csrf<label><input type="checkbox" name="accept" value="1" required> {{ __('mcp_admin.enable_accept') }}</label><p><button class="btn" type="submit">{{ __('mcp_admin.enable') }}</button></p></form>
@elseif($enabled)
<form method="post" action="/admin/mcp/disable">@csrf<button class="btn" type="submit">{{ __('mcp_admin.disable') }}</button></form>
@endif
<h2>{{ __('mcp_admin.connections') }}</h2>
@forelse($tokens->groupBy('client_id') as $client => $group)
<div class="card" style="padding:16px;margin:12px 0"><strong>{{ $group->first()->client?->name }}</strong><p>{{ __('mcp_admin.scope') }}: {{ implode(', ', $group->flatMap(fn($t) => $t->scopes)->unique()->all()) }}</p>
<form method="post" action="/admin/mcp/connections/{{ $client }}/revoke">@csrf<button class="btn">{{ __('mcp_admin.revoke') }}</button></form></div>
@empty<p>{{ __('mcp_admin.no_connections') }}</p>@endforelse
<h2>{{ __('mcp_admin.infrastructure') }}</h2><p>{{ __('mcp_admin.inventory_note') }}</p>
<ul>@foreach($inventory['panels'] as $panel)<li>{{ $panel['name'] }} · {{ $panel['type'] }} · {{ $panel['country'] }} · {{ $panel['status'] }}</li>@endforeach</ul>
<h2>SSH</h2><p>{{ __('mcp_admin.ssh_note') }}</p>
<ul>@forelse($inventory['ssh_targets'] as $target)<li>{{ $target['label'] }} — <code>{{ $target['id'] }}</code></li>@empty<li>{{ __('mcp_admin.ssh_empty') }}</li>@endforelse</ul>
</div>
@endsection
