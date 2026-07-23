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
      {{-- نگهبان hasTable: تا وقتی جدول tickets روی سرور ساخته نشده، این
           شمارش نباید کل پنل را ۵۰۰ کند --}}
      @php $openTickets = \Illuminate\Support\Facades\Schema::hasTable('tickets')
              ? \App\Models\Ticket::where('status', 'open')->count() : 0; @endphp
      <a href="/admin/tickets" class="@yield('nav_tickets')"><svg class="icon"><use href="#i-lifebuoy"/></svg>تیکت‌ها@if($openTickets)<span class="ad-pill">{{ $openTickets }}</span>@endif</a>
      <a href="/admin/finance" class="@yield('nav_finance')"><svg class="icon"><use href="#i-coins"/></svg>مالی و سود</a>
      @if(auth()->user()->isAdmin())
      <a href="/admin/users" class="@yield('nav_users')"><svg class="icon"><use href="#i-user"/></svg>کاربران</a>
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
</body>
</html>
