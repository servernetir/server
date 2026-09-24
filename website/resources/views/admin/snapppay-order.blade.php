@extends('admin.layout')
@section('title', 'سفارش اسنپ‌پی '.$order->transaction_id)
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
  $kindLabel = [
      'token' => 'دریافت توکن', 'verify' => 'تأیید', 'settle' => 'نهایی‌سازی',
      'status' => 'استعلام وضعیت', 'update' => 'کاهش سفارش', 'cancel' => 'لغو',
  ];
@endphp

@if(session('ok'))<div class="ad-note" style="border-color:#34d399;color:#34d399">{{ session('ok') }}</div>@endif
@if(session('err'))<div class="ad-note" style="border-color:#ff6b6b;color:#ff6b6b">{{ session('err') }}</div>@endif
@if($errors->any())<div class="ad-note" style="border-color:#ff6b6b;color:#ff6b6b">{{ $errors->first() }}</div>@endif

<div class="ad-toolbar"><a class="pnl-btn" href="/admin/snapppay">← همهٔ سفارش‌ها</a></div>

<div class="ad-panel">
  <div class="ad-panel-h"><h2>سفارش {{ $order->transaction_id }}</h2></div>
  <table class="fin-pl">
    <tr><td>شمارهٔ تراکنش</td><td class="fin-num" dir="ltr"><b>{{ $order->transaction_id }}</b></td>
        <td class="fin-src">همین شماره را اسنپ‌پی می‌شناسد</td></tr>
    <tr><td>وضعیت نزد ما</td><td class="fin-num">{{ $stateLabel[$order->state] ?? $order->state }}</td><td></td></tr>
    <tr><td>وضعیت نزد اسنپ‌پی</td><td class="fin-num" dir="ltr">{{ $order->remote_status ?: '—' }}</td>
        <td class="fin-src">{{ $order->checked_at ? 'آخرین استعلام '.sdate($order->checked_at) : 'استعلام نشده' }}</td></tr>
    <tr><td>مبلغ</td><td class="fin-num">{{ $t($order->amount) }}</td><td></td></tr>
    <tr><td>مشتری</td><td class="fin-num">{{ $order->customer?->email ?? '—' }}</td>
        <td class="fin-src">{{ $order->mobile }}</td></tr>
    <tr><td>فاکتور</td>
        <td class="fin-num">@if($order->invoice)<a href="/admin/customers/{{ $order->customer_id }}">{{ $order->invoice->number }}</a>@else — @endif</td>
        <td class="fin-src">{{ $order->invoice?->status }}</td></tr>
    @if($order->error)
      <tr><td>خطا</td><td class="fin-num" style="color:#ff6b6b">{{ $order->error }}</td><td></td></tr>
    @endif
  </table>

  <div style="padding:12px 16px">
    <form method="POST" action="/admin/snapppay/{{ $order->id }}/status" style="display:inline">
      @csrf<button class="pnl-btn" type="submit">استعلام وضعیت از اسنپ‌پی</button>
    </form>
    <span style="color:var(--dim);font-size:12px">— بی‌خطر است و چیزی را تغییر نمی‌دهد.</span>
  </div>
</div>

