@extends('auth.shell')
@section('title', 'ثبت‌نام — سرورنت')

@section('steps')
  <li class="on"><b>۱</b><span>تماس</span></li>
  <li><b>۲</b><span>تأیید</span></li>
  @if($iranian)<li><b>۳</b><span>احراز هویت</span></li>@endif
  <li><b>{{ $iranian ? '۴' : '۳' }}</b><span>رمز عبور</span></li>
@endsection

@section('heading', 'ساخت حساب کاربری')
@section('sub', 'برای سفارش سرویس، اول یک حساب بسازید. کمتر از دو دقیقه طول می‌کشد.')

@section('form')
<form method="POST" action="{{ lroute('register.start') }}" class="auth-f">
  @csrf

  <div class="auth-field">
    <label>نوع حساب</label>
    <div class="auth-pick">
      <input type="radio" name="type" id="t-ind" value="individual" {{ old('type', 'individual') === 'individual' ? 'checked' : '' }}>
      <label for="t-ind"><b>شخص حقیقی</b><span>فاکتور به نام خودتان</span></label>

      <input type="radio" name="type" id="t-co" value="company" {{ old('type') === 'company' ? 'checked' : '' }}>
      <label for="t-co"><b>شخص حقوقی</b><span>فاکتور رسمی شرکت</span></label>
    </div>
  </div>

  @if($iranian)
  <div class="auth-field">
    <label for="phone">شمارهٔ موبایل</label>
    <input type="tel" id="phone" name="phone" dir="ltr" inputmode="numeric"
           autocomplete="tel" placeholder="09121234567" value="{{ old('phone') }}" required>
    <small>کد تأیید به همین شماره پیامک می‌شود. شماره باید به نام خودتان باشد.</small>
  </div>
  @endif

  <div class="auth-field">
    <label for="email">ایمیل</label>
    <input type="email" id="email" name="email" dir="ltr"
           autocomplete="email" placeholder="you@example.com" value="{{ old('email') }}" required>
    <small>فاکتور، هشدار تمدید و اطلاع‌رسانی سرویس به این آدرس می‌آید.</small>
  </div>

  <button type="submit" class="auth-btn">ارسال کد تأیید</button>
</form>
@endsection

@section('aside')
  حساب دارید؟ <a href="{{ lroute('login') }}">وارد شوید</a>
@endsection
