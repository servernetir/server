@extends('admin.layout')
@section('title', __('mcp_admin.approve_command'))
@section('content')
<div class="card" style="padding:26px;max-width:1000px;margin:auto">
<h1>{{ __('mcp_admin.approve_command') }}</h1><p>{{ __('mcp_admin.command_warning') }}</p>
<p>{{ __('mcp_admin.target') }}: <code>{{ $action->target }}</code></p>
<pre dir="ltr" style="white-space:pre-wrap;overflow-wrap:anywhere;background:#111827;color:#e5e7eb;padding:20px;border-radius:12px">{{ $action->command }}</pre>
@php($expired = in_array($action->status, ['pending', 'approved'], true) && ! $action->expires_at->isFuture())
<p>{{ __('mcp_admin.status') }}: {{ $expired ? __('mcp_admin.expired') : $action->status }} · {{ __('mcp_admin.expires') }}: {{ $action->expires_at->toIso8601String() }}</p>
@if($expired)<p role="status">{{ __('mcp_admin.command_expired') }}</p>@endif
@if($action->status === 'pending' && $action->expires_at->isFuture())
<form method="post" action="/admin/mcp/actions/{{ $action->id }}/approve">@csrf<label><input type="checkbox" name="accept" value="1" required> {{ __('mcp_admin.command_accept') }}</label><p><button class="btn">{{ __('mcp_admin.approve_command') }}</button></p></form>
@endif
@if($action->result)<pre dir="ltr" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ json_encode($action->result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>@endif
<a href="/admin/mcp">{{ __('mcp_admin.title') }}</a></div>
@endsection
