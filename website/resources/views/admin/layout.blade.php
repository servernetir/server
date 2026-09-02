<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'مدیریت') — ServerNet</title>
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="stylesheet" href="{{ asset_ver('assets/css/admin.css') }}">
{{-- استایلِ اختصاصیِ این دو بخش. عمداً اینجا و نه داخل admin.css: آن فایل مشترک
     است و هر خطِ اضافه یک تعارضِ merge بالقوه برای تیم. --}}
@yield('head')
{{-- تم پیش از رنگ‌آمیزی ست می‌شود تا «فلاشِ» تمِ اشتباه نبینیم. همان کوکیِ
     snet-theme سایت اصلی، پس تمِ مدیر بین سایت و کنسول یکی می‌ماند. --}}
<script>(function(){try{var m=document.cookie.match(/(?:^|;\s*)snet-theme=(light|dark)/);var t=m?m[1]:localStorage.getItem('snet-theme');if(t==='light')document.documentElement.dataset.theme='light';}catch(e){}})();</script>
</head>
<body>
@include('partials.icons')
@auth
{{-- ══ نوارِ موبایل ══
     🔴 تا امروز در ≤۸۲۰px سایدبار یک دیوارِ افقیِ ~۳۵ لینک بالای هر صفحه
     می‌شد: محتوا یک صفحه پایین می‌رفت، لینک‌ها بیرون می‌زدند و عملاً چیزی
     قابلِ مدیریت نبود — همان «همه‌چیز بهم ریخته» که گزارش شد. حالا منو
     کشوی کناری است و با همبرگر باز می‌شود. --}}
<header class="ad-mob" aria-label="نوار مدیریت">
  <button type="button" class="ad-burger" id="ad-burger" aria-label="باز و بسته کردن منو" aria-expanded="false">
    <svg class="icon"><use href="#i-list"/></svg>
  </button>
  <a class="ad-mob-brand" href="/admin"><svg class="icon"><use href="#i-server"/></svg>سرورنت <b>مدیریت</b></a>
