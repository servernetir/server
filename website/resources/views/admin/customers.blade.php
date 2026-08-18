@extends('admin.layout')
@section('title', 'مشتریان')
@section('nav_customers', 'on')
@section('content')

<div class="ad-toolbar" style="justify-content:space-between;flex-wrap:wrap;gap:12px">
  <div class="ad-tabs">
    <a href="/admin/customers?status=all"       class="{{ $status === 'all' ? 'on' : '' }}">همه ({{ fa_num($counts['all']) }})</a>
    <a href="/admin/customers?status=active"    class="{{ $status === 'active' ? 'on' : '' }}">فعال ({{ fa_num($counts['active']) }})</a>
    <a href="/admin/customers?status=pending"   class="{{ $status === 'pending' ? 'on' : '' }}">در انتظار ({{ fa_num($counts['pending']) }})</a>
    <a href="/admin/customers?status=suspended" class="{{ $status === 'suspended' ? 'on' : '' }}">معلق ({{ fa_num($counts['suspended']) }})</a>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    {{-- ══ احراز هویت ══
         آیتمِ مستقلِ منوی مدیریت برداشته شد: صف‌ِ احراز هویت زیرمجموعهٔ همین
         مشتریان است و یک ردیفِ دائمی در نوارِ کناری برای صفی که معمولاً خالی
         است، فقط جا می‌گیرد.

         🔴 ولی **شمارشِ در انتظار با آن حذف نشد** و همین‌جا آمد. اگر فقط لینک
         را برمی‌داشتیم، تنها سیگنالِ «N نفر منتظرِ تأییدند» از پنل غیب می‌شد و
         مدیر تا شکایتِ خودِ مشتری خبردار نمی‌شد — همان الگوی «حذفِ هشدار به‌جای
         حذفِ شلوغی» که در این پروژه ثبت شده.

         ⚠️ صف که خالی باشد دکمه ساده است؛ که پر باشد، رنگِ هشدار می‌گیرد. --}}
    @php $pendingKyc = \Illuminate\Support\Facades\Schema::hasTable('customer_profiles')
            ? \App\Models\CustomerProfile::where('status', 'pending')->count() : 0; @endphp
    <a class="btn {{ $pendingKyc ? 'btn-primary' : 'btn-glass' }}" href="/admin/verifications">
      <svg class="icon"><use href="#i-shield"/></svg>احراز هویت
      @if($pendingKyc)<span class="ad-pill">{{ fa_num($pendingKyc) }}</span>@endif
    </a>

    <form method="get" action="/admin/customers" style="display:flex;gap:8px">
      <input type="hidden" name="status" value="{{ $status }}">
      <input type="search" name="q" value="{{ $q }}" placeholder="کد، ایمیل، موبایل یا نام…"
             style="background:var(--surface2);border:1px solid var(--line);border-radius:9px;color:var(--text);padding:8px 12px;min-width:240px;font:inherit">
      <button class="btn btn-primary" type="submit"><svg class="icon"><use href="#i-search"/></svg>جستجو</button>
    </form>
  </div>
</div>


@php
  /* دکمهٔ تماس یک بار برای کلِ جدول ارزیابی می‌شود، نه به ازای هر ردیف —
     `OutgoingCallService::enabled()` فقط config می‌خواند ولی ساختنِ سرویس در
     حلقهٔ ۵۰ ردیفی بی‌دلیل است. */
  $callSvc   = app(\App\Services\CloudPhone\OutgoingCallService::class);
  $callAgent = $callSvc->agentNumberFor(auth()->user()->phoneExtension());
  $canCall   = auth()->user()->isAdmin() && $callSvc->enabled() && $callAgent;
@endphp

@if($notReady)
  <div class="ad-panel"><p style="padding:20px;color:#fbbf24">جدول مشتریان روی این سرور هنوز ساخته نشده. پس از اجرای مهاجرت، مشتریان این‌جا نمایش داده می‌شوند.</p></div>
