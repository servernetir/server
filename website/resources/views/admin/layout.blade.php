<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'مدیریت') — ServerNet</title>
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="stylesheet" href="{{ asset('assets/css/admin.css') }}?v={{ filemtime(public_path('assets/css/admin.css')) }}">
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
      @php $openTickets = \Illuminate\Support\Facades\Schema::hasTable('tickets')
              ? \App\Models\Ticket::where('status', 'open')->count() : 0; @endphp
      <a href="/admin/tickets" class="@yield('nav_tickets')"><svg class="icon"><use href="#i-lifebuoy"/></svg>تیکت‌ها@if($openTickets)<span class="ad-pill">{{ $openTickets }}</span>@endif</a>
      <a href="/admin/broadcasts" class="@yield('nav_broadcasts')"><svg class="icon"><use href="#i-bell"/></svg>اعلان‌ها</a>
      <a href="/admin/servers" class="@yield('nav_servers')"><svg class="icon"><use href="#i-server"/></svg>سرورهای تحویل</a>
      <a href="/admin/products" class="@yield('nav_products')"><svg class="icon"><use href="#i-box"/></svg>پکیج‌های فروش</a>

      <div class="ad-nav-sep">مالی</div>
      <a href="/admin/finance" class="@yield('nav_finance')"><svg class="icon"><use href="#i-coins"/></svg>مالی و سود</a>
      <a href="/admin/transactions" class="@yield('nav_transactions')"><svg class="icon"><use href="#i-list"/></svg>تراکنش‌ها و اعتبار</a>
      @php $pendingBank = \Illuminate\Support\Facades\Schema::hasTable('bank_transfer_receipts')
              ? \App\Models\BankTransferReceipt::where('status', 'pending')->count() : 0; @endphp
      <a href="/admin/bank-transfers" class="@yield('nav_bank')"><svg class="icon"><use href="#i-db"/></svg>واریز به حساب@if($pendingBank)<span class="ad-pill">{{ $pendingBank }}</span>@endif</a>
      <a href="/admin/costs" class="@yield('nav_costs')"><svg class="icon"><use href="#i-tag"/></svg>هزینه‌های سرویس‌ها</a>
      <div class="ad-nav-sep">سیستم</div>
      @php $errCount = \App\Support\ErrorTracker::recent(150); $errCount = count(array_filter($errCount, fn($e)=>($e['type']??'')==='error')); @endphp
      <a href="/admin/errors" class="@yield('nav_errors')"><svg class="icon"><use href="#i-zap"/></svg>ردیاب خطا@if($errCount)<span class="ad-pill">{{ $errCount }}</span>@endif</a>
      @if(auth()->user()->isAdmin())
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
      <div class="ad-user"><span>{{ auth()->user()->name }}</span><b>{{ auth()->user()->isAdmin() ? 'مدیر' : 'نویسنده' }}</b></div>
    </header>
    @if(session('ok'))<div class="ad-flash ok">{{ session('ok') }}</div>@endif
    @if($errors->any())<div class="ad-flash err">{{ $errors->first() }}</div>@endif
    <div class="ad-content">@yield('content')</div>
  </main>
</div>
@else
<div class="ad-auth">@yield('content')</div>
@endauth
@yield('scripts')
@include('partials.ui-dialog')
</body>
</html>
