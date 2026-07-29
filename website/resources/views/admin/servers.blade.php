@extends('admin.layout')
@section('title', 'سرورهای تحویل')
@section('nav_servers', 'on')
@section('content')

<div class="ad-panel">
  <div class="ad-panel-h"><h2>سرورهای تحویل</h2></div>
  <p style="padding:0 18px;color:var(--muted);font-size:13.5px;line-height:1.9">
    سرورهایی که سرویسِ مشتری روی آن‌ها ساخته می‌شود. برای WHM/cPanel تحویل
    <b>خودکار</b> است (توکنِ API لازم است)؛ VPS و اختصاصی فعلاً تحویلِ <b>دستی</b> دارند.
    توکن رمزنگاری‌شده ذخیره می‌شود و دیگر نمایش داده نمی‌شود.
  </p>

  @if($notReady)
    <p style="padding:18px;color:#fbbf24">جدول سرورها هنوز روی این سرور ساخته نشده. پس از اجرای مهاجرت فعال می‌شود.</p>
  @else

  {{-- فهرست سرورها --}}
  @if($servers->isEmpty())
    <p style="padding:16px;color:var(--dim)">هنوز سروری اضافه نکرده‌اید.</p>
  @else
    <table class="ad-table">
      <thead><tr><th>نام</th><th>نوع</th><th>مکان</th><th>میزبان</th><th>وضعیت</th><th>حساب‌ها</th><th></th></tr></thead>
      <tbody>
        @foreach($servers as $s)
        <tr>
          <td><b>{{ $s->name }}</b></td>
          <td>{{ $s->typeLabel() }}@if($s->isAutoProvisioned())<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399;margin-inline-start:6px">خودکار</span>@else<span class="ad-badge" style="background:rgba(148,163,184,.12);color:var(--muted);margin-inline-start:6px">دستی</span>@endif</td>
          <td style="color:var(--muted);white-space:nowrap">@if($s->locationLabel()){{ $s->locationLabel() }}@else<span style="color:#fbbf24" title="بدونِ کشور، در صفحهٔ خرید انتخاب نمی‌شود">— تعیین نشده</span>@endif</td>
          <td dir="ltr" style="color:var(--muted)">{{ $s->hostname ?: '—' }}@if($s->hostname):{{ $s->effectivePort() }}@endif</td>
          <td>@php $sb=$s->statusBadge(); @endphp<span class="ad-badge" style="background:{{ $sb[1] }}22;color:{{ $sb[1] }}">{{ $sb[0] }}</span></td>
          <td dir="ltr" style="color:var(--muted)">{{ $s->services_count }}@if($s->max_accounts) / {{ $s->max_accounts }}@endif</td>
          <td style="text-align:left;white-space:nowrap">
            @if($s->isAutoProvisioned())
              <form method="post" action="/admin/servers/{{ $s->id }}/test" style="display:inline">@csrf<button class="btn btn-glass" style="padding:6px 12px;font-size:12.5px">آزمون اتصال</button></form>
            @endif
          </td>
        </tr>
        <tr><td colspan="7" style="padding:0 12px 12px">
          <details class="srv-edit">
            <summary style="cursor:pointer;color:#22d3ee;font-size:12.5px;padding:6px 0">ویرایش / حذف</summary>
            @include('admin.partials.server-form', ['server' => $s, 'action' => "/admin/servers/{$s->id}"])
            <form method="post" action="/admin/servers/{{ $s->id }}/delete" style="margin-top:8px"
                  data-confirm="سرور «{{ $s->name }}» حذف شود؟" data-confirm-danger>
              @csrf<button class="btn" style="background:#ff6b6b;color:var(--bg);font-size:12.5px;padding:7px 13px">حذف سرور</button>
            </form>
          </details>
        </td></tr>
        @endforeach
      </tbody>
    </table>
  @endif
  @endif
</div>

{{-- افزودن سرور --}}
@unless($notReady)
<div class="ad-panel">
  <div class="ad-panel-h"><h3>افزودن سرور</h3></div>
  @include('admin.partials.server-form', ['server' => null, 'action' => '/admin/servers'])
</div>
@endunless

<style>
.srv-f{ padding:16px; display:grid; grid-template-columns:1fr 1fr; gap:14px; max-width:760px }
.srv-f label{ display:flex; flex-direction:column; gap:6px; font-size:12.5px; color:var(--muted) }
.srv-f input, .srv-f select, .srv-f textarea{ background:var(--surface2); border:1px solid var(--line); border-radius:9px; color:var(--text); padding:9px 12px; font:inherit; font-size:13px }
.srv-f .col2{ grid-column:1/3 }
.srv-f .chk{ flex-direction:row; align-items:center; gap:8px }
.srv-edit summary::-webkit-details-marker{ display:none }
@media(max-width:640px){ .srv-f{ grid-template-columns:1fr } .srv-f .col2{ grid-column:1 } }
</style>
@endsection