@else
<div class="ad-panel">
  <div class="ad-panel-h"><h2>مشتریان</h2></div>
  @if($customers->isEmpty())
    <p style="padding:20px;color:var(--muted)">{{ $q !== '' ? 'مشتری‌ای با این جستجو پیدا نشد.' : 'هنوز مشتری‌ای ثبت‌نام نکرده.' }}</p>
  @else
    <table class="ad-table">
      <thead>
        <tr><th>مشتری</th><th>تماس</th><th>احراز هویت</th><th>سرویس فعال</th><th>دامنه</th><th>فاکتور</th><th>تیکت</th><th>وضعیت</th><th>عضویت</th><th></th></tr>
      </thead>
      <tbody>
        @foreach($customers as $c)
          <tr onclick="location='/admin/customers/{{ $c->id }}'" style="cursor:pointer">
            <td>
              <span class="t">{{ $c->displayName() }}</span>
              <div style="font-size:12px;color:var(--dim)" dir="ltr">{{ $c->code }}</div>
            </td>
            <td dir="ltr" style="color:var(--muted)">
              {{ $c->phone ?: '—' }}
              <div style="font-size:12px;color:var(--dim)">{{ $c->email }}</div>
            </td>
            <td>
              @if($c->identityVerification && $c->identityVerification->status === 'verified')
                <span class="ad-badge" style="background:rgba(52,211,153,.15);color:#34d399">احرازشده</span>
              @elseif($c->identityVerification)
                <span class="ad-badge" style="background:rgba(251,191,36,.15);color:#fbbf24">ناقص</span>
              @else
                <span class="ad-badge" style="background:rgba(95,108,130,.15);color:var(--muted)">—</span>
              @endif
            </td>
            <td>
              @if(($c->active_services_count ?? 0) > 0)
                <b style="color:#34d399">{{ fa_num($c->active_services_count) }}</b>
              @else<span style="color:var(--dim)">—</span>@endif
            </td>
            <td>
              @if(($c->active_domains_count ?? 0) > 0)
                <b style="color:#22d3ee">{{ fa_num($c->active_domains_count) }}</b>
              @else<span style="color:var(--dim)">—</span>@endif
            </td>
            <td>{{ fa_num($c->invoices_count) }}</td>
            <td>{{ fa_num($c->tickets_count) }}</td>
            <td>
              @php $st = ['active'=>['فعال','#34d399'],'pending'=>['در انتظار','#fbbf24'],'suspended'=>['معلق','#ff6b6b'],'closed'=>['بسته','var(--dim)']][$c->status] ?? [$c->status,'var(--muted)']; @endphp
              <span class="ad-badge" style="background:{{ $st[1] }}22;color:{{ $st[1] }}">{{ $st[0] }}</span>
            </td>
            <td dir="ltr" style="color:var(--muted)">{{ sdate($c->created_at) }}</td>
            {{-- عملیات: onclick ردیف نباید فعال شود، پس جلوی انتشارش گرفته می‌شود --}}
            <td class="cust-act" onclick="event.stopPropagation()" style="white-space:nowrap;text-align:left">
              <a class="cust-a" href="/admin/customers/{{ $c->id }}" title="پرونده"><svg class="icon"><use href="#i-list"/></svg></a>
              @if(auth()->user()->isAdmin() && $c->status !== 'closed')
                <form method="post" action="/admin/customers/{{ $c->id }}/impersonate" style="display:inline"
                      data-confirm="وارد پنلِ «{{ $c->displayName() }}» می‌شوید. این کار در لاگ ثبت می‌شود.">
                  @csrf<button class="cust-a" type="submit" title="ورود به پنل کاربری"><svg class="icon"><use href="#i-key"/></svg></button>
                </form>
              @endif
              {{-- تماسِ یک‌کلیکی.
                   ⚠️ دو شرط: مدیر باشیم و مشتری شماره داشته باشد. دکمه‌ای که
                   کلیکش خطا بدهد بدتر از نبودنِ دکمه است. --}}
              @if($canCall && $c->phone)
                <form method="post" action="/admin/customers/{{ $c->id }}/call" style="display:inline"
                      data-confirm="تماس با {{ $c->phone }} برقرار شود؟ اول {{ $callAgent }} زنگ می‌خورد، بعد مشتری.">
                  @csrf<button class="cust-a" type="submit" title="تماس با {{ $c->phone }}"><svg class="icon"><use href="#i-phone"/></svg></button>
                </form>
              @endif
              <a class="cust-a" href="/admin/broadcasts?customer={{ $c->id }}" title="ارسال اعلان"><svg class="icon"><use href="#i-message"/></svg></a>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>

{{ $customers->links() }}
@endif

{{-- استایلِ ستونِ عملیات (.cust-act / .cust-a) به `admin.css` منتقل شد.
     چرایش آن‌جا نوشته شده: `display:flex` روی یک <td> سلول را از جدول جدا
     می‌کرد. این‌جا دوباره تعریفش نکن. --}}
@endsection
