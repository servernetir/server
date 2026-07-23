@extends('auth.shell')
@section('title', 'احراز هویت — سرورنت')

@section('heading', 'احراز هویت')
@section('sub', 'طبق قوانین، پیش از ارائهٔ سرویس باید هویت شما تأیید شود.')

@section('form')
<div class="auth-info">
  <b>چرا نام و نام خانوادگی نمی‌پرسیم؟</b><br>
  نام شما مستقیم از ثبت احوال خوانده می‌شود — هم غلط تایپی پیش نمی‌آید، هم بعداً
  که حساب بانکی اضافه کنید، تطابق نام خودکار انجام می‌شود.
</div>

<form method="POST" action="{{ lroute('register.identity') }}" class="auth-f" id="idf" novalidate>
  @csrf

  <div class="auth-field" data-check="nid">
    <label for="national_id">کد ملی</label>
    <input type="text" id="national_id" name="national_id" dir="ltr" inputmode="numeric"
           maxlength="10" placeholder="۰۰۸۴۵۷۵۹۴۸" value="{{ old('national_id') }}"
           required autofocus aria-describedby="hint-nid">
    <span class="msg">این کد ملی معتبر نیست؛ رقم‌ها را دوباره بررسی کنید.</span>
    <small id="hint-nid">ده رقم، بدون خط تیره.</small>
  </div>

  <div class="auth-field" data-check="birth">
    <label for="birth_date">تاریخ تولد (شمسی)</label>
    <input type="text" id="birth_date" name="birth_date" dir="ltr" inputmode="numeric"
           maxlength="10" placeholder="۱۳۷۰/۰۵/۱۲" value="{{ old('birth_date') }}"
           required aria-describedby="hint-birth">
    <span class="msg">تاریخ را به شکل ۱۳۷۰/۰۵/۱۲ وارد کنید.</span>
    <small id="hint-birth">همان تاریخی که در شناسنامه یا کارت ملی ثبت شده است.</small>
  </div>

  <button type="submit" class="auth-btn" id="go">
    <span class="spin"></span><span class="txt">تأیید هویت</span>
  </button>
</form>

<script>
(function () {
  var nid = document.getElementById('national_id'),
      bd  = document.getElementById('birth_date'),
      form = document.getElementById('idf');

  var toEn = function (s) {
    return s.replace(/[۰-۹]/g, function (d) { return d.charCodeAt(0) - 1776; })
            .replace(/[٠-٩]/g, function (d) { return d.charCodeAt(0) - 1632; });
  };
  var mark = function (el, ok) {
    var f = el.closest('.auth-field');
    f.classList.toggle('is-bad', !ok && el.value !== '');
    f.classList.toggle('is-ok', ok);
  };

  // همان الگوریتم رسمی که سمت سرور هم اجرا می‌شود — اینجا فقط برای اینکه
  // کاربر قبل از فرستادن بفهمد، نه به‌جای بررسی سرور
  function validNid(v) {
    if (!/^\d{10}$/.test(v) || /^(\d)\1{9}$/.test(v)) return false;
    for (var i = 0, s = 0; i < 9; i++) s += +v[i] * (10 - i);
    var r = s % 11;
    return r < 2 ? +v[9] === r : +v[9] === 11 - r;
  }

  nid.addEventListener('input', function () {
    this.value = toEn(this.value).replace(/[^0-9]/g, '').slice(0, 10);
    this.closest('.auth-field').classList.remove('is-bad');
  });
  nid.addEventListener('blur', function () { mark(this, validNid(this.value)); });

  bd.addEventListener('input', function () {
    var d = toEn(this.value).replace(/[^0-9]/g, '').slice(0, 8), out = d.slice(0, 4);
    if (d.length > 4) out += '/' + d.slice(4, 6);
    if (d.length > 6) out += '/' + d.slice(6, 8);
    this.value = out;
    this.closest('.auth-field').classList.remove('is-bad');
  });
  bd.addEventListener('blur', function () {
    var d = this.value.replace(/[^0-9]/g, '');
    var ok = d.length === 8 && +d.slice(0,4) >= 1250 && +d.slice(0,4) <= 1450
             && +d.slice(4,6) >= 1 && +d.slice(4,6) <= 12
             && +d.slice(6,8) >= 1 && +d.slice(6,8) <= 31;
    mark(this, ok);
  });

  // استعلام چند ثانیه طول می‌کشد؛ بدون این، کاربر دوباره کلیک می‌کند و
  // هر کلیک یعنی یک استعلام پولی دیگر
  form.addEventListener('submit', function () {
    var b = document.getElementById('go');
    b.classList.add('busy');
    b.disabled = true;
    b.querySelector('.txt').textContent = 'در حال استعلام…';
  });
})();
</script>
@endsection

@section('assure')
  <div>
    <svg class="icon"><use href="#i-lock"/></svg>
    <span>کد ملی <b>رمزنگاری‌شده</b> ذخیره می‌شود.</span>
  </div>
  <div>
    <svg class="icon"><use href="#i-check"/></svg>
    <span>استعلام <b>رسمی</b> است — نیازی به آپلود مدرک نیست.</span>
  </div>
  <div>
    <svg class="icon"><use href="#i-shield"/></svg>
    <span>هیچ اطلاعاتی برای <b>بازاریابی</b> استفاده نمی‌شود.</span>
  </div>
@endsection
