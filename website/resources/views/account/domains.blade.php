@extends('panel.layout')
@section('title', 'دامنه‌ها')

@section('panel')

<div class="pnl-head">
  <div>
    <h1>دامنه‌های من</h1>
    <p>ثبت، تمدید، تغییر نام‌سرور و انتقال — همه از همین‌جا.</p>
  </div>
</div>

@if(session('ok'))<div class="dm-note ok">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="dm-note danger">{{ $errors->first() }}</div>@endif

{{-- ثبتِ دامنهٔ تازه — همین‌جا، نه در صفحهٔ دیگر.
     استعلام سمتِ سرور گرفته می‌شود تا قیمتی که مشتری روی دکمه می‌بیند همانی
     باشد که پرداخت می‌کند (استعلام ۱۵ دقیقه اعتبار دارد). --}}
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>ثبت دامنهٔ جدید</h2></div>
  <div class="pnl-sec-b">
    <form method="get" action="{{ route('account.domains') }}" class="dm-search">
      <input name="register" dir="ltr" autocomplete="off" value="{{ $query }}"
             placeholder="example.com یا فقط example">
      <button class="pnl-btn" type="submit">
        <svg class="icon"><use href="#i-search"/></svg>جستجو
      </button>
    </form>

    @if($query !== '')
      @forelse($results as $r)
        <div class="dm-res {{ $r['available'] ? ($r['orderable'] ? 'ok' : 'no') : 'no' }}">
          <span class="dm-res-n" dir="ltr">{{ $r['domain'] }}</span>

          @if(! $r['available'])
            <span class="pnl-pill mute">ثبت‌شده</span>
          @elseif(! $r['orderable'])
            <span class="pnl-pill warn">فعلاً قابل سفارش نیست</span>
          @else
            <span class="pnl-pill ok">آزاد</span>
            <span class="dm-res-p">{{ cloud_price($r['price_toman']) }} <small>/ سال</small></span>
            <form method="post" action="{{ route('account.domains.order') }}">
              @csrf
              <input type="hidden" name="quote_id" value="{{ $r['quote_id'] }}">
              <input type="hidden" name="years" value="1">
              <button class="pnl-btn" type="submit">ثبت این دامنه</button>
            </form>
          @endif
        </div>
      @empty
        <p class="dm-note">نتیجه‌ای پیدا نشد. املای دامنه را بررسی کنید.</p>
      @endforelse
    @endif
  </div>
</section>

@if($domains->isEmpty())
  <section class="pnl-sec">
    <div class="pnl-sec-b">
      <div class="pnl-empty">
        <p><b>هنوز دامنه‌ای ثبت نکرده‌اید</b></p>
        <p>نام دلخواهتان را جستجو کنید؛ اگر آزاد بود، همان‌جا قیمت و دکمهٔ ثبت را می‌بینید.</p>
        <p><a class="pnl-btn" href="{{ lroute('domain.search') }}">جستجوی دامنه</a></p>
      </div>
    </div>
  </section>
@else
  <section class="pnl-sec">
    <div class="pnl-sec-b">
      <div class="pnl-tw">
        <table class="pnl-table">
          <thead>
            <tr>
              <th>دامنه</th>
              <th>وضعیت</th>
              <th>انقضا</th>
              <th>تمدید خودکار</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            @foreach($domains as $d)
            @php
              $left = $d->daysLeft();
              // ⚠️ «نزدیک به انقضا» فقط برای دامنهٔ **فعال** معنا دارد؛ دامنهٔ
              // در انتظارِ ثبت تاریخ انقضا ندارد و نباید هشدار بگیرد.
              $warn = $d->isActive() && $left !== null && $left <= 30;
            @endphp
            <tr>
              <td><b dir="ltr">{{ $d->domain }}</b></td>
              <td>
                @if($d->isActive())
                  <span class="pnl-pill ok">فعال</span>
                @elseif($d->provision_status === 'manual')
                  <span class="pnl-pill danger">بررسی دستی</span>
                @elseif($d->isPending())
                  <span class="pnl-pill info">در انتظار ثبت</span>
                @else
                  <span class="pnl-pill mute">{{ $d->status }}</span>
                @endif
              </td>
              <td>
                @if($d->expires_at)
                  {{ sdate($d->expires_at) }}
                  @if($warn)<br><span class="pnl-pill warn">{{ fa_num($left) }} روز مانده</span>@endif
                @else
                  —
                @endif
              </td>
              <td>{{ $d->auto_renew ? 'روشن' : 'خاموش' }}</td>
              <td><a class="pnl-btn" href="{{ route('account.domain', $d) }}">مدیریت</a></td>
            </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  </section>
@endif

@endsection
