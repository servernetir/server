@php
  /** @var \App\Models\Product|null $product */
  $isEdit = $product !== null;
  $specsRaw = $isEdit && $product->specs
    ? collect($product->specs)->map(fn ($s) => trim(($s['label'] ?? '').': '.($s['value'] ?? '')))->implode("\n")
    : '';
@endphp
<form method="post" action="{{ $action }}" class="srv-f">
  @csrf
  <label>نام پکیج
    <input type="text" name="name" value="{{ old('name', $product->name ?? '') }}" required maxlength="150" placeholder="هاست لینوکس اقتصادی">
  </label>
  <label>دسته
    <select name="category">
      @foreach(\App\Models\Product::CATEGORIES as $v => $t)
        <option value="{{ $v }}" @selected(old('category', $product->category ?? 'shared') === $v)>{{ $t }}</option>
      @endforeach
    </select>
  </label>
  <label>سرور تحویل (اختیاری)
    <select name="server_id">
      <option value="">— بدون تحویل خودکار —</option>
      @foreach($servers as $srv)
        <option value="{{ $srv->id }}" @selected((int) old('server_id', $product->server_id ?? 0) === $srv->id)>{{ $srv->name }} ({{ $srv->typeLabel() }})</option>
      @endforeach
    </select>
  </label>
  <label>پکیج WHM (plan)
    <input type="text" name="plan" dir="ltr" value="{{ old('plan', $product->plan ?? '') }}" maxlength="80" placeholder="مثلاً WP-5">
  </label>
  <label>قیمت دوره‌ای (تومان)
    <input type="number" name="price" dir="ltr" value="{{ old('price', $product->price ?? 0) }}" required min="0" step="1000">
  </label>
  <label>هزینهٔ راه‌اندازی (تومان، اختیاری)
    <input type="number" name="setup_fee" dir="ltr" value="{{ old('setup_fee', $product->setup_fee ?? 0) }}" min="0" step="1000">
  </label>
  <label>دوره
    <select name="cycle">
      @foreach(['monthly'=>'ماهانه','quarterly'=>'سه‌ماهه','yearly'=>'سالانه','once'=>'یک‌بار'] as $v => $t)
        <option value="{{ $v }}" @selected(old('cycle', $product->cycle ?? 'monthly') === $v)>{{ $t }}</option>
      @endforeach
    </select>
  </label>
  <label>مالیات (٪)
    <input type="number" name="tax_percent" dir="ltr" value="{{ old('tax_percent', $product->tax_percent ?? 10) }}" min="0" max="100">
  </label>
  <label class="col2">مشخصات (هر خط: «برچسب: مقدار»)
    <textarea name="specs_raw" rows="4" placeholder="فضا: ۵ گیگابایت NVMe&#10;پهنای باند: نامحدود&#10;دیتابیس: ۱۰">{{ old('specs_raw', $specsRaw) }}</textarea>
  </label>
  <label class="col2">توضیحات (اختیاری)
    <input type="text" name="description" value="{{ old('description', $product->description ?? '') }}" maxlength="2000">
  </label>
  <label style="grid-column:auto"><input type="number" name="sort" dir="ltr" value="{{ old('sort', $product->sort ?? 0) }}" min="0" style="width:80px"> ترتیب</label>
  <label class="chk"><input type="checkbox" name="requires_domain" value="1" @checked(old('requires_domain', $product->requires_domain ?? false))> نیاز به دامنه</label>
  <label class="chk"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $product->is_active ?? true))> فعال (در فروشگاه دیده شود)</label>
  <div class="col2" style="display:flex;justify-content:flex-end">
    <button type="submit" class="btn btn-primary"><svg class="icon"><use href="#i-check"/></svg>{{ $isEdit ? 'ذخیرهٔ تغییرات' : 'افزودن پکیج' }}</button>
  </div>
</form>
