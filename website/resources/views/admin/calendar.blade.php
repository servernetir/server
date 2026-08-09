@extends('admin.layout')
@section('title', 'تقویم کسب‌وکار')
@section('nav_calendar', 'on')
@section('content')

{{--
  تقویمِ کسب‌وکار.

  ⚠️ سه تلهٔ Blade که در این پروژه سابقه دارند و این فایل عمداً از هرسه دور
  می‌مانَد:
    • هیچ اسکریپتِ درون‌خطی — همهٔ JS در public/assets/js/admin-calendar.js
      (در Blade هر «اَت + واژه» یک directive و هر آکولادِ دوتایی درون‌یابی است)
    • هیچ آرایهٔ درون‌خطی داخلِ json — payload در کنترلر ساخته می‌شود
    • هیچ کاراکترِ «کمتر از + علامت سؤال» در هیچ رشته‌ای (short_open_tag روی
      سرور روشن است و محلی خاموش ⇒ خطای ۵۰۰ فقط روی لایو)
--}}

<div class="cal-wrap" id="cal-root" data-view="month">

  <div>
    {{-- ══ نوارِ ابزار ══ --}}
    <div class="cal-bar">
      <div class="cal-nav">
        <button type="button" data-cal="prev" aria-label="ماه پیش">
          <svg class="icon" style="transform:rotate(90deg)"><use href="#i-chev"/></svg>
        </button>
        <div class="cal-title" id="cal-title"></div>
        <button type="button" data-cal="next" aria-label="ماه بعد">
          <svg class="icon" style="transform:rotate(-90deg)"><use href="#i-chev"/></svg>
        </button>
        <button type="button" data-cal="today" aria-label="رفتن به ماهِ جاری" style="padding:0 14px;width:auto">
          امروز
        </button>
      </div>

      <button type="button" class="btn btn-primary" data-cal="add">
        <svg class="icon"><use href="#i-plus"/></svg>افزودن یادآوری
      </button>

      <div class="cal-views" id="cal-views" role="group" aria-label="نمای تقویم">
        <button type="button" data-cal="view" data-view="month" aria-pressed="true">ماه</button>
        <button type="button" data-cal="view" data-view="week" aria-pressed="false">هفته</button>
        <button type="button" data-cal="view" data-view="list" aria-pressed="false">فهرست</button>
      </div>
    </div>

    {{-- ══ چیپ‌های لایه — هر کدام یک کلیدِ config ══ --}}
    <div class="cal-chips" id="cal-chips" role="group" aria-label="لایه‌های تقویم">
      @foreach($layers as $key => $layer)
        <button type="button" class="cal-chip t-{{ $layer['tone'] ?? 'task' }}"
                data-layer="{{ $key }}"
                aria-pressed="{{ ($prefs[$key] ?? true) ? 'true' : 'false' }}"
                title="{{ $layer['hint'] ?? '' }}">
          <span class="dot" aria-hidden="true"></span>
          <svg class="icon"><use href="#{{ $layer['icon'] ?? 'i-check' }}"/></svg>
          {{ $layer['label'] ?? $key }}
          <span class="n" aria-hidden="true"></span>
        </button>
      @endforeach
    </div>

    {{-- ══ نوارِ اتصالِ گوگل ══
         فقط وقتی دیده می‌شود که اعتبارنامهٔ اپ در تنظیمات باشد. اتصال per-user
         است، پس این نوار وضعیتِ **همین کاربر** را می‌گوید نه شرکت را. --}}
    @if($google['configured'])
      <div class="cal-gbar @if($google['last_error']) is-broken @endif">
        <svg class="icon"><use href="#i-calendar"/></svg>
        @if($google['connected'])
          <b>تقویم گوگل وصل است</b>
          @if($google['email'])<span class="who">{{ $google['email'] }}</span>@endif
          @if($google['synced_at'])<span style="color:var(--dim)">· آخرین همگام‌سازی {{ $google['synced_at'] }}</span>@endif
          @if($google['last_error'])
            <span class="msg" style="flex-basis:100%">⚠️ {{ $google['last_error'] }}</span>
          @endif
          <span class="sep"></span>
          <a class="btn btn-ghost" href="/admin/calendar/google/connect">اتصال دوباره</a>
          <form method="post" action="/admin/calendar/google/disconnect" style="margin:0"
                data-confirm="اتصالِ تقویمِ گوگل قطع شود؟" data-confirm-danger>
            @csrf<button type="submit" class="btn btn-danger">قطع اتصال</button>
          </form>
        @else
          <b>تقویم گوگلتان وصل نیست</b>
          <span style="color:var(--muted)">رویدادهای شخصی‌تان کنار سررسیدهای کاری دیده می‌شوند — فقط خودتان.</span>
          <span class="sep"></span>
          <a class="btn btn-primary" href="/admin/calendar/google/connect">
            <svg class="icon"><use href="#i-link"/></svg>اتصال به گوگل
          </a>
        @endif
      </div>
    @endif

    {{-- لایه‌ای که خوانده نشد یا از سقف گذشت — خالیِ خراب نباید شبیهِ خالیِ سالم باشد --}}
    <div class="cal-warn" id="cal-warn" role="status" hidden></div>

    <div class="ad-panel" style="padding:14px">
      {{-- سرستونِ روزهای هفته: از **شنبه**، و در RTL ستونِ اول سمتِ راست است --}}
      <div class="cal-grid" id="cal-weekdays" aria-hidden="true">
        @foreach($weekdays as $wd)
          <div class="cal-wd">{{ $wd }}</div>
        @endforeach
      </div>

      {{-- اسکلتِ بارگذاری: دقیقاً همان شبکهٔ ۶×۷، پس صفحه موقعِ عوض‌شدنِ ماه
           نمی‌پرد (بیشترین طولِ ممکنِ یک ماهِ شمسی روی شبکه ۶ ردیف است) --}}
      <div class="cal-skel" id="cal-skel" aria-hidden="true" hidden>
        @for($i = 0; $i < 42; $i++)<i></i>@endfor
      </div>

      <div class="cal-grid" id="cal-grid" role="grid"
           aria-label="شبکهٔ ماه — با کلیدهای جهت‌نما بین روزها جابه‌جا شوید"></div>

      <div class="cal-list" id="cal-list" hidden></div>

      <div class="cal-empty" id="cal-empty" hidden>
        <svg class="icon"><use href="#i-calendar"/></svg>
        <p>در این بازه رویدادی نیست</p>
        <small>
          یا لایه‌ها را از بالای صفحه روشن کنید، یا با دکمهٔ «افزودن یادآوری»
          اولین مورد را بسازید.
        </small>
      </div>
    </div>
  </div>

  {{-- ══ ستونِ رویدادهای پیش‌رو ══ --}}
  <aside class="cal-side">
    <div class="ad-panel">
      <div class="ad-panel-h">
        <h3>{{ $upcomingDays }} روزِ آینده</h3>
      </div>
      <div class="cal-up" id="cal-up">
        @forelse($upcoming as $item)
          @php
            $layer = $layers[$item->type] ?? [];
            $away  = $item->daysFromToday();
            $when  = $away <= 0 ? 'امروز' : ($away === 1 ? 'فردا' : fa_num($away).' روز دیگر');
          @endphp
          <button type="button"
                  class="cal-up-item t-{{ $layer['tone'] ?? 'task' }}@if($away <= $dueSoonDays) is-soon @endif"
                  data-date="{{ $item->jalaliDate() }}"
                  aria-label="{{ $item->screenReaderLabel() }}">
            <span class="bar" aria-hidden="true"></span>
            <span>
              <span class="t">{{ $item->title }}</span>
              <span class="w">{{ $layer['label'] ?? $item->type }} · {{ $when }}</span>
            </span>
          </button>
        @empty
          <div class="cal-empty" style="padding:22px 10px">
            <svg class="icon"><use href="#i-check"/></svg>
            <p>هفتهٔ آرامی پیش رو دارید</p>
            <small>در {{ fa_num($upcomingDays) }} روزِ آینده سررسیدی نیست.</small>
          </div>
        @endforelse
      </div>
    </div>
  </aside>
