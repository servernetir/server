@extends('panel.layout')
@section('title', 'امنیت حساب — ServerNet')

@section('panel')

<div class="pnl-head">
  <div>
    <h1 class="dash-h">امنیت حساب</h1>
    <p>رمز عبور، محدودسازیِ IP و دسترسیِ API را این‌جا مدیریت کنید.</p>
  </div>
</div>

@if(session('ok'))
  <div class="pnl-sec" style="border-color:var(--ok-line)">
    <div class="pnl-sec-b" style="color:var(--ok);font-size:13.5px;line-height:2">{{ session('ok') }}</div>
  </div>
@endif
@if($errors->any())
  <div class="pnl-sec" style="border-color:var(--danger-line)">
    <div class="pnl-sec-b" style="color:var(--danger);font-size:13.5px;line-height:2">
      @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
  </div>
@endif

{{-- ══ رمز عبور ══ --}}
<section class="pnl-sec" id="sec-pw">
  <div class="pnl-sec-h"><h2>رمز عبور</h2></div>
  <div class="pnl-sec-b">
    <p class="sec-note">ورود به حساب با <b>کدِ یک‌بارمصرف</b> است و به رمز نیازی ندارد. اگر می‌خواهید رمز هم داشته باشید، این‌جا با تأییدِ کد تنظیمش کنید — خودِ کد اثباتِ هویت است.</p>

    @if($pwReady)
      <form method="POST" action="{{ lroute('account.security.pw') }}" class="sec-form">
        @csrf
        <label>کد تأیید
          <input type="text" name="code" dir="ltr" inputmode="numeric" required autocomplete="one-time-code" placeholder="۶ رقم">
        </label>
        <label>رمز عبور جدید
          <input type="password" name="password" required minlength="8" placeholder="حداقل ۸ نویسه">
        </label>
        <label>تکرار رمز عبور
          <input type="password" name="password_confirmation" required>
        </label>
        <button class="pnl-btn primary" style="justify-content:center">ثبت رمز جدید</button>
      </form>
    @else
      <form method="POST" action="{{ lroute('account.security.pw.start') }}">
        @csrf
        <p class="sec-note" style="margin-bottom:12px">کد تأیید به {{ $customer->phone ? 'موبایلِ ثبت‌شده' : 'ایمیلِ ثبت‌شده' }} فرستاده می‌شود.</p>
        <button class="pnl-btn primary" style="justify-content:center">{{ $hasPassword ? 'تغییر رمز عبور' : 'تنظیم رمز عبور' }}</button>
      </form>
    @endif
  </div>
</section>

{{-- ══ محدودسازی IP ══ --}}
<section class="pnl-sec" id="sec-ip">
  <div class="pnl-sec-h"><h2>محدودسازیِ IP</h2></div>
  <div class="pnl-sec-b">
    <p class="sec-note">می‌توانید IPهای <b>مجاز</b> (سفید) یا <b>مسدود</b> (سیاه) تعریف کنید. IP فعلی شما: <b dir="ltr">{{ $currentIp }}</b></p>

    <form method="POST" action="{{ lroute('account.security.ipmode') }}">
      @csrf
      <div class="sec-modes">
        @foreach(['off'=>['خاموش','هیچ محدودیتی اعمال نمی‌شود'],'warn'=>['هشدار','فقط اطلاع؛ ورود بلاک نمی‌شود'],'enforce'=>['سخت‌گیرانه','ورود از IPِ غیرمجاز بلاک می‌شود']] as $m => $info)
          <label class="sec-mode-opt {{ $ipMode === $m ? 'on' : '' }}">
            <input type="radio" name="mode" value="{{ $m }}" {{ $ipMode === $m ? 'checked' : '' }} onchange="this.form.submit()">
            <b>{{ $info[0] }}</b><small>{{ $info[1] }}</small>
          </label>
        @endforeach
      </div>
    </form>

    @if($ipMode === 'enforce')
      <p class="sec-warn">⚠️ حالتِ سخت‌گیرانه فعال است. اگر IP فعلی‌تان در فهرستِ «مجاز» نباشد، در ورودِ بعدی قفل می‌شوید و باید با پشتیبانی تماس بگیرید. مطمئن شوید <b dir="ltr">{{ $currentIp }}</b> را به‌عنوان «مجاز» اضافه کرده‌اید.</p>
    @endif

    @if($ipRules->isNotEmpty())
      <div class="sec-rules">
        @foreach($ipRules as $r)
          <div class="sec-rule">
            <span class="sec-badge {{ $r->action === 'deny' ? 'deny' : 'allow' }}">{{ $r->action === 'deny' ? 'مسدود' : 'مجاز' }}</span>
            <span dir="ltr" class="sec-cidr">{{ $r->cidr }}</span>
            @if($r->label)<span class="sec-lbl">{{ $r->label }}</span>@endif
            <form method="POST" action="{{ lroute('account.security.ip.delete', $r) }}" data-confirm="این قاعده حذف شود؟" data-confirm-danger style="margin-inline-start:auto;display:flex">
              @csrf<button type="submit" class="sec-x" title="حذف"><svg class="icon"><use href="#i-x"/></svg></button>
            </form>
          </div>
        @endforeach
      </div>
    @else
      <p class="sec-note" style="margin-top:12px">هنوز قاعده‌ای ندارید.</p>
    @endif

    <form method="POST" action="{{ lroute('account.security.ip') }}" class="sec-form sec-inline">
      @csrf
      <label>IP یا رنج
        <input type="text" name="cidr" dir="ltr" placeholder="1.2.3.4 یا 1.2.3.0/24" required>
      </label>
      <label>نوع
        <select name="action"><option value="allow">مجاز (سفید)</option><option value="deny">مسدود (سیاه)</option></select>
      </label>
      <label>برچسب (اختیاری)
        <input type="text" name="label" placeholder="خانه / اداره" maxlength="64">
      </label>
      <button class="pnl-btn" style="justify-content:center">افزودن</button>
    </form>
  </div>
