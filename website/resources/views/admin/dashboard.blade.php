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
  <a class="ad-kpi" href="/admin/finance">
    <span class="ad-kpi-ic" style="background:rgba(255,107,107,.14);color:#ff6b6b"><svg class="icon"><use href="#i-coins"/></svg></span>
    <b>{{ fa_num($biz['invoices_unpaid']) }}</b>
    <span>فاکتور پرداخت‌نشده@if($biz['unpaid_amount'])<i style="color:#96a3ba"> ({{ fa_num(number_format($biz['unpaid_amount'])) }} ت)</i>@endif</span>
  </a>
  <a class="ad-kpi" href="/admin/finance">
    <span class="ad-kpi-ic" style="background:rgba(52,211,153,.14);color:#34d399"><svg class="icon"><use href="#i-coins"/></svg></span>
    <b style="color:{{ $fin['net_profit'] >= 0 ? '#34d399' : '#ff6b6b' }}">{{ fa_num(number_format($fin['net_profit'])) }}</b>
    <span>سود خالص (تومان)</span>
  </a>
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
            <div style="font-size:12px;color:#5f6c82" dir="ltr">{{ $c->code }}</div></td>
          <td dir="ltr" style="color:#96a3ba">{{ $c->phone ?: $c->email }}</td>
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

  {{-- محتوا --}}
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
</div>

{{-- ══ محتوا: آمار ثانویه ══ --}}
<div class="ad-stats" style="margin-top:16px">
  <div class="ad-stat"><b style="color:var(--cyan)">{{ fa_num($stats['blog']) }}</b><span>پست بلاگ</span></div>
  <div class="ad-stat"><b style="color:var(--violet)">{{ fa_num($stats['kb']) }}</b><span>مقاله دانش</span></div>
  <div class="ad-stat"><b style="color:var(--green)">{{ fa_num($stats['published']) }}</b><span>منتشرشده</span></div>
  <div class="ad-stat"><b style="color:var(--amber)">{{ fa_num($stats['draft']) }}</b><span>پیش‌نویس</span></div>
  <div class="ad-stat"><b style="color:var(--amber)">{{ fa_num($stats['comments']) }}</b><span>کامنت در انتظار</span></div>
  <div class="ad-stat"><b>{{ fa_num($stats['users']) }}</b><span>کاربر پنل</span></div>
</div>

<style>
.ad-kpis{ display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:16px }
.ad-kpi{ display:flex; flex-direction:column; gap:4px; padding:16px; background:var(--panel,#141b2b); border:1px solid #1e2637; border-radius:14px; text-decoration:none; transition:border-color .15s }
.ad-kpi:hover{ border-color:#334155 }
.ad-kpi-ic{ width:38px; height:38px; border-radius:10px; display:grid; place-items:center; margin-bottom:6px }
.ad-kpi-ic .icon{ width:20px; height:20px }
.ad-kpi b{ font-size:26px; color:#e7edf7; font-variant-numeric:tabular-nums; line-height:1 }
.ad-kpi span{ font-size:12.5px; color:#96a3ba }
.ad-kpi span i{ font-style:normal; font-size:11px }
.ad-grid2{ display:grid; grid-template-columns:1fr 1fr; gap:16px }
@media(max-width:900px){ .ad-kpis{ grid-template-columns:repeat(2,1fr) } .ad-grid2{ grid-template-columns:1fr } }
</style>
@endsection
