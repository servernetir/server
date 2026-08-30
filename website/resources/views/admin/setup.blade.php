@extends('admin.layout')
@section('title', 'راه‌اندازی')
@section('content')
<div class="ad-card">
  <div class="ad-brand"><span class="m"><svg class="icon"><use href="#i-server"/></svg></span> سرورنت</div>
  <h2>ساخت اولین مدیر</h2>
  @if($errors->any())<div class="ad-flash err">{{ $errors->first() }}</div>@endif
  <form method="post" action="/admin/setup">
    @csrf
    <div class="ad-field"><label>نام</label><input class="ad-input" type="text" name="name" required value="{{ old('name') }}"></div>
    <div class="ad-field"><label>ایمیل</label><input class="ad-input" type="email" name="email" dir="ltr" required value="{{ old('email') }}"></div>
    <div class="ad-field"><label>رمز عبور (حداقل ۸ کاراکتر)</label><input class="ad-input" type="password" name="password" required></div>
    <div class="ad-field"><label>تکرار رمز عبور</label><input class="ad-input" type="password" name="password_confirmation" required></div>
    <button class="btn btn-primary" type="submit">ساخت حساب مدیر</button>
  </form>
</div>
@endsection
