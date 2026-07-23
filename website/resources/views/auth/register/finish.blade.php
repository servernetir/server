@extends('auth.shell')
@section('title', 'انتخاب رمز عبور — سرورنت')

@section('steps')
  <li class="done"><b>✓</b><span>تماس</span></li>
  <li class="done"><b>✓</b><span>تأیید</span></li>
  @if($reg['iranian'])<li class="done"><b>✓</b><span>احراز هویت</span></li>@endif
  <li class="on"><b>{{ $reg['iranian'] ? '۴' : '۳' }}</b><span>رمز عبور</span></li>
@endsection

@section('heading')
  @if(!empty($reg['name']))
    {{ $reg['name'] }} عزیز، خوش آمدید
  @else
    انتخاب رمز عبور
  @endif
@endsection

@section('sub')
  @if(!empty($reg['name']))
    هویت شما تأیید شد. فقط یک رمز عبور بسازید و حساب آماده است.
  @else
    یک رمز عبور امن انتخاب کنید تا حساب شما ساخته شود.
  @endif
@endsection

@section('form')
<form method="POST" action="{{ lroute('register.finish') }}" class="auth-f">
  @csrf

  <div class="auth-field">
    <label for="password">رمز عبور</label>
    <input type="password" id="password" name="password" dir="ltr"
           autocomplete="new-password" minlength="10" required autofocus>
    <small>دست‌کم ۱۰ نویسه. از رمزی که جای دیگری استفاده می‌کنید، استفاده نکنید.</small>
  </div>

  <div class="auth-field">
    <label for="password_confirmation">تکرار رمز عبور</label>
    <input type="password" id="password_confirmation" name="password_confirmation" dir="ltr"
           autocomplete="new-password" minlength="10" required>
  </div>

  <label class="auth-check">
    <input type="checkbox" name="terms" value="1" {{ old('terms') ? 'checked' : '' }} required>
    <span>
      <a href="{{ lroute('terms') }}" target="_blank" rel="noopener">شرایط استفاده</a>
      و
      <a href="{{ lroute('privacy') }}" target="_blank" rel="noopener">سیاست حریم خصوصی</a>
      را خوانده‌ام و می‌پذیرم.
    </span>
  </label>

  <button type="submit" class="auth-btn">ساخت حساب</button>
</form>
@endsection
