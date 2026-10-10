@extends('panel.layout')
@section('title', __('ui.nav_verify').' — ServerNet')

@section('panel')

<div class="pnl-head">
  <div>
    <h1 class="dash-h">{{ __('ui.nav_verify') }}</h1>
    <p>{{ __('panel.verify_intro') }}</p>
  </div>
</div>

@if(session('ok'))
  <div class="pnl-sec" style="border-color:var(--ok-line)"><div class="pnl-sec-b" style="color:var(--ok);font-size:13.5px;line-height:2">{{ \App\Support\PanelText::text(session('ok')) }}</div></div>
@endif
@if($errors->any())
  <div class="pnl-sec" style="border-color:var(--danger-line)"><div class="pnl-sec-b" style="color:var(--danger);font-size:13.5px;line-height:2">@foreach($errors->all() as $e)<div>{{ \App\Support\PanelText::text($e) }}</div>@endforeach</div></div>
@endif

@php
  $st = $profile->status;
  $badge = ['verified'=>[__('ui.prof_st_verified'),'var(--ok)'],'pending'=>[__('ui.prof_st_pending'),'var(--warn)'],'rejected'=>[__('ui.prof_st_rejected'),'var(--danger)']][$st] ?? [__('ui.prof_st_none'),'var(--muted)'];
@endphp

<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>{{ __('panel.verify_status') }}</h2>
    <span class="pnl-pill" style="background:{{ $badge[1] }}22;color:{{ $badge[1] }}">{{ $badge[0] }}</span>
  </div>
  <div class="pnl-sec-b">
    @if($st === 'verified')
      <p class="vf-note" style="color:var(--ok)">{{ __('ui.prof_vf_ok_individual') }}</p>
    @elseif($st === 'pending')
      <p class="vf-note">{{ __('ui.prof_vf_pending') }}</p>
    @elseif($st === 'rejected')
      <p class="vf-note" style="color:var(--danger)">{{ __('ui.prof_vf_rejected') }}@if($profile->reject_reason): {{ \App\Support\PanelText::text($profile->reject_reason) }}@endif. {{ __('ui.prof_vf_resubmit') }}</p>
    @else
      <p class="vf-note">{{ __('panel.verify_start') }}</p>
    @endif
  </div>
</section>

@if($st !== 'verified')
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>{{ __('ui.prof_legal_h') }}</h2></div>
  <div class="pnl-sec-b">
    <form method="POST" action="{{ lroute('account.verify.submit') }}" enctype="multipart/form-data" class="vf-form" id="vf-form">
      @csrf
      <label class="vf-f">{{ __('ui.prof_acct_type') }}
        <select name="type" id="vf-type">
          <option value="individual" @selected($profile->type !== 'company')>{{ __('ui.prof_type_individual') }}</option>
          <option value="company" @selected($profile->type === 'company')>{{ __('ui.prof_type_company') }}</option>
        </select>
      </label>

      {{-- فیلدهای شرکت --}}
      <div id="vf-company" @if($profile->type !== 'company') hidden @endif>
        <div class="vf-grid">
          <label class="vf-f">{{ __('ui.prof_company_name') }}<input type="text" name="company_name" value="{{ old('company_name', $profile->company_name) }}" maxlength="190" placeholder="{{ __('ui.prof_company_name_ph') }}"></label>
          <label class="vf-f">{{ __('ui.prof_reg_number') }}<input type="text" name="registration_number" dir="ltr" value="{{ old('registration_number', $profile->registration_number) }}" maxlength="60"></label>
          <label class="vf-f">{{ __('ui.prof_econ_code') }}<input type="text" name="economic_code" dir="ltr" value="{{ old('economic_code', $profile->economic_code) }}" maxlength="60"></label>
          <label class="vf-f">{{ __('ui.prof_rep_position') }}<input type="text" name="rep_position" value="{{ old('rep_position', $profile->rep_position) }}" maxlength="80" placeholder="{{ __('ui.prof_rep_position_ph') }}"></label>
          <label class="vf-f">{{ __('ui.prof_rep_first') }}<input type="text" name="rep_first_name" value="{{ old('rep_first_name', $profile->rep_first_name) }}" maxlength="80"></label>
          <label class="vf-f">{{ __('ui.prof_rep_last') }}<input type="text" name="rep_last_name" value="{{ old('rep_last_name', $profile->rep_last_name) }}" maxlength="80"></label>
        </div>

        <div class="vf-docs">
          <div class="vf-doc">
            <b>{{ __('ui.prof_doc_letter') }}</b>
            @if($docs->has('rep_letter'))<span class="vf-ok">✓ {{ __('panel.verify_uploaded') }} — {{ \Illuminate\Support\Str::limit($docs['rep_letter']->original_name, 30) }}</span>@endif
            <input type="file" name="doc_letter" accept="application/pdf,image/png,image/jpeg">
            <small>{{ __('ui.prof_doc_hint') }}</small>
          </div>
          <div class="vf-doc">
            <b>{{ __('ui.prof_doc_articles') }}</b>
            @if($docs->has('articles'))<span class="vf-ok">✓ {{ __('panel.verify_uploaded') }} — {{ \Illuminate\Support\Str::limit($docs['articles']->original_name, 30) }}</span>@endif
            <input type="file" name="doc_articles" accept="application/pdf,image/png,image/jpeg">
            <small>{{ __('ui.prof_doc_hint') }}</small>
          </div>
        </div>
      </div>

      <button type="submit" class="pnl-btn primary" style="justify-content:center">{{ __('ui.prof_submit') }}</button>
    </form>
  </div>
</section>
@endif

<style>
.vf-note{ font-size:13.5px; color:var(--muted); line-height:2; margin:0; }
.vf-form{ display:flex; flex-direction:column; gap:14px; }
.vf-f{ display:flex; flex-direction:column; gap:6px; font-size:12.5px; color:var(--muted); }
.vf-f input, .vf-f select{ background:var(--surface); border:1px solid var(--line); border-radius:10px; padding:10px 12px; font:inherit; font-size:13px; color:var(--text); }
.vf-grid{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
@media(max-width:560px){ .vf-grid{ grid-template-columns:1fr; } }
.vf-docs{ display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:14px; }
@media(max-width:560px){ .vf-docs{ grid-template-columns:1fr; } }
.vf-doc{ border:1px dashed var(--line); border-radius:12px; padding:13px 14px; display:flex; flex-direction:column; gap:7px; }
.vf-doc b{ font-size:13px; color:var(--text); }
.vf-doc small{ font-size:11px; color:var(--muted); }
.vf-doc input[type=file]{ font:inherit; font-size:12px; color:var(--muted); }
.vf-ok{ font-size:11.5px; color:var(--ok); }
</style>
<script>
(function(){
  var t = document.getElementById('vf-type'), c = document.getElementById('vf-company');
  if(!t || !c) return;
  t.addEventListener('change', function(){ c.hidden = this.value !== 'company'; });
})();
</script>
@endsection
