@extends('admin.layout')
@section('title', 'داشبورد')
@section('nav_dash', 'on')
@section('content')

{{-- ══ کسب‌وکار: مشتری، تیکت، فاکتور، سود ══ --}}
<div class="ad-kpis">
  <a class="ad-kpi" href="/admin/customers">
    <span class="ad-kpi-ic" style="background:rgba(34,211,238,.14);color:#22d3ee"><svg class="icon"><use href="#i-user"/></svg></span>
    <b>{{ fa_num($biz['customers']) }}</b>
    <span>مشتری@if($biz['customers_new'])<i style="color:#34d399"> +{{ fa_num($biz['customers_new']) }} این ماه</i>@endif</span>
  </a>
  <a class="ad-kpi" href="/admin/tickets">
    <span class="ad-kpi-ic" style="background:rgba(251,191,36,.14);color:#fbbf24"><svg class="icon"><use href="#i-lifebuoy"/></svg></span>
    <b>{{ fa_num($biz['tickets_open']) }}</b><span>تیکت باز</span>
  </a>
  {{--
    🔴 دو کاشیِ مالی برای پشتیبان رندر نمی‌شوند.

    داشبورد تنها صفحه‌ای است که پشتیبان هم بازش می‌کند، و **سودِ خالصِ شرکت**
    روی همان صفحه بود. صفحهٔ `/admin/finance` پشتِ گاردِ مدیر است، ولی عددش
    این‌جا بی‌گارد نشسته بود — همان الگوی «در به قفل، پنجره باز».

    ⚠️ کاشیِ فاکتورِ پرداخت‌نشده هم می‌رود، چون هم مبلغ را نشان می‌دهد و هم
    لینکش به `/admin/finance` است؛ لینکی که برای پشتیبان ۴۰۳ می‌دهد.
  --}}
  @if(auth()->user()?->isAdmin())
  <a class="ad-kpi" href="/admin/finance">
    <span class="ad-kpi-ic" style="background:rgba(255,107,107,.14);color:#ff6b6b"><svg class="icon"><use href="#i-coins"/></svg></span>
    <b>{{ fa_num($biz['invoices_unpaid']) }}</b>
    <span>فاکتور پرداخت‌نشده@if($biz['unpaid_amount'])<i style="color:var(--muted)"> ({{ fa_num(number_format($biz['unpaid_amount'])) }} ت)</i>@endif</span>
  </a>
  <a class="ad-kpi" href="/admin/finance">
    <span class="ad-kpi-ic" style="background:rgba(52,211,153,.14);color:#34d399"><svg class="icon"><use href="#i-coins"/></svg></span>
    <b style="color:{{ $fin['net_profit'] >= 0 ? '#34d399' : '#ff6b6b' }}">{{ fa_num(number_format($fin['net_profit'])) }}</b>
    <span>سود خالص (تومان)</span>
  </a>
  @endif
</div>

<div class="ad-grid2">
  {{-- تازه‌ترین مشتریان --}}
  <div class="ad-panel" style="margin:0">
    <div class="ad-panel-h"><h3>تازه‌ترین مشتریان</h3><a href="/admin/customers" style="font-size:13px;color:#22d3ee">همه</a></div>
    <table class="ad-table">
      <tbody>
        @forelse($newCustomers as $c)
        <tr>
          <td><a class="t" href="/admin/customers/{{ $c->id }}">{{ $c->displayName() }}</a>
            <div style="font-size:12px;color:var(--dim)" dir="ltr">{{ $c->code }}</div></td>
          <td dir="ltr" style="color:var(--muted)">{{ $c->phone ?: $c->email }}</td>
          <td>
            @php $st = ['active'=>['فعال','pub'],'pending'=>['در انتظار','draft'],'suspended'=>['معلق','draft'],'closed'=>['بسته','draft']][$c->status] ?? [$c->status,'draft']; @endphp
            <span class="ad-badge {{ $st[1] }}">{{ $st[0] }}</span>
          </td>
        </tr>
        @empty
        <tr><td colspan="3" style="text-align:center;color:var(--dim);padding:30px">هنوز مشتری‌ای ثبت‌نام نکرده.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  {{-- محتوا — کارِ نویسنده و مدیر است، نه پشتیبان --}}
  @unless(auth()->user()?->isSupport())
  <div class="ad-panel" style="margin:0">
    <div class="ad-panel-h"><h3>آخرین مطالب</h3><a class="btn btn-primary" href="/admin/posts/new?type=blog"><svg class="icon"><use href="#i-plus"/></svg>مطلب جدید</a></div>
    <table class="ad-table">
      <tbody>
        @forelse($recent as $p)
        <tr>
          <td><a class="t" href="/admin/posts/{{ $p->id }}/edit">{{ optional($p->tr('fa'))->title ?? $p->slug }}</a></td>
          <td>{{ $p->type === 'kb' ? 'دانش' : 'بلاگ' }}</td>
          <td><span class="ad-badge {{ $p->status === 'published' ? 'pub' : 'draft' }}">{{ $p->status === 'published' ? 'منتشر' : 'پیش‌نویس' }}</span></td>
        </tr>
        @empty
        <tr><td colspan="3" style="text-align:center;color:var(--dim);padding:30px">هنوز مطلبی ندارید.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  @endunless
