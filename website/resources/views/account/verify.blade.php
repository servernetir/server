@extends('panel.layout')
@section('title', 'احراز هویت — سرورنت')

@section('panel')

<div class="pnl-head">
  <div>
    <h1 class="dash-h">احراز هویت</h1>
    <p>برای فعال‌سازیِ کاملِ حساب و خریدهای سازمانی، هویت و مدارکِ خود را ثبت کنید.</p>
  </div>
</div>

@if(session('ok'))
  <div class="pnl-sec" style="border-color:var(--ok-line)"><div class="pnl-sec-b" style="color:var(--ok);font-size:13.5px;line-height:2">{{ session('ok') }}</div></div>
@endif
@if($errors->any())
  <div class="pnl-sec" style="border-color:var(--danger-line)"><div class="pnl-sec-b" style="color:var(--danger);font-size:13.5px;line-height:2">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>
@endif

@php
  $st = $profile->status;
  $badge = ['verified'=>['تأییدشده','var(--ok)'],'pending'=>['در حال بررسی','var(--warn)'],'rejected'=>['رد شده','var(--danger)']][$st] ?? ['تکمیل‌نشده','var(--muted)'];
@endphp

<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>وضعیت احراز هویت</h2>
    <span class="pnl-pill" style="background:{{ $badge[1] }}22;color:{{ $badge[1] }}">{{ $badge[0] }}</span>
  </div>
  <div class="pnl-sec-b">
    @if($st === 'verified')
      <p class="vf-note" style="color:var(--ok)">✅ هویتِ شما تأیید شده است. برای تغییر مدارک با پشتیبانی هماهنگ کنید.</p>
    @elseif($st === 'pending')
      <p class="vf-note">⏳ مدارکِ شما ثبت شده و در صفِ بررسیِ پشتیبانی است. نتیجه به‌زودی اعلام می‌شود. می‌توانید مدارک را به‌روزرسانی کنید.</p>
    @elseif($st === 'rejected')
      <p class="vf-note" style="color:var(--danger)">❌ مدارک تأیید نشد@if($profile->reject_reason): {{ $profile->reject_reason }}@endif. لطفاً اصلاح و دوباره ارسال کنید.</p>
    @else
      <p class="vf-note">برای شروع، نوعِ حساب را انتخاب و اطلاعات را کامل کنید.</p>
    @endif
  </div>
</section>

@if($st !== 'verified')
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>اطلاعات و مدارک</h2></div>
  <div class="pnl-sec-b">
    <form method="POST" action="{{ lroute('account.verify.submit') }}" enctype="multipart/form-data" class="vf-form" id="vf-form">
      @csrf
      <label class="vf-f">نوع حساب
        <select name="type" id="vf-type">
          <option value="individual" @selected($profile->type !== 'company')>حقیقی (شخصی)</option>
          <option value="company" @selected($profile->type === 'company')>حقوقی (شرکت)</option>
        </select>
      </label>

      {{-- فیلدهای شرکت --}}
      <div id="vf-company" @if($profile->type !== 'company') hidden @endif>
        <div class="vf-grid">
          <label class="vf-f">نام شرکت<input type="text" name="company_name" value="{{ old('company_name', $profile->company_name) }}" maxlength="190" placeholder="نامِ رسمیِ ثبت‌شده"></label>
          <label class="vf-f">شمارهٔ ثبت<input type="text" name="registration_number" dir="ltr" value="{{ old('registration_number', $profile->registration_number) }}" maxlength="60"></label>
          <label class="vf-f">کد اقتصادی<input type="text" name="economic_code" dir="ltr" value="{{ old('economic_code', $profile->economic_code) }}" maxlength="60"></label>
          <label class="vf-f">سِمَتِ نماینده<input type="text" name="rep_position" value="{{ old('rep_position', $profile->rep_position) }}" maxlength="80" placeholder="مدیرعامل / …"></label>
          <label class="vf-f">نامِ نماینده<input type="text" name="rep_first_name" value="{{ old('rep_first_name', $profile->rep_first_name) }}" maxlength="80"></label>
          <label class="vf-f">نام‌خانوادگیِ نماینده<input type="text" name="rep_last_name" value="{{ old('rep_last_name', $profile->rep_last_name) }}" maxlength="80"></label>
        </div>

        <div class="vf-docs">
          <div class="vf-doc">
            <b>معرفی‌نامهٔ نماینده</b>
            @if($docs->has('rep_letter'))<span class="vf-ok">✓ آپلود شد — {{ \Illuminate\Support\Str::limit($docs['rep_letter']->original_name, 30) }}</span>@endif
            <input type="file" name="doc_letter" accept="application/pdf,image/png,image/jpeg">
            <small>PDF یا تصویر، تا ۵ مگابایت</small>
          </div>
          <div class="vf-doc">
            <b>اساسنامهٔ شرکت</b>
            @if($docs->has('articles'))<span class="vf-ok">✓ آپلود شد — {{ \Illuminate\Support\Str::limit($docs['articles']->original_name, 30) }}</span>@endif
            <input type="file" name="doc_articles" accept="application/pdf,image/png,image/jpeg">
            <small>PDF یا تصویر، تا ۵ مگابایت</small>
          </div>
        </div>
      </div>

      <button type="submit" class="pnl-btn primary" style="justify-content:center">ثبت و ارسال برای بررسی</button>
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
