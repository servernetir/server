@extends('panel.layout')
@section('title', 'مدیریت DNS')

@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/dns.css') }}">
@endpush

@section('panel')
<div class="dns">

  <div class="pnl-head">
    <div>
      <h1>مدیریت DNS</h1>
      <p>نیم‌سرورهای دامنه‌تان را روی سرورنت بگذارید و همهٔ رکوردها — A، MX، TXT، CNAME و بقیه — را همین‌جا مدیریت کنید.</p>
    </div>
  </div>

  @if(session('ok'))<div class="dns-note ok">{{ session('ok') }}</div>@endif
  @if($errors->has('domain'))<div class="dns-note danger">{{ $errors->first('domain') }}</div>@endif

  @unless($ready)
    {{-- 🔴 مهاجرتِ پروداکشن دستی است، پس همیشه پنجره‌ای هست که کد بالا رفته و
         جدول هنوز نیست. صفحه باید علت را بگوید، نه ۵۰۰ بدهد. --}}
    <div class="dns-note warn">
      <b>این بخش به‌زودی فعال می‌شود.</b><br>
      راه‌اندازیِ سرویسِ DNS روی سرور هنوز کامل نشده است.
    </div>
  @else

  {{-- ═════════ اثباتِ مالکیت — وقتی دامنه در انتظارِ حسابِ دیگری است ═════════ --}}
  @if($claim)
  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>تأییدِ مالکیتِ {{ $claim['domain'] }}</h2></div>
    <div class="pnl-sec-b">
      @if($errors->has('claim'))<div class="dns-note danger" style="margin-bottom:14px">{{ $errors->first('claim') }}</div>@endif
      <p class="dns-t-dim" style="font-size:13.5px;line-height:2;margin:0 0 12px">
        این دامنه پیش‌تر در سرورنت اضافه شده ولی مالکیتش هنوز تأیید نشده است.
        اگر دامنه مالِ شماست، این رکورد را در <b>DNSِ فعلیِ دامنه</b> (همان جایی که الان رکوردهایش را مدیریت می‌کنید) بسازید و بعد دکمهٔ تأیید را بزنید:
      </p>
      <div class="dns-ns">
        <div class="dns-copy"><code>TXT</code></div>
        <div class="dns-copy"><code>{{ $claim['name'] }}</code>
          <button type="button" data-copy="{{ $claim['name'] }}"><svg class="icon"><use href="#i-copy"/></svg>کپی</button></div>
        <div class="dns-copy"><code>{{ $claim['content'] }}</code>
          <button type="button" data-copy="{{ $claim['content'] }}"><svg class="icon"><use href="#i-copy"/></svg>کپی</button></div>
      </div>
      <form method="POST" action="{{ lroute('account.dns.claim') }}" style="margin-top:14px">
        @csrf
        <input type="hidden" name="domain" value="{{ $claim['domain'] }}">
        <button class="pnl-btn primary" type="submit"><svg class="icon"><use href="#i-check"/></svg>بررسی و تأیید مالکیت</button>
      </form>
    </div>
  </section>
  @endif

  {{-- ═════════ افزودنِ دامنه ═════════ --}}
  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>افزودنِ دامنه</h2></div>
    <div class="pnl-sec-b">
      <form method="POST" action="{{ lroute('account.dns') }}" class="dns-add">
        @csrf
        <input class="dns-input dns-ltr" name="domain" autocomplete="off" spellcheck="false" inputmode="url"
               placeholder="example.com" value="{{ old('domain') }}" required aria-label="نامِ دامنه">
        <button class="pnl-btn primary" type="submit"><svg class="icon"><use href="#i-plus"/></svg>افزودن</button>
      </form>
      <p class="dns-t-dim" style="font-size:12.5px;line-height:1.9;margin:10px 0 0">
        فقط دامنهٔ اصلی را بنویسید (بدونِ www). بعد از افزودن، رکوردهای فعلیِ دامنه را برایتان می‌خوانیم تا با تعویضِ نیم‌سرور چیزی قطع نشود.
      </p>
    </div>
  </section>

  {{-- ═════════ دامنه‌ها ═════════ --}}
  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>دامنه‌های شما</h2>@if($zones->count())<span class="dns-t-dim" style="font-size:12.5px">{{ fa_num($zones->count()) }} دامنه</span>@endif</div>
    <div class="pnl-sec-b">
      @forelse($zones as $z)
        @php
          $pill = match(true) {
            $z->status === 'suspended'                       => ['off',  'معلق'],
            $z->status === 'active' && $z->verify_error      => ['wait', 'نیم‌سرور تغییر کرده'],
            $z->status === 'active'                          => ['on',   'فعال'],
            default                                          => ['wait', 'در انتظارِ تنظیمِ نیم‌سرور'],
          };
        @endphp
        <a class="dns-zone" href="{{ lroute('account.dns.zone', $z->id) }}">
          <span class="dns-zone-ic"><svg class="icon"><use href="#i-globe"/></svg></span>
          <span>
            <span class="dns-zone-d dns-ltr">{{ $z->domain }}</span>
            <span class="dns-zone-m" style="display:block">
              {{ fa_num($z->records_count) }} رکورد
              @if($z->verified_at) · فعال از {{ sdate($z->verified_at) }}@endif
            </span>
          </span>
          <span class="dns-zone-end">
            <span class="dns-pill {{ $pill[0] }}">{{ $pill[1] }}</span>
            <svg class="icon" style="width:16px;height:16px;transform:scaleX(-1)"><use href="#i-chev"/></svg>
          </span>
        </a>
      @empty
        <div class="pnl-empty">
          <svg class="icon"><use href="#i-globe"/></svg>
          <b>هنوز دامنه‌ای اضافه نکرده‌اید</b>
          <p class="dns-t-dim" style="font-size:13px">نامِ دامنه را در کادرِ بالا بنویسید تا شروع کنیم.</p>
        </div>
      @endforelse
    </div>
  </section>

  {{-- ═════════ نیم‌سرورها ═════════ --}}
  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>نیم‌سرورهای سرورنت</h2></div>
    <div class="pnl-sec-b">
      <p class="dns-t-dim" style="font-size:13px;line-height:1.9;margin:0 0 12px">
        این نیم‌سرورها را در پنلِ ثبت‌کنندهٔ دامنه‌تان به‌جای نیم‌سرورهای فعلی وارد کنید.
        <b>همهٔ</b> نیم‌سرورهای قبلی باید حذف شوند؛ نیم‌سرورِ قدیمیِ باقی‌مانده باعث می‌شود دامنه یک‌درمیان جواب بدهد.
      </p>
      <div class="dns-ns">
        @foreach($nameservers as $ns)
          <div class="dns-copy"><code>{{ $ns }}</code>
            <button type="button" data-copy="{{ $ns }}"><svg class="icon"><use href="#i-copy"/></svg>کپی</button></div>
        @endforeach
      </div>
    </div>
  </section>

  @endunless
</div>

<script src="{{ asset_ver('assets/js/dns.js') }}" defer></script>
@endsection
