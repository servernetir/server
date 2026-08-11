{{--
  تبِ هزینه‌ها — از منوی «مالی» به این‌جا آمد.

  ⚠️ فرم‌های این تب به `/admin/costs` POST می‌کنند، نه به `/admin/settings`.
  عمدی است: هزینه‌ها جدولِ خودشان را دارند و اعتبارسنجیِ گروهی‌شان با کلیدهای
  `Setting` هیچ اشتراکی ندارد.
--}}

<div class="ad-panel">
  <div class="ad-panel-h"><h2>هزینه‌های ثابت سرویس‌ها</h2></div>
  <p class="set-lead">
    این اعداد را <b style="color:var(--text)">خودتان</b> تعیین می‌کنید. هر بار که سیستم یک استعلام
    می‌زند یا پیامکی می‌فرستد، دفتر مالی همین مبلغ را به‌عنوان هزینه ثبت می‌کند. تا وقتی مبلغی
    را ننوشته‌اید، آن هزینه در گزارش‌ها وارد نمی‌شود (نه اینکه با عددِ حدسی پر شود).
  </p>

  @if($costsNotReady)
    <p style="padding:18px;color:#fbbf24">جدول هزینه‌ها روی این سرور هنوز ساخته نشده. پس از اجرای مهاجرت این‌جا فعال می‌شود.</p>
  @else
    <form method="post" action="/admin/costs">
      @csrf
      <div style="padding:0 18px">
        <table class="ad-table">
          <thead><tr><th>سرویس</th><th style="width:200px">هزینهٔ هر بار (تومان)</th><th>توضیح</th><th></th></tr></thead>
          <tbody>
            @foreach($costs as $cost)
            <tr>
              <td data-sort="{{ $cost->label }}">
                <b>{{ $cost->label }}</b>
                @if($cost->is_system)<small style="color:var(--dim);display:block" dir="ltr">{{ $cost->key }}</small>@endif
              </td>
              {{-- ⚠️ `data-sort` لازم است: خانه‌ای که فقط `<input>` دارد متنِ
                   قابلِ خواندن ندارد، پس مرتب‌سازیِ عمومیِ جدول‌ها همهٔ ردیف‌ها
                   را برابر می‌دید و ستون بی‌اثر می‌شد. --}}
              <td data-sort="{{ $cost->amount }}">
                <input type="number" name="amount[{{ $cost->id }}]" value="{{ $cost->amount }}" min="0" step="1"
                       dir="ltr" class="set-cell" style="width:170px;text-align:left">
              </td>
              <td data-sort="{{ $cost->note }}">
                <input type="text" name="note[{{ $cost->id }}]" value="{{ $cost->note }}" placeholder="—"
                       class="set-cell" style="width:100%">
              </td>
              <td class="ad-row-act">
                @unless($cost->is_system)
                  <button form="del-{{ $cost->id }}" class="del" type="submit">حذف</button>
                @endunless
              </td>
            </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div style="padding:16px 18px;display:flex;justify-content:flex-end">
        <button class="btn btn-primary" type="submit"><svg class="icon"><use href="#i-check"/></svg>ذخیرهٔ همه</button>
      </div>
    </form>

    {{-- فرم‌های حذف جدا، چون داخل فرم اصلی nest نمی‌شوند --}}
    @foreach($costs as $cost)
      @unless($cost->is_system)
        <form id="del-{{ $cost->id }}" method="post" action="/admin/costs/{{ $cost->id }}/delete" style="display:none"
              data-confirm="حذف این هزینه؟" data-confirm-danger>@csrf</form>
      @endunless
    @endforeach
  @endif
</div>

@unless($costsNotReady)
<div class="ad-panel">
  <div class="ad-panel-h"><h3>افزودن هزینهٔ ثابت جدید</h3></div>
  <form method="post" action="/admin/costs/add" style="padding:0 18px 18px;display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    @csrf
    <label class="set-f">عنوان
      <input type="text" name="label" required placeholder="مثلاً لایسنس ماهانه cPanel" style="min-width:240px"></label>
    <label class="set-f">مبلغ (تومان)
      <input type="number" name="amount" required min="0" step="1" dir="ltr" style="width:160px;text-align:left"></label>
    <label class="set-f">توضیح
      <input type="text" name="note" style="min-width:200px"></label>
    <button class="btn btn-primary" type="submit"><svg class="icon"><use href="#i-plus"/></svg>افزودن</button>
  </form>
</div>
@endunless
