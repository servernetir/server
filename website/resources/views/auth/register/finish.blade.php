@extends('auth.shell')
@section('title', 'انتخاب رمز عبور — سرورنت')

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
<form method="POST" action="{{ lroute('register.finish') }}" class="auth-f" id="pwf" novalidate>
  @csrf

  <div class="auth-field" data-check="pw">
    <label for="password">رمز عبور</label>
    <input type="password" id="password" name="password" dir="ltr"
           autocomplete="new-password" minlength="10" required
           autofocus aria-describedby="pw-meter">
    {{-- سنجهٔ قدرت: بازخورد فوری، ولی قاعدهٔ واقعی (حداقل ۱۰ نویسه) سمت سرور است --}}
    <div id="pw-meter" style="margin-top:9px" aria-live="polite">
      <div class="auth-prog-bar"><i id="pw-bar" style="width:0"></i></div>
      <small id="pw-text">دست‌کم ۱۰ نویسه. از رمزی که جای دیگری استفاده می‌کنید، استفاده نکنید.</small>
    </div>
  </div>

  <div class="auth-field" data-check="pw2">
    <label for="password_confirmation">تکرار رمز عبور</label>
    <input type="password" id="password_confirmation" name="password_confirmation" dir="ltr"
           autocomplete="new-password" minlength="10" required>
    <span class="msg">تکرار رمز با رمز اصلی یکی نیست.</span>
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

  <button type="submit" class="auth-btn"><span class="spin"></span><span>ساخت حساب</span></button>
</form>

<script>
(function () {
  var pw = document.getElementById('password'),
      pw2 = document.getElementById('password_confirmation'),
      bar = document.getElementById('pw-bar'),
      txt = document.getElementById('pw-text'),
      form = document.getElementById('pwf');

  var LABEL = ['خیلی ضعیف', 'ضعیف', 'متوسط', 'خوب', 'قوی'];
  var COLOR = ['var(--danger)', 'var(--danger)', 'var(--warn)', 'var(--ok)', 'var(--ok)'];

  pw.addEventListener('input', function () {
    var v = this.value, score = 0;
    if (v.length >= 10) score++;
    if (v.length >= 14) score++;
    if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
    if (/[0-9]/.test(v)) score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;
    score = Math.min(score, 5);

    bar.style.width = (score * 20) + '%';
    bar.style.background = v ? COLOR[Math.max(score - 1, 0)] : 'var(--line)';
    txt.textContent = v
      ? (v.length < 10 ? 'حداقل ۱۰ نویسه لازم است.' : 'قدرت رمز: ' + LABEL[Math.max(score - 1, 0)])
      : 'دست‌کم ۱۰ نویسه. از رمزی که جای دیگری استفاده می‌کنید، استفاده نکنید.';
  });

  pw2.addEventListener('blur', function () {
    var f = this.closest('.auth-field'), same = this.value === pw.value;
    f.classList.toggle('is-bad', this.value !== '' && !same);
    f.classList.toggle('is-ok', this.value !== '' && same);
  });

  form.addEventListener('submit', function () {
    var b = form.querySelector('.auth-btn');
    b.classList.add('busy');
    b.disabled = true;
  });
})();
</script>
@endsection

@section('assure')
  <div>
    <svg class="icon"><use href="#i-key"/></svg>
    <span>رمز شما <b>هش</b> می‌شود — حتی ما هم آن را نمی‌بینیم.</span>
  </div>
  <div>
    <svg class="icon"><use href="#i-shield"/></svg>
    <span>بعداً می‌توانید <b>ورود دومرحله‌ای</b> را فعال کنید.</span>
  </div>
@endsection
