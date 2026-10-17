@extends('admin.layout')
@section('title', 'پکیج‌های فروش')
@section('nav_products', 'on')
@section('content')

<div class="ad-panel">
  <div class="ad-panel-h"><h2>پکیج‌های فروش</h2></div>
  <p style="padding:0 18px;color:var(--muted);font-size:13.5px;line-height:1.9">
    پکیج‌هایی که مشتری در فروشگاهِ پنلِ خود (<span dir="ltr">/account/store</span>) می‌بیند و آنلاین می‌خرد.
    اگر پکیج به یک سرورِ تحویل وصل باشد، پس از پرداختِ مشتری <b>خودکار</b> ساخته می‌شود.
  </p>

  @if($notReady)
    <p style="padding:18px;color:#fbbf24">جدول پکیج‌ها هنوز روی این سرور ساخته نشده. پس از اجرای مهاجرت فعال می‌شود.</p>
  @else

  <div style="padding:2px 18px 10px">
    <form method="post" action="/admin/products-whm-sync-all" style="display:inline" data-confirm="package همهٔ پکیج‌هایِ متصل به سرورِ WHM ساخته/به‌روزرسانی و وصل شود؟">
      @csrf<button class="btn btn-glass" style="font-size:12.5px"><svg class="icon"><use href="#i-server"/></svg>ساخت همهٔ package ها در WHM</button>
    </form>
    <small style="color:var(--dim);margin-inline-start:8px">حدومرزها از مشخصاتِ هر پکیج حدس زده می‌شوند؛ در WHM قابلِ تنظیم‌اند.</small>
  </div>

  @if($products->isEmpty())
    <p style="padding:16px;color:var(--dim)">هنوز پکیجی نساخته‌اید.</p>
  @else
    <table class="ad-table">
      <thead><tr><th>پکیج</th><th>دسته</th><th>قیمت</th><th>دوره</th><th>تحویل</th><th>وضعیت</th></tr></thead>
      <tbody>
        @foreach($products as $p)
        <tr>
          <td><b>{{ $p->name }}</b>@if($p->plan)<small dir="ltr" style="color:var(--dim);display:block">plan: {{ $p->plan }}</small>@endif</td>
          <td>{{ $p->categoryLabel() }}</td>
          <td dir="ltr">{{ $p->effectivePrice() > 0 ? number_format($p->effectivePrice()).' تومان' : 'قیمت موقتاً در دسترس نیست' }}@if((float) $p->base_setup_eur > 0 && $p->effectivePrice() > 0)<small style="color:var(--dim)"> +{{ number_format($p->effectiveSetup()) }}</small>@endif</td>
          <td>{{ $p->cycleLabel() }}</td>
          <td>@if($p->server)<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">{{ $p->server->name }}</span>@else<span style="color:var(--dim)">دستی</span>@endif</td>
          <td>@if($p->is_active)<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">فعال</span>@else<span class="ad-badge" style="background:rgba(148,163,184,.12);color:var(--muted)">غیرفعال</span>@endif</td>
        </tr>
        <tr><td colspan="6" style="padding:0 12px 12px">
          <details class="srv-edit">
            <summary style="cursor:pointer;color:#22d3ee;font-size:12.5px;padding:6px 0">ویرایش / حذف</summary>
            @include('admin.partials.product-form', ['product' => $p, 'action' => "/admin/products/{$p->id}"])
            @if($p->server && $p->server->type === 'whm')
              <form method="post" action="/admin/products/{{ $p->id }}/whm-sync" style="margin-top:8px;display:inline-block">
                @csrf<button class="btn btn-glass" style="font-size:12.5px"><svg class="icon"><use href="#i-server"/></svg>ساخت package این پکیج در WHM</button>
              </form>
            @endif
            <form method="post" action="/admin/products/{{ $p->id }}/delete" style="margin-top:8px" data-confirm="پکیج «{{ $p->name }}» حذف شود؟" data-confirm-danger>
              @csrf<button class="btn" style="background:#ff6b6b;color:var(--bg);font-size:12.5px;padding:7px 13px">حذف پکیج</button>
            </form>
          </details>
        </td></tr>
        @endforeach
      </tbody>
    </table>
  @endif
  @endif
</div>

{{-- ══ تغییرِ قیمتِ گروهی ══
     کارِ روزمرهٔ کارفرما «۱۰٪ به همهٔ هاست‌های لینوکس اضافه کن» است؛ انجامِ
     دستی‌اش ۵۲ بار فرصتِ خطای انسانی است. --}}
