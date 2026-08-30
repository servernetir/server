@extends('admin.layout')
@section('title', 'سرورِ فیزیکی')
@section('nav_server_shop', 'on')
@section('content')

<div class="ad-panel">
  <div class="ad-panel-h" style="display:flex;justify-content:space-between;align-items:center;gap:12px">
    <h2>فروشگاهِ سرورِ فیزیکی</h2>
    <a href="/admin/server-shop/create" class="btn btn-primary" style="font-size:13px"><svg class="icon"><use href="#i-plus"/></svg>افزودنِ سرور</a>
  </div>
  <p style="padding:0 18px;color:var(--muted);font-size:13.5px;line-height:1.9">
    مدل‌هایی که در صفحهٔ <a href="/servers" target="_blank" dir="ltr" style="color:#22d3ee">/servers</a> نمایش داده می‌شوند
    (HP/Dell/Lenovo/Supermicro). تحویلِ فیزیکی خودکار نیست، پس دکمهٔ سایت «تماس برای استعلام» است مگر قیمتِ ثابت بگذارید.
  </p>

  @if($notReady)
    <p style="padding:18px;color:#fbbf24">جدولِ سرورِ فیزیکی هنوز روی این سرور ساخته نشده. پس از اجرای مهاجرت فعال می‌شود.</p>
  @elseif($servers->isEmpty())
    <p style="padding:16px;color:var(--dim)">هنوز سروری اضافه نکرده‌اید. با «افزودنِ سرور» شروع کنید.</p>
  @else
    <table class="ad-table">
      <thead><tr><th>مدل</th><th>برند</th><th>وضعیت</th><th>عکس</th><th>قیمت</th><th>نمایش</th><th></th></tr></thead>
      <tbody>
        @foreach($servers as $s)
        @php $b = $brands[$s->brand] ?? ['label' => $s->brand, 'color' => 'var(--cyan)']; @endphp
        <tr>
          <td>
            <b>{{ $s->name['fa'] ?? $s->slug }}</b>
            <small dir="ltr" style="color:var(--dim);display:block">{{ $s->slug }}</small>
          </td>
          <td><span class="ad-badge" style="background:{{ $b['color'] }}1f;color:{{ $b['color'] }}">{{ $b['label'] }}</span></td>
          <td>{{ $s->condition === 'refurb' ? 'بازسازی‌شده' : 'نو' }}@if($s->popular)<span class="ad-badge" style="background:rgba(251,191,36,.14);color:#fbbf24;margin-inline-start:6px">پرفروش</span>@endif</td>
          <td>{{ count($s->gallery ?? []) }}</td>
          <td dir="ltr">
            @if($s->price_contact)<span style="color:var(--dim)">استعلام</span>
            @else{{ $s->price_irt ? number_format($s->price_irt).'﷼' : '—' }}@endif
          </td>
          <td>
            @if($s->active)<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">فعال</span>
            @else<span class="ad-badge" style="background:rgba(148,163,184,.12);color:var(--muted)">مخفی</span>@endif
          </td>
          <td style="white-space:nowrap">
            <a href="/admin/server-shop/{{ $s->id }}/edit" style="color:#22d3ee;font-size:12.5px">ویرایش</a>
            <form method="post" action="/admin/server-shop/{{ $s->id }}/delete" style="display:inline;margin-inline-start:10px" data-confirm="سرورِ «{{ $s->name['fa'] ?? $s->slug }}» حذف شود؟" data-confirm-danger>
              @csrf<button class="ad-linkbtn" style="color:#ff6b6b;font-size:12.5px;background:none;border:0;cursor:pointer;padding:0">حذف</button>
            </form>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>

@endsection
