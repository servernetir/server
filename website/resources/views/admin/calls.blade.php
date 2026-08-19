@extends('admin.layout')
@section('title', 'تماس‌ها')
@section('nav_calls', 'on')
@section('content')

<div class="ad-toolbar" style="justify-content:space-between;flex-wrap:wrap;gap:12px">
  <div class="ad-tabs">
    <a href="/admin/calls?f=all"       class="{{ $filter === 'all' ? 'on' : '' }}">همه ({{ fa_num($counts['all']) }})</a>
    <a href="/admin/calls?f=missed"    class="{{ $filter === 'missed' ? 'on' : '' }}">از دست رفته ({{ fa_num($counts['missed']) }})</a>
    <a href="/admin/calls?f=incoming"  class="{{ $filter === 'incoming' ? 'on' : '' }}">ورودی ({{ fa_num($counts['incoming']) }})</a>
    <a href="/admin/calls?f=outgoing"  class="{{ $filter === 'outgoing' ? 'on' : '' }}">خروجی ({{ fa_num($counts['outgoing']) }})</a>
    <a href="/admin/calls?f=unmatched" class="{{ $filter === 'unmatched' ? 'on' : '' }}">ناشناس ({{ fa_num($counts['unmatched'] ?? 0) }})</a>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <form method="get" action="/admin/calls" style="display:flex;gap:8px">
      <input type="hidden" name="f" value="{{ $filter }}">
      <input type="search" name="q" value="{{ $q }}" placeholder="شمارهٔ تماس‌گیرنده…"
             style="background:var(--surface2);border:1px solid var(--line);border-radius:9px;color:var(--text);padding:8px 12px;min-width:200px;font:inherit">
      <button class="btn btn-glass" type="submit"><svg class="icon"><use href="#i-search"/></svg>جستجو</button>
    </form>

    {{-- ══ شماره‌گیرِ دلخواه ══
         خواستهٔ کارفرما: «مشتریم نبود هم بتوانم تماس بگیرم.»

         ⚠️ این فقط برای **مدیر** است، نه نقشِ نویسنده — تماس پول خرج می‌کند و
         از خطِ شرکت می‌رود. همان تفکیکی که روتِ تماس با مشتری دارد.

         ⚠️ فرم `data-confirm` دارد چون برخلافِ دکمهٔ تماس با مشتری، این‌جا
         مقصد **تایپ** می‌شود و یک رقمِ اشتباه یعنی زنگ‌زدن به یک غریبه با
         شمارهٔ ما روی کالر آی‌دی. --}}
    @if(auth()->user()->isAdmin() && $dialer['ready'])
      <form method="post" action="/admin/calls/dial" style="display:flex;gap:8px"
            data-confirm="با این شماره تماس گرفته شود؟ اول {{ $dialer['agent'] }} زنگ می‌خورد، بعد مقصد.">
        @csrf
        <input type="tel" name="number" required inputmode="tel" dir="ltr" placeholder="۰۹۱۲۳۴۵۶۷۸۹"
               style="background:var(--surface2);border:1px solid var(--line);border-radius:9px;color:var(--text);padding:8px 12px;min-width:170px;font:inherit">
        <button class="btn btn-primary" type="submit"><svg class="icon"><use href="#i-phone"/></svg>تماس</button>
      </form>
    @elseif(auth()->user()->isAdmin())
      {{-- علتِ نبودن دیده می‌شود، وگرنه «چرا دکمه نیست؟» خودش یک تیکت است --}}
      <span style="font-size:12px;color:#fbbf24;align-self:center">{{ $dialer['why'] }}</span>
    @endif
  </div>
</div>

@if($notReady)
  <div class="ad-panel"><p style="padding:20px;color:#fbbf24">جدول تماس‌ها روی این سرور هنوز ساخته نشده. پس از اجرای مهاجرت، تماس‌ها این‌جا نمایش داده می‌شوند.</p></div>
