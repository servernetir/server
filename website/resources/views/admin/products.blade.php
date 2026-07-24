@extends('admin.layout')
@section('title', 'پکیج‌های فروش')
@section('nav_products', 'on')
@section('content')

<div class="ad-panel">
  <div class="ad-panel-h"><h2>پکیج‌های فروش</h2></div>
  <p style="padding:0 18px;color:#96a3ba;font-size:13.5px;line-height:1.9">
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
    <small style="color:#5f6c82;margin-inline-start:8px">حدومرزها از مشخصاتِ هر پکیج حدس زده می‌شوند؛ در WHM قابلِ تنظیم‌اند.</small>
  </div>

  @if($products->isEmpty())
    <p style="padding:16px;color:#5f6c82">هنوز پکیجی نساخته‌اید.</p>
  @else
    <table class="ad-table">
      <thead><tr><th>پکیج</th><th>دسته</th><th>قیمت</th><th>دوره</th><th>تحویل</th><th>وضعیت</th></tr></thead>
      <tbody>
        @foreach($products as $p)
        <tr>
          <td><b>{{ $p->name }}</b>@if($p->plan)<small dir="ltr" style="color:#5f6c82;display:block">plan: {{ $p->plan }}</small>@endif</td>
          <td>{{ $p->categoryLabel() }}</td>
          <td dir="ltr">{{ number_format($p->price) }}@if($p->setup_fee)<small style="color:#5f6c82"> +{{ number_format($p->setup_fee) }}</small>@endif</td>
          <td>{{ $p->cycleLabel() }}</td>
          <td>@if($p->server)<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">{{ $p->server->name }}</span>@else<span style="color:#5f6c82">دستی</span>@endif</td>
          <td>@if($p->is_active)<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">فعال</span>@else<span class="ad-badge" style="background:rgba(148,163,184,.12);color:#96a3ba">غیرفعال</span>@endif</td>
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
              @csrf<button class="btn" style="background:#ff6b6b;color:#0b1220;font-size:12.5px;padding:7px 13px">حذف پکیج</button>
            </form>
          </details>
        </td></tr>
        @endforeach
      </tbody>
    </table>
  @endif
  @endif
</div>

@unless($notReady)
<div class="ad-panel">
  <div class="ad-panel-h"><h3>افزودن پکیج</h3></div>
  @include('admin.partials.product-form', ['product' => null, 'action' => '/admin/products'])
</div>
@endunless

<style>
.srv-f{ padding:16px; display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:760px }
.srv-f label{ display:flex; flex-direction:column; gap:6px; font-size:12.5px; color:#96a3ba }
.srv-f input, .srv-f select, .srv-f textarea{ background:#0f1522; border:1px solid #1e2637; border-radius:9px; color:#e7edf7; padding:9px 12px; font:inherit; font-size:13px }
.srv-f textarea{ resize:vertical }
.srv-f .col2{ grid-column:1/3 }
.srv-f .chk{ flex-direction:row; align-items:center; gap:8px }
.srv-edit summary::-webkit-details-marker{ display:none }
@media(max-width:640px){ .srv-f{ grid-template-columns:1fr } .srv-f .col2{ grid-column:1 } }
</style>
@endsection
