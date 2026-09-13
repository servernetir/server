@extends('admin.layout')
@section('title', 'کد اپلیکیشن')
@section('content')
<div class="ad-card">
  <div class="ad-brand"><span class="m"><svg class="icon"><use href="#i-server"/></svg></span> سرورنت</div>
  <h2>تأیید دومرحله‌ای</h2>
  <p style="margin:-6px 0 16px;color:var(--muted);font-size:13.5px;line-height:1.9">
    کد شش‌رقمی را از اپلیکیشن احراز هویت روی گوشی‌تان وارد کنید.
  </p>

  @if(session('err'))<div class="ad-flash err">{{ session('err') }}</div>@endif
  @if($errors->any())<div class="ad-flash err">{{ $errors->first() }}</div>@endif

  {{--
    ⚠️ `maxlength` عمداً ۲۴ است و نه ۶: کدِ بازیابی (`xxxxx-xxxxx`) هم از همین
    فرم می‌آید. مدیری که گوشی‌اش را گم کرده تنها راهش همین است.
  --}}
  <form method="post" action="/admin/login/totp">
    @csrf
    <div class="ad-field"><label>کد اپلیکیشن</label>
      <input class="ad-input" type="text" name="code" id="ad-totp" required autofocus dir="ltr"
             autocomplete="one-time-code" maxlength="24" inputmode="numeric"
             style="text-align:center;letter-spacing:8px;font-size:20px;font-weight:700">
    </div>
    <p style="margin:-4px 0 14px;color:var(--muted);font-size:12.5px;line-height:1.9">
      گوشی‌تان در دسترس نیست؟ یکی از کدهای بازیابی را وارد کنید.
    </p>
    <button class="btn btn-primary" type="submit">ورود</button>
  </form>

  <div style="margin-top:14px">
    <a href="/admin/login" style="color:var(--muted);font-size:13px;text-decoration:none">بازگشت به ورود</a>
  </div>
</div>
<script>
(function () {
  // ارقام فارسی/عربی به لاتین. حروف دست نمی‌خورند چون کدِ بازیابی حرف دارد.
  var input = document.getElementById('ad-totp');
  if (!input) return;
  input.addEventListener('input', function () {
    this.value = this.value
      .replace(/[۰-۹]/g, function (d) { return d.charCodeAt(0) - 1776; })
      .replace(/[٠-٩]/g, function (d) { return d.charCodeAt(0) - 1632; });
  });
})();
</script>
@endsection