</header>
<div class="ad-scrim" id="ad-scrim" hidden></div>
<div class="ad-shell">
  <aside class="ad-side" id="ad-side">
    <a class="ad-logo" href="/admin"><span class="ad-logo-m"><svg class="icon"><use href="#i-server"/></svg></span> سرورنت <b>مدیریت</b></a>
    {{--
      ═══ منو بر اساسِ نقش ═══

      🔴 منویی که به صفحهٔ ۴۰۳ لینک می‌دهد بدتر از منوی کوتاه است: کارشناس
      کلیک می‌کند، «دسترسی ندارید» می‌گیرد، و دفعهٔ بعد به کلِ منو بی‌اعتماد
      می‌شود. پس آنچه پشتیبان نمی‌تواند باز کند، اصلاً رندر نمی‌شود.

      ⚠️ این فقط **نمایش** است، نه امنیت. گارد در روت و میان‌افزار است؛ اگر
      روزی این شرط‌ها اشتباه شوند، بدترین حالت یک لینکِ ۴۰۳ است نه دسترسی.
    --}}
    @php
      $navUser  = auth()->user();
      $navAdmin = (bool) $navUser?->isAdmin();
      $navSup   = (bool) $navUser?->isSupport();
    @endphp
    <nav class="ad-nav">
      <a href="/admin" class="@yield('nav_dash')"><svg class="icon"><use href="#i-layout"/></svg>داشبورد</a>
      @unless($navSup)
        <a href="/admin/posts?type=blog" class="@yield('nav_blog')"><svg class="icon"><use href="#i-book"/></svg>بلاگ</a>
        <a href="/admin/posts?type=kb" class="@yield('nav_kb')"><svg class="icon"><use href="#i-lifebuoy"/></svg>پایگاه دانش</a>
        @php $pendingComments = \App\Models\Comment::where('approved', false)->count(); @endphp
        <a href="/admin/comments" class="@yield('nav_comments')"><svg class="icon"><use href="#i-message"/></svg>کامنت‌ها@if($pendingComments)<span class="ad-pill">{{ $pendingComments }}</span>@endif</a>
      @endunless

      <div class="ad-nav-sep">{{ $navSup ? 'پشتیبانی' : 'کسب‌وکار' }}</div>
      {{-- نمای یک‌جای همهٔ سررسیدهای این گروه — عمداً اولین آیتم، چون خودش
           چیزی نمی‌سازد و فقط به بقیه اشاره می‌کند --}}
      @unless($navSup)
        <a href="/admin/calendar" class="@yield('nav_calendar')"><svg class="icon"><use href="#i-calendar"/></svg>تقویم کسب‌وکار</a>
      @endunless
      {{-- نگهبان hasTable همه‌جا: روی سروری که هنوز جدول‌های CMS را نساخته،
           این شمارش‌ها نباید کل پنل را ۵۰۰ کنند --}}
      @php $custCount = \Illuminate\Support\Facades\Schema::hasTable('customers')
              ? \App\Models\Customer::count() : 0; @endphp
      <a href="/admin/customers" class="@yield('nav_customers')"><svg class="icon"><use href="#i-users"/></svg>مشتریان@if($custCount)<span class="ad-pill" style="background:rgba(34,211,238,.18);color:#22d3ee">{{ $custCount }}</span>@endif</a>
      {{-- «احراز هویت» عمداً آیتمِ مستقلِ منو نیست: زیرمجموعهٔ مشتریان است و
           به‌صورت دکمه (با همان شمارشِ در انتظار) بالای /admin/customers نشسته.
           ⚠️ روتِ /admin/verifications دست‌نخورده است — فقط از نوارِ کناری
           برداشته شد، حذف نشد. --}}
      {{-- تحویل‌های گیرکرده: پولِ آمده که سرویسش نرسیده. شمارش فقط حالت‌هایی
           که **بدونِ آدم** از جا تکان نمی‌خورند (failed/manual/قفلِ کهنه) —
           صفِ سالمِ pending عمداً شمرده نمی‌شود وگرنه نشان همیشه روشن می‌مانْد
           و از روزِ دوم نادیده گرفته می‌شد. --}}
      @php
        try {
          $provStuck = \Illuminate\Support\Facades\Cache::remember('admin.nav.prov-stuck', 60,
              fn () => \Illuminate\Support\Facades\Schema::hasTable('services')
                  ? \App\Models\Service::whereNotIn('status', \App\Models\Service::DEAD_STATUSES)
                      ->where(fn ($q) => $q->whereIn('provision_status', ['failed', 'manual'])
                          ->orWhere(fn ($qq) => $qq->where('provision_status', 'running')
                              ->where('updated_at', '<', now()->subMinutes(15))))
                      ->count()
                  : 0);
        } catch (\Throwable) { $provStuck = 0; }
      @endphp
      {{-- فهرستِ کلِ سرویس‌های فروخته‌شده — «تحویل‌ها»یِ پایین زیرمجموعهٔ همین
           است (فقط تحویل‌نشده‌ها)، پس دقیقاً بالایِ آن می‌نشیند.
           ⚠️ بی‌نشان است: عددِ کلِ سرویس‌ها هشدار نیست و هرگز صفر نمی‌شود
           — همان درسِ «نشانِ دائمی از روزِ دوم نادیده گرفته می‌شود». --}}
      @if($navAdmin)
        <a href="/admin/services" class="@yield('nav_services')"><svg class="icon"><use href="#i-hdd"/></svg>سرویس‌ها</a>
      @endif
      @unless($navSup)
        <a href="/admin/provisioning" class="@yield('nav_provisioning')"><svg class="icon"><use href="#i-box"/></svg>تحویل‌ها@if($provStuck)<span class="ad-pill">{{ fa_num($provStuck) }}</span>@endif</a>
      @endunless
      @php $openTickets = \Illuminate\Support\Facades\Schema::hasTable('tickets')
              ? \App\Models\Ticket::where('status', 'open')->count() : 0; @endphp
      <a href="/admin/tickets" class="@yield('nav_tickets')"><svg class="icon"><use href="#i-lifebuoy"/></svg>تیکت‌ها@if($openTickets)<span class="ad-pill">{{ $openTickets }}</span>@endif</a>
      {{-- ⚠️ شمارش فقط تماس‌های از‌دست‌رفتهٔ **۲۴ ساعت اخیر** است، نه کل تاریخ.
           نشانِ دائمیِ سه‌رقمی که هیچ‌وقت صفر نمی‌شود، از روز دوم نادیده گرفته
           می‌شود — همان درسِ «اعلانِ تکراری بدتر از نبودِ هشدار» که در
           SystemHealth ثبت شده.
           و `answered = false` صریح: تماسِ در جریان از‌دست‌رفته نیست. --}}
      @unless($navSup)
      @php $missedCalls = \Illuminate\Support\Facades\Schema::hasTable('phone_calls')
              ? \App\Models\PhoneCall::where('answered', false)
                  ->where('started_at', '>=', now()->subDay())->count() : 0; @endphp
      <a href="/admin/calls" class="@yield('nav_calls')"><svg class="icon"><use href="#i-phone"/></svg>تماس‌ها@if($missedCalls)<span class="ad-pill">{{ fa_num($missedCalls) }}</span>@endif</a>
      <a href="/admin/broadcasts" class="@yield('nav_broadcasts')"><svg class="icon"><use href="#i-bell"/></svg>اعلان‌ها</a>
      <a href="/admin/seo" class="@yield('nav_seo')"><svg class="icon"><use href="#i-gauge"/></svg>بررسی سایت</a>
      @endunless

      {{--
        ═══ گروه‌بندیِ منو بر اساسِ **محصول**، نه بر اساسِ تاریخِ ساخت ═══

        تا امروز همهٔ آیتم‌ها پشتِ سرِ هم بودند، به همان ترتیبی که ساخته شده
        بودند. یعنی «پکیج‌های فروش» (پکیجِ هاست) کنارِ «سرورِ فیزیکی» می‌نشست و
        «سرورهای تحویل» (کنترل‌پنل‌های هاست) کنارِ «زیرساختِ ابری» — و مدیر باید
        هر بار کلِ فهرست را می‌خواند تا چیزی را پیدا کند.

        قاعدهٔ کارفرما: هر محصول زیرمنویِ خودش. هاست = کنترل‌پنل‌ها + پکیج‌ها ·
        سرور = ابری + فیزیکی.

        ⚠️ «سرورهای تحویل» عمداً «تنظیماتِ هاست» نامیده **نشد** با اینکه فقط
        کنترل‌پنل‌ها را دارد (cPanel/Plesk/DirectAdmin): آن صفحه هنوز درایورِ
        `manual` را هم می‌پذیرد و ممکن است روی این نصب ردیفِ غیرهاستینگی داشته
        باشد. تغییرِ نام پیش از راستی‌آزماییِ آن ردیف‌ها یعنی چیزی بی‌صدا از
        دسترس خارج شود. نام وقتی عوض می‌شود که مطمئن باشیم.
      --}}
      {{-- از این‌جا به بعد کارِ پشتیبان نیست: زیرساخت، فروش، مالی و تنظیمات.
           پشتیبان همین‌جا فهرستش تمام می‌شود. --}}
      @unless($navSup)
      <div class="ad-nav-sep">هاست</div>
      <a href="/admin/servers" class="@yield('nav_servers')"><svg class="icon"><use href="#i-server"/></svg>سرورهای تحویل</a>
      <a href="/admin/products" class="@yield('nav_products')"><svg class="icon"><use href="#i-box"/></svg>پکیج‌های فروش</a>

      <div class="ad-nav-sep">سرور</div>
      <a href="/admin/cloud" class="@yield('nav_cloud')"><svg class="icon"><use href="#i-cloud"/></svg>زیرساختِ ابری</a>
      <a href="/admin/server-shop" class="@yield('nav_server_shop')"><svg class="icon"><use href="#i-server"/></svg>سرورِ فیزیکی</a>
      <a href="/admin/parts" class="@yield('nav_parts')"><svg class="icon"><use href="#i-cpu"/></svg>قطعاتِ سرور</a>
      <a href="/admin/exit-infra" class="@yield('nav_exit_infra')"><svg class="icon"><use href="#i-flow"/></svg>زیرساختِ اکسیت</a>

      <div class="ad-nav-sep">دامنه</div>
      <a href="/admin/domains" class="@yield('nav_domains')"><svg class="icon"><use href="#i-globe"/></svg>دامنه‌ها</a>

      @if(auth()->user()->isAdmin())
      {{-- دو بخشِ **جدا**. یکی بیرون را می‌گیرد، یکی داخل را مرتب می‌کند؛
           قاطی کردنشان یعنی هیچ‌کدام جای مشخصی در ذهن ندارد. --}}
      <div class="ad-nav-sep">رشد</div>
      @php $crmPending = \Illuminate\Support\Facades\Schema::hasTable('crm_messages')
              ? \App\Models\CrmMessage::where('direction', 'out')->where('status', 'queued')->count() : 0; @endphp
      <a href="/admin/marketing" class="@yield('nav_marketing')"><svg class="icon"><use href="#i-rocket"/></svg>بازاریابی هوشمند@if($crmPending)<span class="ad-pill">{{ $crmPending }}</span>@endif</a>
      @php $mailOpen = \Illuminate\Support\Facades\Schema::hasTable('mailbox_messages')
              ? \App\Models\MailboxMessage::open()->where('needs_reply', true)->count() : 0; @endphp
      <a href="/admin/mail" class="@yield('nav_mail')"><svg class="icon"><use href="#i-mail"/></svg>صندوق ایمیل@if($mailOpen)<span class="ad-pill">{{ $mailOpen }}</span>@endif</a>
      @endif

      <div class="ad-nav-sep">مالی</div>
      <a href="/admin/finance" class="@yield('nav_finance')"><svg class="icon"><use href="#i-coins"/></svg>مالی و سود</a>
      <a href="/admin/reports" class="@yield('nav_reports')"><svg class="icon"><use href="#i-gauge"/></svg>گزارشِ کسب‌وکار</a>
      <a href="/admin/transactions" class="@yield('nav_transactions')"><svg class="icon"><use href="#i-list"/></svg>تراکنش‌ها و اعتبار</a>
      @php $pendingBank = \Illuminate\Support\Facades\Schema::hasTable('bank_transfer_receipts')
              ? \App\Models\BankTransferReceipt::where('status', 'pending')->count() : 0; @endphp
      <a href="/admin/bank-transfers" class="@yield('nav_bank')"><svg class="icon"><use href="#i-db"/></svg>واریز به حساب@if($pendingBank)<span class="ad-pill">{{ $pendingBank }}</span>@endif</a>
      {{--
        «حساب‌های ارزی»، «کیف‌های رمزارز» و «هزینه‌های سرویس‌ها» از این‌جا
        برداشته شدند و داخلِ **تنظیمات** رفتند (تبِ حساب‌ها و تبِ هزینه‌ها).

        سه منوی جدا برای یک پرسشِ واحد («پول از کجا می‌آید؟») یعنی مدیر هر بار
        باید یادش بماند کدام کجاست. مسیرهای قدیمی زنده‌اند و به همان تب
        ریدایرکت می‌شوند، پس هیچ بوکمارکی نمی‌شکند.
      --}}
      <div class="ad-nav-sep">سیستم</div>
      @php $errCount = \App\Support\ErrorTracker::recent(150, 'error');
              $errCount = count(array_filter($errCount, fn($e)=>in_array(($e['type']??''), ['error','incident'], true))); @endphp
      <a href="/admin/errors" class="@yield('nav_errors')"><svg class="icon"><use href="#i-zap"/></svg>ردیاب خطا@if($errCount)<span class="ad-pill">{{ $errCount }}</span>@endif</a>
      @if(auth()->user()->isAdmin())
      <a href="/admin/status" class="@yield('nav_status')"><svg class="icon"><use href="#i-gauge"/></svg>صفحهٔ وضعیت</a>
      {{-- «الگوی پیام‌ها» هم به تنظیمات رفت (تبِ پیام‌ها). --}}
      <a href="/admin/settings" class="@yield('nav_settings')"><svg class="icon"><use href="#i-wrench"/></svg>تنظیمات</a>
      <a href="/admin/users" class="@yield('nav_users')"><svg class="icon"><use href="#i-user"/></svg>کاربران پنل</a>
      @endif
      @endunless {{-- پایانِ بخش‌های غیرپشتیبانی (از «هاست» تا این‌جا) --}}
      {{-- بیرونِ گاردِ isAdmin: امنیتِ حسابِ خودِ کاربر برای هر نقشی لازم است --}}
      <a href="/admin/security" class="@yield('nav_security')"><svg class="icon"><use href="#i-lock"/></svg>امنیت حساب من</a>
      <a href="/" target="_blank"><svg class="icon"><use href="#i-globe"/></svg>مشاهده‌ی سایت</a>
    </nav>
    <form class="ad-logout" method="post" action="/admin/logout">@csrf<button type="submit"><svg class="icon"><use href="#i-x"/></svg>خروج</button></form>
  </aside>
  <main class="ad-main">
    <header class="ad-top">
      <h1>@yield('title', 'مدیریت')</h1>
      <div class="ad-top-side">
        <button type="button" class="ad-theme" id="ad-theme" aria-label="تمِ روشن / تاریک" title="تمِ روشن / تاریک">
          <svg class="icon tt-sun"><use href="#i-sun"/></svg>
          <svg class="icon tt-moon"><use href="#i-moon"/></svg>
        </button>
        <div class="ad-user"><span>{{ auth()->user()->name }}</span><b>{{ auth()->user()->isAdmin() ? 'مدیر' : 'نویسنده' }}</b></div>
      </div>
    </header>
    @if(session('ok'))<div class="ad-flash ok">{{ session('ok') }}</div>@endif
    {{-- 🔴 این خط جا افتاده بود و ده‌ها `back()->with('err', …)` در کنترلرهای
         مدیریت **بی‌صدا** گم می‌شدند: «پلن پیدا نشد»، «زیرساخت ناشناخته»،
         «هیچ توکنی ذخیره نشده» — همه فلش می‌شدند و هیچ‌جا رندر نمی‌شدند، پس
         مدیر یک ریدایرکتِ بی‌پیام می‌دید و می‌گفت دکمه کار نمی‌کند. --}}
    {{-- ⚠️ «نمی‌دانیم» رنگِ خودش را دارد.
         بدونِ این، یک نتیجهٔ نامعلوم یا سبز نشان داده می‌شود (دروغِ خوش‌بینانه)
         یا قرمز (دروغِ بدبینانه). هر دو بار مدیر را گمراه می‌کند. --}}
    @if(session('warn'))<div class="ad-flash" style="background:rgba(251,191,36,.12);border-color:rgba(251,191,36,.35);color:#fbbf24">{{ session('warn') }}</div>@endif
    @if(session('err'))<div class="ad-flash err">{{ session('err') }}</div>@endif
    @if($errors->any())<div class="ad-flash err">{{ $errors->first() }}</div>@endif
    <div class="ad-content">@yield('content')</div>
  </main>