</section>

{{-- ══ دسترسی API ══ --}}
<section class="pnl-sec" id="sec-api">
  <div class="pnl-sec-h"><h2>دسترسیِ API</h2></div>
  <div class="pnl-sec-b">
    <p class="sec-note">با توکنِ API می‌توانید وضعیت حساب، سرویس‌ها، فاکتورها و اعتبارتان را برنامه‌نویسی بخوانید. <b>فعلاً فقط‌خواندنی</b>؛ ساختِ سرویس و دامنه به‌زودی اضافه می‌شود.</p>

    @if(session('new_token'))
      <div class="sec-newtok">
        <b>توکنِ شما ساخته شد — همین حالا کپی کنید، دیگر نشان داده نمی‌شود:</b>
        <code dir="ltr" class="copyable" title="برای کپی کلیک کنید">{{ session('new_token') }}</code>
      </div>
    @endif

    @if($apiTokens->isNotEmpty())
      <div class="sec-tokens">
        @foreach($apiTokens as $t)
          <div class="sec-token">
            <div class="sec-token-t">
              <b>{{ $t->name }}</b>
              <small>ساخته‌شده {{ stime($t->created_at) }}@if($t->last_used_at) · آخرین استفاده {{ stime($t->last_used_at) }}@else · هنوز استفاده نشده @endif</small>
            </div>
            <form method="POST" action="{{ lroute('account.security.token.delete', $t) }}" data-confirm="این توکن باطل شود؟ برنامه‌هایی که از آن استفاده می‌کنند از کار می‌افتند." data-confirm-danger style="margin-inline-start:auto">
              @csrf<button type="submit" class="sec-revoke">باطل کردن</button>
            </form>
          </div>
        @endforeach
      </div>
    @else
      <p class="sec-note" style="margin-top:12px">هنوز توکنی نساخته‌اید.</p>
    @endif

    <form method="POST" action="{{ lroute('account.security.token') }}" class="sec-form sec-inline">
      @csrf
      <label>نام توکن
        <input type="text" name="name" placeholder="مثلاً اسکریپت مانیتورینگ" maxlength="80" required>
      </label>
      <button class="pnl-btn primary" style="justify-content:center">ساخت توکن</button>
    </form>

    <details class="sec-doc">
      <summary>نمونهٔ استفاده</summary>
      <pre dir="ltr">curl -H "Authorization: Bearer sn_..." \
     https://servernet.cloud/api/v1/me</pre>
      <p class="sec-note">endpointها: <code dir="ltr">/api/v1/me</code> · <code dir="ltr">/api/v1/services</code> · <code dir="ltr">/api/v1/invoices</code> · <code dir="ltr">/api/v1/credit</code></p>
    </details>
  </div>
</section>

