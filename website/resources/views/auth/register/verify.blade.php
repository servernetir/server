@extends('auth.shell')
@section('title', 'تأیید شماره — سرورنت')

@section('steps')
  <li class="done"><b>✓</b><span>تماس</span></li>
  <li class="on"><b>۲</b><span>تأیید</span></li>
  @if($iranian)<li><b>۳</b><span>احراز هویت</span></li>@endif
  <li><b>{{ $iranian ? '۴' : '۳' }}</b><span>رمز عبور</span></li>
@endsection

@section('heading', 'کد تأیید را وارد کنید')
@section('sub')
  کد شش‌رقمی به <b dir="ltr">{{ $masked }}</b> فرستاده شد.
@endsection

@section('form')
<form method="POST" action="{{ lroute('register.verify') }}" class="auth-f">
  @csrf

  <div class="auth-field auth-otp">
    <label for="code">کد تأیید</label>
    <input type="text" id="code" name="code" dir="ltr" inputmode="numeric"
           autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}"
           placeholder="······" required autofocus>
    <small>کد تا ۳ دقیقه معتبر است.</small>
  </div>

  <button type="submit" class="auth-btn">تأیید و ادامه</button>
</form>

<div class="auth-row" style="margin-top:16px">
  <form method="POST" action="{{ lroute('register.resend') }}">
    @csrf
    <button type="submit" class="auth-ghost" id="resend" disabled>
      ارسال دوباره <span id="cd">(۶۰)</span>
    </button>
  </form>
  <a class="auth-ghost" href="{{ lroute('register') }}">تغییر {{ $iranian ? 'شماره' : 'ایمیل' }}</a>
</div>

<script>
(function () {
  // شمارش معکوس فقط برای راحتی چشم است؛ سقف واقعی سمت سرور اعمال می‌شود
  var btn = document.getElementById('resend'), cd = document.getElementById('cd'), n = 60;
  var fa = function (x) { return String(x).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
  var t = setInterval(function () {
    n--;
    if (n <= 0) { clearInterval(t); btn.disabled = false; cd.textContent = ''; return; }
    cd.textContent = '(' + fa(n) + ')';
  }, 1000);

  // شش رقم که کامل شد، خودکار بفرست — یک کلیک کمتر روی موبایل
  var input = document.getElementById('code');
  input.addEventListener('input', function () {
    this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
    if (this.value.length === 6) this.form.requestSubmit();
  });
})();
</script>
@endsection
