@extends('emails.layout')

@section('title', __('ui.email_service_subject', ['name' => $serviceName]))
@section('preview', __('ui.email_service_heading'))

@section('content')
@php
  /* ⚠️ 🔴 تلهٔ ثبت‌شدهٔ این پروژه: نوشتنِ دستورِ ssh مستقیم در قالب **مقدار را
     چاپ نمی‌کند** — یک `@` چسبیده به آکولاد برای Blade دستورِ فرار است و خروجی
     می‌شود «ssh root{{ $ip }}». پس رشته این‌جا در PHP ساخته می‌شود و بعد چاپ.
     یک بار همین باگ روی خطِ SSH پنل رفت روی سایت و صفحه هم ۲۰۰ می‌داد.

     فقط برای سرورِ ابری (`$sshGuide`) و فقط وقتی آدرس یک IP است؛ روی هاستِ
     اشتراکی `domain` نامِ دامنه است و «ssh user@example.com» دستوری گمراه‌کننده. */
  $sshCmd = ($sshGuide ?? false) && filled($domain ?? null)
            && filter_var((string) $domain, FILTER_VALIDATE_IP)
      ? 'ssh '.($username ?: 'root').'@'.$domain
      : null;
@endphp
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
    {{-- ⚠️ `passwordInPanel` رمز را **می‌بندد**، نه اینکه فقط توضیحی اضافه کند.
         وگرنه یک فراخوانِ آیندهٔ بی‌دقت که هر دو را بدهد، هم‌زمان می‌گفت «رمز در
         ایمیل نیست» و رمز را چاپ می‌کرد — بدترین حالتِ ممکن. --}}
    @if($password && ! ($passwordInPanel ?? false))
      <p style="margin:0; color:#5b6577; font-size:13px;">{{ __('ui.email_service_pass') }}:
        <b style="color:#1a2233; direction:ltr; display:inline-block; font-family:'Courier New',monospace;">{{ $password }}</b></p>
    @endif
    @if($sshCmd)
      <p style="margin:0; color:#5b6577; font-size:13px;">{{ __('ui.email_service_ssh_cmd') }}:
        <b style="color:#1a2233; direction:ltr; display:inline-block; font-family:'Courier New',monospace;">{{ $sshCmd }}</b></p>
    @endif
  </td></tr>
</table>

{{-- ═══ رمزِ root: در ایمیل نیست، یک بار در پنل ═══
     کارفرما: مشتری ایمیل را می‌بیند، رمزی پیدا نمی‌کند و فکر می‌کند چیزی جا
     افتاده. سکوت این‌جا گران‌تر از خودِ نبودِ رمز بود. --}}
@if($passwordInPanel ?? false)
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
         style="margin-top:14px; background:#fff8e6; border:1px solid #f3dfae; border-radius:12px;">
    <tr><td style="padding:16px 20px;">
      <p style="margin:0 0 6px; color:#8a5b00; font-size:13.5px; font-weight:700;">
        🔑 {{ __('ui.email_service_pass_panel_h') }}
      </p>
      <p style="margin:0; color:#6b5220; font-size:13px; line-height:1.95;">
        {{ __('ui.email_service_pass_panel') }}
      </p>
      @if($panelUrl)
        <p style="margin:14px 0 0;">
          <a href="{{ $panelUrl }}"
             style="display:inline-block; background:#0891b2; color:#ffffff; text-decoration:none;
                    font-size:13.5px; font-weight:700; border-radius:10px; padding:11px 20px;">
            {{ __('ui.email_service_pass_panel_btn') }}
          </a>
        </p>
      @endif
    </td></tr>
  </table>
@endif

{{-- ═══ راهنمای اتصال ═══
     لینک فقط وقتی چاپ می‌شود که مقاله واقعاً منتشر شده باشد
     (`ServiceReadyMail::sshDocUrl()` خودش می‌پرسد). لینکِ ۴۰۴ در ایمیلِ تحویل،
     از نبودِ لینک بدتر است. --}}
@if(!empty($sshDocUrl))
  <p style="margin:18px 0 0; font-size:13px; color:#3b4658; line-height:1.95;">
    {{ __('ui.email_service_ssh_p') }}
    <a href="{{ $sshDocUrl }}" style="color:#06758c; font-weight:700;">{{ __('ui.email_service_ssh_link') }}</a>
  </p>
@endif

<p style="margin:18px 0 0; font-size:13px; color:#8a93a6;">
  {{ ($passwordInPanel ?? false) ? __('ui.email_service_note_cloud') : __('ui.email_service_note') }}
</p>
@endsection
