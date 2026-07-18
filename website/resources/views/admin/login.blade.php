@extends('admin.layout')
@section('title', 'ورود')
@section('content')
<div class="ad-card">
  <div class="ad-brand"><span class="m"><svg class="icon"><use href="#i-server"/></svg></span> سرورنت</div>
  <h2>ورود به پنل مدیریت</h2>
  @if($errors->any())<div class="ad-flash err">{{ $errors->first() }}</div>@endif
  <form method="post" action="/admin/login">
    @csrf
    <div class="ad-field"><label>ایمیل</label><input class="ad-input" type="email" name="email" dir="ltr" required value="{{ old('email') }}"></div>
    <div class="ad-field"><label>رمز عبور</label><input class="ad-input" type="password" name="password" required></div>
    <div class="ad-field"><label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" name="remember" value="1"> مرا به خاطر بسپار</label></div>
    <button class="btn btn-primary" type="submit">ورود</button>
  </form>
</div>
@endsection
