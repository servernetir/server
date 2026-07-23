@extends('auth.shell')
@section('title', 'ورود — سرورنت')

@section('heading', 'ورود به پنل کاربری')
@section('sub', 'برای مدیریت سرویس‌ها، فاکتورها و تیکت‌ها وارد شوید.')

@section('form')
<form method="POST" action="{{ lroute('login') }}" class="auth-f" id="lf">
  @csrf

  <div class="auth-field">
    <label for="email">ایمیل</label>
    <input type="email" id="email" name="email" dir="ltr"
           autocomplete="username" placeholder="you@example.com"
           value="{{ old('email') }}" required autofocus>
  </div>

  <div class="auth-field">
    <label for="password">رمز عبور</label>
    <input type="password" id="password" name="password" dir="ltr"
           autocomplete="current-password" required>
  </div>

  <label class="auth-check" style="align-items:center">
    <input type="checkbox" name="remember" value="1" style="margin-top:0">
    <span>مرا به خاطر بسپار</span>
  </label>

  <button type="submit" class="auth-btn"><span class="spin"></span><span>ورود</span></button>
</form>

<script>
document.getElementById('lf').addEventListener('submit', function () {
  var b = this.querySelector('.auth-btn');
  b.classList.add('busy');
  b.disabled = true;
});
</script>
@endsection

@section('assure')
  <div>
    <svg class="icon"><use href="#i-shield"/></svg>
    <span>پس از چند تلاش ناموفق، حساب <b>موقتاً قفل</b> می‌شود.</span>
  </div>
  <div>
    <svg class="icon"><use href="#i-lock"/></svg>
    <span>ارتباط شما <b>رمزنگاری‌شده</b> است.</span>
  </div>
@endsection

@section('aside')
  حساب ندارید؟ <a href="{{ lroute('register') }}">ثبت‌نام کنید</a>
@endsection