</div>

{{-- ══ آخرین اتفاقات ══
     🔴 هر سه فهرست **زنده** از منبعِ خودشان می‌آیند؛ هیچ جدولِ خلاصه‌ای در
     میان نیست. خلاصهٔ کپی‌شده روزی با واقعیت drift می‌کند و داشبورد چیزی
     نشان می‌دهد که دیگر درست نیست.

     ⚠️ تاریخ‌ها با `sdate()` شمسی می‌شوند — همان تابعی که کلِ پنل استفاده
     می‌کند، نه قالب‌بندیِ دستیِ تازه. --}}
<div class="ad-latest" style="margin-top:16px">

  {{-- پرداخت‌ها: مبلغ + درگاه + مشتری. پشتیبان لازم ندارد و
       `/admin/invoices` هم برایش ۴۰۳ است. --}}
  @if(auth()->user()?->isAdmin())
  <div class="ad-panel" style="margin:0">
    <div class="ad-panel-h">
      <h3>آخرین پرداخت‌ها</h3>
      <a href="/admin/invoices" style="font-size:13px;color:var(--cyan)">همه</a>
    </div>
    <div class="ad-lat-b">
      @forelse($latest['payments'] as $p)
        <a class="ad-lat-row" href="{{ $p->customer_id ? '/admin/customers/'.$p->customer_id : '#' }}">
          <span class="ad-lat-ic ok"><svg class="icon"><use href="#i-check"/></svg></span>
          <span class="ad-lat-t">
            <b>{{ $p->customer?->displayName() ?? '—' }}</b>
            <small>{{ $p->gateway }} · {{ sdate($p->paid_at ?? $p->created_at, true) }}</small>
          </span>
          <span class="ad-lat-n">{{ invoice_money((int) $p->amount, $p->currency_code ?: 'IRT') }}</span>
        </a>
      @empty
        {{-- ⚠️ «هنوز چیزی نیست» و نه ردیفِ ساختگی --}}
        <p class="ad-lat-empty">هنوز پرداختی ثبت نشده.</p>
      @endforelse
    </div>
  </div>
  @endif

  {{-- سرویس‌ها: مبلغِ فروش دارد و لینکِ `/admin/services` هم برای پشتیبان
       ۴۰۳ است. تیکت‌ها (پایین‌تر) عمداً برای همه می‌مانَد. --}}
  @if(auth()->user()?->isAdmin())
  <div class="ad-panel" style="margin:0">
    <div class="ad-panel-h">
      <h3>تازه‌ترین سرویس‌ها</h3>
      <a href="/admin/services" style="font-size:13px;color:var(--cyan)">همه</a>
    </div>
    <div class="ad-lat-b">
      @forelse($latest['services'] as $sv)
        <a class="ad-lat-row" href="{{ $sv->customer_id ? '/admin/customers/'.$sv->customer_id : '#' }}">
          <span class="ad-lat-ic info"><svg class="icon"><use href="#i-server"/></svg></span>
          <span class="ad-lat-t">
            <b>{{ $sv->name }}</b>
            <small>{{ $sv->customer?->displayName() ?? '—' }} · {{ sdate($sv->created_at) }}</small>
          </span>
          <span class="ad-lat-n">{{ invoice_money((int) $sv->price, $sv->currency_code ?: 'IRT') }}</span>
        </a>
      @empty
        <p class="ad-lat-empty">هنوز سرویسی ثبت نشده.</p>
      @endforelse
    </div>
  </div>
  @endif

  <div class="ad-panel" style="margin:0">
    <div class="ad-panel-h">
      <h3>آخرین تیکت‌ها</h3>
      <a href="/admin/tickets" style="font-size:13px;color:var(--cyan)">همه</a>
    </div>
    <div class="ad-lat-b">
      @forelse($latest['tickets'] as $t)
        <a class="ad-lat-row" href="/admin/tickets/{{ $t->id }}">
          <span class="ad-lat-ic {{ $t->status === 'open' ? 'warn' : 'mute' }}"><svg class="icon"><use href="#i-message"/></svg></span>
          <span class="ad-lat-t">
            <b>{{ $t->subject }}</b>
            <small>{{ $t->customer?->displayName() ?? '—' }} · {{ sdate($t->last_reply_at ?? $t->created_at, true) }}</small>
          </span>
          {{-- ⚠️ «پاسخ با ماست» را از `last_reply_role` می‌گیرد، نه از وضعیت:
               تیکتِ بازی که خودمان آخرین پاسخ را داده‌ایم منتظرِ ما نیست. --}}
          @if($t->status === 'open' && $t->last_reply_role === 'customer')
            <span class="ad-lat-tag">منتظر پاسخ</span>
          @endif
        </a>
      @empty
        <p class="ad-lat-empty">هنوز تیکتی ثبت نشده.</p>
      @endforelse
    </div>
  </div>

