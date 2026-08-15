@extends('admin.layout')
@section('title', 'کنسولِ بله')
@section('nav_bale', 'active')
@section('content')

@php
  $webhookLabel = [
    'ours'      => ['✅', 'وب‌هوک به همین سایت وصل است'],
    'elsewhere' => ['🔴', 'وب‌هوک به جای دیگری وصل است — تا اصلاح نشود هیچ فرمانی به ما نمی‌رسد'],
    'unset'     => ['🔴', 'وب‌هوکی ثبت نشده — ربات هیچ پیامی تحویل نمی‌دهد'],
    'no_token'  => ['🟡', 'توکنِ ربات در .env نیست'],
    'unknown'   => ['🟡', 'وضعیتِ وب‌هوک خوانده نشد'],
  ][$webhook['state']] ?? ['🟡', '—'];
@endphp

<div class="ad-panel">
  <div class="ad-panel-h"><h2>کنسولِ مدیر در بله</h2></div>

  <p style="padding:0 18px 14px;color:var(--muted);font-size:13px;line-height:1.9">
    پاسخ‌دادن و بستنِ تیکت از داخلِ بله، بدونِ باز کردنِ پنل. روی اعلانِ هر تیکت
    <b>ریپلای</b> بزنید و متن را بنویسید؛ شماره لازم نیست.
    <br>
    کارهای پولی و برگشت‌ناپذیر — تأیید یا ردِ رسیدِ بانکی، حذفِ سرویس یا مشتری، تغییرِ رمزِ
    مشتری، ثبت و تمدیدِ دامنه، اعلانِ گروهی و تنظیماتِ توکن — <b>عمداً</b> از ربات در دسترس
    نیستند و فقط از همین پنل انجام می‌شوند.
  </p>

  @if(session('ok'))<div class="ad-flash ok" style="margin:0 18px 14px">{{ session('ok') }}</div>@endif
  @if(session('err'))<div class="ad-flash err" style="margin:0 18px 14px">{{ session('err') }}</div>@endif

  <div style="display:flex;flex-wrap:wrap;gap:10px;padding:0 18px 18px">
    @php $col = $user ? '#34d399' : '#fbbf24'; @endphp
    <span class="ad-badge" style="background:{{ $col }}22;color:{{ $col }};font-size:12.5px;padding:7px 12px">
      {{ $user ? 'متصل به: '.$user->name : 'هنوز متصل نشده' }}
    </span>

    @php $col2 = $enabled ? '#34d399' : 'rgb(148,163,184)'; @endphp
    <span class="ad-badge" style="background:{{ $enabled ? 'rgba(52,211,153,.14)' : 'rgba(148,163,184,.14)' }};color:{{ $col2 }};font-size:12.5px;padding:7px 12px">
      کنسول: {{ $enabled ? 'روشن' : 'خاموش' }}
    </span>

    @if($bind && !empty($bind['at']))
      <span class="ad-badge" style="background:rgba(148,163,184,.14);color:var(--muted);font-size:12.5px;padding:7px 12px">
        از {{ sdate($bind['at']) }}
      </span>
    @endif

    @if($pending)
      <span class="ad-badge" style="background:rgba(251,191,36,.14);color:#fbbf24;font-size:12.5px;padding:7px 12px">
        ⏳ در انتظارِ تأیید: {{ $pending }}
      </span>
    @endif
  </div>

  {{-- 🔴 اگر وب‌هوک جای دیگری باشد، هیچ‌چیزِ این صفحه کار نمی‌کند و تنها نشانه‌اش «سکوت» است --}}
  <div style="padding:0 18px 18px">
    <div class="ad-flash {{ $webhook['state'] === 'ours' ? 'ok' : 'err' }}" style="margin:0">
      {{ $webhookLabel[0] }} {{ $webhookLabel[1] }}
      @if($webhook['host'])<span dir="ltr" style="opacity:.75"> ({{ $webhook['host'] }})</span>@endif
      @if($webhook['state'] !== 'ours')
        <div style="margin-top:6px;font-size:12.5px">
          اصلاح از مسیرِ <code>/system/bale-setup</code> با <code>DEPLOY_TOKEN</code>.
        </div>
      @endif
    </div>
  </div>
</div>

