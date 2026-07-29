@extends('admin.layout')
@section('title', 'کد ورود')
@section('content')
<div class="ad-card">
  <div class="ad-brand"><span class="m"><svg class="icon"><use href="#i-server"/></svg></span> سرورنت</div>
  <h2>تأیید ورود مدیر</h2>
  <p style="margin:-6px 0 16px;color:var(--muted);font-size:13.5px;line-height:1.9">
    یک کد تأیید به ایمیل <b dir="ltr">{{ $masked }}</b> فرستادیم. برای تکمیل ورود، کد را وارد کنید.
  </p>

  @if(session('ok'))<div class="ad-flash ok" style="background:rgba(52,211,153,.12);border-color:rgba(52,211,153,.35);color:#34d399">{{ session('ok') }}</div>@endif
  @if($errors->any())<div class="ad-flash err">{{ $errors->first() }}</div>@endif

  <form method="post" action="/admin/login/otp" id="ad-otp-form">
    @csrf
    <div class="ad-field"><label>کد تأیید</label>
      <input class="ad-input" type="text" name="code" id="ad-otp" inputmode="numeric" autocomplete="one-time-code"
             maxlength="6" required autofocus dir="ltr"
             style="text-align:center;letter-spacing:10px;font-size:22px;font-weight:700">
    </div>
    <button class="btn btn-primary" type="submit">ورود</button>
  </form>

  <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;gap:12px">
    <form method="post" action="/admin/login/otp/resend" style="margin:0">
      @csrf
      <button type="submit" id="ad-resend" disabled
              style="background:none;border:0;color:var(--muted);cursor:pointer;font:inherit;font-size:13px;padding:0">
        ارسال دوبارهٔ کد <span id="ad-cd">(۶۰)</span>
      </button>
    </form>
    <a href="/admin/login" style="color:var(--muted);font-size:13px;text-decoration:none">بازگشت به ورود</a>
  </div>
</div>
<script>
(function () {
  // فقط رقم بپذیر و ارقام فارسی/عربی را به لاتین برگردان
  var input = document.getElementById('ad-otp');
  if (input) {
    input.addEventListener('input', function () {
      this.value = this.value
        .replace(/[۰-۹]/g, function (d) { return d.charCodeAt(0) - 1776; })
        .replace(/[٠-٩]/g, function (d) { return d.charCodeAt(0) - 1632; })
        .replace(/[^0-9]/g, '');
    });
  }
  // شمارش معکوس ۶۰ ثانیه‌ای تا فعال‌شدن «ارسال دوباره»
  var btn = document.getElementById('ad-resend'), cd = document.getElementById('ad-cd'), n = 60;
  var fa = function (x) { return String(x).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
  if (btn) {
    cd.textContent = '(' + fa(n) + ')';
    var t = setInterval(function () {
      if (--n <= 0) { clearInterval(t); btn.disabled = false; cd.textContent = ''; return; }
      cd.textContent = '(' + fa(n) + ')';
    }, 1000);
  }
})();
</script>
@endsection
