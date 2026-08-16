@extends('emails.layout')

@php
  $host   = $report->host;
  $score  = $report->score;
  $fails  = $report->failCount();
  $issues = $report->issueCount();
  // رنگِ نمره — همان آستانه‌های خودِ ابزار (۷۵ / ۵۰)
  $tone = $score >= 75 ? '#1a9f6b' : ($score >= 50 ? '#b7791f' : '#c2453f');
@endphp

@section('title', $outreach ? __('ui.rp_mail_subj_out', ['host' => $host]) : __('ui.rp_mail_subj', ['host' => $host]))
@section('preview', __('ui.rp_mail_preview', ['score' => $score, 'issues' => $issues]))

@section('content')

<h1 style="margin:0 0 14px; font-size:19px; font-weight:800; color:#0b1220;">
  {{ __('ui.rp_mail_head', ['host' => $host]) }}
</h1>

<p style="margin:0 0 18px; color:#3b4658; line-height:1.9;">
  {{ $outreach ? __('ui.rp_mail_intro_out') : __('ui.rp_mail_intro') }}
</p>

{{-- کارتِ نمره — عددی که باید در یک نگاه دیده شود --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"
       style="background:#f6f8fb; border:1px solid #e6eaf1; border-radius:12px;">
  <tr><td style="padding:20px;" align="center">
    <div style="font-size:40px; font-weight:800; line-height:1; color:{{ $tone }};">{{ $score }}<span style="font-size:18px; color:#8a94a6;">/100</span></div>
    <div style="margin-top:6px; color:#5b6577; font-size:13px;">{{ __('ui.rp_mail_score_lbl') }}</div>

    @if($issues > 0)
      <div style="margin-top:14px; color:#3b4658; font-size:14px;">
        {{ __('ui.rp_mail_issues', ['fails' => $fails, 'issues' => $issues]) }}
      </div>
    @else
      <div style="margin-top:14px; color:#1a9f6b; font-size:14px;">{{ __('ui.rp_mail_clean') }}</div>
    @endif
  </td></tr>
</table>

@if($note)
  <p style="margin:18px 0 0; color:#3b4658; line-height:1.9; white-space:pre-line;">{{ $note }}</p>
@endif

{{-- دکمهٔ گزارشِ زنده — کارِ اصلیِ این ایمیل --}}
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 6px;">
  <tr><td align="center" style="background:#1b7fd4; border-radius:11px;">
    <a href="{{ $reportUrl }}" style="display:inline-block; padding:13px 26px; color:#ffffff; font-weight:700; font-size:15px; text-decoration:none;">
      {{ __('ui.rp_mail_cta') }}
    </a>
  </td></tr>
</table>

<p style="margin:0 0 18px; color:#8a94a6; font-size:12.5px; line-height:1.8; direction:ltr; word-break:break-all;">{{ $reportUrl }}</p>

<p style="margin:0 0 6px; color:#3b4658; line-height:1.9;">{{ __('ui.rp_mail_offer') }}</p>

{{-- 🔴 بخشِ کمپین: «شما که هستید و چرا این ایمیل به من رسید».
     بی‌این، پیام از دیدِ گیرنده — و از دیدِ قانونِ اروپا — اسپم است. --}}
@if($outreach)
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
         style="margin-top:26px; border-top:1px solid #e6eaf1;">
    <tr><td style="padding-top:16px;">
      <p style="margin:0 0 8px; color:#8a94a6; font-size:12.5px; line-height:1.85;">
        {{ __('ui.rp_mail_why', ['host' => $host]) }}
      </p>
      @if($unsubscribeUrl)
        <p style="margin:0; color:#8a94a6; font-size:12.5px; line-height:1.85;">
          <a href="{{ $unsubscribeUrl }}" style="color:#5b6577;">{{ __('ui.rp_mail_unsub') }}</a>
        </p>
      @endif
    </td></tr>
  </table>
@endif

@endsection