@else
<div class="ad-panel">
  <div class="ad-panel-h"><h2>گزارش تماس‌ها</h2></div>

  @if($calls->isEmpty())
    <p style="padding:20px;color:var(--muted)">
      {{ $q !== '' ? 'تماسی با این جستجو پیدا نشد.' : 'هنوز تماسی ثبت نشده. اگر وبهوک تازه وصل شده، اولین تماس چند لحظه بعد این‌جا می‌آید.' }}
    </p>
  @else
    <table class="ad-table">
      <thead>
        <tr><th>زمان</th><th>جهت</th><th>تماس‌گیرنده</th><th>مشتری</th><th>نتیجه</th><th>مدت</th><th>مسیر</th></tr>
      </thead>
      <tbody>
        @foreach($calls as $call)
          <tr>
            {{-- روزِ هفته + تاریخِ شمسی + ساعت، همه از یک تابع و یک منطقهٔ
                 زمانی. `format('H:i')`ِ خام UTC می‌داد و با تاریخِ تهرانی
                 کنارش نمی‌خواند — شب‌ها یک روز اختلاف. --}}
            <td style="color:var(--muted);white-space:nowrap">
              {{ sdate_full($call->started_at) }}
            </td>

            <td>
              @if($call->direction === 'incoming')
                <span class="ad-badge" style="background:rgba(34,211,238,.15);color:#22d3ee">ورودی</span>
              @elseif($call->direction === 'outgoing')
                <span class="ad-badge" style="background:rgba(167,139,250,.15);color:#a78bfa">خروجی</span>
              @else
                <span class="ad-badge" style="background:rgba(95,108,130,.15);color:var(--muted)">—</span>
              @endif
            </td>

            <td dir="ltr" style="color:var(--muted)">
              {{ $call->caller_number ?: '—' }}
              @if($call->transferred_to_number)
                <div style="font-size:12px;color:var(--dim)">← {{ $call->transferred_to_number }}</div>
              @endif
            </td>

            {{--
              🔴 درجهٔ اطمینانِ تطبیق **نمایش داده می‌شود**، پنهان نمی‌ماند.

              شمارهٔ ثابت بدونِ پیش‌شمارهٔ شهر می‌آید، پس یک تطبیقِ ثابت هیچ‌وقت
              به قطعیتِ تطبیقِ موبایل نیست. اگر هر دو را یک‌شکل نشان دهیم،
              کارشناس به هر دو یکسان اعتماد می‌کند و روزی نامِ مشتریِ اشتباه را
              صدا می‌زند.
            --}}
            <td>
              @if($call->customer)
                <a href="/admin/customers/{{ $call->customer->id }}" style="color:var(--text)">{{ $call->customer->displayName() }}</a>
                @if($call->match_confidence === \App\Models\PhoneCall::MATCH_LOCAL)
                  <div style="font-size:11px;color:#fbbf24" title="شماره بدون پیش‌شمارهٔ شهر آمده — ممکن است مشتری دیگری باشد">تطبیق نامطمئن</div>
                @endif
              @elseif($call->match_confidence === \App\Models\PhoneCall::MATCH_MANY)
                <span style="color:#fbbf24">چند مشتری خوردند</span>
                <div style="font-size:11px;color:var(--dim)">عمداً به هیچ‌کدام وصل نشد</div>
              @else
                <span style="color:var(--dim)">ناشناس</span>
              @endif
            </td>

            {{-- ⚠️ سه حالت، نه دو تا: null یعنی «هنوز تمام نشده»، نه «بی‌پاسخ» --}}
            <td>
              @if($call->answered === true)
                <span class="ad-badge" style="background:rgba(52,211,153,.15);color:#34d399">پاسخ داده شد</span>
              @elseif($call->answered === false)
                <span class="ad-badge" style="background:rgba(255,107,107,.15);color:#ff6b6b">بی‌پاسخ</span>
              @else
                <span class="ad-badge" style="background:rgba(251,191,36,.15);color:#fbbf24">در جریان</span>
              @endif
            </td>

            <td dir="ltr" style="color:var(--muted)">
              @if($call->duration_seconds !== null)
                {{ fa_num(gmdate($call->duration_seconds >= 3600 ? 'H:i:s' : 'i:s', $call->duration_seconds)) }}
              @else
                <span style="color:var(--dim)">—</span>
              @endif
            </td>

            <td style="font-size:12px;color:var(--dim)">
              @if($call->was_transferred)منتقل شد@endif
              @if($call->menu_name) · {{ $call->menu_name }}@endif
              @if($call->initiation_source) · {{ $call->initiation_source }}@endif
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>

{{ $calls->links() }}
@endif

@endsection