@unless($notReady)
<div class="ad-panel">
  <div class="ad-panel-h"><h3>تغییرِ قیمتِ گروهی</h3></div>
  <p style="padding:0 18px;color:var(--muted);font-size:13.5px;line-height:1.9">
    قیمت پایهٔ یورویی <b>همهٔ پکیج‌های یک گروه</b> را با هم عوض کنید. مبلغ یورویی به پلهٔ انتخابی رو به بالا گرد می‌شود.
    <br>قیمت فروش با نرخ ذخیره‌شدهٔ نوسان، سود فعلی و تخفیف دوره محاسبه می‌شود.
    تغییر بلافاصله در <b>سایت اصلی</b> هم دیده می‌شود.
  </p>
  <form method="post" action="/admin/products-reprice" class="rp-f"
        data-confirm="قیمتِ همهٔ پکیج‌های این گروه عوض شود؟" data-confirm-title="تغییرِ قیمتِ گروهی">
    @csrf
    <input type="hidden" name="pricing_mode" value="native_eur">
    <label>گروه
      <select name="group" required>
        @foreach($groups as $items)
          @if($items->first()->group)
            <option value="{{ $items->first()->group }}">{{ $items->first()->groupLabel() }} — {{ fa_num($items->count()) }} پکیج</option>
          @endif
        @endforeach
      </select>
    </label>
    <label>نوعِ تغییر
      <select name="mode">
        <option value="percent">درصدی (۱۰ یا ۵-)</option>
        <option value="amount">افزودن مبلغ ثابت (یورو)</option>
        <option value="set">قیمت پایهٔ یکسان (یورو)</option>
      </select>
    </label>
    <label>مقدار
      <input type="number" name="value" step="0.01" required dir="ltr" placeholder="10">
    </label>
    <label>گردکردن به
      <select name="round">
        <option value="0.01">۰٫۰۱ یورو</option>
        <option value="0.10">۰٫۱۰ یورو</option>
        <option value="1.00">۱ یورو</option>
      </select>
    </label>
    <div><button class="btn btn-primary" type="submit"><svg class="icon"><use href="#i-coins"/></svg>اعمال روی گروه</button></div>
  </form>
</div>

{{-- ══ نمای گروه‌بندی‌شده ══ --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h3>پکیج‌ها بر اساس گروه</h3></div>
  @foreach($groups as $items)
    <details class="rp-g" @if($loop->first) open @endif>
      <summary>
        <b>{{ $items->first()->groupLabel() }}</b>
        <span class="ad-badge" style="background:rgba(34,211,238,.12);color:#22d3ee">{{ fa_num($items->count()) }}</span>
        <small dir="ltr" style="color:var(--dim)">{{ $items->first()->group ?: '—' }}</small>
        <small style="color:var(--muted);margin-inline-start:auto">
          €{{ number_format((float) $items->min('base_price_eur'), 4) }} – €{{ number_format((float) $items->max('base_price_eur'), 4) }} پایه
        </small>
      </summary>
      <table class="ad-table">
        <thead><tr><th>پکیج</th><th>فروش فعلی (تومان)</th><th>پایهٔ یورو</th><th>package در WHM</th><th>وضعیت</th></tr></thead>
        <tbody>
          @foreach($items as $p)
          <tr>
            <td>{{ $p->name }}</td>
            <td dir="ltr">{{ $p->effectivePrice() > 0 ? fa_num(number_format($p->effectivePrice())) : '—' }}</td>
            <td dir="ltr">{{ $p->base_price_eur !== null ? '€'.number_format((float) $p->base_price_eur, 6) : '—' }}</td>
            <td dir="ltr" style="color:var(--muted);font-size:12px">{{ $p->plan ?: '—' }}</td>
            <td>@if($p->is_active)<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">فعال</span>@else<span class="ad-badge" style="background:rgba(148,163,184,.12);color:var(--muted)">غیرفعال</span>@endif</td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </details>
  @endforeach
</div>
@endunless

@unless($notReady)
<div class="ad-panel">
  <div class="ad-panel-h"><h3>افزودن پکیج</h3></div>
  @include('admin.partials.product-form', ['product' => null, 'action' => '/admin/products'])
</div>
@endunless

<style>
.srv-f{ padding:16px; display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:760px }
.srv-f label{ display:flex; flex-direction:column; gap:6px; font-size:12.5px; color:var(--muted) }
.srv-f input, .srv-f select, .srv-f textarea{ background:var(--surface2); border:1px solid var(--line); border-radius:9px; color:var(--text); padding:9px 12px; font:inherit; font-size:13px }
.srv-f textarea{ resize:vertical }
.srv-f .col2{ grid-column:1/3 }
.srv-f .chk{ flex-direction:row; align-items:center; gap:8px }
.srv-edit summary::-webkit-details-marker{ display:none }

/* تغییرِ قیمتِ گروهی */
.rp-f{ padding:16px 18px; display:grid; grid-template-columns:repeat(auto-fit,minmax(165px,1fr)); gap:12px; align-items:end }
.rp-f label{ display:flex; flex-direction:column; gap:6px; font-size:12.5px; color:var(--muted) }
.rp-f input, .rp-f select{ background:var(--surface2); border:1px solid var(--line); border-radius:9px; color:var(--text); padding:9px 12px; font:inherit; font-size:13px }
.rp-f .chk{ flex-direction:row; align-items:center; gap:8px }
.rp-g{ border-top:1px solid var(--line) }
.rp-g summary{ display:flex; align-items:center; gap:10px; padding:13px 18px; cursor:pointer; font-size:13.5px }
.rp-g summary::-webkit-details-marker{ display:none }
.rp-g summary:hover, .rp-g[open] summary{ background:var(--surface) }

@media(max-width:640px){ .srv-f{ grid-template-columns:1fr } .srv-f .col2{ grid-column:1 } }
</style>
@endsection
