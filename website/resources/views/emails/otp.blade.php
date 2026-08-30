@extends('emails.layout')

@php $destructive = $destructive ?? false; @endphp
@section('title', __($destructive ? 'ui.email_otp_del_subject' : 'ui.email_otp_subject'))
@section('preview', __($destructive ? 'ui.email_otp_del_heading' : 'ui.email_otp_heading').' — '.$code)

@section('content')
@php $dir = app()->getLocale() === 'fa' ? 'rtl' : 'ltr'; @endphp

<h1 style="margin:0 0 14px; font-size:19px; font-weight:800; color:{{ $destructive ? '#b91c1c' : '#0b1220' }};">{{ __($destructive ? 'ui.email_otp_del_heading' : 'ui.email_otp_heading') }}</h1>

<p style="margin:0 0 20px; color:#3b4658;">{{ __($destructive ? 'ui.email_otp_del_intro' : 'ui.email_otp_intro') }}</p>

{{-- 🔴 هشدارِ برگشت‌ناپذیری. کدِ تأییدِ یک کنشِ ویرانگر نباید شبیهِ کدِ ورود
     باشد؛ کاربری که فکر می‌کند دارد وارد می‌شود، سرورش را پاک می‌کند. --}}
@if($destructive)
<div style="margin:0 0 20px; padding:12px 14px; border-radius:8px; background:#fef2f2; border:1px solid #fecaca; color:#991b1b; font-size:13px; line-height:1.9">
  {{ __('ui.email_otp_del_warn') }}
</div>
@endif

<!-- جعبهٔ کد -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
  <tr>
    <td align="center" style="padding:6px 0 22px;">
      <div style="display:inline-block; background:#ecfbfe; border:1px solid #b6ecf5; border-radius:14px;
                  padding:16px 34px; direction:ltr;">
        <span style="font-size:34px; font-weight:800; letter-spacing:12px; color:#06758c;
                     font-family:'Courier New', monospace;">{{ $code }}</span>
      </div>
    </td>
  </tr>
</table>

<p style="margin:0 0 6px; color:#3b4658;">{{ __('ui.email_otp_ttl', ['min' => $minutes]) }}</p>
<p style="margin:0; font-size:13px; color:#8a93a6;">{{ __('ui.email_otp_ignore') }}</p>
@endsection