</div>
{{-- ══ محتوا: آمار ثانویه ══
     شمارشِ پست و کامنت و «کاربر پنل» کارِ پشتیبان نیست. --}}
@unless(auth()->user()?->isSupport())
<div class="ad-stats" style="margin-top:16px">
  <div class="ad-stat"><b style="color:var(--cyan)">{{ fa_num($stats['blog']) }}</b><span>پست بلاگ</span></div>
  <div class="ad-stat"><b style="color:var(--violet)">{{ fa_num($stats['kb']) }}</b><span>مقاله دانش</span></div>
  <div class="ad-stat"><b style="color:var(--green)">{{ fa_num($stats['published']) }}</b><span>منتشرشده</span></div>
  <div class="ad-stat"><b style="color:var(--amber)">{{ fa_num($stats['draft']) }}</b><span>پیش‌نویس</span></div>
  <div class="ad-stat"><b style="color:var(--amber)">{{ fa_num($stats['comments']) }}</b><span>کامنت در انتظار</span></div>
  <div class="ad-stat"><b>{{ fa_num($stats['users']) }}</b><span>کاربر پنل</span></div>
</div>
@endunless

<style>
.ad-latest{ display:grid; grid-template-columns:repeat(3,1fr); gap:14px }
.ad-lat-b{ padding:6px 0 10px }
.ad-lat-row{ display:flex; align-items:center; gap:10px; padding:9px 16px; text-decoration:none; color:var(--text); transition:background .14s }
.ad-lat-row:hover{ background:var(--surface) }
.ad-lat-ic{ width:30px; height:30px; border-radius:9px; display:grid; place-items:center; flex:none }
.ad-lat-ic .icon{ width:15px; height:15px }
.ad-lat-ic.ok{ background:rgba(52,211,153,.12); color:#34d399 }
.ad-lat-ic.info{ background:rgba(34,211,238,.12); color:#22d3ee }
.ad-lat-ic.warn{ background:rgba(245,158,11,.12); color:#f59e0b }
.ad-lat-ic.mute{ background:var(--surface); color:var(--muted) }
.ad-lat-t{ flex:1; min-width:0; display:flex; flex-direction:column; gap:1px }
.ad-lat-t b{ font-size:13px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis }
.ad-lat-t small{ font-size:11.5px; color:var(--muted) }
.ad-lat-n{ font-size:12.5px; color:var(--muted); font-variant-numeric:tabular-nums; white-space:nowrap }
.ad-lat-tag{ font-size:11px; padding:2px 8px; border-radius:20px; background:rgba(245,158,11,.12); color:#f59e0b; white-space:nowrap }
.ad-lat-empty{ padding:14px 16px; font-size:12.5px; color:var(--muted) }
@media(max-width:1100px){ .ad-latest{ grid-template-columns:1fr } }
.ad-kpis{ display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:16px }
.ad-kpi{ display:flex; flex-direction:column; gap:4px; padding:16px; background:var(--panel,var(--surface)); border:1px solid var(--line); border-radius:14px; text-decoration:none; transition:border-color .15s }
.ad-kpi:hover{ border-color:#334155 }
.ad-kpi-ic{ width:38px; height:38px; border-radius:10px; display:grid; place-items:center; margin-bottom:6px }
.ad-kpi-ic .icon{ width:20px; height:20px }
.ad-kpi b{ font-size:26px; color:var(--text); font-variant-numeric:tabular-nums; line-height:1 }
.ad-kpi span{ font-size:12.5px; color:var(--muted) }
.ad-kpi span i{ font-style:normal; font-size:11px }
/* .ad-grid2 حالا در admin.css است — تعریفِ درون‌خطی فقط روی همین صفحه اثر داشت */
@media(max-width:900px){ .ad-kpis{ grid-template-columns:repeat(2,1fr) } }
</style>
@endsection
