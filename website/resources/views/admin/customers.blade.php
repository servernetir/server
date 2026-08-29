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
      {{-- فیلترهای فعال باید با جستجوی تازه بمانند، وگرنه هر جستجو انتخاب‌ها را بی‌صدا پاک می‌کند --}}
      @foreach(['service','verified','reseller','sort','from','to'] as $f)
        @if(($filters[$f] ?? '') !== '' && $filters[$f] !== 'newest')<input type="hidden" name="{{ $f }}" value="{{ $filters[$f] }}">@endif
      @endforeach
      {{-- ══ جستجوی زنده ══
           کادر همان کادرِ قبلی است و Enter هنوز جستجوی کاملِ صفحه‌بندی‌شده را
           می‌دهد؛ فقط حین تایپ، نتایجِ کلِ جدول (نه فقط این صفحه) زیرش می‌آید.
           `autocomplete=off` لازم است وگرنه پیشنهادِ خودِ مرورگر روی فهرست
           می‌افتد و کلیک را می‌خورَد. --}}
      <div class="cs-live">
        <input type="search" name="q" id="cs-q" value="{{ $q }}" autocomplete="off"
               placeholder="کد، ایمیل، موبایل یا نام و نام‌خانوادگی…"
               style="background:var(--surface2);border:1px solid var(--line);border-radius:9px;color:var(--text);padding:8px 12px;min-width:240px;font:inherit">
        <div class="cs-drop" id="cs-drop" hidden></div>
      </div>
      <button class="btn btn-primary" type="submit"><svg class="icon"><use href="#i-search"/></svg>جستجو</button>
    </form>
  </div>
</div>

@php $inp2 = 'background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:7px 10px;font:inherit;font-size:12.5px'; @endphp
{{-- ══ فیلترهای پیشرفته ══ --}}
<form method="get" action="/admin/customers" class="ad-toolbar" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
  <input type="hidden" name="status" value="{{ $status }}">
  <input type="hidden" name="q" value="{{ $q }}">
  <select name="service" style="{{ $inp2 }}">
    <option value="">سرویس: همه</option>
    <option value="with"    @selected(($filters['service'] ?? '') === 'with')>دارای سرویس فعال</option>
    <option value="without" @selected(($filters['service'] ?? '') === 'without')>بدون سرویس فعال</option>
  </select>
  <select name="verified" style="{{ $inp2 }}">
    <option value="">احراز هویت: همه</option>
    <option value="yes" @selected(($filters['verified'] ?? '') === 'yes')>احرازشده</option>
    <option value="no"  @selected(($filters['verified'] ?? '') === 'no')>احرازنشده</option>
  </select>
  <select name="reseller" style="{{ $inp2 }}">
    <option value="">نوع: همه</option>
    <option value="yes" @selected(($filters['reseller'] ?? '') === 'yes')>نماینده</option>
    <option value="no"  @selected(($filters['reseller'] ?? '') === 'no')>عادی</option>
  </select>
  <label style="display:flex;align-items:center;gap:5px;color:var(--dim);font-size:12px">از
    <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" style="{{ $inp2 }}" dir="ltr"></label>
  <label style="display:flex;align-items:center;gap:5px;color:var(--dim);font-size:12px">تا
    <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" style="{{ $inp2 }}" dir="ltr"></label>
  <select name="sort" style="{{ $inp2 }}">
    <option value="newest"   @selected(($filters['sort'] ?? 'newest') === 'newest')>جدیدترین</option>
    <option value="oldest"   @selected(($filters['sort'] ?? '') === 'oldest')>قدیمی‌ترین</option>
    <option value="services" @selected(($filters['sort'] ?? '') === 'services')>بیشترین سرویس</option>
    <option value="invoices" @selected(($filters['sort'] ?? '') === 'invoices')>بیشترین فاکتور</option>
  </select>
  <button class="btn" type="submit" style="font-size:12.5px">اعمال فیلتر</button>
  @if(($filters['service'] ?? '') !== '' || ($filters['verified'] ?? '') !== '' || ($filters['reseller'] ?? '') !== '' || ($filters['from'] ?? '') !== '' || ($filters['to'] ?? '') !== '' || ($filters['sort'] ?? 'newest') !== 'newest')
    <a href="/admin/customers?status={{ $status }}{{ $q !== '' ? '&q='.urlencode($q) : '' }}" style="font-size:12px;color:#ff6b6b">حذف فیلترها</a>
  @endif
</form>


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

{{--
  ══ جستجوی زندهٔ مشتری ══

  🔴 لایوتِ ادمین `@stack('scripts')` ندارد — درسِ ثبت‌شدهٔ دکمهٔ «پیشنهاد
  پاسخ»: اسکریپتِ push‌شده بی‌صدا دور ریخته می‌شود و چیزی رندر می‌شود که
  هرگز کار نمی‌کند. پس اسکریپت مستقیم داخلِ همین بخش می‌نشیند.
--}}
<script>
(function () {
  var inp  = document.getElementById('cs-q');
  var drop = document.getElementById('cs-drop');
  if (!inp || !drop) return;

  var timer = null, lastQ = null, ctrl = null;

  function hide() { drop.hidden = true; drop.innerHTML = ''; }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function render(data) {
    if (!data.results.length) {
      drop.innerHTML = '<div class="cs-empty">چیزی پیدا نشد</div>';
      drop.hidden = false;
      return;
    }

    var html = data.results.map(function (r) {
      var line2 = [r.email, r.phone].filter(Boolean).join(' · ');
      return '<a class="cs-row" href="' + esc(r.url) + '">'
        + '<span class="cs-code">' + esc(r.code) + '</span>'
        + '<span class="cs-main"><b>' + (r.name ? esc(r.name) : '<i>بی‌نام</i>') + '</b>'
        + '<small>' + esc(line2) + '</small></span>'
        + (r.services ? '<span class="cs-badge">' + r.services + ' سرویس</span>' : '')
        + '</a>';
    }).join('');

    // 🔴 «چند تا بیشتر هست» را صریح بگو: کاربر باید بداند فهرست بریده شده،
    //    وگرنه نبودِ یک مشتری در این ۱۲ تا را «نیست» می‌خواند.
    if (data.total > data.results.length) {
      html += '<div class="cs-more">' + (data.total - data.results.length)
        + ' نتیجهٔ دیگر — Enter بزنید تا همه را ببینید</div>';
    }

    drop.innerHTML = html;
    drop.hidden = false;
  }

  function run() {
    var q = inp.value.trim();

    if (q === lastQ) return;
    lastQ = q;

    if (q.length < 2) { hide(); return; }

    // درخواستِ قبلی را لغو کن — وگرنه پاسخِ کُندِ «عل» می‌تواند بعد از
    // پاسخِ «علی رضایی» برسد و نتیجهٔ کهنه را روی تازه بنشاند.
    if (ctrl) ctrl.abort();
    ctrl = new AbortController();

    fetch('/admin/customers/search?q=' + encodeURIComponent(q), {
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      signal: ctrl.signal,
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d && d.ok) render(d); })
      .catch(function () { /* لغو یا قطعی شبکه — کادر دست‌نخورده می‌مانَد */ });
  }

  inp.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(run, 220);      // ضربه‌گیر: هر کلید یک درخواست نشود
  });

  inp.addEventListener('focus', function () { if (drop.innerHTML) drop.hidden = false; });

  document.addEventListener('click', function (e) {
    if (!drop.contains(e.target) && e.target !== inp) hide();
  });

  inp.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') hide();
  });
})();
</script>
@endsection
