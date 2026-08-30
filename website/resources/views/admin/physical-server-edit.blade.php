@extends('admin.layout')
@section('title', $server ? 'ویرایشِ سرورِ فیزیکی' : 'افزودنِ سرورِ فیزیکی')
@section('nav_server_shop', 'on')
@section('content')

@php
  /** @var \App\Models\PhysicalServer|null $server */
  $g = fn (string $col, string $lang, $def = '') => old($col.'_'.$lang, data_get($server, $col.'.'.$lang, $def));
  // فهرست‌ها (قوت/ضعف) خط‌به‌خط ویرایش می‌شوند
  $gl = fn (string $col, string $lang) => old($col.'_'.$lang, $server ? implode("\n", (array) data_get($server, $col.'.'.$lang, [])) : '');
  $specs = old('spec_label_fa') !== null
    ? null   // اگر اعتبارسنجی برگشت، از old پر می‌شود (پایین)
    : ($server->specs ?? []);
@endphp

<div class="ad-panel">
  <div class="ad-panel-h" style="display:flex;justify-content:space-between;align-items:center;gap:12px">
    <h2>{{ $server ? 'ویرایشِ '.($server->name['fa'] ?? $server->slug) : 'افزودنِ سرورِ فیزیکی' }}</h2>
    <a href="/admin/server-shop" style="color:var(--dim);font-size:13px">← بازگشت به فهرست</a>
  </div>

  <form method="post" action="{{ $action }}" enctype="multipart/form-data" class="psf" style="padding:6px 18px 20px">
    @csrf

    {{-- ══ پایه ══ --}}
    <h3 class="psf-h">اطلاعاتِ پایه</h3>
    <div class="psf-grid">
      <label>شناسه (در URL)
        <input type="text" name="slug" dir="ltr" required maxlength="80" pattern="[a-z0-9-]+"
               value="{{ old('slug', $server->slug ?? '') }}" placeholder="hpe-proliant-dl380-gen10">
      </label>
      <label>برند
        <select name="brand">
          @foreach($brands as $key => $b)
            <option value="{{ $key }}" @selected(old('brand', $server->brand ?? 'hp') === $key)>{{ $b['label'] }}</option>
          @endforeach
        </select>
      </label>
      <label>وضعیت
        <select name="condition">
          <option value="new" @selected(old('condition', $server->condition ?? 'new') === 'new')>نو</option>
          <option value="refurb" @selected(old('condition', $server->condition ?? 'new') === 'refurb')>بازسازی‌شده</option>
        </select>
      </label>
      <label>ترتیبِ نمایش
        <input type="number" name="sort" dir="ltr" min="0" max="99999" value="{{ old('sort', $server->sort ?? 0) }}">
      </label>
    </div>
    <div style="display:flex;gap:22px;flex-wrap:wrap;margin:8px 0 4px">
      <label class="psf-chk"><input type="checkbox" name="popular" value="1" @checked(old('popular', $server->popular ?? false))> پرفروش (نشانِ ویژه)</label>
      <label class="psf-chk"><input type="checkbox" name="active" value="1" @checked(old('active', $server->active ?? true))> فعال (در سایت دیده شود)</label>
    </div>

    {{-- ══ قیمت ══ --}}
    <h3 class="psf-h">قیمت</h3>
    <label class="psf-chk"><input type="checkbox" name="price_contact" id="psf-contact" value="1" @checked(old('price_contact', $server->price_contact ?? true))> «تماس برای استعلام» (بدونِ نمایشِ عدد)</label>
    <div class="psf-grid" id="psf-price" style="margin-top:8px">
      <label>قیمتِ پایه (تومان)
        <input type="number" name="price_irt" dir="ltr" min="0" step="1000" value="{{ old('price_irt', $server->price_irt ?? '') }}" placeholder="مثلاً ۸۵۰۰۰۰۰۰">
      </label>
      <label>قیمتِ یورو (سنت — برای en/tr)
        <input type="number" name="price_eur" dir="ltr" min="0" value="{{ old('price_eur', $server->price_eur ?? '') }}" placeholder="مثلاً ۱۲۰۰ = ۱۲€">
      </label>
    </div>

    {{-- ══ متنِ سه‌زبانه ══ --}}
    <h3 class="psf-h">نام و توضیحات (سه‌زبانه)</h3>
    @foreach(['name' => ['نام', 'input'], 'tag' => ['شعارِ کوتاه', 'input'], 'hero_d' => ['توضیحِ هرو (کوتاه)', 'area'], 'desc' => ['توضیحِ بلند (سئو)', 'bigarea']] as $col => $meta)
      @php [$title, $kind] = $meta; $req = $col === 'name'; @endphp
      <div class="psf-lang">
        <span class="psf-lang-t">{{ $title }}@if($req)<b style="color:#ff6b6b"> *</b>@endif</span>
        <div class="psf-lang-g">
          @foreach(['fa' => 'فارسی', 'en' => 'English', 'tr' => 'Türkçe'] as $l => $ph)
            @php $dir = $l === 'fa' ? 'rtl' : 'ltr'; $name = ($col === 'desc' ? 'desc' : $col).'_'.$l; @endphp
            @if($kind === 'input')
              <input type="text" name="{{ $name }}" dir="{{ $dir }}" @if($req) required @endif maxlength="200" placeholder="{{ $ph }}" value="{{ $g($col === 'desc' ? 'description' : $col, $l) }}">
            @elseif($kind === 'area')
              <textarea name="{{ $name }}" dir="{{ $dir }}" rows="2" maxlength="600" placeholder="{{ $ph }}">{{ $g($col, $l) }}</textarea>
            @else
              <textarea name="{{ $name }}" dir="{{ $dir }}" rows="4" maxlength="6000" placeholder="{{ $ph }}">{{ $g('description', $l) }}</textarea>
            @endif
          @endforeach
        </div>
      </div>
    @endforeach

    {{-- ══ محتوای غنیِ صفحه (سئو) ══ --}}
    <h3 class="psf-h">محتوای غنیِ صفحه (سئو) <small style="color:var(--dim);font-weight:400">— اختیاری ولی برای دیده‌شدن در گوگل توصیه می‌شود</small></h3>
    <div class="psf-lang">
      <span class="psf-lang-t">بدنهٔ بلند و تحلیلی — هر پاراگراف با یک خطِ خالی جدا شود</span>
      <div class="psf-lang-g">
        <textarea name="body_fa" dir="rtl" rows="6" maxlength="12000" placeholder="فارسی — کاربردها، قدرت، جایگاه…">{{ $g('body', 'fa') }}</textarea>
        <textarea name="body_en" dir="ltr" rows="6" maxlength="12000" placeholder="English">{{ $g('body', 'en') }}</textarea>
        <textarea name="body_tr" dir="ltr" rows="6" maxlength="12000" placeholder="Türkçe">{{ $g('body', 'tr') }}</textarea>
      </div>
    </div>
    <div class="psf-lang">
      <span class="psf-lang-t">نقاطِ قوت — هر خط یک مورد</span>
      <div class="psf-lang-g">
        <textarea name="strengths_fa" dir="rtl" rows="4" maxlength="4000" placeholder="مثلاً: مقرون‌به‌صرفه&#10;iLO کامل">{{ $gl('strengths', 'fa') }}</textarea>
        <textarea name="strengths_en" dir="ltr" rows="4" maxlength="4000" placeholder="one per line">{{ $gl('strengths', 'en') }}</textarea>
        <textarea name="strengths_tr" dir="ltr" rows="4" maxlength="4000" placeholder="her satır bir madde">{{ $gl('strengths', 'tr') }}</textarea>
      </div>
    </div>
    <div class="psf-lang">
      <span class="psf-lang-t">نکاتِ قابلِ توجه / ضعف — هر خط یک مورد</span>
      <div class="psf-lang-g">
        <textarea name="weaknesses_fa" dir="rtl" rows="3" maxlength="4000" placeholder="مثلاً: مصرفِ برقِ بالاتر">{{ $gl('weaknesses', 'fa') }}</textarea>
        <textarea name="weaknesses_en" dir="ltr" rows="3" maxlength="4000" placeholder="one per line">{{ $gl('weaknesses', 'en') }}</textarea>
        <textarea name="weaknesses_tr" dir="ltr" rows="3" maxlength="4000" placeholder="her satır bir madde">{{ $gl('weaknesses', 'tr') }}</textarea>
      </div>
    </div>

    {{-- ══ مشخصاتِ فنی ══ --}}
    <h3 class="psf-h">مشخصاتِ فنی <small style="color:var(--dim);font-weight:400">— هر ردیف: برچسب + مقدار در سه زبان</small></h3>
    <datalist id="psf-labels">
      @foreach(\App\Models\PhysicalServer::SPEC_LABELS as $lbl)
        <option value="{{ $lbl['fa'] }}" data-en="{{ $lbl['en'] }}" data-tr="{{ $lbl['tr'] }}">
      @endforeach
    </datalist>
    <div id="psf-specs">
      @php
        $rows = $specs;
        if ($rows === null) {   // بازگشت از اعتبارسنجی
            $rows = [];
            foreach ((array) old('spec_label_fa', []) as $i => $_) {
                $rows[] = ['label' => ['fa' => old('spec_label_fa')[$i] ?? '', 'en' => old('spec_label_en')[$i] ?? '', 'tr' => old('spec_label_tr')[$i] ?? ''],
                           'fa' => old('spec_val_fa')[$i] ?? '', 'en' => old('spec_val_en')[$i] ?? '', 'tr' => old('spec_val_tr')[$i] ?? ''];
            }
        }
      @endphp
      @forelse($rows as $r)
        @include('admin.partials.spec-row', ['r' => $r])
      @empty
        @include('admin.partials.spec-row', ['r' => null])
      @endforelse
    </div>
    <button type="button" class="btn btn-glass" id="psf-add-spec" style="font-size:12.5px;margin-top:6px"><svg class="icon"><use href="#i-plus"/></svg>افزودنِ ردیف</button>

    {{-- ══ گالری ══ --}}
    <h3 class="psf-h">گالریِ تصاویر</h3>
    @if($server && !empty($server->gallery))
      <div class="psf-imgs">
        @foreach($server->gallery as $img)
          <label class="psf-img">
            <img src="{{ $img }}" alt="">
            <span><input type="checkbox" name="remove_images[]" value="{{ $img }}"> حذف</span>
          </label>
        @endforeach
      </div>
    @endif
    <label style="display:block;margin-top:8px">افزودنِ عکس (JPG/PNG/WebP — چندتایی مجاز)
      <input type="file" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
    </label>
    <p style="color:var(--dim);font-size:12px;margin-top:4px">اگر عکسی نباشد، سایت خودکار یک تصویرِ جای‌گزین (آیکونِ سرور) نشان می‌دهد — صفحه هیچ‌وقت خالی نیست.</p>

    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:18px">
      <a href="/admin/server-shop" class="btn btn-glass">انصراف</a>
      <button type="submit" class="btn btn-primary"><svg class="icon"><use href="#i-check"/></svg>{{ $server ? 'ذخیرهٔ تغییرات' : 'افزودنِ سرور' }}</button>
    </div>
  </form>
