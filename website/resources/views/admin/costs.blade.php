@extends('admin.layout')
@section('title', 'هزینه‌های سرویس‌ها')
@section('nav_costs', 'on')
@section('content')

@if(session('ok'))<div class="ad-note ok">{{ session('ok') }}</div>@endif

<div class="ad-panel">
  <div class="ad-panel-h"><h2>هزینه‌های ثابت سرویس‌ها</h2></div>
  <p style="padding:0 18px;color:#96a3ba;font-size:13.5px;line-height:1.9">
    این اعداد را <b style="color:#e7edf7">خودتان</b> تعیین می‌کنید. هر بار که سیستم یک استعلام
    می‌زند یا پیامکی می‌فرستد، دفتر مالی همین مبلغ را به‌عنوان هزینه ثبت می‌کند. تا وقتی مبلغی
    را ننوشته‌اید، آن هزینه در گزارش‌ها وارد نمی‌شود (نه اینکه با عددِ حدسی پر شود).
  </p>

  @if($notReady)
    <p style="padding:18px;color:#fbbf24">جدول هزینه‌ها روی این سرور هنوز ساخته نشده. پس از اجرای مهاجرت این‌جا فعال می‌شود.</p>
  @else
  <form method="post" action="/admin/costs">
    @csrf
    <table class="ad-table">
      <thead><tr><th>سرویس</th><th style="width:200px">هزینهٔ هر بار (تومان)</th><th>توضیح</th><th></th></tr></thead>
      <tbody>
        @foreach($costs as $cost)
        <tr>
          <td>
            <b>{{ $cost->label }}</b>
            @if($cost->is_system)<small style="color:#5f6c82;display:block" dir="ltr">{{ $cost->key }}</small>@endif
          </td>
          <td>
            <input type="number" name="amount[{{ $cost->id }}]" value="{{ $cost->amount }}" min="0" step="1000"
                   dir="ltr" style="width:170px;background:#0f1522;border:1px solid #1e2637;border-radius:8px;color:#e7edf7;padding:7px 10px;font:inherit;text-align:left">
          </td>
          <td>
            <input type="text" name="note[{{ $cost->id }}]" value="{{ $cost->note }}" placeholder="—"
                   style="width:100%;background:#0f1522;border:1px solid #1e2637;border-radius:8px;color:#96a3ba;padding:7px 10px;font:inherit">
          </td>
          <td class="ad-row-act">
            @unless($cost->is_system)
              <button form="del-{{ $cost->id }}" class="del" type="submit" onclick="return confirm('حذف این هزینه؟')">حذف</button>
            @endunless
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
    <div style="padding:16px;display:flex;justify-content:flex-end">
      <button class="btn btn-primary" type="submit"><svg class="icon"><use href="#i-check"/></svg>ذخیرهٔ همه</button>
    </div>
  </form>

  {{-- فرم‌های حذف جدا، چون داخل فرم اصلی nest نمی‌شوند --}}
  @foreach($costs as $cost)
    @unless($cost->is_system)
      <form id="del-{{ $cost->id }}" method="post" action="/admin/costs/{{ $cost->id }}/delete" style="display:none">@csrf</form>
    @endunless
  @endforeach
  @endif
</div>

{{-- ══ افزودن هزینهٔ دلخواه (لایسنس، اجاره، …) ══ --}}
@unless($notReady)
<div class="ad-panel">
  <div class="ad-panel-h"><h3>افزودن هزینهٔ ثابت جدید</h3></div>
  <form method="post" action="/admin/costs/add" style="padding:16px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    @csrf
    <label style="display:flex;flex-direction:column;gap:5px;font-size:13px;color:#96a3ba">عنوان
      <input type="text" name="label" required placeholder="مثلاً لایسنس ماهانه cPanel" style="background:#0f1522;border:1px solid #1e2637;border-radius:8px;color:#e7edf7;padding:8px 12px;font:inherit;min-width:240px"></label>
    <label style="display:flex;flex-direction:column;gap:5px;font-size:13px;color:#96a3ba">مبلغ (تومان)
      <input type="number" name="amount" required min="0" step="1000" dir="ltr" style="background:#0f1522;border:1px solid #1e2637;border-radius:8px;color:#e7edf7;padding:8px 12px;font:inherit;width:160px;text-align:left"></label>
    <label style="display:flex;flex-direction:column;gap:5px;font-size:13px;color:#96a3ba">توضیح
      <input type="text" name="note" style="background:#0f1522;border:1px solid #1e2637;border-radius:8px;color:#e7edf7;padding:8px 12px;font:inherit;min-width:200px"></label>
    <button class="btn btn-primary" type="submit"><svg class="icon"><use href="#i-plus"/></svg>افزودن</button>
  </form>
</div>
@endunless

@endsection
