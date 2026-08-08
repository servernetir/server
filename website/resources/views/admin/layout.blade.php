<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'مدیریت') — ServerNet</title>
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="stylesheet" href="{{ asset_ver('assets/css/admin.css') }}">
{{-- تم پیش از رنگ‌آمیزی ست می‌شود تا «فلاشِ» تمِ اشتباه نبینیم. همان کوکیِ
     snet-theme سایت اصلی، پس تمِ مدیر بین سایت و کنسول یکی می‌ماند. --}}
<script>(function(){try{var m=document.cookie.match(/(?:^|;\s*)snet-theme=(light|dark)/);var t=m?m[1]:localStorage.getItem('snet-theme');if(t==='light')document.documentElement.dataset.theme='light';}catch(e){}})();</script>
</head>
<body>
@include('partials.icons')
@auth
<div class="ad-shell">
  <aside class="ad-side">
    <a class="ad-logo" href="/admin"><span class="ad-logo-m"><svg class="icon"><use href="#i-server"/></svg></span> سرورنت <b>مدیریت</b></a>
    <nav class="ad-nav">
      <a href="/admin" class="@yield('nav_dash')"><svg class="icon"><use href="#i-layout"/></svg>داشبورد</a>
      <a href="/admin/posts?type=blog" class="@yield('nav_blog')"><svg class="icon"><use href="#i-book"/></svg>بلاگ</a>
      <a href="/admin/posts?type=kb" class="@yield('nav_kb')"><svg class="icon"><use href="#i-lifebuoy"/></svg>پایگاه دانش</a>
      @php $pendingComments = \App\Models\Comment::where('approved', false)->count(); @endphp
      <a href="/admin/comments" class="@yield('nav_comments')"><svg class="icon"><use href="#i-message"/></svg>کامنت‌ها@if($pendingComments)<span class="ad-pill">{{ $pendingComments }}</span>@endif</a>

      <div class="ad-nav-sep">کسب‌وکار</div>
      {{-- نگهبان hasTable همه‌جا: روی سروری که هنوز جدول‌های CMS را نساخته،
           این شمارش‌ها نباید کل پنل را ۵۰۰ کنند --}}
      @php $custCount = \Illuminate\Support\Facades\Schema::hasTable('customers')
              ? \App\Models\Customer::count() : 0; @endphp
      <a href="/admin/customers" class="@yield('nav_customers')"><svg class="icon"><use href="#i-users"/></svg>مشتریان@if($custCount)<span class="ad-pill" style="background:rgba(34,211,238,.18);color:#22d3ee">{{ $custCount }}</span>@endif</a>
      @php $pendingKyc = \Illuminate\Support\Facades\Schema::hasTable('customer_profiles')
              ? \App\Models\CustomerProfile::where('status', 'pending')->count() : 0; @endphp
      <a href="/admin/verifications" class="@yield('nav_verifications')"><svg class="icon"><use href="#i-shield"/></svg>احراز هویت@if($pendingKyc)<span class="ad-pill">{{ $pendingKyc }}</span>@endif</a>
      @php $openTickets = \Illuminate\Support\Facades\Schema::hasTable('tickets')
              ? \App\Models\Ticket::where('status', 'open')->count() : 0; @endphp
      <a href="/admin/tickets" class="@yield('nav_tickets')"><svg class="icon"><use href="#i-lifebuoy"/></svg>تیکت‌ها@if($openTickets)<span class="ad-pill">{{ $openTickets }}</span>@endif</a>
      <a href="/admin/broadcasts" class="@yield('nav_broadcasts')"><svg class="icon"><use href="#i-bell"/></svg>اعلان‌ها</a>
      <a href="/admin/servers" class="@yield('nav_servers')"><svg class="icon"><use href="#i-server"/></svg>سرورهای تحویل</a>
      <a href="/admin/products" class="@yield('nav_products')"><svg class="icon"><use href="#i-box"/></svg>پکیج‌های فروش</a>
      <a href="/admin/server-shop" class="@yield('nav_server_shop')"><svg class="icon"><use href="#i-server"/></svg>سرورِ فیزیکی</a>
      <a href="/admin/cloud" class="@yield('nav_cloud')"><svg class="icon"><use href="#i-cloud"/></svg>زیرساختِ ابری</a>
      <a href="/admin/domains" class="@yield('nav_domains')"><svg class="icon"><use href="#i-globe"/></svg>دامنه‌ها</a>

      <div class="ad-nav-sep">مالی</div>
      <a href="/admin/finance" class="@yield('nav_finance')"><svg class="icon"><use href="#i-coins"/></svg>مالی و سود</a>
      <a href="/admin/transactions" class="@yield('nav_transactions')"><svg class="icon"><use href="#i-list"/></svg>تراکنش‌ها و اعتبار</a>
      @php $pendingBank = \Illuminate\Support\Facades\Schema::hasTable('bank_transfer_receipts')
              ? \App\Models\BankTransferReceipt::where('status', 'pending')->count() : 0; @endphp
      <a href="/admin/bank-transfers" class="@yield('nav_bank')"><svg class="icon"><use href="#i-db"/></svg>واریز به حساب@if($pendingBank)<span class="ad-pill">{{ $pendingBank }}</span>@endif</a>
      <a href="/admin/payment-accounts" class="@yield('nav_payacc')"><svg class="icon"><use href="#i-coins"/></svg>حساب‌های ارزی و رمزارز</a>
      <a href="/admin/crypto-wallets" class="@yield('nav_crypto')"><svg class="icon"><use href="#i-key"/></svg>کیف‌های رمزارز</a>
      <a href="/admin/costs" class="@yield('nav_costs')"><svg class="icon"><use href="#i-tag"/></svg>هزینه‌های سرویس‌ها</a>
      <div class="ad-nav-sep">سیستم</div>
      @php $errCount = \App\Support\ErrorTracker::recent(150, 'error');
              $errCount = count(array_filter($errCount, fn($e)=>in_array(($e['type']??''), ['error','incident'], true))); @endphp
      <a href="/admin/errors" class="@yield('nav_errors')"><svg class="icon"><use href="#i-zap"/></svg>ردیاب خطا@if($errCount)<span class="ad-pill">{{ $errCount }}</span>@endif</a>
      @if(auth()->user()->isAdmin())
      <a href="/admin/status" class="@yield('nav_status')"><svg class="icon"><use href="#i-gauge"/></svg>صفحهٔ وضعیت</a>
      <a href="/admin/templates" class="@yield('nav_templates')"><svg class="icon"><use href="#i-mail"/></svg>الگوی پیام‌ها</a>
      <a href="/admin/settings" class="@yield('nav_settings')"><svg class="icon"><use href="#i-wrench"/></svg>تنظیمات</a>
      <a href="/admin/users" class="@yield('nav_users')"><svg class="icon"><use href="#i-user"/></svg>کاربران پنل</a>
      @endif
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
    @if(session('err'))<div class="ad-flash err">{{ session('err') }}</div>@endif
    @if($errors->any())<div class="ad-flash err">{{ $errors->first() }}</div>@endif
    <div class="ad-content">@yield('content')</div>
  </main>
</div>
@else
<div class="ad-auth">@yield('content')</div>
@endauth
@yield('scripts')
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
@include('partials.ui-dialog')
</body>
</html>