@if($order->isChangeable())
  {{--
    🔴 هر دو عملِ زیر برگشت‌ناپذیرند. مستنداتِ اسنپ‌پی:
    «به دلیل غیر قابل برگشت بودن آپدیت و کنسل قبل از اعمال و ارسال سمت اسنپ پی
     از ادمین در پاپ آپ تاییدیه مجدد گرفته شود.»

    پس دو لایه محافظ هست: `confirm()` مرورگر، و یک عبارتِ تایپی که کنترلر
    اعتبارسنجی می‌کند. کلیکِ اشتباه روی دکمه‌ای که بدهیِ مشتری را برمی‌گرداند
    جبران‌پذیر نیست.
  --}}

  <div class="ad-panel" style="margin-top:16px">
    <div class="ad-panel-h"><h2>کاهشِ سفارش</h2></div>
    <p style="padding:0 16px;color:var(--dim);line-height:1.9;font-size:13px">
      تعدادِ هر ردیف را می‌توانید کم کنید؛ صفر یعنی حذفِ کاملِ آن ردیف.
      مبلغِ تازه باید <b>کمتر</b> از مبلغ فعلی باشد — اسنپ‌پی افزایش را نمی‌پذیرد.
      مابه‌التفاوت به حسابِ اعتباریِ مشتری نزدِ اسنپ‌پی برمی‌گردد.
    </p>

    <form method="POST" action="/admin/snapppay/{{ $order->id }}/update"
          onsubmit="return confirm('کاهشِ سفارش برگشت‌ناپذیر است و بدهیِ مشتری نزدِ اسنپ‌پی کم می‌شود. مطمئنید؟')">
      @csrf
      <table class="ad-table">
        <thead><tr><th>شرح</th><th>قیمت واحد</th><th>تعداد فعلی</th><th>تعداد تازه</th></tr></thead>
        <tbody>
          @foreach($order->invoice?->items ?? [] as $item)
            <tr>
              <td>{{ $item->title }}</td>
              <td class="num">{{ $t($item->unit_price) }}</td>
              <td class="num">{{ fa_num($item->quantity) }}</td>
              <td><input type="number" class="ad-input" style="width:90px"
                         name="qty[{{ $item->id }}]" value="{{ $item->quantity }}"
                         min="0" max="{{ $item->quantity }}"></td>
            </tr>
          @endforeach
        </tbody>
      </table>

      <div style="padding:14px 16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <input type="text" name="confirm" class="ad-input" placeholder="برای تأیید، UPDATE را بنویسید"
               dir="ltr" style="min-width:240px" autocomplete="off">
        <button class="pnl-btn primary" type="submit">اعمالِ کاهش</button>
      </div>
    </form>
  </div>

  <div class="ad-panel" style="margin-top:16px;border-color:#ff6b6b">
    <div class="ad-panel-h"><h2 style="color:#ff6b6b">لغوِ کاملِ سفارش</h2></div>
    <p style="padding:0 16px;color:var(--dim);line-height:1.9;font-size:13px">
      کلِ بدهیِ مشتری نزدِ اسنپ‌پی برگشت می‌خورد و فاکتور «بازگشتی» می‌شود.
      <b style="color:#fbbf24">⚠️ این کار سرویسِ مشتری را خودش غیرفعال نمی‌کند</b> —
      اگر سرویسی تحویل شده، جداگانه تعیین تکلیفش کنید.
    </p>

    <form method="POST" action="/admin/snapppay/{{ $order->id }}/cancel"
          onsubmit="return confirm('لغوِ سفارش برگشت‌ناپذیر است. مطمئنید؟')"
          style="padding:14px 16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      @csrf
      <input type="text" name="confirm" class="ad-input" placeholder="برای لغو، CANCEL را بنویسید"
             dir="ltr" style="min-width:240px" autocomplete="off">
      <button class="pnl-btn" type="submit" style="border-color:#ff6b6b;color:#ff6b6b">لغوِ سفارش</button>
    </form>
  </div>
@else
  <div class="ad-note" style="margin-top:16px">
    کاهش و لغو فقط روی سفارشِ <b>نهایی‌شده</b> ممکن است. وضعیتِ فعلی:
    {{ $stateLabel[$order->state] ?? $order->state }}.
  </div>
@endif

<div class="ad-panel" style="margin-top:16px">
  <div class="ad-panel-h"><h2>ردِ حسابرسی</h2></div>
  @if($order->events->isEmpty())
    <p style="padding:18px 16px;color:var(--dim)">هنوز رویدادی ثبت نشده.</p>
  @else
    <table class="ad-table">
      <thead><tr><th>رویداد</th><th>نتیجه</th><th>پیام</th><th>توسط</th><th>زمان</th></tr></thead>
      <tbody>
        @foreach($order->events->sortByDesc('id') as $e)
          <tr>
            <td>{{ $kindLabel[$e->kind] ?? $e->kind }}</td>
            <td style="color:{{ $e->ok ? '#34d399' : '#ff6b6b' }}">{{ $e->ok ? 'موفق' : 'ناموفق' }}</td>
            <td style="color:var(--dim)">{{ $e->message ?: '—' }}</td>
            {{-- خالی = سیستم؛ پرشده = ادمینی که دکمه را زد --}}
            <td>{{ $e->created_by ? ($e->admin?->name ?? '#'.$e->created_by) : 'سیستم' }}</td>
            <td>{{ sdate($e->created_at) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>

@include('admin.partials.finance-styles')
@endsection
