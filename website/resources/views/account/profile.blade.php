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

{{-- ══ احراز هویتِ حقوقی (شرکت) — ادغام‌شده از صفحهٔ جدای قبلی ══ --}}
@php
  $st = $profile->status ?? 'draft';
  $isCompany = ($profile->type ?? 'individual') === 'company';
  $vb = ['verified'=>[__('ui.prof_st_verified'),'var(--ok)'],'pending'=>[__('ui.prof_st_pending'),'var(--warn)'],'rejected'=>[__('ui.prof_st_rejected'),'var(--danger)']][$st] ?? [__('ui.prof_st_none'),'var(--muted)'];
@endphp

<section class="pnl-sec" id="company">
  <div class="pnl-sec-h">
    <h2>{{ __('ui.prof_legal_h') }}</h2>
    <span class="pnl-pill" style="background:{{ $vb[1] }}22;color:{{ $vb[1] }}">{{ $vb[0] }}</span>
  </div>
  <div class="pnl-sec-b">

    @if(session('ok'))
      <p class="vf-msg ok">{{ session('ok') }}</p>
    @endif
    @if($errors->any())
      <div class="vf-msg bad">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif

    @if($st === 'verified')
      <p class="vf-note ok">✅ {{ $isCompany ? __('ui.prof_vf_ok_company') : __('ui.prof_vf_ok_individual') }}</p>
    @elseif($st === 'pending')
      <p class="vf-note">⏳ {{ __('ui.prof_vf_pending') }}</p>
    @elseif($st === 'rejected')
      <p class="vf-note bad">❌ {{ __('ui.prof_vf_rejected') }}@if($profile->reject_reason ?? null): {{ $profile->reject_reason }}@endif. {{ __('ui.prof_vf_resubmit') }}</p>
    @else
      <p class="vf-note">{!! __('ui.prof_vf_intro') !!}</p>
    @endif

    @if($st !== 'verified')
    <form method="POST" action="{{ lroute('account.verify.submit') }}" enctype="multipart/form-data" class="vf-form">
      @csrf
      <label class="vf-f">{{ __('ui.prof_acct_type') }}
        <select name="type" id="vf-type">
          <option value="individual" @selected(! $isCompany)>{{ __('ui.prof_type_individual') }}</option>
          <option value="company" @selected($isCompany)>{{ __('ui.prof_type_company') }}</option>
        </select>
      </label>

      @php $foreignKyc = ($identity ?? null) === null; @endphp
      @if($foreignKyc)
      {{-- فردِ خارجی: پاسپورت + مدرکِ آدرس (تصمیمِ ۶ شهریور — تأییدِ دستیِ ما).
           ایرانی این بلوک را نمی‌بیند؛ هویتش از استعلامِ ثبتِ احوال آمده. --}}
      <div id="vf-foreign" @if($isCompany) hidden @endif>
        <p class="vf-note">{{ __('ui.prof_foreign_note') }}</p>
        <div class="vf-grid">
          <label class="vf-f">{{ __('ui.prof_first_name') }}<input type="text" name="first_name" value="{{ old('first_name', $profile->first_name) }}" maxlength="80" autocomplete="given-name"></label>
          <label class="vf-f">{{ __('ui.prof_last_name') }}<input type="text" name="last_name" value="{{ old('last_name', $profile->last_name) }}" maxlength="80" autocomplete="family-name"></label>
          <label class="vf-f">{{ __('ui.prof_birth_date') }}<input type="date" name="birth_date" dir="ltr" value="{{ old('birth_date', optional($profile->birth_date)->format('Y-m-d')) }}" max="{{ now()->subYears(18)->toDateString() }}" autocomplete="bday"></label>
          <label class="vf-f">{{ __('ui.prof_country') }}
            @php
              /*
              | پیش‌فرضِ کشور برای خارجی از IPِ خودش می‌آید، نه «IR»ِ پیش‌فرضِ
              | ستون (گزارشِ کارفرما). GeoIp کشِ ۲۴ساعته دارد و روی ورود از قبل
              | گرم شده؛ مقدارِ صریحاً ذخیره‌شدهٔ غیرایرانی دست‌نخورده می‌مانَد.
              */
              $savedCc = strtoupper(trim((string) $profile->country));
              $defCc = $savedCc !== '' && $savedCc !== 'IR'
                  ? $savedCc
                  : strtoupper((string) (app(\App\Services\GeoIp::class)->locate(request()->ip())['cc'] ?? ''));
              $defCc = strtoupper((string) old('country', $defCc));
            @endphp
            <select name="country" autocomplete="country">
              <option value="" disabled @selected($defCc === '')>—</option>
              @foreach(\App\Support\Countries::options() as $cc => $cn)
                <option value="{{ $cc }}" @selected($defCc === $cc)>{{ $cn }}</option>
              @endforeach
            </select>
          </label>
          <label class="vf-f" style="grid-column:1/-1">{{ __('ui.prof_address') }}<input type="text" name="address" value="{{ old('address', $profile->address) }}" maxlength="500" autocomplete="street-address"></label>
          <label class="vf-f">{{ __('ui.prof_city') }}<input type="text" name="city" value="{{ old('city', $profile->city) }}" maxlength="64" autocomplete="address-level2"></label>
          <label class="vf-f">{{ __('ui.prof_postal') }}<input type="text" name="postal_code" dir="ltr" value="{{ old('postal_code', $profile->postal_code) }}" maxlength="20" autocomplete="postal-code"></label>
          <label class="vf-f">{{ __('ui.prof_id_type') }}
            @php $curIdType = old('id_type', $docs->has('national_id') ? 'national_id' : ($docs->has('driving_license') ? 'driving_license' : 'passport')); @endphp
            <select name="id_type" id="vf-id-type">
              <option value="passport" @selected($curIdType === 'passport')>{{ __('ui.prof_id_passport') }}</option>
              <option value="national_id" @selected($curIdType === 'national_id')>{{ __('ui.prof_id_national') }}</option>
              <option value="driving_license" @selected($curIdType === 'driving_license')>{{ __('ui.prof_id_license') }}</option>
            </select>
          </label>
        </div>
        @php $idDoc = $docs['passport'] ?? $docs['national_id'] ?? $docs['driving_license'] ?? null; @endphp
        <div class="vf-docs">
          <label class="vf-doc">
            <span class="vf-doc-h"><svg class="icon"><use href="#i-file"/></svg><b>{{ __('ui.prof_doc_id_front') }}</b></span>
            @if($idDoc)<span class="vf-ok">✓ {{ \Illuminate\Support\Str::limit($idDoc->original_name, 26) }}</span>@endif
            <input type="file" name="doc_passport" accept="application/pdf,image/png,image/jpeg">
            <small>{{ __('ui.prof_doc_hint') }}</small>
          </label>
          <label class="vf-doc" id="vf-id-back" @if($curIdType === 'passport') hidden @endif>
            <span class="vf-doc-h"><svg class="icon"><use href="#i-file"/></svg><b>{{ __('ui.prof_doc_id_back') }}</b></span>
            @if($docs->has('id_back'))<span class="vf-ok">✓ {{ \Illuminate\Support\Str::limit($docs['id_back']->original_name, 26) }}</span>@endif
            <input type="file" name="doc_id_back" accept="application/pdf,image/png,image/jpeg">
            <small>{{ __('ui.prof_doc_hint') }}</small>
          </label>
          <label class="vf-doc">
            <span class="vf-doc-h"><svg class="icon"><use href="#i-file"/></svg><b>{{ __('ui.prof_doc_selfie') }}</b></span>
            @if($docs->has('selfie'))<span class="vf-ok">✓ {{ \Illuminate\Support\Str::limit($docs['selfie']->original_name, 26) }}</span>@endif
            <input type="file" name="doc_selfie" accept="image/png,image/jpeg">
            <small>{{ __('ui.prof_selfie_hint') }}</small>
          </label>
          <label class="vf-doc">
            <span class="vf-doc-h"><svg class="icon"><use href="#i-file"/></svg><b>{{ __('ui.prof_doc_address') }}</b></span>
            @if($docs->has('address_proof'))<span class="vf-ok">✓ {{ \Illuminate\Support\Str::limit($docs['address_proof']->original_name, 26) }}</span>@endif
            <input type="file" name="doc_address" accept="application/pdf,image/png,image/jpeg">
            <small>{{ __('ui.prof_doc_address_hint') }}</small>
          </label>
        </div>
      </div>
      @endif

      <div id="vf-company" @unless($isCompany) hidden @endunless>
        <div class="vf-grid">
          <label class="vf-f">{{ __('ui.prof_company_name') }}<input type="text" name="company_name" value="{{ old('company_name', $profile->company_name) }}" maxlength="190" placeholder="{{ __('ui.prof_company_name_ph') }}"></label>
          <label class="vf-f">{{ __('ui.prof_reg_number') }}<input type="text" name="registration_number" dir="ltr" value="{{ old('registration_number', $profile->registration_number) }}" maxlength="60"></label>
          <label class="vf-f">{{ __('ui.prof_econ_code') }}<input type="text" name="economic_code" dir="ltr" value="{{ old('economic_code', $profile->economic_code) }}" maxlength="60"></label>
          <label class="vf-f">{{ __('ui.prof_rep_position') }}<input type="text" name="rep_position" value="{{ old('rep_position', $profile->rep_position) }}" maxlength="80" placeholder="{{ __('ui.prof_rep_position_ph') }}"></label>
          <label class="vf-f">{{ __('ui.prof_rep_first') }}<input type="text" name="rep_first_name" value="{{ old('rep_first_name', $profile->rep_first_name) }}" maxlength="80"></label>
          <label class="vf-f">{{ __('ui.prof_rep_last') }}<input type="text" name="rep_last_name" value="{{ old('rep_last_name', $profile->rep_last_name) }}" maxlength="80"></label>
        </div>

        <div class="vf-docs">
          <label class="vf-doc">
            <span class="vf-doc-h"><svg class="icon"><use href="#i-file"/></svg><b>{{ __('ui.prof_doc_letter') }}</b></span>
            @if($docs->has('rep_letter'))<span class="vf-ok">✓ {{ \Illuminate\Support\Str::limit($docs['rep_letter']->original_name, 26) }}</span>@endif
            <input type="file" name="doc_letter" accept="application/pdf,image/png,image/jpeg">
            <small>{{ __('ui.prof_doc_hint') }}</small>
          </label>
          <label class="vf-doc">
            <span class="vf-doc-h"><svg class="icon"><use href="#i-file"/></svg><b>{{ __('ui.prof_doc_articles') }}</b></span>
            @if($docs->has('articles'))<span class="vf-ok">✓ {{ \Illuminate\Support\Str::limit($docs['articles']->original_name, 26) }}</span>@endif
            <input type="file" name="doc_articles" accept="application/pdf,image/png,image/jpeg">
            <small>{{ __('ui.prof_doc_hint') }}</small>
          </label>
        </div>
      </div>

      <button type="submit" class="pnl-btn primary" style="justify-content:center">{{ __('ui.prof_submit') }}</button>
    </form>
    @endif
  </div>
