@extends('admin.layout')
@section('title', 'سفارش‌های اسنپ‌پی')
@section('nav_snapppay', 'on')
@section('content')

@php
  $t = fn ($n) => fa_num(number_format((int) $n)).' ت';
  $stateLabel = [
      'pending'  => 'در انتظار',
      'verified' => 'تأییدشده، نهایی‌نشده',
      'settled'  => 'نهایی',
      'canceled' => 'لغوشده',
      'failed'   => 'ناموفق',
  ];
  // `.ad-badge` فقط دو حالت دارد (pub سبز، draft کم‌رنگ). وضعیت‌هایی که
  // توجه می‌خواهند رنگِ خودشان را درون‌خطی می‌گیرند، همان الگوی بقیهٔ پنل.
  $stateClass = [
      'pending' => 'draft', 'verified' => 'draft', 'settled' => 'pub',
      'canceled' => 'draft', 'failed' => 'draft',
  ];
  $stateColor = ['pending' => '#fbbf24', 'verified' => '#fbbf24', 'failed' => '#ff6b6b'];
@endphp

@unless($ready)
  <div class="ad-note" style="border-color:#fbbf24;color:#fbbf24;line-height:2">
    جدولِ سفارش‌های اسنپ‌پی هنوز روی این سرور ساخته نشده است. یک بار
    <a href="/system/migrate" style="color:#22d3ee">مهاجرت دیتابیس</a> را اجرا کنید.
  </div>
@endunless

@if(session('ok'))<div class="ad-note" style="border-color:#34d399;color:#34d399">{{ session('ok') }}</div>@endif
@if(session('err'))<div class="ad-note" style="border-color:#ff6b6b;color:#ff6b6b">{{ session('err') }}</div>@endif

@if($ready)
  <div class="fin-kpis">
    <div class="fin-kpi">
      <span class="fin-kpi-l">سفارشِ نهایی</span>
      <b class="fin-kpi-v" style="color:#34d399">{{ fa_num($kpis['settled']) }}</b>
      <small>پرداختِ کامل‌شده</small>
    </div>
    <div class="fin-kpi">
      <span class="fin-kpi-l">گردشِ اقساطی</span>
      <b class="fin-kpi-v">{{ $t($kpis['volume']) }}</b>
      <small>جمعِ سفارش‌های نهایی</small>
    </div>
    <div class="fin-kpi">
      <span class="fin-kpi-l">معلق</span>
      <b class="fin-kpi-v" style="color:{{ $kpis['open'] > 0 ? '#fbbf24' : 'inherit' }}">{{ fa_num($kpis['open']) }}</b>
      <small>کرونِ پیگیری هر ۵ دقیقه می‌بیندشان</small>
    </div>
    <div class="fin-kpi">
      <span class="fin-kpi-l">لغوشده</span>
      <b class="fin-kpi-v">{{ fa_num($kpis['canceled']) }}</b>
      <small>بدهیِ مشتری برگشت خورده</small>
    </div>
  </div>

  {{-- 🔴 کادرِ جست‌وجو بخشی از خواستهٔ اسنپ‌پی است، نه یک راحتیِ اضافه:
       ارتباطِ آن‌ها با ما دربارهٔ هر سفارش با شمارهٔ تراکنش انجام می‌شود. --}}
  <div class="ad-panel" style="margin-bottom:16px">
    <form method="GET" class="ad-toolbar" style="gap:10px;flex-wrap:wrap;padding:14px 16px">
      <input type="search" name="q" value="{{ $q }}" placeholder="شمارهٔ تراکنش، شمارهٔ فاکتور، ایمیل یا کد مشتری"
             class="ad-input" style="flex:1;min-width:260px" dir="auto">
      <select name="state" class="ad-input">
        <option value="all" @selected($state === 'all')>همهٔ وضعیت‌ها</option>
        @foreach($stateLabel as $k => $label)
          <option value="{{ $k }}" @selected($state === $k)>{{ $label }}</option>
        @endforeach
      </select>
      <button class="pnl-btn primary" type="submit">جست‌وجو</button>
      @if($q !== '' || $state !== 'all')
        <a class="pnl-btn" href="/admin/snapppay">پاک‌کردن</a>
      @endif
    </form>
  </div>

  <div class="ad-panel">
    <div class="ad-panel-h"><h2>سفارش‌ها</h2></div>

    @if($orders->isEmpty())
      <p style="padding:18px 16px;color:var(--dim)">
        @if($q !== '') چیزی با «{{ $q }}» پیدا نشد. @else هنوز سفارشِ اقساطی‌ای ثبت نشده است. @endif
      </p>
    @else
      <div style="overflow-x:auto">
        <table class="ad-table">
          <thead>
            <tr>
              <th>شمارهٔ تراکنش</th><th>مشتری</th><th>فاکتور</th>
              <th>مبلغ</th><th>وضعیت</th><th>اسنپ‌پی</th><th>تاریخ</th>
            </tr>
          </thead>
          <tbody>
            @foreach($orders as $o)
              <tr>
                <td><a href="/admin/snapppay/{{ $o->id }}" dir="ltr"><b>{{ $o->transaction_id }}</b></a></td>
                <td>{{ $o->customer?->email ?? '—' }}</td>
                <td>{{ $o->invoice?->number ?? '—' }}</td>
                <td class="num">{{ $t($o->amount) }}</td>
                <td><span class="ad-badge {{ $stateClass[$o->state] ?? 'draft' }}"@isset($stateColor[$o->state]) style="color:{{ $stateColor[$o->state] }}"@endisset>{{ $stateLabel[$o->state] ?? $o->state }}</span></td>
                <td dir="ltr" style="color:var(--dim)">{{ $o->remote_status ?: '—' }}</td>
                <td>{{ sdate($o->created_at) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      <div style="padding:12px 16px">{{ $orders->links() }}</div>
    @endif
  </div>
@endif

@include('admin.partials.finance-styles')
@endsection
