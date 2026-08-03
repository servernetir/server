@extends('panel.layout')
@section('title', 'دامنه‌ها')

@section('panel')

<div class="pnl-head">
  <div>
    <h1>دامنه‌های من</h1>
    <p>ثبت، تمدید، تغییر نام‌سرور و انتقال — همه از همین‌جا.</p>
  </div>
  <a class="pnl-btn" href="{{ lroute('domain.search') }}">
    <svg class="icon"><use href="#i-search"/></svg>ثبت دامنهٔ جدید
  </a>
</div>

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
