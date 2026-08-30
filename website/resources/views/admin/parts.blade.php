@extends('admin.layout')
@section('title', 'قطعاتِ سرور')
@section('nav_parts', 'on')
@section('content')

<div class="ad-panel">
  <div class="ad-panel-h" style="display:flex;justify-content:space-between;align-items:center;gap:12px">
    <h2>فروشگاهِ قطعاتِ سرور</h2>
    <a href="/admin/parts/create" class="btn btn-primary" style="font-size:13px"><svg class="icon"><use href="#i-plus"/></svg>افزودنِ قطعه</a>
  </div>

  <p style="padding:0 18px;color:var(--muted);font-size:13.5px;line-height:1.9">
    قطعاتی که در <a href="/parts" target="_blank" dir="ltr" style="color:#22d3ee">/parts</a> فروخته می‌شوند.
    قیمتِ مبنا <b>یورو</b> است و صفحهٔ فارسی با نرخِ همین لحظهٔ تنظیماتِ سایت تومان نشان می‌دهد؛
    en/tr همان یورو را می‌بینند.
    @if($eurRate > 0)
      نرخِ فعلی: <b dir="ltr">۱ € = {{ fa_num(number_format($eurRate)) }} تومان</b>.
    @else
      {{-- ⚠️ نرخ که نباشد قیمتِ تومانی اصلاً رندر نمی‌شود؛ مدیر باید بداند چرا. --}}
      <b style="color:#fbbf24">نرخِ یورو در دسترس نیست — تا رفعش، صفحاتِ فارسی «استعلام قیمت» نشان می‌دهند.</b>
    @endif
  </p>

  @if($notReady)
    <p style="padding:18px;color:#fbbf24">جدولِ قطعات هنوز روی این سرور ساخته نشده. پس از اجرای مهاجرت فعال می‌شود.</p>
  @else
    <form method="get" action="/admin/parts" style="display:flex;gap:8px;flex-wrap:wrap;padding:0 18px 14px">
      <select name="category" class="ad-input" style="max-width:210px" onchange="this.form.submit()">
        <option value="">همهٔ دسته‌ها</option>
        @foreach($categories as $key => $c)
          <option value="{{ $key }}" @selected($category === $key)>{{ $c['fa'] }}</option>
        @endforeach
      </select>
      <input type="search" name="q" value="{{ $q }}" placeholder="جستجو در نام، شناسه یا برند" class="ad-input" style="max-width:280px">
      <button class="btn" style="font-size:13px">جستجو</button>
      @if($category || $q !== '')
        <a href="/admin/parts" style="align-self:center;color:#ff6b6b;font-size:12.5px">حذفِ فیلتر</a>
      @endif
    </form>

    @if($parts->isEmpty())
      <p style="padding:16px;color:var(--dim)">
        @if($category || $q !== '') با این فیلترها قطعه‌ای نیست. @else هنوز قطعه‌ای اضافه نکرده‌اید. @endif
      </p>
    @else
      <table class="ad-table">
        <thead><tr><th>قطعه</th><th>دسته</th><th>نسل</th><th>وضعیت</th><th>قیمت (یورو)</th><th>موجودی</th><th>نمایش</th><th></th></tr></thead>
        <tbody>
          @foreach($parts as $p)
          <tr>
            <td>
              <b>{{ $p->name['fa'] ?? $p->slug }}</b>
              <small dir="ltr" style="color:var(--dim);display:block">{{ $p->slug }} · {{ $p->brand }}</small>
            </td>
            <td>{{ $categories[$p->category]['fa'] ?? $p->category }}</td>
            <td dir="ltr" style="font-size:12px;color:var(--muted)">
              {{ $p->compat_gens ? implode(' ', array_map(fn ($g) => str_replace('gen', 'Gen', $g), $p->compat_gens)) : 'همه' }}
            </td>
            <td>
              {{ ['new' => 'نو', 'refurb' => 'بازسازی‌شده', 'used' => 'کارکرده'][$p->condition] ?? $p->condition }}
              @if($p->popular)<span class="ad-badge" style="background:rgba(251,191,36,.14);color:#fbbf24;margin-inline-start:6px">پرفروش</span>@endif
            </td>
            <td dir="ltr">
              @if($p->price_contact)
                <span style="color:var(--dim)">استعلام</span>
              @elseif($p->price_eur)
                €{{ number_format($p->price_eur / 100, 2) }}
                {{-- override تومانی را جدا نشان می‌دهیم؛ وگرنه مدیر نمی‌فهمد چرا
                     صفحهٔ فارسی عددِ دیگری دارد. --}}
                @if($p->price_irt)<small style="display:block;color:#fbbf24">override: {{ number_format($p->price_irt) }} ت</small>@endif
              @else
                <span style="color:#fbbf24">بی‌قیمت</span>
              @endif
            </td>
            <td>
              @if($p->in_stock)<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">موجود</span>
              @else<span class="ad-badge" style="background:rgba(148,163,184,.12);color:var(--muted)">ناموجود</span>@endif
            </td>
            <td>
              @if($p->active)<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">فعال</span>
              @else<span class="ad-badge" style="background:rgba(148,163,184,.12);color:var(--muted)">مخفی</span>@endif
            </td>
            <td style="white-space:nowrap">
              <a href="/admin/parts/{{ $p->id }}/edit" style="color:#22d3ee;font-size:12.5px">ویرایش</a>
              <form method="post" action="/admin/parts/{{ $p->id }}/delete" style="display:inline;margin-inline-start:10px" data-confirm="قطعهٔ «{{ $p->name['fa'] ?? $p->slug }}» حذف شود؟" data-confirm-danger>
                @csrf<button class="ad-linkbtn" style="color:#ff6b6b;font-size:12.5px;background:none;border:0;cursor:pointer;padding:0">حذف</button>
              </form>
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
      <p style="padding:12px 18px;color:var(--dim);font-size:12.5px">{{ fa_num($parts->count()) }} قطعه</p>
    @endif
  @endif
</div>

@endsection
