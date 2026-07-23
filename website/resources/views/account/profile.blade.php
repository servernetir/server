@extends('panel.layout')
@section('title', __('ui.auth_s_identity').' — ServerNet')

@section('panel')

{{--
  پروفایل.

  چیدمان از جدول به «فهرست مشخصات» عوض شد: جدول برای داده‌های تکرارشونده
  است، نه برای یک ردیف اطلاعات. با فهرست، برچسب و مقدار هر کدام جای خودشان
  را دارند و در موبایل زیر هم می‌روند به‌جای اینکه ستون بشکند.

  نام سرویس استعلام (شاهکار) نوشته نمی‌شود — از دید مشتری «ما تأیید کردیم»،
  و نام تأمین‌کننده اطلاعات داخلی ماست نه چیزی که به او مربوط باشد.
--}}

<div class="pnl-head">
  <div>
    <h1 class="dash-h">{{ __('ui.auth_s_identity') }}</h1>
    <p>{{ __('ui.pnl_profile_sub') }}</p>
  </div>
</div>

{{-- ══ هویت ══ --}}
@php $ok = $identity?->status === 'verified'; @endphp

<section class="pnl-sec" style="border-color:{{ $ok ? 'var(--ok-line)' : 'var(--warn-line)' }}">
  <div class="pnl-sec-h" style="background:{{ $ok ? 'var(--ok-bg)' : 'var(--warn-bg)' }}">
    <h2 style="color:{{ $ok ? 'var(--ok)' : 'var(--warn)' }}">{{ __('ui.auth_s_identity') }}</h2>
    <span class="pnl-pill {{ $ok ? 'ok' : 'warn' }}">
      {{ $ok ? __('ui.pnl_identity_ok') : __('ui.pnl_identity_no') }}
    </span>
  </div>
  <div class="pnl-sec-b">
    @if($ok)
      <dl class="spec">
        <div>
          <dt>{{ __('ui.pnl_fullname') }}</dt>
          <dd><b>{{ trim($identity->first_name.' '.$identity->last_name) }}</b></dd>
        </div>
        @if($identity->father_name)
          <div>
            <dt>{{ __('ui.pnl_father') }}</dt>
            <dd>{{ $identity->father_name }}</dd>
          </div>
        @endif
        <div>
          <dt>{{ __('ui.auth_mobile') }}</dt>
          <dd dir="ltr" class="num">{{ fa_num($identity->mobile) }}</dd>
        </div>
        <div>
          <dt>{{ __('ui.auth_birth') }}</dt>
          <dd class="num">{{ fa_num(str_replace('-', '/', (string) $identity->birth_date?->format('Y-m-d'))) }}</dd>
        </div>
        <div>
          <dt>{{ __('ui.pnl_verified_at') }}</dt>
          <dd class="num">{{ blog_date((string) $identity->verified_at) }}</dd>
        </div>
      </dl>

      <p class="spec-note">
        {{ __('ui.pnl_name_from_registry') }}
        @if($nameLocked) {{ __('ui.pnl_name_locked') }} @endif
      </p>
    @else
      <p style="font-size:13.5px;color:var(--muted);line-height:2;margin:0 0 16px">
        {{ __('ui.auth_kyc_sub') }}
      </p>
      <a class="pnl-btn primary" href="{{ lroute('register') }}">
        <svg class="icon"><use href="#i-user"/></svg>{{ __('ui.auth_kyc_submit') }}
      </a>
    @endif
  </div>
</section>

{{-- ══ حساب کاربری ══ --}}
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>{{ __('ui.pnl_account') }}</h2></div>
  <div class="pnl-sec-b">
    <dl class="spec">
      <div>
        <dt>{{ __('ui.pnl_customer_code') }}</dt>
        <dd dir="ltr" class="num"><b>{{ $customer->code }}</b></dd>
      </div>
      <div>
        <dt>{{ __('ui.auth_email') }}</dt>
        <dd dir="ltr">{{ $customer->email }}</dd>
      </div>
      <div>
        <dt>{{ __('ui.auth_mobile') }}</dt>
        <dd dir="ltr" class="num">{{ $customer->phone ? fa_num($customer->phone) : '—' }}</dd>
      </div>
      <div>
        <dt>{{ __('ui.auth_acct_type') }}</dt>
        <dd>{{ ($profile?->type ?? 'individual') === 'company' ? __('ui.auth_company') : __('ui.auth_individual') }}</dd>
      </div>
      <div>
        <dt>{{ __('ui.pnl_member_since') }}</dt>
        <dd class="num">{{ blog_date((string) $customer->created_at) }}</dd>
      </div>
    </dl>
  </div>
</section>

<style>
/* ── فهرست مشخصات ──────────────────────────────────────────────────────
   برچسب و مقدار در دو سر یک ردیف، با خط جداکنندهٔ نازک بینشان. در موبایل
   زیر هم می‌روند تا مقدارهای بلند (ایمیل، شبا) نشکنند. */
.spec{ margin:0; display:flex; flex-direction:column; }
.spec > div{
  display:flex; align-items:baseline; justify-content:space-between; gap:16px;
  padding:13px 2px; border-bottom:1px solid var(--line);
}
.spec > div:last-child{ border-bottom:0; padding-bottom:2px; }
.spec dt{ font-size:12.5px; color:var(--muted); flex:none; }
.spec dd{ margin:0; font-size:13.5px; text-align:end; min-width:0; word-break:break-word; }
.spec dd b{ font-weight:700; }
/* اعداد جدولی و همیشه چپ‌به‌راست — بدون این، شماره‌ها در RTL جابه‌جا دیده می‌شوند */
.spec dd.num{ font-variant-numeric:tabular-nums; letter-spacing:.02em; }

.spec-note{
  margin:16px 0 0; padding-top:14px; border-top:1px solid var(--line);
  font-size:12.5px; color:var(--dim); line-height:2;
}

@media(max-width:520px){
  .spec > div{ flex-direction:column; align-items:stretch; gap:5px; }
  .spec dd{ text-align:start; }
}
</style>

@endsection
