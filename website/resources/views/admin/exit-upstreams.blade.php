@extends('admin.layout')
@section('title', 'رله و نودهای اکسیت')
@section('nav_exit_upstreams', 'on')
@section('content')

{{-- ── نوارِ ابزار ── --}}
<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
  <a href="{{ route('admin.exit-infra') }}" class="ad-badge"
     style="background:rgba(148,163,184,.14);color:var(--muted);padding:8px 14px;text-decoration:none">
    <svg class="icon" style="width:14px;height:14px"><use href="#i-flow"/></svg>
    زیرساختِ اکسیت (VMها)
  </a>
  <a href="{{ route('admin.exit-upstreams.create') }}" class="ad-badge"
     style="background:rgba(34,211,238,.18);color:var(--text);padding:8px 14px;text-decoration:none">
    + افزودنِ رله / نود
  </a>
</div>

{{-- ── سلامت: ضربانِ ایجنت + توکن ── --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h2>رله و نودهای اکسیت</h2></div>
  <p style="padding:0 18px 14px;color:var(--muted);font-size:13px;line-height:1.95">
    این‌جا آپ‌استریم‌هایی را می‌سازی که موتورِ اکسیتِ ایران از راهشان از کشور خارج
    می‌شود: <b>رله</b> (آپ‌لینکِ SSH برای عبور از فیلترینگ، مستقلِ کشور) و
    <b>اکسیتِ کشوری</b> (سرورِ اختصاصیِ خودت — SSH یا VLESS — که خروجِ یک کشور را
    تضمین می‌کند). پنل فقط «حالتِ مطلوب» را می‌نویسد؛ میزبانِ ایران آن را می‌کشد و
    اعمال می‌کند. پس تغییر چند دقیقه (تا پیمایشِ بعدیِ ایجنت) طول می‌کشد.
  </p>

  <div style="display:flex;flex-wrap:wrap;gap:10px;padding:0 18px 18px">
    @php $col = $agent['stale'] ? '#ff6b6b' : '#34d399'; @endphp
    <span class="ad-badge" style="background:{{ $col }}22;color:{{ $col }};font-size:12.5px;padding:7px 12px;display:inline-flex;align-items:center;gap:6px">
      <svg class="icon" style="width:14px;height:14px"><use href="#i-{{ $agent['stale'] ? 'x' : 'check' }}"/></svg>
      ایجنتِ آپ‌استریم —
      @if($agent['seen'] === null)
        هرگز دیده نشده
      @else
        {{ fa_num($agent['minutes']) }} دقیقه پیش
      @endif
    </span>
    @php $tcol = $tokenSet ? '#34d399' : '#fbbf24'; @endphp
    <span class="ad-badge" style="background:{{ $tcol }}22;color:{{ $tcol }};font-size:12.5px;padding:7px 12px">
      توکنِ pull-agent: {{ $tokenSet ? 'تنظیم‌شده' : 'تنظیم‌نشده' }}
    </span>
    <span class="ad-badge" dir="ltr" style="background:rgba(148,163,184,.14);color:var(--muted);font-size:12.5px;padding:7px 12px">
      exit countries: {{ $exitCountries }}
    </span>
  </div>
</div>

{{-- ── رله‌ها (آپ‌لینک) ── --}}
<div class="ad-panel" style="margin-top:16px">
  <div class="ad-panel-h" style="display:flex;justify-content:space-between;align-items:center">
    <h2>رله‌ها (آپ‌لینک) — {{ fa_num($relayCount) }}</h2>
    @if($relayCount > 0 && $relayActive < 2)
      <span class="ad-badge" style="background:rgba(251,191,36,.16);color:#fbbf24;font-size:12px">
        برای چرخشِ خودکار ≥۲ رله‌ی فعال لازم است
      </span>
    @endif
  </div>

  @if($relays->isEmpty())
    <p style="padding:16px 18px;color:var(--dim);font-size:13.5px">
      هنوز رله‌ای نیست. رله همان آپ‌لینکی است که همه‌ی نودها از داخلِ آن dial می‌شوند
      تا زیرِ DPI کار کنند. دستِ‌کم یکی لازم است.
    </p>
  @else
    <div style="padding:0 4px 8px;overflow-x:auto">
      <table class="ad-table">
        <thead><tr>
          <th>نام</th><th>نوع</th><th>مقصد</th><th>کاربر</th>
          <th>اولویت</th><th>سلامت</th><th>وضعیت</th><th></th>
        </tr></thead>
        <tbody>
          @foreach($relays as $u)
            @include('admin.partials.exit-upstream-row', ['u' => $u, 'showCountry' => false])
          @endforeach
        </tbody>
      </table>
    </div>
  @endif
</div>

{{-- ── اکسیت‌های اختصاصیِ کشوری ── --}}
<div class="ad-panel" style="margin-top:16px">
  <div class="ad-panel-h"><h2>اکسیت‌های اختصاصیِ کشوری — {{ fa_num($exits->count()) }}</h2></div>

  {{-- خلاصه‌ی هر کشور: چند اکسیتِ اختصاصیِ فعال دارد --}}
  <div style="display:flex;flex-wrap:wrap;gap:10px;padding:0 18px 14px">
    @foreach($countries as $c)
      @php $ok = $c['active'] > 0; $bcol = $ok ? '#34d399' : '#fbbf24'; @endphp
      <span class="ad-badge" style="background:{{ $bcol }}1f;color:var(--text);font-size:12.5px;padding:7px 12px" dir="rtl"
            title="{{ $ok ? 'اکسیتِ اختصاصی دارد' : 'فقط استخرِ رایگان — اکسیتِ اختصاصی ندارد' }}">
        {{ $c['flag'] }} {{ $c['name'] }} — {{ fa_num($c['active']) }} فعال
        @if(! $ok)<span style="color:#fbbf24">•</span>@endif
      </span>
    @endforeach
  </div>

  @if($exits->isEmpty())
    <p style="padding:0 18px 16px;color:var(--dim);font-size:13.5px">
      هنوز اکسیتِ اختصاصی‌ای نیست. کشورهای بالا فعلاً فقط به استخرِ رایگان تکیه دارند
      (DE/NL/FI پایدار). برای تضمین/پایداری، سرورِ خودت را به‌عنوانِ اکسیتِ یک کشور
      اضافه کن.
    </p>
  @else
    <div style="padding:0 4px 8px;overflow-x:auto">
      <table class="ad-table">
        <thead><tr>
          <th>نام</th><th>کشور</th><th>نوع</th><th>مقصد</th>
          <th>اولویت</th><th>سلامت</th><th>وضعیت</th><th></th>
        </tr></thead>
        <tbody>
          @foreach($exits as $u)
            @include('admin.partials.exit-upstream-row', ['u' => $u, 'showCountry' => true])
          @endforeach
        </tbody>
      </table>
    </div>
  @endif
</div>

{{-- تأییدِ حذف را دیالوگِ برنددارِ سراسری (partials/ui-dialog) از روی
     data-confirm خودکار می‌گیرد — نه جعبه‌ی خامِ مرورگر. --}}
@endsection