</section>

<style>
.vf-note{ font-size:13.5px; color:var(--muted); line-height:2; margin:0 0 14px; }
.vf-note.ok{ color:var(--ok); } .vf-note.bad{ color:var(--danger); }
.vf-msg{ font-size:13px; line-height:2; margin:0 0 12px; padding:10px 13px; border-radius:11px; }
.vf-msg.ok{ color:var(--ok); background:color-mix(in srgb,var(--ok) 10%,transparent); border:1px solid var(--ok-line,var(--line)); }
.vf-msg.bad{ color:var(--danger); background:color-mix(in srgb,var(--danger) 10%,transparent); border:1px solid var(--danger-line,var(--line)); }
.vf-form{ display:flex; flex-direction:column; gap:14px; }
.vf-f{ display:flex; flex-direction:column; gap:6px; font-size:12.5px; color:var(--muted); }
.vf-f input, .vf-f select{ background:var(--surface); border:1px solid var(--line); border-radius:10px; padding:10px 12px; font:inherit; font-size:13px; color:var(--text); }
.vf-f input:focus, .vf-f select:focus{ outline:2px solid var(--cyan); outline-offset:1px; border-color:transparent; }
.vf-grid{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
@media(max-width:560px){ .vf-grid{ grid-template-columns:1fr; } }
.vf-docs{ display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:14px; }
@media(max-width:560px){ .vf-docs{ grid-template-columns:1fr; } }
.vf-doc{ border:1px dashed var(--line-2); border-radius:13px; padding:13px 14px; display:flex; flex-direction:column; gap:7px; cursor:pointer; transition:border-color .16s, background .16s; }
/* hidden به‌تنهایی به display:flexِ بالا می‌بازد (تلهٔ ثبت‌شدهٔ پروژه) */
.vf-doc[hidden]{ display:none; }
.vf-doc:hover{ border-color:var(--cyan); background:var(--surface); }
.vf-doc-h{ display:flex; align-items:center; gap:8px; }
.vf-doc-h .icon{ width:16px; height:16px; color:var(--info); }
.vf-doc b{ font-size:13px; color:var(--text); }
.vf-doc small{ font-size:11px; color:var(--muted); }
.vf-doc input[type=file]{ font:inherit; font-size:12px; color:var(--muted); }
.vf-ok{ font-size:11.5px; color:var(--ok); }
</style>
<script>
(function(){
  var t=document.getElementById('vf-type'), c=document.getElementById('vf-company'),
      f=document.getElementById('vf-foreign');
  if(!t||!c) return;
  t.addEventListener('change', function(){
    c.hidden = this.value !== 'company';
    if (f) { f.hidden = this.value === 'company'; }
  });

  // پشتِ مدرک فقط برای کارتِ ملی/گواهینامه — پاسپورت یک‌صفحه‌ای است
  var it=document.getElementById('vf-id-type'), back=document.getElementById('vf-id-back');
  if(it && back){
    it.addEventListener('change', function(){ back.hidden = this.value === 'passport'; });
  }
})();
</script>

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
