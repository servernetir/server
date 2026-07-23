@extends('panel.layout')
@section('title', 'پروفایل و احراز هویت — سرورنت')

@section('panel')

<div class="pnl-head">
  <div>
    <h1>پروفایل و احراز هویت</h1>
    <p>اطلاعاتی که روی فاکتور و قراردادها می‌نشیند</p>
  </div>
</div>

{{-- ==== هویت ==== --}}
<section class="pnl-sec" style="border-color:{{ $identity?->status === 'verified' ? 'var(--ok-line)' : 'var(--warn-line)' }}">
  <div class="pnl-sec-h" style="background:{{ $identity?->status === 'verified' ? 'var(--ok-bg)' : 'var(--warn-bg)' }}">
    <h2 style="color:{{ $identity?->status === 'verified' ? 'var(--ok)' : 'var(--warn)' }}">هویت</h2>
    @if($identity?->status === 'verified')
      <span class="pnl-pill ok">تأیید شده</span>
    @else
      <span class="pnl-pill warn">تأیید نشده</span>
    @endif
  </div>
  <div class="pnl-sec-b">
    @if($identity?->status === 'verified')
      <div class="pnl-tw">
        <table class="pnl-table"><tbody>
          <tr><td style="color:var(--muted)">نام و نام خانوادگی</td><td><b>{{ trim($identity->first_name.' '.$identity->last_name) }}</b></td></tr>
          @if($identity->father_name)
            <tr><td style="color:var(--muted)">نام پدر</td><td>{{ $identity->father_name }}</td></tr>
          @endif
          <tr><td style="color:var(--muted)">موبایل</td><td dir="ltr">{{ $identity->mobile }}</td></tr>
          <tr><td style="color:var(--muted)">شاهکار</td><td><span class="pnl-pill ok">تطابق کد ملی و موبایل</span></td></tr>
        </tbody></table>
      </div>
      <p style="margin-top:14px;font-size:12.5px;color:var(--muted);line-height:2">
        نام شما از ثبت احوال خوانده شده است.
        @if($nameLocked)
          چون حساب بانکی تأییدشده دارید، تغییر آن ممکن نیست — حساب بانکی به همین
          نام تأیید شده و تغییرش آن تطابق را می‌شکند.
        @endif
      </p>
    @else
      <p style="font-size:13.5px;color:var(--muted);line-height:2;margin:0 0 14px">
        برای سفارش سرویس، هویت شما باید تأیید شود. این کار با استعلام رسمی انجام
        می‌شود و نیازی به آپلود مدرک ندارد.
      </p>
      <a class="pnl-btn primary" href="{{ lroute('register') }}">
        <svg class="icon"><use href="#i-user"/></svg>شروع احراز هویت
      </a>
    @endif
  </div>
</section>

{{-- ==== حساب ==== --}}
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>حساب کاربری</h2></div>
  <div class="pnl-sec-b flush">
    <div class="pnl-tw">
      <table class="pnl-table"><tbody>
        <tr><td style="color:var(--muted)">شناسهٔ مشتری</td><td dir="ltr"><b>{{ $customer->code }}</b></td></tr>
        <tr><td style="color:var(--muted)">ایمیل</td><td dir="ltr">{{ $customer->email }}</td></tr>
        <tr><td style="color:var(--muted)">موبایل</td><td dir="ltr">{{ $customer->phone ?: '—' }}</td></tr>
        <tr><td style="color:var(--muted)">نوع حساب</td>
            <td>{{ ($profile?->type ?? 'individual') === 'company' ? 'شخص حقوقی' : 'شخص حقیقی' }}</td></tr>
        <tr><td style="color:var(--muted)">عضویت از</td>
            <td>{{ fa_num($customer->created_at?->format('Y/m/d') ?? '—') }}</td></tr>
      </tbody></table>
    </div>
  </div>
</section>

@endsection
