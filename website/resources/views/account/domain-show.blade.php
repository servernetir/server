@extends('panel.layout')
@section('title', $domain->domain)

@section('panel')

<div class="pnl-head">
  <div>
    <nav class="blog-crumbs" style="margin-bottom:8px">
      <a href="{{ route('account.home') }}">پنل</a><span>/</span>
      <a href="{{ route('account.domains') }}">دامنه‌ها</a><span>/</span>
      <span dir="ltr">{{ $domain->domain }}</span>
    </nav>
    <h1 dir="ltr">{{ $domain->domain }}</h1>
  </div>
  @if($domain->isActive())
    <span class="pnl-pill ok">فعال</span>
  @elseif($domain->provision_status === 'manual')
    <span class="pnl-pill danger">بررسی دستی</span>
  @else
    <span class="pnl-pill info">در انتظار ثبت</span>
  @endif
</div>

@if(session('ok'))<div class="dm-note ok">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="dm-note danger">{{ $errors->first() }}</div>@endif

{{-- 🔴 کدِ انتقال فقط **یک بار** و فقط از session نشان داده می‌شود: ذخیره‌اش
     نمی‌کنیم چون کلیدِ مالکیت است و هرکس داشته باشد دامنه را می‌برد. --}}
@if(session('authCode'))
  <div class="dm-note warn">
    <b>کد انتقال (EPP):</b>
    <code dir="ltr" style="user-select:all;font-size:14px">{{ session('authCode') }}</code>
    <br><small>این کد کلید مالکیت دامنه است. فقط به رجیستراری بدهید که می‌خواهید دامنه به آن منتقل شود. با تازه‌کردن صفحه ناپدید می‌شود.</small>
  </div>
@endif

@if(! $domain->isActive())
  <section class="pnl-sec">
    <div class="pnl-sec-b">
      @if($domain->provision_status === 'manual')
        <p>ثبت این دامنه نیاز به بررسی دستی دارد و همکاران ما در حال پیگیری‌اند. به‌محض ثبت به شما اطلاع می‌دهیم.</p>
      @else
        <p>این دامنه در صف ثبت است. پس از تأیید پرداخت، ثبت به‌صورت خودکار انجام می‌شود و معمولاً چند دقیقه طول می‌کشد.</p>
      @endif
    </div>
  </section>
@endif

<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>مشخصات</h2></div>
  <div class="pnl-sec-b">
    <div class="pnl-tw">
      <table class="pnl-table">
        <tbody>
          <tr><th>تاریخ ثبت</th><td>{{ $domain->registered_at ? sdate($domain->registered_at) : '—' }}</td></tr>
          <tr><th>تاریخ انقضا</th><td>{{ $domain->expires_at ? sdate($domain->expires_at) : '—' }}</td></tr>
          <tr><th>دورهٔ ثبت</th><td>{{ fa_num($domain->period_years) }} سال</td></tr>
          <tr><th>هزینهٔ تمدید</th><td>{{ cloud_price($domain->renew_toman) }} / سال</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</section>

@if($domain->isActive())
<section class="pnl-sec">
  <div class="pnl-sec-h">
    <h2>نام‌سرورها</h2>
  </div>
  <div class="pnl-sec-b">
    <p style="font-size:13px;color:var(--dim);line-height:2;margin-top:0">
      نام‌سرور تعیین می‌کند دامنه به کدام هاست اشاره کند. اگر هاست را از ما گرفته‌اید، مقدار پیش‌فرض درست است.
      تغییرات تا ۲۴ ساعت طول می‌کشد تا در همهٔ دنیا منتشر شود.
    </p>
    <form method="post" action="{{ route('account.domain.ns', $domain) }}">
      @csrf
      @foreach(range(0, 3) as $i)
        <p class="dm-ns-row">
          <label for="ns{{ $i }}" style="display:block;font-size:12.5px;margin-bottom:4px">
            نام‌سرور {{ fa_num($i + 1) }}@if($i < 2) <span style="color:var(--danger)">*</span>@endif
          </label>
          <input id="ns{{ $i }}" name="ns[]" dir="ltr" autocomplete="off"
                 value="{{ $domain->effectiveNameServers()[$i] ?? '' }}"
                 placeholder="{{ $defaultNs[$i] ?? 'ns.example.com' }}">
        </p>
      @endforeach
      <button class="pnl-btn" type="submit">ذخیرهٔ نام‌سرورها</button>
    </form>
  </div>
</section>

<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>امنیت و انتقال</h2></div>
  <div class="pnl-sec-b">
    <p style="font-size:13px;color:var(--dim);line-height:2;margin-top:0">
      وقتی قفل انتقال روشن است، هیچ‌کس نمی‌تواند دامنه را از سرورنت خارج کند — حتی با داشتن کد انتقال.
      برای انتقال دامنه به جای دیگر، اول قفل را خاموش و سپس کد انتقال را دریافت کنید.
    </p>

    <form method="post" action="{{ route('account.domain.lock', $domain) }}" style="display:inline">
      @csrf
      <input type="hidden" name="lock" value="{{ $domain->is_locked ? 0 : 1 }}">
      <button class="pnl-btn" type="submit">
        {{ $domain->is_locked ? 'خاموش‌کردن قفل انتقال' : 'روشن‌کردن قفل انتقال' }}
      </button>
    </form>

    @unless($domain->is_locked)
      <form method="post" action="{{ route('account.domain.authcode', $domain) }}" style="display:inline">
        @csrf
        <button class="pnl-btn" type="submit">دریافت کد انتقال</button>
      </form>
    @endunless

    <form method="post" action="{{ route('account.domain.autorenew', $domain) }}" style="display:inline">
      @csrf
      <input type="hidden" name="auto_renew" value="{{ $domain->auto_renew ? 0 : 1 }}">
      <button class="pnl-btn" type="submit">
        {{ $domain->auto_renew ? 'خاموش‌کردن تمدید خودکار' : 'روشن‌کردن تمدید خودکار' }}
      </button>
    </form>

    <p style="font-size:12px;color:var(--dim);margin-bottom:0;line-height:2">
      وضعیت فعلی — قفل انتقال: <b>{{ $domain->is_locked ? 'روشن' : 'خاموش' }}</b> ·
      تمدید خودکار: <b>{{ $domain->auto_renew ? 'روشن' : 'خاموش' }}</b>
    </p>
  </div>
</section>
@endif

@endsection
