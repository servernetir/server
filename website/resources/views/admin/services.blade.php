@extends('admin.layout')
@section('title', 'سرویس‌ها')
@section('nav_services', 'on')
@section('content')

{{--
  ══ فهرستِ کلِ سرویس‌های فروخته‌شده ══

  🔴 این صفحه فقط **می‌خوانَد**. هیچ دکمهٔ اقدامی (تحویل، تمدید، تعلیق، خاتمه)
  این‌جا نیست و همه در پروندهٔ مشتری می‌مانند — یک کلیکِ اشتباه روی ردیفِ
  همسایه در فهرستِ ۳۰تایی، روی داده‌ای که پول است، جبران‌ناپذیر است.

  ⚠️ ستونِ آخر عمداً فقط دو **لینک** دارد: پرونده و تاریخچه.
--}}

@php $inp = 'background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:7px 10px;font:inherit;font-size:12.5px'; @endphp

<div class="ad-toolbar" style="justify-content:space-between;flex-wrap:wrap;gap:12px">
  <div class="ad-tabs">
    {{-- ⚠️ برچسب و شمارش هر دو از `ServiceController::TABS` می‌آیند تا عددِ
         زبانه و تعدادِ ردیف‌های جدول نتوانند واگرا شوند. --}}
    @foreach(['all' => 'همه', 'active' => 'فعال', 'pending' => 'منتظر پرداخت', 'delivery' => 'در حالِ تحویل', 'suspended' => 'معلق', 'dead' => 'بسته'] as $key => $label)
      <a href="/admin/services?tab={{ $key }}" class="{{ $tab === $key ? 'on' : '' }}">{{ $label }} ({{ fa_num($counts[$key] ?? 0) }})</a>
    @endforeach
  </div>

  <form method="get" action="/admin/services" style="display:flex;gap:8px;flex-wrap:wrap">
    <input type="hidden" name="tab" value="{{ $tab }}">
    {{-- فیلترهای فعال باید با جستجوی تازه بمانند، وگرنه هر جستجو انتخاب‌ها را
         بی‌صدا پاک می‌کند — همان الگوی فهرستِ مشتریان. --}}
    @foreach(['server', 'cycle', 'billing', 'sort'] as $f)
      @if(($filters[$f] ?? '') !== '' && $filters[$f] !== 'newest')<input type="hidden" name="{{ $f }}" value="{{ $filters[$f] }}">@endif
    @endforeach
    <input type="search" name="q" value="{{ $q }}" autocomplete="off"
           placeholder="نام سرویس، دامنه، نام‌کاربری، یا کد/ایمیل/موبایلِ مشتری…"
           style="{{ $inp }};min-width:280px;padding:8px 12px;font-size:inherit">
    <button class="btn btn-primary" type="submit"><svg class="icon"><use href="#i-search"/></svg>جستجو</button>
  </form>
</div>

{{-- ══ فیلترهای پیشرفته ══ --}}
<form method="get" action="/admin/services" class="ad-toolbar" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
  <input type="hidden" name="tab" value="{{ $tab }}">
  <input type="hidden" name="q" value="{{ $q }}">
  <select name="server" style="{{ $inp }}">
    <option value="">سرورِ تحویل: همه</option>
    @foreach($servers as $srv)
      <option value="{{ $srv->id }}" @selected((string) ($filters['server'] ?? '') === (string) $srv->id)>{{ $srv->name }}</option>
    @endforeach
  </select>
  <select name="cycle" style="{{ $inp }}">
    <option value="">دوره: همه</option>
    @foreach(\App\Models\Service::cycles() as $cy)
      <option value="{{ $cy }}" @selected(($filters['cycle'] ?? '') === $cy)>{{ \App\Models\Service::labelFor($cy) }}</option>
    @endforeach
  </select>
  <select name="billing" style="{{ $inp }}">
    <option value="">صورت‌حساب: همه</option>
    <option value="cycle"  @selected(($filters['billing'] ?? '') === 'cycle')>دوره‌ای</option>
    <option value="hourly" @selected(($filters['billing'] ?? '') === 'hourly')>ساعتی</option>
  </select>
  <select name="sort" style="{{ $inp }}">
    <option value="newest" @selected(($filters['sort'] ?? 'newest') === 'newest')>جدیدترین</option>
    <option value="oldest" @selected(($filters['sort'] ?? '') === 'oldest')>قدیمی‌ترین</option>
    <option value="due"    @selected(($filters['sort'] ?? '') === 'due')>نزدیک‌ترین سررسید</option>
    <option value="price"  @selected(($filters['sort'] ?? '') === 'price')>گران‌ترین</option>
  </select>
  <button class="btn" type="submit" style="font-size:12.5px">اعمال فیلتر</button>
  @if(($filters['server'] ?? '') !== '' || ($filters['cycle'] ?? '') !== '' || ($filters['billing'] ?? '') !== '' || ($filters['sort'] ?? 'newest') !== 'newest')
    <a href="/admin/services?tab={{ $tab }}{{ $q !== '' ? '&q='.urlencode($q) : '' }}" style="font-size:12px;color:#ff6b6b">حذف فیلترها</a>
  @endif
