@extends('auth.shell')
@section('title', 'ثبت‌نام — سرورنت')

@section('heading', 'ساخت حساب کاربری')
@section('sub', 'کمتر از دو دقیقه طول می‌کشد و بعدش می‌توانید سرویس سفارش دهید.')

@section('form')
<form method="POST" action="{{ lroute('register.start') }}" class="auth-f" novalidate>
  @csrf

  <div class="auth-field">
    <label id="lbl-type">نوع حساب</label>
    <div class="auth-pick" role="radiogroup" aria-labelledby="lbl-type">
      <input type="radio" name="type" id="t-ind" value="individual" {{ old('type', 'individual') === 'individual' ? 'checked' : '' }}>
      <label for="t-ind"><b>شخص حقیقی</b><span>فاکتور به نام خودتان</span></label>

      <input type="radio" name="type" id="t-co" value="company" {{ old('type') === 'company' ? 'checked' : '' }}>
      <label for="t-co"><b>شخص حقوقی</b><span>فاکتور رسمی شرکت</span></label>
    </div>
  </div>

  @if($iranian)
  <div class="auth-field" data-check="mobile">
    <label for="phone">شمارهٔ موبایل</label>
    <input type="tel" id="phone" name="phone" dir="ltr" inputmode="numeric"
           autocomplete="tel" placeholder="۰۹۱۲۱۲۳۴۵۶۷" value="{{ old('phone') }}"
           required aria-describedby="hint-phone">
    <span class="msg">شماره باید با ۰۹ شروع شود و ۱۱ رقم باشد.</span>
    <small id="hint-phone">کد تأیید به همین شماره پیامک می‌شود و باید به نام خودتان باشد.</small>
  </div>
  @endif

  <div class="auth-field" data-check="email">
    <label for="email">ایمیل</label>
    <input type="email" id="email" name="email" dir="ltr"
           autocomplete="email" placeholder="you@example.com" value="{{ old('email') }}"
           required aria-describedby="hint-email">
    <span class="msg">این ایمیل معتبر به نظر نمی‌رسد.</span>
    <small id="hint-email">فاکتور، هشدار تمدید و اطلاع‌رسانی سرویس به این آدرس می‌آید.</small>
  </div>

  <button type="submit" class="auth-btn"><span class="spin"></span><span>ارسال کد تأیید</span></button>
</form>
@endsection

@section('assure')
  <div>
    <svg class="icon"><use href="#i-shield"/></svg>
    <span>ارتباط شما <b>رمزنگاری‌شده</b> است.</span>
  </div>
  <div>
    <svg class="icon"><use href="#i-mail"/></svg>
    <span>ایمیل شما <b>هرگز</b> در اختیار دیگری قرار نمی‌گیرد.</span>
  </div>
@endsection

@section('aside')
  حساب دارید؟ <a href="{{ lroute('login') }}">وارد شوید</a>
@endsection
