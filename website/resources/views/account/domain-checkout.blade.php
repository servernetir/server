@extends('panel.layout')
@section('title', __('ui.dch_title').' — '.$quote->domain)

@section('panel')

{{--
  ═══════════════ تسویهٔ دامنه ═══════════════

  🔴 چرا این صفحه هست: تا امروز انتخابِ دامنه دوباره به
  `/account/domains?register=…` برمی‌گشت — یعنی کاربر همان صفحهٔ جستجو را
  می‌دید و باید دوباره دنبالِ ردیفِ خودش می‌گشت. حالا مستقیم به این‌جا می‌آید:
  نام‌سرور، مشخصاتِ مالک، و بعد پرداخت.

  🔴 و مهم‌تر: این تنها لحظه‌ای است که کاربر **حاضر است**. تا امروز نشانی و
  تلفنش هرگز پرسیده نمی‌شد و ثبتِ خودکار ساعت‌ها بعد بی‌سروصدا به‌خاطرِ
  نبودنش شکست می‌خورد — با پولِ گرفته‌شده. (`zhina.shop`، مرداد ۱۴۰۵)

  ⚠️ همهٔ لینک‌ها و اکشن‌ها با `lroute()`، نه `route()`: روت‌های account داخلِ
  closureِ `$site` هستند و `/en/account/…` و `/tr/account/…` هم وجود دارند.
--}}

<div class="pnl-head">
  <div>
    <nav class="blog-crumbs" style="margin-bottom:8px">
      {{-- ⚠️ همان برچسب‌های `domain-show.blade.php` — عمداً سخت‌کد، چون آن صفحه
           هم همین است و کلیدِ `ui.*`ی برایشان وجود ندارد. اگر روزی کلید ساخته
           شد، هر دو صفحه با هم عوض شوند. --}}
      <a href="{{ lroute('account.home') }}">پنل</a><span>/</span>
      <a href="{{ lroute('account.domains') }}">دامنه‌ها</a><span>/</span>
      <span dir="ltr">{{ $quote->domain }}</span>
    </nav>
    <h1>{{ __('ui.dch_title') }}</h1>
    <p class="pnl-sub">{{ __('ui.dch_lead') }}</p>
  </div>
</div>

@if($errors->any())<div class="dm-note danger">{{ $errors->first() }}</div>@endif

<form method="POST" action="{{ lroute('account.domains.order') }}" class="pnl-card" style="padding:22px">
  @csrf
  <input type="hidden" name="quote_id" value="{{ $quote->id }}">

  {{-- ── خلاصهٔ سفارش ── --}}
  <div class="dch-sum">
    <div>
      <span class="dch-lbl">{{ __('ui.dch_domain') }}</span>
      <b dir="ltr" class="dch-dom">{{ $quote->domain }}</b>
    </div>
    <div>
      <span class="dch-lbl">{{ __('ui.dch_years') }}</span>
      <select name="years" class="pnl-input" style="max-width:130px">
        @for($y = 1; $y <= 5; $y++)
          <option value="{{ $y }}" @selected($years === $y)>{{ fa_num($y) }} {{ __('ui.dch_year_unit') }}</option>
        @endfor
      </select>
    </div>
    <div>
      <span class="dch-lbl">{{ __('ui.dch_price') }}</span>
      {{-- ⚠️ `cloud_price()` واحد را خودش می‌چسبانَد — « تومان»ِ دستی نزن،
           چون در en/tr باید یورو نشان دهد. --}}
      <b>{{ cloud_price((int) $quote->sell_toman) }}</b>
    </div>
  </div>

  {{-- ── نام‌سرورها ── --}}
  <h2 class="dch-h2">{{ __('ui.dch_ns_title') }}</h2>
  <p class="dch-why">{{ __('ui.dch_ns_hint') }}</p>

  <div class="dch-grid">
    @for($i = 0; $i < 2; $i++)
      <label class="dch-f">
        <span>{{ __('ui.dch_ns_row', ['n' => fa_num($i + 1)]) }}</span>
        <input type="text" dir="ltr" name="ns[]" class="pnl-input"
               value="{{ old('ns.'.$i, $ns[$i] ?? '') }}" autocomplete="off" spellcheck="false">
      </label>
    @endfor
  </div>

  {{-- ── مشخصاتِ مالک ── --}}
  <h2 class="dch-h2">{{ __('ui.dch_owner_title') }}</h2>

  @if($missing === [])
    {{-- کامل است: دوباره نمی‌پرسیم. فرمی که هر بار همه‌چیز را بپرسد،
         خریدارِ دومین دامنه را می‌پرانَد. --}}
    <p class="dm-note ok" style="margin:0 0 4px">{{ __('ui.dch_complete_ok') }}</p>
  @else
    <p class="dch-why">{{ __('ui.dch_owner_why') }}</p>

    @php
      $labels = [
        'first_name'  => __('ui.dch_f_first'),
        'last_name'   => __('ui.dch_f_last'),
        'email'       => __('ui.dch_f_email'),
        'address'     => __('ui.dch_f_address'),
        'city'        => __('ui.dch_f_city'),
        'postal_code' => __('ui.dch_f_zip'),
        'mobile'      => __('ui.dch_f_mobile'),
      ];
      /* کدپستی همیشه نشان داده می‌شود ولی اجباری نیست — رجیسترار برای
         بعضی پسوندها می‌خواهدش و برای بعضی نه، و بازداشتنِ مشتری سرِ چیزی
         که شاید لازم نباشد یعنی فروشِ نرفته. */
      $show = array_values(array_unique(array_merge($missing, ['postal_code'])));
    @endphp

    <div class="dch-grid">
      @foreach($show as $f)
        <label class="dch-f">
          <span>{{ $labels[$f] ?? $f }}@if(in_array($f, $missing, true))<i class="dch-req">*</i>@endif</span>
          <input type="{{ $f === 'email' ? 'email' : 'text' }}"
                 name="{{ $f }}" class="pnl-input"
                 @if($f === 'email' || $f === 'mobile' || $f === 'postal_code') dir="ltr" @endif
                 value="{{ old($f, $profile?->{$f}) }}"
                 @if(in_array($f, $missing, true)) required @endif>
        </label>
      @endforeach
    </div>
  @endif

  <button class="btn btn-primary" style="margin-top:20px">{{ __('ui.dch_submit') }}</button>
</form>

<style>
.dch-sum{display:flex;flex-wrap:wrap;gap:26px;align-items:flex-end;
  padding-bottom:18px;border-bottom:1px solid var(--bg2)}
.dch-sum > div{display:flex;flex-direction:column;gap:6px}
.dch-lbl{font-size:12.5px;color:var(--muted)}
.dch-dom{font-size:19px}
.dch-h2{font-size:15px;margin:22px 0 6px}
.dch-why{font-size:12.5px;color:var(--muted);margin:0 0 12px;line-height:1.9}
.dch-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
.dch-f{display:flex;flex-direction:column;gap:6px;font-size:13px}
.dch-req{color:#f87171;font-style:normal;margin-inline-start:3px}
/* ⚠️ تکِ ستونی زیرِ ۵۴۰: دو ستونِ ۲۲۰پیکسلی روی موبایل سرریز می‌کرد و
   ورودیِ نشانی از کادر بیرون می‌زد. */
@media(max-width:540px){.dch-grid{grid-template-columns:1fr}.dch-sum{gap:16px}}
</style>

@endsection