</form>

@if($notReady)
  <div class="ad-panel"><p style="padding:20px;color:#fbbf24">جدول سرویس‌ها روی این سرور هنوز ساخته نشده. پس از اجرای مهاجرت، سرویس‌ها این‌جا نمایش داده می‌شوند.</p></div>
@else
<div class="ad-panel">
  <div class="ad-panel-h">
    <h2>سرویس‌ها</h2>
    {{-- ⚠️ «تحویل‌ها» زیرمجموعهٔ همین فهرست است (فقط تحویل‌نشده‌ها) — لینکش
         این‌جاست تا مدیر برای رسیدگی به گیرکرده‌ها دنبالِ منو نگردد. --}}
    <a class="btn btn-glass" href="/admin/provisioning"><svg class="icon"><use href="#i-box"/></svg>مرکز تحویل‌ها</a>
  </div>

  @if($services->isEmpty())
    <p style="padding:20px;color:var(--muted)">
      {{ $q !== '' ? 'سرویسی با این جستجو پیدا نشد.' : 'در این دسته سرویسی نیست.' }}
    </p>
  @else
    <table class="ad-table">
      <thead>
        <tr><th>سرویس</th><th>مشتری</th><th>وضعیت</th><th>تحویل</th><th>دوره</th><th>مبلغ</th><th>سررسید</th><th>ثبت</th><th></th></tr>
      </thead>
      <tbody>
        @foreach($services as $s)
          <tr>
            <td>
              <span class="t">{{ $s->name }}</span>
              {{-- شناسه‌های واقعیِ سرویس: دامنه، نام‌کاربری، سرورِ تحویل --}}
              @php
                $ident = array_values(array_filter([$s->domain, $s->username, $s->server?->name]));
              @endphp
              @if($ident)
                <div style="font-size:12px;color:var(--dim)" dir="ltr">{{ implode(' · ', $ident) }}</div>
              @endif
            </td>
            <td>
              @if($s->customer)
                <a class="t" href="/admin/customers/{{ $s->customer_id }}">{{ $s->customer->displayName() }}</a>
                <div style="font-size:12px;color:var(--dim)" dir="ltr">{{ $s->customer->code }}</div>
              @else
                {{-- 🔴 سرویسِ یتیم بی‌صدا حذف نمی‌شود: ممکن است هنوز روی سرور
                     زنده باشد و هزینه‌اش پای ما. --}}
                <span style="color:#fbbf24">مشتری حذف شده</span>
              @endif
            </td>
            <td>
              @php $badge = $s->statusBadge(); @endphp
              <span class="ad-badge" style="background:{{ $badge[1] }}22;color:{{ $badge[1] }}">{{ $badge[0] }}</span>
            </td>
            <td>
              {{-- ⚠️ «انجام شد» را ساکت نشان می‌دهیم و بقیه را با رنگ: صفحهٔ
                   مروری باید ردیفِ مشکل‌دار را در یک نگاه لو بدهد. --}}
              @if($s->provision_status === 'done')
                <span style="color:var(--dim)">انجام شد</span>
              @elseif(in_array($s->provision_status, ['failed', \App\Models\Service::PROVISION_RELEASING], true))
                <span style="color:#ff6b6b" dir="ltr">{{ $s->provision_status }}</span>
              @elseif($s->provision_status && $s->provision_status !== \App\Models\Service::PROVISION_NONE)
                <span style="color:#fbbf24" dir="ltr">{{ $s->provision_status }}</span>
              @else
                <span style="color:var(--dim)">—</span>
              @endif
            </td>
            <td>
              @if($s->isHourly())
                <span class="ad-badge" style="background:rgba(34,211,238,.15);color:#22d3ee">ساعتی</span>
              @else
                {{ \App\Models\Service::labelFor((string) $s->cycle) }}
              @endif
            </td>
            <td dir="ltr">
              {{-- ⚠️ سرویسِ ساعتی «مبلغِ دوره» ندارد؛ نرخِ ساعتی‌اش نشان داده
                   می‌شود، وگرنه ستون عددِ صفر می‌داد و «رایگان» خوانده می‌شد. --}}
              @if($s->isHourly())
                {{ invoice_money((int) $s->hourly_rate_irt, 'IRT') }}<small style="color:var(--dim)"> /ساعت</small>
              @else
                {{ invoice_money((int) $s->price, $s->currency_code ?: 'IRT') }}
              @endif
            </td>
            <td dir="ltr" style="color:var(--muted)">{{ $s->next_due_at ? sdate($s->next_due_at) : '—' }}</td>
            <td dir="ltr" style="color:var(--muted)">{{ sdate($s->created_at) }}</td>
            <td class="cust-act" style="white-space:nowrap;text-align:left">
              @if($s->customer_id)
                <a class="cust-a" href="/admin/customers/{{ $s->customer_id }}" title="پروندهٔ مشتری"><svg class="icon"><use href="#i-list"/></svg></a>
              @endif
              <a class="cust-a" href="/admin/services/{{ $s->id }}/history" title="تاریخچه"><svg class="icon"><use href="#i-clock"/></svg></a>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>

{{ $services->links() }}
@endif

@endsection
