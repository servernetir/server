@extends('admin.layout')
@section('title', 'درگاه هوش مصنوعی — افزودنِ مدل')
@section('nav_ai_models', 'on')
@section('content')

{{--
  ساختِ مدل + بهای ارائه‌دهنده در یک فرم. بها به **دلار/یورو به ازای ۱M توکن** تایپ
  می‌شود (همان عددی که در صفحهٔ قیمتِ ارائه‌دهنده دیده می‌شود، مثلاً 0.23)، نه میکرو-واحد؛
  تبدیل در کنترلر از رشته و بی float انجام می‌شود. مدلِ تازه فروختنی نیست تا پرچم‌های
  ارائه‌دهنده، سربارِ ارز و حاشیه تنظیم شوند — پیش‌نمایشِ بعد از ثبت همین را می‌گوید.
--}}
<div class="ad-panel">
  <div class="ad-panel-h">
    <h2>افزودنِ مدل</h2>
    <a href="/admin/ai/models" class="btn btn-glass" style="font-size:13px">بازگشت</a>
  </div>

  @if($errors->any())
    <p style="padding:6px 18px;color:#f87171;font-size:13px">{{ $errors->first() }}</p>
  @endif

  @if($providers->isEmpty())
    <p style="padding:16px;color:var(--dim)">هیچ ارائه‌دهنده‌ای ثبت نشده؛ مدل بی‌ارائه‌دهنده ساخته نمی‌شود.</p>
  @else
  <form method="post" action="/admin/ai/models" style="padding:14px 18px;display:grid;gap:12px;max-width:760px">
    @csrf

    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted);min-width:220px">ارائه‌دهنده
        <select name="ai_provider_id" class="ad-input" style="padding:8px" required>
          @foreach($providers as $p)
            <option value="{{ $p->id }}" @selected((int) old('ai_provider_id', $providers->first()->id) === $p->id)>{{ $p->name }} ({{ $p->billing_currency_code }})</option>
          @endforeach
        </select>
      </label>
      <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">دسته
        <select name="category" class="ad-input" style="padding:8px">
          @foreach(['chat' => 'چت', 'embedding' => 'امبِدینگ', 'image' => 'تصویر', 'audio' => 'صدا', 'rerank' => 'رِیتِنگ'] as $val => $label)
            <option value="{{ $val }}" @selected(old('category', 'chat') === $val)>{{ $label }}</option>
          @endforeach
        </select>
      </label>
      <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">وضعیت
        <select name="status" class="ad-input" style="padding:8px">
          @foreach(['active' => 'فعال', 'disabled' => 'خاموش', 'deprecated' => 'ازکارافتاده'] as $val => $label)
            <option value="{{ $val }}" @selected(old('status', 'active') === $val)>{{ $label }}</option>
          @endforeach
        </select>
      </label>
    </div>

    <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">نامِ نمایشی
      <input type="text" name="name" maxlength="120" value="{{ old('name') }}" class="ad-input" style="padding:8px" required placeholder="مثلاً Llama 3.3 70B">
    </label>

    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted);flex:1;min-width:240px">شناسهٔ عمومی (اسلاگ) — همانی که مشتری در `model` می‌فرستد
        <input type="text" name="slug" maxlength="80" dir="ltr" value="{{ old('slug') }}" class="ad-input" style="padding:8px" required placeholder="llama-3.3-70b">
        <small style="color:var(--dim)">حروفِ کوچکِ لاتین، رقم و . _ / : - ؛ بعداً عوض نشود.</small>
      </label>
      <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted);flex:1;min-width:240px">شناسهٔ مدل نزدِ ارائه‌دهنده
        <input type="text" name="upstream_model" maxlength="120" dir="ltr" value="{{ old('upstream_model') }}" class="ad-input" style="padding:8px" required placeholder="meta-llama/Llama-3.3-70B-Instruct">
      </label>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">سازنده
        <input type="text" name="vendor" maxlength="60" value="{{ old('vendor') }}" class="ad-input" style="padding:8px" placeholder="Meta">
      </label>
      <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">پنجرهٔ کانتکست
        <input type="number" name="context_tokens" min="0" value="{{ old('context_tokens') }}" class="ad-input" style="padding:8px">
      </label>
      <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">سقفِ خروجی
        <input type="number" name="max_output_tokens" min="0" value="{{ old('max_output_tokens') }}" class="ad-input" style="padding:8px">
      </label>
      @if($hasMarginColumn)
      <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">حاشیهٔ اختصاصی (٪)
        <input type="text" inputmode="decimal" name="margin_pct" dir="ltr" maxlength="6" value="{{ old('margin_pct') }}" class="ad-input" style="padding:8px"
               placeholder="{{ $globalMarginBp !== null ? 'خالی = سراسری ('.\App\Services\Ai\AiPricing::bpToPercent($globalMarginBp).'٪)' : 'خالی = سراسری (تنظیم نشده)' }}">
      </label>
      @endif
    </div>

    <fieldset style="border:1px solid var(--line, rgba(148,163,184,.2));border-radius:10px;padding:10px 14px">
      <legend style="font-size:12.5px;color:var(--muted);padding:0 6px">بهای ارائه‌دهنده — به ارزِ ارائه‌دهنده، به ازای ۱٬۰۰۰٬۰۰۰ توکن (اختیاری؛ بعداً هم از صفحهٔ قیمت ثبت می‌شود)</legend>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        @foreach(['price_input' => 'ورودی', 'price_cached' => 'ورودیِ کش‌شده', 'price_output' => 'خروجی'] as $f => $label)
          <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">{{ $label }}
            <input type="text" inputmode="decimal" name="{{ $f }}" dir="ltr" maxlength="16" value="{{ old($f) }}" class="ad-input" style="padding:8px" placeholder="{{ $f === 'price_output' ? '0.40' : ($f === 'price_cached' ? '0.115' : '0.23') }}">
            @error($f)<small style="color:#f87171">{{ $message }}</small>@enderror
          </label>
        @endforeach
      </div>
      <small style="color:var(--dim)">تا ۶ رقمِ اعشار. «ورودیِ کش‌شده» را خالی بگذارید اگر ارائه‌دهنده جدا اعلامش نکرده — آن‌وقت به قیمتِ کاملِ ورودی فروخته می‌شود، هرگز ارزان‌تر.</small>
    </fieldset>

    <div>
      <button class="btn btn-primary">ساختِ مدل و دیدنِ قیمت</button>
    </div>
  </form>
  @endif
</div>
@endsection