</div>

{{-- ══ کشوی جزئیاتِ روز / فرمِ افزودن ══ --}}
<div class="cal-back" id="cal-back"></div>
<aside class="cal-drawer" id="cal-drawer" role="dialog" aria-modal="true"
       aria-labelledby="cal-drawer-title" aria-hidden="true">
  <div class="cal-drawer-h">
    <h3 id="cal-drawer-title"></h3>
    <button type="button" class="x" id="cal-close" aria-label="بستن">
      <svg class="icon"><use href="#i-x"/></svg>
    </button>
  </div>
  <div class="cal-drawer-b" id="cal-drawer-body"></div>
</aside>

{{-- ناحیهٔ زندهٔ صفحه‌خوان: تغییرِ ماه و نتیجهٔ هر اقدام از این‌جا خوانده می‌شود.
     بی‌این، کاربرِ صفحه‌خوان بعد از زدنِ «ماه بعد» هیچ بازخوردی نمی‌گیرد. --}}
<p class="cal-sr" id="cal-live" role="status" aria-live="polite"></p>

{{-- تنها دریچهٔ داده به JS. نوعِ application/json یعنی مرورگر اجرایش نمی‌کند
     و هیچ رشته‌ای در آن به‌عنوان کد تفسیر نمی‌شود. --}}
<script type="application/json" id="cal-boot">@json($boot)</script>

@endsection

@section('scripts')
<script src="{{ asset_ver('assets/js/admin-calendar.js') }}" defer></script>
@endsection
