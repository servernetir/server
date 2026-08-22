@extends('admin.layout')
@section('title', $part ? 'ویرایشِ قطعه' : 'افزودنِ قطعه')
@section('nav_parts', 'on')
@section('content')

@php
  /** @var \App\Models\ServerPart|null $part */
  $g = fn (string $col, string $lang, $def = '') => old($col.'_'.$lang, data_get($part, $col.'.'.$lang, $def));
  $cats = \App\Models\ServerPart::CATEGORIES;
  $attrs = \App\Models\ServerPart::ATTR_LABELS;
  $gens = (array) config('hp_generations', []);
  $picked = old('gens', $part?->compat_gens ?? []);

  /*
  | ⚠️ اگر اعتبارسنجی برگشته باشد، مشخصات باید از `old()` بیاید نه از مدل —
  | وگرنه مدیر یک ردیف اضافه می‌کند، فرم به‌خاطر خطای دیگری برمی‌گردد، و آن
  | ردیف بی‌سروصدا ناپدید می‌شود.
  */
  $specLabels = old('spec_label_fa');
  $specRows = $specLabels !== null
      ? array_map(fn ($i) => [
            'label' => ['fa' => $specLabels[$i] ?? '', 'en' => old('spec_label_en')[$i] ?? '', 'tr' => old('spec_label_tr')[$i] ?? ''],
            'value' => ['fa' => old('spec_val_fa')[$i] ?? '', 'en' => old('spec_val_en')[$i] ?? '', 'tr' => old('spec_val_tr')[$i] ?? ''],
        ], array_keys($specLabels))
      : (array) ($part->specs ?? []);
@endphp

<div class="ad-panel">
  <div class="ad-panel-h" style="display:flex;justify-content:space-between;align-items:center;gap:12px">
    <h2>{{ $part ? 'ویرایشِ '.($part->name['fa'] ?? $part->slug) : 'افزودنِ قطعه' }}</h2>
    <a href="/admin/parts" style="color:var(--dim);font-size:13px">← بازگشت به فهرست</a>
  </div>

