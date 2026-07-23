@extends('panel.layout')
@section('title', 'پنل کاربری — سرورنت')

@section('panel')

<div class="pnl-head">
  <div>
    <h1>سلام{{ $identity?->first_name ? '، '.$identity->first_name : '' }}</h1>
    <p>شناسهٔ مشتری شما: <span dir="ltr">{{ $customer->code }}</span></p>
  </div>
</div>

@if(session('ok'))
  <div class="pnl-sec" style="border-color:var(--ok-line)">
    <div class="pnl-sec-b" style="color:var(--ok);font-size:13.5px">{{ session('ok') }}</div>
  </div>
@endif

{{-- ==== کارهای نیمه‌تمام ====
     تا وقتی سرویس و فاکتور نداریم، مفیدترین چیزی که می‌شود نشان داد این است
     که کاربر برای سفارش دادن چه چیزی کم دارد. --}}
@php
  $todo = [];
  if ($identity?->status !== 'verified' && $customer->locale === 'fa') {
      $todo[] = ['t' => 'احراز هویت را کامل کنید', 's' => 'بدون آن امکان سفارش سرویس نیست', 'u' => lroute('account.profile'), 'k' => 'd'];
  }
  if ($bank === null && $identity?->status === 'verified') {
      $todo[] = ['t' => 'حساب بانکی اضافه کنید', 's' => 'برای تسویه و بازگشت وجه لازم است', 'u' => lroute('account.bank'), 'k' => 'w'];
  }
@endphp

@if($todo)
<section class="pnl-sec pnl-alert">
  <div class="pnl-sec-h"><h2>کارهای باقی‌مانده</h2></div>
  <ul class="pnl-todo">
    @foreach($todo as $item)
      <li>
        <span class="pnl-todo-ic {{ $item['k'] }}"><svg class="icon"><use href="#i-user"/></svg></span>
        <span class="pnl-todo-t"><b>{{ $item['t'] }}</b><small>{{ $item['s'] }}</small></span>
        <a class="pnl-btn" href="{{ $item['u'] }}">انجام</a>
      </li>
    @endforeach
  </ul>
</section>
@endif

<div class="pnl-stats">
  <div class="pnl-stat">
    <div class="pnl-stat-h"><svg class="icon"><use href="#i-server"/></svg>سرویس فعال</div>
    <b class="pnl-num">۰</b>
    <small>هنوز سرویسی سفارش نداده‌اید</small>
  </div>
  <div class="pnl-stat">
    <div class="pnl-stat-h"><svg class="icon"><use href="#i-coins"/></svg>فاکتور باز</div>
    <b class="pnl-num">۰</b>
    <small>پرداخت معوقی ندارید</small>
  </div>
  <div class="pnl-stat">
    <div class="pnl-stat-h"><svg class="icon"><use href="#i-lifebuoy"/></svg>تیکت باز</div>
    <b class="pnl-num">۰</b>
    <small>پشتیبانی ۲۴ ساعته</small>
  </div>
</div>

<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>شروع کنید</h2></div>
  <div class="pnl-sec-b">
    <p style="font-size:13.5px;color:var(--muted);line-height:2;margin:0 0 14px">
      حساب شما آماده است. برای شروع، یک دامنه بگیرید یا سرویس میزبانی سفارش دهید.
    </p>
    <div class="pnl-acts">
      <a class="pnl-btn primary" href="{{ lroute('domain.search') }}">
        <svg class="icon"><use href="#i-search"/></svg>جستجوی دامنه
      </a>
      <a class="pnl-btn" href="{{ lroute('home') }}">
        <svg class="icon"><use href="#i-server"/></svg>مشاهدهٔ سرویس‌ها
      </a>
    </div>
  </div>
</section>

@endsection
