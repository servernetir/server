@extends('auth.shell')
@section('title', 'احراز هویت — سرورنت')

@section('steps')
  <li class="done"><b>✓</b><span>تماس</span></li>
  <li class="done"><b>✓</b><span>تأیید</span></li>
  <li class="on"><b>۳</b><span>احراز هویت</span></li>
  <li><b>۴</b><span>رمز عبور</span></li>
@endsection

@section('heading', 'احراز هویت')
@section('sub', 'طبق قوانین، پیش از ارائهٔ سرویس باید هویت شما تأیید شود.')

@section('form')
<div class="auth-info">
  <b>چرا نام و نام خانوادگی نمی‌پرسیم؟</b><br>
  نام شما مستقیم از ثبت احوال خوانده می‌شود — هم غلط تایپی پیش نمی‌آید، هم بعداً
  که حساب بانکی اضافه کنید، تطابق نام خودکار انجام می‌شود.
</div>

<form method="POST" action="{{ lroute('register.identity') }}" class="auth-f">
  @csrf

  <div class="auth-field">
    <label for="national_id">کد ملی</label>
    <input type="text" id="national_id" name="national_id" dir="ltr" inputmode="numeric"
           maxlength="10" placeholder="0084575948" value="{{ old('national_id') }}" required autofocus>
    <small>ده رقم، بدون خط تیره.</small>
  </div>

  <div class="auth-field">
    <label for="birth_date">تاریخ تولد (شمسی)</label>
    <input type="text" id="birth_date" name="birth_date" dir="ltr" inputmode="numeric"
           maxlength="10" placeholder="1370/05/12" value="{{ old('birth_date') }}" required>
    <small>همان تاریخی که در شناسنامه یا کارت ملی ثبت شده است.</small>
  </div>

  <button type="submit" class="auth-btn" id="go">تأیید هویت</button>
</form>

<script>
(function () {
  var nid = document.getElementById('national_id'), bd = document.getElementById('birth_date');

  nid.addEventListener('input', function () {
    this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10);
  });

  // خط‌تیره خودکار: کاربر فقط رقم می‌زند
  bd.addEventListener('input', function () {
    var d = this.value.replace(/[^0-9]/g, '').slice(0, 8), out = d.slice(0, 4);
    if (d.length > 4) out += '/' + d.slice(4, 6);
    if (d.length > 6) out += '/' + d.slice(6, 8);
    this.value = out;
  });

  // استعلام چند ثانیه طول می‌کشد؛ بدون این، کاربر دوباره کلیک می‌کند
  document.querySelector('.auth-f').addEventListener('submit', function () {
    var b = document.getElementById('go');
    b.disabled = true;
    b.textContent = 'در حال استعلام…';
  });
})();
</script>
@endsection