<div class="ad-panel" style="margin-top:16px">
  <div class="ad-panel-h"><h3>اتصال</h3></div>

  <p style="padding:0 18px 14px;color:var(--muted);font-size:13px;line-height:1.9">
    با زدنِ دکمه، یک کدِ ۶ رقمی به <b dir="ltr">{{ $me->email }}</b> فرستاده می‌شود.
    ظرفِ ۳ دقیقه در چتِ خصوصیِ ربات بفرستید: <code dir="ltr">/pair 123456</code>
    <br>
    <span style="color:var(--dim)">
      کد عمداً روی این صفحه نشان داده نمی‌شود؛ برای اتصال باید هم به پنل دسترسی داشته باشید
      هم به ایمیل. چتی که کد را درست بزند، از آن لحظه تنها چتِ مجاز است.
    </span>
  </p>

  <div style="display:flex;flex-wrap:wrap;gap:10px;padding:0 18px 18px">
    <form method="post" action="/admin/bale/pair">@csrf
      <button class="btn btn-primary" type="submit">
        <svg class="icon"><use href="#i-key"/></svg>{{ $user ? 'اتصالِ چتِ تازه' : 'شروعِ اتصال' }}
      </button>
    </form>

    @if($user)
      <form method="post" action="/admin/bale/toggle">@csrf
        <input type="hidden" name="on" value="{{ $enabled ? 0 : 1 }}">
        <button class="btn" type="submit">
          <svg class="icon"><use href="#i-{{ $enabled ? 'eye-off' : 'eye' }}"/></svg>{{ $enabled ? 'خاموش کن' : 'روشن کن' }}
        </button>
      </form>

      {{-- ⚠️ `data-confirm` و نه جعبهٔ خامِ مرورگر — قاعدهٔ برندِ سایت، قفل‌شده
           با `BrandedDialogTest`. آن تست **سورس** را می‌پاید، پس حتی نامِ آن
           تابع در یک کامنت هم قرمزش می‌کند. --}}
      <form method="post" action="/admin/bale/revoke"
            data-confirm="اتصالِ ربات قطع و کنسول خاموش شود؟ برای اتصالِ دوباره باید کدِ تازه بگیرید."
            data-confirm-title="قطعِ اتصالِ بله"
            data-confirm-ok="بله، قطع کن">@csrf
        <button class="btn" type="submit" style="color:#ff6b6b">
          <svg class="icon"><use href="#i-x"/></svg>قطعِ اتصال
        </button>
      </form>
    @endif
  </div>

  @if($user)
    <p style="padding:0 18px 18px;color:var(--dim);font-size:12.5px;line-height:1.9">
      گوشی‌تان گم شد؟ همین‌جا «قطعِ اتصال» را بزنید — کنسول بلافاصله خاموش می‌شود و
      کدهای تأییدِ نیمه‌کاره هم پاک می‌شوند.
    </p>
  @endif
</div>

<div class="ad-panel" style="margin-top:16px">
  <div class="ad-panel-h"><h3>فرمان‌ها</h3></div>
  <div style="padding:0 4px 8px;overflow-x:auto">
    <table class="ad-table">
      <thead><tr><th>فرمان</th><th>کار</th><th>تأیید</th></tr></thead>
      <tbody>
        <tr><td>راهنما</td><td>فهرستِ کارها</td><td>—</td></tr>
        <tr><td>کارها</td><td>صفِ تیکت‌های منتظرِ پاسخ</td><td>—</td></tr>
        <tr><td>تیکت &lt;شماره&gt;</td><td>پروندهٔ کامل — همین پیام لنگرِ ریپلای می‌شود</td><td>—</td></tr>
        <tr><td>سلامت</td><td>وضعیتِ سامانه</td><td>—</td></tr>
        <tr><td>وضعیت</td><td>ربات به چه حسابی وصل است</td><td>—</td></tr>
        <tr><td>یادداشت &lt;متن&gt;</td><td>یادداشتِ داخلی؛ مشتری نمی‌بیند</td><td>ندارد</td></tr>
        <tr><td><b>«متنِ آزاد» با ریپلای</b></td><td>پاسخ به مشتری</td><td><b>دارد</b></td></tr>
        <tr><td>بستن [متن]</td><td>پاسخ و بستن، یا فقط بستن</td><td><b>دارد</b></td></tr>
        <tr><td>تأیید &lt;۶ رقم&gt;</td><td>اجرای کارِ منتظر</td><td>—</td></tr>
      </tbody>
    </table>
  </div>
  <p style="padding:10px 18px 18px;color:var(--dim);font-size:12.5px;line-height:1.9">
    یادداشتِ داخلی در ربات <b>نمایش داده نمی‌شود</b> (فقط شمارشش)، و ایمیل و موبایلِ مشتری
    هرگز چاپ نمی‌شود — یک ترنسکریپتِ چت قابلِ فوروارد است.
  </p>
</div>
@endsection