</div>
@else
<div class="ad-auth">@yield('content')</div>
@endauth
@yield('scripts')
{{-- فیلتر و مرتب‌سازیِ همهٔ جدول‌ها — عمومی است و هیچ ویویی لازم نیست چیزی
     اضافه کند. انصراف با `data-no-enhance` روی خودِ <table>. --}}
<script src="{{ asset_ver('assets/js/admin-tables.js') }}" defer></script>
{{-- دیت‌پیکرِ شمسی. خودمیزبان و بی‌کتابخانه (CSP هر CDN را بی‌صدا بلاک
     می‌کند)، و هیچ ریاضیِ جلالی در مرورگر ندارد — شبکهٔ ماه از سرور می‌آید. --}}
<script src="{{ asset_ver('assets/js/jdate.js') }}" defer></script>
<script>
(function(){
  var b = document.getElementById('ad-theme');
  if (!b) return;
  b.addEventListener('click', function(){
    var light = document.documentElement.dataset.theme === 'light';
    if (light) delete document.documentElement.dataset.theme;
    else document.documentElement.dataset.theme = 'light';
    var val = light ? 'dark' : 'light';
    try { localStorage.setItem('snet-theme', val); } catch (e) {}
    // کوکی روی دامنهٔ ریشه تا بین سایت و کنسول مشترک بماند
    try {
      var h = location.hostname,
          d = /(^|\.)servernet\.cloud$/i.test(h) ? '; domain=.servernet.cloud' : '';
      document.cookie = 'snet-theme=' + val + '; path=/; max-age=31536000; samesite=lax'
        + d + (location.protocol === 'https:' ? '; secure' : '');
    } catch (e) {}
  });
})();
</script>
<script>
/* کشوی موبایل — بدونِ وابستگی؛ Escape و کلیک روی رویه هم می‌بندند. */
(function () {
  var burger = document.getElementById('ad-burger');
  var side = document.getElementById('ad-side');
  var scrim = document.getElementById('ad-scrim');
  if (!burger || !side || !scrim) return;

  function set(open) {
    document.body.classList.toggle('nav-open', open);
    scrim.hidden = !open;
    burger.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  burger.addEventListener('click', function () {
    set(!document.body.classList.contains('nav-open'));
  });
  scrim.addEventListener('click', function () { set(false); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') set(false);
  });
  /* کلیک روی هر لینکِ منو کشو را می‌بندد — وگرنه بعدِ رفتن به صفحهٔ بعد،
     منو دوباره بازِ رویِ محتوا ظاهر می‌شد (bfcache). */
  side.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('a')) set(false);
  });
})();
</script>
@include('partials.ui-dialog')
</body>
</html>
