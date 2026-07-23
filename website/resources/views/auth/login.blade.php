@extends('auth.shell')
@section('title', 'ورود — سرورنت')

@section('heading', 'ورود به پنل کاربری')
@section('sub', 'برای مدیریت سرویس‌ها، فاکتورها و تیکت‌ها وارد شوید.')

@section('form')
<form method="POST" action="{{ lroute('login') }}" class="auth-f">
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

  <div class="auth-row">
    <label class="auth-check" style="align-items:center">
      <input type="checkbox" name="remember" value="1" style="margin-top:0">
      <span>مرا به خاطر بسپار</span>
    </label>
  </div>

  <button type="submit" class="auth-btn">ورود</button>
</form>
@endsection

@section('aside')
  حساب ندارید؟ <a href="{{ lroute('register') }}">ثبت‌نام کنید</a>
@endsection
