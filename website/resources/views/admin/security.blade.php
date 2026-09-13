@extends('admin.layout')
@section('title', 'امنیت حساب من')
@section('nav_security', 'on')
@section('content')
<div class="ad-panel" style="margin:0;max-width:760px">
  <div class="ad-panel-h">
    <h3>ورود دومرحله‌ای با اپلیکیشن</h3>
    <span class="ad-badge {{ $user->hasTwoFactor() ? 'pub' : 'draft' }}">
      {{ $user->hasTwoFactor() ? 'روشن' : ($user->twoFactorPending() ? 'در حال راه‌اندازی' : 'خاموش') }}
    </span>
  </div>

  <div style="padding:16px 18px">
    <p class="ad-hint" style="margin:0 0 6px">
      علاوه بر رمز عبور و کدی که به ایمیلتان می‌رود، یک کد شش‌رقمی هم از اپلیکیشن روی
      گوشی خودتان خواسته می‌شود. با Google Authenticator، Microsoft Authenticator،
      Authy و هر اپلیکیشن استاندارد دیگری کار می‌کند.
    </p>
    <p class="ad-hint" style="margin:0 0 4px">
      این تنظیم فقط روی <b>حساب خودتان</b> اثر دارد. هیچ‌کس — حتی مدیر دیگر — نمی‌تواند
      دومرحله‌ای شما را از پنل بردارد.
    </p>

    {{-- کدهای بازیابی — فقط همین یک بار، درست بعد از ساخته‌شدن --}}
    @if($recovery)
      <div class="ad-note" style="margin:16px 0">
        <b style="display:block;margin-bottom:8px">کدهای بازیابی — همین حالا ذخیره‌شان کنید</b>
        <div class="totp-codes">
          @foreach($recovery as $rc)<code dir="ltr">{{ $rc }}</code>@endforeach
        </div>
        <p class="ad-hint" style="margin:10px 0 0">
          اگر گوشی‌تان گم شود، این کدها تنها راه ورود شما هستند. هر کد فقط یک بار کار
          می‌کند. بعد از ترک این صفحه دیگر نشان داده نمی‌شوند.
        </p>
      </div>
    @endif

    @if($user->twoFactorPending())
      <div class="totp-setup">
        <div class="totp-qr">{!! $qr !!}</div>
        <div style="flex:1;min-width:240px">
          <p class="ad-hint" style="margin:0 0 8px">این کد را با اپلیکیشن احراز هویت اسکن کنید.</p>
          <p class="ad-hint" style="margin:0 0 6px">دوربین کار نمی‌کند؟ همین کلید را دستی وارد کنید:</p>
          <code class="totp-secret" dir="ltr">{{ \App\Services\Security\Totp::formatSecret($secret) }}</code>

          <form method="post" action="/admin/security/2fa/confirm" style="margin-top:14px">
            @csrf
            <div class="ad-field"><label>کد شش‌رقمی</label>
              <input class="ad-input" type="text" name="code" dir="ltr" inputmode="numeric" maxlength="6"
                     required autofocus autocomplete="one-time-code"
                     style="text-align:center;letter-spacing:8px;font-weight:700">
            </div>
            <button class="btn btn-primary" type="submit">تأیید و فعال‌سازی</button>
          </form>

          <form method="post" action="/admin/security/2fa/cancel" style="margin-top:10px">
            @csrf
            <button type="submit" style="background:none;border:0;color:var(--muted);font:inherit;font-size:12.5px;cursor:pointer;padding:0;text-decoration:underline">
              انصراف از راه‌اندازی
            </button>
          </form>
        </div>
      </div>

    @elseif($user->hasTwoFactor())
      <p class="ad-hint" style="margin:14px 0 0">
        از {{ blog_date($user->two_factor_confirmed_at) }} فعال است.
        {{ fa_num($leftCodes) }} کد بازیابی استفاده‌نشده باقی مانده است.
      </p>

      <div class="totp-block">
        <b>ساخت کدهای بازیابی تازه</b>
        <p class="ad-hint">با ساخت فهرست تازه، کدهای قبلی همان لحظه باطل می‌شوند.</p>
        <form method="post" action="/admin/security/2fa/recovery" class="totp-inline">
          @csrf
          <input class="ad-input" type="text" name="code" dir="ltr" maxlength="24" required
                 placeholder="کد اپلیکیشن یا کد بازیابی">
          <button class="btn" type="submit">ساخت کدهای تازه</button>
        </form>
      </div>

      <div class="totp-block">
        <b>خاموش کردن</b>
        <p class="ad-hint">برای خاموش کردن، کد فعلی اپلیکیشن (یا یکی از کدهای بازیابی) را وارد کنید.</p>
        <form method="post" action="/admin/security/2fa/disable" class="totp-inline"
              data-confirm="ورود دومرحله‌ای خاموش شود؟" data-confirm-danger>
          @csrf
          <input class="ad-input" type="text" name="code" dir="ltr" maxlength="24" required
                 placeholder="کد اپلیکیشن یا کد بازیابی">
          <button class="btn btn-danger" type="submit">خاموش کردن</button>
        </form>
      </div>

    @else
      <form method="post" action="/admin/security/2fa/start" style="margin-top:16px">
        @csrf<button class="btn btn-primary" type="submit">فعال‌سازی</button>
      </form>
    @endif
  </div>
</div>

<style>
.totp-setup{ display:flex; flex-wrap:wrap; gap:20px; align-items:flex-start; margin-top:16px }
.totp-qr{ background:#fff; border:1px solid var(--line); border-radius:12px; padding:10px; line-height:0; flex:none }
.totp-qr svg{ width:170px; height:170px; display:block }
.totp-secret{ display:inline-block; direction:ltr; background:var(--surface); border:1px solid var(--line); border-radius:8px; padding:9px 12px; font-size:13px; letter-spacing:1px; color:var(--text) }
.totp-codes{ display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:8px }
.totp-codes code{ direction:ltr; text-align:center; background:var(--surface); border:1px solid var(--line); border-radius:8px; padding:9px 6px; font-size:13px; letter-spacing:1px; color:var(--text) }
.totp-block{ margin-top:18px; border-top:1px solid var(--line); padding-top:14px }
.totp-block b{ font-size:13.5px; color:var(--text) }
.totp-inline{ display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-top:10px }
.totp-inline .ad-input{ flex:1; min-width:200px; margin:0 }
</style>
@endsection
