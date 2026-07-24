@extends('emails.layout')

@section('title', __('ui.email_service_subject', ['name' => $serviceName]))
@section('preview', __('ui.email_service_heading'))

@section('content')
<h1 style="margin:0 0 14px; font-size:19px; font-weight:800; color:#0b1220;">{{ __('ui.email_service_heading') }}</h1>

<p style="margin:0 0 18px; color:#3b4658; line-height:1.9;">{{ __('ui.email_service_intro', ['name' => $serviceName]) }}</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0"
       style="background:#f6f8fb; border:1px solid #e6eaf1; border-radius:12px;">
  <tr><td style="padding:16px 20px;">
    @if($domain)
      <p style="margin:0 0 10px; color:#5b6577; font-size:13px;">{{ __('ui.email_service_domain') }}:
        <b style="color:#1a2233; direction:ltr; display:inline-block;">{{ $domain }}</b></p>
    @endif
    @if($panelUrl)
      <p style="margin:0 0 10px; color:#5b6577; font-size:13px;">{{ __('ui.email_service_login') }}:
        <a href="{{ $panelUrl }}" style="color:#06758c; direction:ltr; display:inline-block;">{{ $panelUrl }}</a></p>
    @endif
    @if($username)
      <p style="margin:0 0 10px; color:#5b6577; font-size:13px;">{{ __('ui.email_service_user') }}:
        <b style="color:#1a2233; direction:ltr; display:inline-block;">{{ $username }}</b></p>
    @endif
    @if($password)
      <p style="margin:0; color:#5b6577; font-size:13px;">{{ __('ui.email_service_pass') }}:
        <b style="color:#1a2233; direction:ltr; display:inline-block; font-family:'Courier New',monospace;">{{ $password }}</b></p>
    @endif
  </td></tr>
</table>

<p style="margin:18px 0 0; font-size:13px; color:#8a93a6;">{{ __('ui.email_service_note') }}</p>
@endsection
