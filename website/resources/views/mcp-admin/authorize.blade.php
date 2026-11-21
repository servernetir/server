<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'fa' ? 'rtl' : 'ltr' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('mcp_admin.connect') }} — ServerNet</title>
<style>body{background:#0b1220;color:#e6edf7;font:16px/1.8 system-ui;margin:0;padding:32px}main{max-width:700px;margin:auto;padding:28px;background:#131f32;border:1px solid #2a3d59;border-radius:22px}code,pre{direction:ltr;unicode-bidi:isolate;overflow-wrap:anywhere;white-space:pre-wrap}button{padding:12px 22px;border:0;border-radius:10px;background:#20d7c4;font:inherit;cursor:pointer}.secondary{background:#293a53;color:white}form{display:inline-block;margin:10px}small{color:#aabbd0}</style></head>
<body><main><small>SERVERNET · MCP</small><h1>{{ __('mcp_admin.connect') }}</h1>
<p>{{ __('mcp_admin.consent_intro') }}</p><h2>{{ $client->name }}</h2>
<p>{{ __('mcp_admin.account') }}: {{ $user->name }}</p>
<ul>@foreach($scopes as $scope)<li>{{ __($scope->description) }}</li>@endforeach</ul>
<p>{{ __('mcp_admin.callback') }}</p><pre>{{ implode("\n", $client->redirect_uris) }}</pre>
<p>{{ __('mcp_admin.consent_detail') }}</p>
<form method="post" action="{{ config('mcp_admin.origin') }}/admin/mcp/oauth/authorize">@csrf<input type="hidden" name="auth_token" value="{{ $authToken }}"><button>{{ __('mcp_admin.allow') }}</button></form>
<form method="post" action="{{ config('mcp_admin.origin') }}/admin/mcp/oauth/authorize">@csrf @method('DELETE')<input type="hidden" name="auth_token" value="{{ $authToken }}"><button class="secondary">{{ __('mcp_admin.deny') }}</button></form>
</main></body></html>