{{-- 🔴 `enctype` بی‌آن، فایل اصلاً به سرور نمی‌رسد و `$request->file()`
     خالی برمی‌گردد — بی‌هیچ خطایی. فرم ذخیره می‌شود و عکس نیست. --}}
  <form method="post" action="{{ $action }}" enctype="multipart/form-data" class="psf" style="padding:6px 18px 20px">
    @csrf

    {{-- ══ پایه ══ --}}
    <h3 class="psf-h">اطلاعاتِ پایه</h3>
    <div class="psf-grid">
      <label>شناسه (در URL)
        <input type="text" name="slug" dir="ltr" required maxlength="100" pattern="[a-z0-9-]+"
               value="{{ old('slug', $part->slug ?? '') }}" placeholder="xeon-e5-2680-v4">
      </label>
      <label>دسته
        <select name="category" required>
          @foreach($cats as $key => $c)
            <option value="{{ $key }}" @selected(old('category', $part->category ?? 'cpu') === $key)>{{ $c['fa'] }}</option>
          @endforeach
        </select>
      </label>
      <label>برند
        <input type="text" name="brand" dir="ltr" required maxlength="40" value="{{ old('brand', $part->brand ?? 'HPE') }}">
      </label>
      <label>وضعیت
        @php $cond = old('condition', $part->condition ?? 'refurb'); @endphp
        <select name="condition">
          <option value="new" @selected($cond === 'new')>نو</option>
          <option value="refurb" @selected($cond === 'refurb')>بازسازی‌شده</option>
          <option value="used" @selected($cond === 'used')>کارکرده</option>
        </select>
      </label>
      <label>ترتیبِ نمایش
        <input type="number" name="sort" dir="ltr" min="0" max="99999" value="{{ old('sort', $part->sort ?? 0) }}">
      </label>
    </div>

    <div style="display:flex;gap:22px;flex-wrap:wrap;margin:8px 0 4px">
      <label class="psf-chk"><input type="checkbox" name="in_stock" value="1" @checked(old('in_stock', $part->in_stock ?? true))> موجود</label>
      <label class="psf-chk"><input type="checkbox" name="popular" value="1" @checked(old('popular', $part->popular ?? false))> پرفروش</label>
      <label class="psf-chk"><input type="checkbox" name="active" value="1" @checked(old('active', $part->active ?? true))> فعال (در سایت دیده شود)</label>
    </div>

    {{-- ══ سازگاری ══ --}}
    <h3 class="psf-h">سازگاری با نسل</h3>
    <p style="color:var(--muted);font-size:12.5px;line-height:1.9;margin-bottom:8px">
      هیچ‌کدام را نزنید ⇒ قطعه «عمومی» است و در <b>همهٔ</b> نسل‌ها دیده می‌شود (مثلِ کدی یا ریلِ رک).
    </p>
    <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:6px">
      @foreach($gens as $key => $gen)
        <label class="psf-chk">
          <input type="checkbox" name="gens[]" value="{{ $key }}" @checked(in_array($key, (array) $picked, true))>
          {{ $gen['name']['fa'] ?? $key }}
        </label>
      @endforeach
    </div>

    {{-- ══ قیمت ══ --}}
    <h3 class="psf-h">قیمت</h3>
    <label class="psf-chk"><input type="checkbox" name="price_contact" value="1" @checked(old('price_contact', $part->price_contact ?? true))> «استعلام قیمت» (بدونِ نمایشِ عدد)</label>
    <div class="psf-grid" style="margin-top:8px">
      <label>قیمت به <b>سنتِ یورو</b>
        <input type="number" name="price_eur" dir="ltr" min="0" step="1"
               value="{{ old('price_eur', $part->price_eur ?? '') }}" placeholder="۳۴۰۰ یعنی ۳۴٫۰۰ یورو">
      </label>
      <label>override تومانی (اختیاری)
        <input type="number" name="price_irt" dir="ltr" min="0" step="10000"
               value="{{ old('price_irt', $part->price_irt ?? '') }}" placeholder="خالی بگذارید مگر قیمتِ داخلی ثابت دارید">
      </label>
    </div>
    <p style="color:var(--muted);font-size:12.5px;line-height:1.9;margin-top:6px">
      یورو مبنا است: صفحهٔ فارسی با نرخِ لحظه‌ای تومان می‌سازد، en/tr همان یورو را نشان می‌دهند.
      override تومانی <b>فقط روی صفحهٔ فارسی</b> اثر دارد — برای قطعه‌ای که از بازارِ داخلی می‌خرید
      و قیمتش به نرخِ ارز وصل نیست.
    </p>

    {{-- ══ متنِ سه‌زبانه ══ --}}
    @foreach(['fa' => 'فارسی', 'en' => 'انگلیسی', 'tr' => 'ترکی'] as $l => $lname)
      <h3 class="psf-h">متنِ {{ $lname }}</h3>
      <div class="psf-grid">
        <label>نام *
          <input type="text" name="name_{{ $l }}" required maxlength="180" @if($l !== 'fa') dir="ltr" @endif value="{{ $g('name', $l) }}">
        </label>
        <label>یک‌خطی (زیرِ نام)
          <input type="text" name="tag_{{ $l }}" maxlength="250" @if($l !== 'fa') dir="ltr" @endif value="{{ $g('tagline', $l) }}">
        </label>
      </div>
      <label style="display:block;margin-top:8px">خلاصه (توضیحِ متا و بالای صفحه)
        <textarea name="sum_{{ $l }}" rows="2" maxlength="900" @if($l !== 'fa') dir="ltr" @endif>{{ $g('summary', $l) }}</textarea>
      </label>
      <label style="display:block;margin-top:8px">متنِ کامل (سئو)
        <textarea name="body_{{ $l }}" rows="6" maxlength="12000" @if($l !== 'fa') dir="ltr" @endif>{{ $g('body', $l) }}</textarea>
      </label>
    @endforeach

    {{-- ══ عکس ══ --}}
    <h3 class="psf-h">عکس محصول</h3>
    <p style="color:var(--muted);font-size:12.5px;line-height:1.9;margin-bottom:8px">
      عکس واقعی همان قطعه‌ای که می‌فروشید بهترین حالت است. اولین عکس روی کارت فهرست می‌نشیند.
      فرمت‌های مجاز: JPG، PNG، WebP — حداکثر ۵ مگابایت.
    </p>

    @if($part && ($shots = (array) ($part->gallery ?? [])))
      <div class="pe-shots">
        @foreach($shots as $shot)
          <label class="pe-shot">
            <img src="{{ $shot }}" alt="" loading="lazy">
            <span><input type="checkbox" name="remove_images[]" value="{{ $shot }}"> حذف</span>
          </label>
        @endforeach
      </div>
    @endif

    <input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple class="ad-input">

    {{-- ══ ویژگی‌های عددی ══ --}}
    <h3 class="psf-h">ویژگی‌های عددی (برای فیلتر و جدولِ مقایسه)</h3>
    <p style="color:var(--muted);font-size:12.5px;line-height:1.9;margin-bottom:8px">
      فقط آن‌هایی را پر کنید که به این قطعه ربط دارد؛ بقیه را خالی بگذارید.
      جدولِ مقایسه ردیفی را که همهٔ ستون‌هایش خالی است اصلاً نشان نمی‌دهد.
    </p>
    <div class="psf-grid">
      @foreach($attrs as $key => $a)
        <label>{{ $a['fa'] }}@if($a['unit']) <small style="color:var(--dim)">({{ $a['unit'] }})</small>@endif
          <input type="number" step="any" dir="ltr" name="attr_{{ $key }}"
                 value="{{ old('attr_'.$key, data_get($part, 'attrs.'.$key, '')) }}">
        </label>
      @endforeach
    </div>

    {{-- ══ مشخصاتِ متنی ══ --}}
    <h3 class="psf-h">جدولِ مشخصات</h3>
    <div id="pe-specs">
      @foreach(array_values($specRows) as $row)
        <div class="pe-spec-row">
          <input type="text" name="spec_label_fa[]" placeholder="برچسب (فارسی)" value="{{ data_get($row, 'label.fa') }}">
          <input type="text" name="spec_label_en[]" dir="ltr" placeholder="Label (EN)" value="{{ data_get($row, 'label.en') }}">
          <input type="text" name="spec_label_tr[]" dir="ltr" placeholder="Etiket (TR)" value="{{ data_get($row, 'label.tr') }}">
          <input type="text" name="spec_val_fa[]" placeholder="مقدار (فارسی)" value="{{ data_get($row, 'value.fa') }}">
          <input type="text" name="spec_val_en[]" dir="ltr" placeholder="Value (EN)" value="{{ data_get($row, 'value.en') }}">
          <input type="text" name="spec_val_tr[]" dir="ltr" placeholder="Değer (TR)" value="{{ data_get($row, 'value.tr') }}">
        </div>
      @endforeach
      <div class="pe-spec-row">
        <input type="text" name="spec_label_fa[]" placeholder="برچسب (فارسی)">
        <input type="text" name="spec_label_en[]" dir="ltr" placeholder="Label (EN)">
        <input type="text" name="spec_label_tr[]" dir="ltr" placeholder="Etiket (TR)">
        <input type="text" name="spec_val_fa[]" placeholder="مقدار (فارسی)">
        <input type="text" name="spec_val_en[]" dir="ltr" placeholder="Value (EN)">
        <input type="text" name="spec_val_tr[]" dir="ltr" placeholder="Değer (TR)">
      </div>
    </div>
    <button type="button" id="pe-add-spec" class="btn" style="font-size:12.5px;margin-top:8px">+ ردیفِ مشخصات</button>

    <div style="margin-top:20px;display:flex;gap:10px;align-items:center">
      <button class="btn btn-primary">{{ $part ? 'ذخیرهٔ تغییرات' : 'افزودنِ قطعه' }}</button>
      @if($part)
        <a href="{{ lroute('parts.show', [$part->category, $part->slug]) }}" target="_blank" style="color:#22d3ee;font-size:12.5px">دیدن در سایت ↗</a>
      @endif
    </div>
  </form>
</div>

<style>
.pe-shots{ display:flex; flex-wrap:wrap; gap:10px; margin-bottom:10px; }
.pe-shot{ display:block; width:120px; }
.pe-shot img{ width:120px; height:90px; object-fit:cover; border-radius:8px; border:1px solid var(--line); display:block; }
.pe-shot span{ display:flex; align-items:center; gap:5px; font-size:11.5px; color:var(--muted); margin-top:5px; cursor:pointer; }
.pe-spec-row{ display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-bottom:6px; }
.pe-spec-row input{ width:100%; }
@media(max-width:760px){ .pe-spec-row{ grid-template-columns:1fr; } }
</style>

<script>
(function () {
  var box = document.getElementById('pe-specs');
  var btn = document.getElementById('pe-add-spec');
  if (!box || !btn) return;

  btn.addEventListener('click', function () {
    var last = box.lastElementChild;
    if (!last) return;
    var row = last.cloneNode(true);
    row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    box.appendChild(row);
    var first = row.querySelector('input');
    if (first) first.focus();
  });
})();
</script>

@endsection
