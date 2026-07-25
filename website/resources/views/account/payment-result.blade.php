{{--
  صفحهٔ بازگشت از درگاه.

  عمداً از پوستهٔ سایت ارث می‌برد و نه از پوستهٔ پنل: کاربر ممکن است با نشستِ
  منقضی یا در مرورگر دیگری برگردد، و پنل بدون کاربر واردشده رندر نمی‌شود.

  شمارهٔ پیگیری بزرگ و قابل انتخاب است — تنها چیزی که مشتری با آن می‌تواند
  پرداختش را پیگیری کند، و اولین چیزی که پشتیبانی از او می‌پرسد.
--}}
@extends('layouts.site')
@section('title', $ok ? 'پرداخت موفق — سرورنت' : 'نتیجهٔ پرداخت — سرورنت')

@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/auth.css') }}">
<meta name="robots" content="noindex,nofollow">
@endpush

@section('content')
<section class="auth-wrap">
  <div class="container" style="max-width:620px">

    <div class="auth-title">
      <h1>
        @if($ok) پرداخت انجام شد
        @elseif($canceled ?? false) پرداخت لغو شد
        @else پرداخت ناموفق بود @endif
      </h1>
      <p>{{ $message }}</p>
    </div>

    <div class="auth-shell" style="grid-template-columns:1fr">
      <div class="auth-main">

        @if($payment)
          <div class="pay-rows">
            @if($payment->ref_id)
              <div class="pay-row hero">
                <span>شمارهٔ پیگیری</span>
                <b dir="ltr">{{ $payment->ref_id }}</b>
              </div>
            @endif

            <div class="pay-row">
              <span>مبلغ</span>
              <b>{{ fa_num(number_format($payment->amount)) }} تومان</b>
            </div>

            @if($payment->invoice)
              <div class="pay-row">
                <span>فاکتور</span>
                <b dir="ltr">{{ $payment->invoice->number }}</b>
              </div>
            @endif

            @if($payment->card_mask)
              <div class="pay-row">
                <span>کارت</span>
                <b dir="ltr">{{ $payment->card_mask }}</b>
              </div>
            @endif

            <div class="pay-row">
              <span>زمان</span>
              <b>{{ stime($payment->paid_at ?? $payment->updated_at) }}</b>
            </div>
          </div>

          @if($ok)
            <p class="pay-note ok">
              شمارهٔ پیگیری را نگه دارید. اگر لازم شد پرداخت را پیگیری کنید،
              همین شماره کافی است.
            </p>
          @else
            <p class="pay-note">
              اگر مبلغی از حساب شما کم شده، طبق قوانین بانکی حداکثر تا ۷۲ ساعت
              به‌صورت خودکار برمی‌گردد. اگر برنگشت، شمارهٔ پیگیری بالا را برای
              پشتیبانی بفرستید.
            </p>
          @endif
        @endif

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:24px">
          @auth('customer')
            <a class="auth-btn" style="width:auto;flex:1;min-width:180px"
               href="{{ $payment?->invoice ? lroute('account.invoice', $payment->invoice) : lroute('account.invoices') }}">
              مشاهدهٔ فاکتور
            </a>
            <a class="auth-ghost" style="align-self:center" href="{{ lroute('account.home') }}">پنل کاربری</a>
          @else
            <a class="auth-btn" style="width:auto;flex:1;min-width:180px" href="{{ lroute('login') }}">
              ورود به پنل کاربری
            </a>
          @endauth
        </div>

      </div>
    </div>
  </div>
</section>

<style>
.pay-rows{ display:flex; flex-direction:column; gap:1px; background:var(--line);
           border:1px solid var(--line); border-radius:14px; overflow:hidden }
.pay-row{ display:flex; align-items:center; justify-content:space-between; gap:14px;
          padding:14px 16px; background:var(--surface); font-size:13.5px }
.pay-row span{ color:var(--muted) }
.pay-row b{ font-variant-numeric:tabular-nums; user-select:all }
.pay-row.hero{ padding:18px 16px }
.pay-row.hero b{ font-family:var(--font-disp); font-size:22px; letter-spacing:.02em }
.pay-note{ margin:18px 0 0; font-size:12.5px; color:var(--muted); line-height:2 }
.pay-note.ok{ color:var(--ok) }
</style>
@endsection