<style>
.sec-note{ font-size:13px; color:var(--muted); line-height:2; margin:0 0 6px }
.sec-form{ display:flex; flex-direction:column; gap:12px; margin-top:14px; max-width:420px }
.sec-form.sec-inline{ flex-flow:row wrap; align-items:flex-end; max-width:none }
.sec-form label{ display:flex; flex-direction:column; gap:6px; font-size:12.5px; color:var(--muted); flex:1; min-width:150px }
.sec-form input, .sec-form select{ background:var(--surface); border:1px solid var(--line); border-radius:10px; padding:10px 12px; font:inherit; font-size:13px; color:var(--text) }
.sec-warn{ font-size:12.5px; color:var(--warn); background:var(--warn-bg); border:1px solid var(--warn-line); border-radius:11px; padding:11px 14px; line-height:2; margin:12px 0 }
.sec-modes{ display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin:12px 0 }
@media(max-width:560px){ .sec-modes{ grid-template-columns:1fr } }
.sec-mode-opt{ cursor:pointer; border:1.5px solid var(--line); border-radius:13px; padding:12px 14px; display:flex; flex-direction:column; gap:3px; transition:.16s }
.sec-mode-opt input{ display:none }
.sec-mode-opt b{ font-size:13px; color:var(--text) }
.sec-mode-opt small{ font-size:11px; color:var(--muted) }
.sec-mode-opt.on{ border-color:var(--info); background:var(--info-bg) }
.sec-rules{ display:flex; flex-direction:column; gap:8px; margin:12px 0 }
.sec-rule{ display:flex; align-items:center; gap:10px; border:1px solid var(--line); border-radius:11px; padding:9px 12px; background:var(--surface) }
.sec-badge{ font-size:11px; font-weight:700; border-radius:20px; padding:3px 10px; flex:none }
.sec-badge.allow{ color:var(--ok); background:var(--ok-bg) }
.sec-badge.deny{ color:var(--danger); background:var(--danger-bg) }
.sec-cidr{ font-size:13px; color:var(--text); font-variant-numeric:tabular-nums }
.sec-lbl{ font-size:12px; color:var(--muted) }
.sec-x{ background:var(--danger-bg); border:1px solid var(--danger-line); color:var(--danger); border-radius:8px; padding:6px 8px; cursor:pointer; line-height:0; display:grid; place-items:center }
.sec-x .icon{ width:14px; height:14px }
.sec-newtok{ border:1px solid var(--ok-line); background:var(--ok-bg); border-radius:12px; padding:13px 15px; margin:12px 0; display:flex; flex-direction:column; gap:8px }
.sec-newtok b{ font-size:12.5px; color:var(--ok) }
.sec-newtok code{ direction:ltr; background:var(--surface); border:1px solid var(--line); border-radius:8px; padding:9px 12px; font-size:12.5px; word-break:break-all; cursor:copy; color:var(--text) }
.sec-tokens{ display:flex; flex-direction:column; gap:8px; margin:12px 0 }
.sec-token{ display:flex; align-items:center; gap:12px; border:1px solid var(--line); border-radius:11px; padding:11px 14px; background:var(--surface) }
.sec-token-t{ display:flex; flex-direction:column; gap:2px; min-width:0 }
.sec-token-t b{ font-size:13px; color:var(--text) }
.sec-token-t small{ font-size:11px; color:var(--dim) }
.sec-revoke{ background:var(--danger-bg); border:1px solid var(--danger-line); color:var(--danger); border-radius:9px; padding:8px 13px; font:inherit; font-size:12.5px; font-weight:600; cursor:pointer; flex:none }
.sec-doc{ margin-top:16px; border-top:1px solid var(--line); padding-top:12px }
.sec-doc summary{ cursor:pointer; font-size:13px; color:var(--info) }
.sec-doc pre{ direction:ltr; background:var(--surface); border:1px solid var(--line); border-radius:10px; padding:12px 14px; font-size:12px; overflow-x:auto; margin:10px 0; color:var(--text) }
.copyable{ cursor:copy }
</style>
<script>
document.querySelectorAll('.copyable').forEach(function (el) {
  el.addEventListener('click', function () {
    var t = (this.textContent || '').trim();
    if (navigator.clipboard) navigator.clipboard.writeText(t);
    if (window.snToast) snToast('کپی شد ✓', 'ok');
  });
});
</script>
@endsection