</div>
@endsection

@section('scripts')
<style>
  .psf-h{margin:20px 0 8px;font-size:14.5px;color:var(--text);border-top:1px solid var(--line);padding-top:14px}
  .psf-h:first-of-type{border-top:0;padding-top:0}
  .psf-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
  .psf label{display:block;font-size:12.5px;color:var(--muted)}
  .psf input,.psf select,.psf textarea{width:100%;margin-top:4px;background:var(--surface2);border:1px solid var(--line);border-radius:9px;padding:9px 11px;color:var(--text);font-family:inherit;font-size:13px}
  .psf textarea{resize:vertical}
  .psf-chk{display:inline-flex;align-items:center;gap:7px;font-size:13px;color:var(--text);cursor:pointer}
  .psf-chk input{width:auto;margin:0}
  .psf-lang{margin-bottom:12px}
  .psf-lang-t{display:block;font-size:12.5px;color:var(--muted);margin-bottom:5px}
  .psf-lang-g{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
  .psf-spec{display:grid;grid-template-columns:1.1fr 1.4fr auto;gap:8px;align-items:start;margin-bottom:8px;padding:8px;background:var(--surface2);border:1px solid var(--line);border-radius:10px}
  .psf-spec .psf-col{display:flex;flex-direction:column;gap:5px}
  .psf-spec .psf-col small{font-size:11px;color:var(--dim)}
  .psf-spec input{margin-top:0}
  .psf-spec-del{background:none;border:0;color:#ff6b6b;cursor:pointer;font-size:20px;line-height:1;padding:4px 6px}
  .psf-imgs{display:flex;flex-wrap:wrap;gap:10px}
  .psf-img{width:120px;font-size:12px;color:var(--muted);text-align:center}
  .psf-img img{width:120px;height:80px;object-fit:cover;border-radius:8px;border:1px solid var(--line);display:block;margin-bottom:4px}
  .psf-img span{display:inline-flex;gap:5px;align-items:center;cursor:pointer}
  @media(max-width:720px){.psf-lang-g{grid-template-columns:1fr}.psf-spec{grid-template-columns:1fr}}
</style>
<script>
(function(){
  // نمایش/مخفیِ فیلدهای قیمت وقتی «تماس برای استعلام» فعال است
  var contact = document.getElementById('psf-contact'), priceBox = document.getElementById('psf-price');
  function sync(){ priceBox.style.display = contact.checked ? 'none' : ''; }
  if (contact && priceBox) { contact.addEventListener('change', sync); sync(); }

  // افزودنِ ردیفِ مشخصات: اولین ردیف را الگو می‌گیریم و خالی کلون می‌کنیم
  var wrap = document.getElementById('psf-specs'), addBtn = document.getElementById('psf-add-spec');
  function bindRow(row){
    var del = row.querySelector('.psf-spec-del');
    if (del) del.addEventListener('click', function(){
      if (wrap.querySelectorAll('.psf-spec').length > 1) row.remove();
      else row.querySelectorAll('input').forEach(function(i){ i.value=''; });
    });
    // پرکردنِ خودکارِ برچسبِ en/tr وقتی برچسبِ fa از فهرست انتخاب شد
    var fa = row.querySelector('[name="spec_label_fa[]"]');
    if (fa) fa.addEventListener('change', function(){
      var opt = document.querySelector('#psf-labels option[value="'+fa.value+'"]');
      if (!opt) return;
      var en = row.querySelector('[name="spec_label_en[]"]'), tr = row.querySelector('[name="spec_label_tr[]"]');
      if (en && !en.value) en.value = opt.getAttribute('data-en') || '';
      if (tr && !tr.value) tr.value = opt.getAttribute('data-tr') || '';
    });
  }
  if (wrap) wrap.querySelectorAll('.psf-spec').forEach(bindRow);
  if (addBtn && wrap){
    addBtn.addEventListener('click', function(){
      var first = wrap.querySelector('.psf-spec');
      var clone = first.cloneNode(true);
      clone.querySelectorAll('input').forEach(function(i){ i.value=''; });
      wrap.appendChild(clone);
      bindRow(clone);
    });
  }
})();
</script>
@endsection
