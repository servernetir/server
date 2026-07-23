@extends('auth.shell')
@section('title', __('ui.auth_pw_title').' — ServerNet')

@section('heading')
  @if(!empty($reg['name']))
    {{ __('ui.auth_pw_welcome', ['name' => $reg['name']]) }}
  @else
    {{ __('ui.auth_pw_title') }}
  @endif
@endsection

@section('sub')
  {{ !empty($reg['name']) ? __('ui.auth_pw_sub_verified') : __('ui.auth_pw_sub') }}
@endsection

@section('form')
@php
    // ⚠ آرایه اینجا ساخته می‌شود و نه درون‌خطی در @json — آرایهٔ چندخطیِ
    // درون‌خطی، پارسر Blade را می‌شکند و صفحه ۵۰۰ می‌دهد.
    $pwStrings = [
        'hint'     => __('ui.auth_pw_hint'),
        'short'    => __('ui.auth_pw_short'),
        'strength' => __('ui.auth_pw_strength', ['level' => '__L__']),
        'levels'   => [
            __('ui.auth_pw_v1'), __('ui.auth_pw_v2'), __('ui.auth_pw_v3'),
            __('ui.auth_pw_v4'), __('ui.auth_pw_v5'),
        ],
    ];
@endphp

<form method="POST" action="{{ lroute('register.finish') }}" class="auth-f" id="pwf" novalidate>
  @csrf

  <div class="auth-field" data-check="pw">
    <label for="password">{{ __('ui.auth_password') }}</label>
    <input type="password" id="password" name="password" dir="ltr"
           autocomplete="new-password" minlength="10" required
           autofocus aria-describedby="pw-meter">
    {{-- سنجهٔ قدرت: بازخورد فوری، ولی قاعدهٔ واقعی (حداقل ۱۰ نویسه) سمت سرور است --}}
    <div id="pw-meter" style="margin-top:9px" aria-live="polite">
      <div class="auth-prog-bar"><i id="pw-bar" style="width:0"></i></div>
      <small id="pw-text">{{ __('ui.auth_pw_hint') }}</small>
    </div>
  </div>

  <div class="auth-field" data-check="pw2">
    <label for="password_confirmation">{{ __('ui.auth_password_again') }}</label>
    <input type="password" id="password_confirmation" name="password_confirmation" dir="ltr"
           autocomplete="new-password" minlength="10" required>
    <span class="msg">{{ __('ui.auth_pw_mismatch') }}</span>
  </div>

  <label class="auth-check">
    <input type="checkbox" name="terms" value="1" {{ old('terms') ? 'checked' : '' }} required>
    {{-- یک جملهٔ کامل با جای‌نگهدار، نه چند تکهٔ به‌هم‌چسبیده — چون ترتیب
         «و» و فعل در سه زبان یکی نیست و تکه‌چسبانی در ترکی جملهٔ بی‌معنی
         می‌سازد.

         آرایه عمداً بیرون از خروجی ساخته می‌شود و نه درون‌خطی، چون آرایهٔ
         چندخطی داخل تگ echo خام، پارسر Blade را می‌شکند.

         ⚠ و در خودِ کامنت هم نباید جداکنندهٔ Blade نوشت: کامنت را زودتر
         می‌بندد و بقیه‌اش به‌عنوان کد اجرا می‌شود. همین‌جا یک بار ۵۰۰ داد. --}}
    @php
        $termsLink   = '<a href="'.lroute('terms').'" target="_blank" rel="noopener">'.e(__('ui.auth_terms')).'</a>';
        $privacyLink = '<a href="'.lroute('privacy').'" target="_blank" rel="noopener">'.e(__('ui.auth_privacy')).'</a>';
        $termsLine   = __('ui.auth_terms_accept', ['terms' => $termsLink, 'privacy' => $privacyLink]);
    @endphp
    <span>{!! $termsLine !!}</span>
  </label>

  <button type="submit" class="auth-btn"><span class="spin"></span><span>{{ __('ui.auth_create') }}</span></button>
</form>

<script>
(function () {
  var pw = document.getElementById('password'),
      pw2 = document.getElementById('password_confirmation'),
      bar = document.getElementById('pw-bar'),
      txt = document.getElementById('pw-text'),
      form = document.getElementById('pwf');

  var T = @json($pwStrings);

  var COLOR = ['var(--danger)', 'var(--danger)', 'var(--warn)', 'var(--ok)', 'var(--ok)'];

  pw.addEventListener('input', function () {
    var v = this.value, score = 0;
    if (v.length >= 10) score++;
    if (v.length >= 14) score++;
    if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
    if (/[0-9]/.test(v)) score++;
    if (/[^A-Za-z0-9]/.test(v)) score++;
    score = Math.min(score, 5);

    var i = Math.max(score - 1, 0);
    bar.style.width = (score * 20) + '%';
    bar.style.background = v ? COLOR[i] : 'var(--line)';
    txt.textContent = v
      ? (v.length < 10 ? T.short : T.strength.replace('__L__', T.levels[i]))
      : T.hint;
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
    <span>{!! __('ui.auth_pw_hashed') !!}</span>
  </div>
  <div>
    <svg class="icon"><use href="#i-shield"/></svg>
    <span>{!! __('ui.auth_2fa_later') !!}</span>
  </div>
@endsection
