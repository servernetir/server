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
    <div class="ad-field"><label>رمز عبور</label>
      <div style="position:relative;direction:ltr">
        <input class="ad-input" type="password" name="password" id="ad-pw" required style="padding-inline-end:44px">
        <button type="button" id="ad-pw-eye" aria-label="نمایش رمز عبور"
                style="position:absolute;inset-inline-end:6px;top:50%;transform:translateY(-50%);background:none;border:0;cursor:pointer;color:var(--muted);padding:6px;display:grid;place-items:center;line-height:0">
          <svg class="icon" style="width:18px;height:18px"><use href="#i-eye" id="ad-pw-icon"/></svg>
        </button>
      </div>
    </div>
    <div class="ad-field"><label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" name="remember" value="1"> مرا به خاطر بسپار</label></div>
    <button class="btn btn-primary" type="submit">ورود</button>
  </form>
</div>
<script>
(function () {
  var input = document.getElementById('ad-pw'), btn = document.getElementById('ad-pw-eye'),
      icon = document.getElementById('ad-pw-icon');
  if (!input || !btn) return;
  btn.addEventListener('click', function () {
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    icon.setAttribute('href', show ? '#i-eye-off' : '#i-eye');
  });
})();
</script>
@endsection
