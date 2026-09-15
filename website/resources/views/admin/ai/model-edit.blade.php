@extends('admin.layout')
@section('title', 'دروازهٔ AI — ویرایشِ مدل')
@section('nav_ai_models', 'on')
@section('content')

<div class="ad-panel">
  <div class="ad-panel-h">
    <h2>ویرایشِ مدل: <span dir="ltr">{{ $model->slug }}</span></h2>
    <a href="/admin/ai/models" class="btn btn-glass" style="font-size:13px">بازگشت</a>
  </div>
  <p style="padding:0 18px;color:var(--muted);font-size:13px;line-height:1.9">
    ارائه‌دهنده: <b dir="ltr">{{ $model->provider->name ?? '—' }}</b> · شناسهٔ بومیِ
    <span dir="ltr">{{ $model->upstream_model }}</span>
    · <a href="/admin/ai/pricing?model={{ $model->id }}" style="color:#22d3ee">قیمتِ این مدل</a>
  </p>

  @if($errors->any())
    <p style="padding:6px 18px;color:#f87171;font-size:13px">{{ $errors->first() }}</p>
  @endif

  <form method="post" action="/admin/ai/models/{{ $model->id }}" style="padding:14px 18px;display:grid;gap:12px;max-width:720px">
    @csrf
    <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">نامِ نمایشی
      <input type="text" name="name" value="{{ old('name', $model->name) }}" class="ad-input" style="padding:8px" required>
    </label>

    <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">شرح
      <textarea name="description" rows="3" class="ad-input">{{ old('description', $model->description) }}</textarea>
    </label>

    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">وضعیت
        <select name="status" class="ad-input" style="padding:8px">
          @foreach(['active' => 'فعال', 'disabled' => 'خاموش', 'deprecated' => 'ازکارافتاده'] as $val => $label)
            <option value="{{ $val }}" @selected(old('status', $model->status) === $val)>{{ $label }}</option>
          @endforeach
        </select>
      </label>
      <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">دسته
        <select name="category" class="ad-input" style="padding:8px">
          @foreach(['chat' => 'چت', 'embedding' => 'امبِدینگ', 'image' => 'تصویر', 'audio' => 'صدا', 'rerank' => 'رِیتِنگ'] as $val => $label)
            <option value="{{ $val }}" @selected(old('category', $model->category) === $val)>{{ $label }}</option>
          @endforeach
        </select>
      </label>
      <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">مدلِ جایگزین (id)
        <input type="number" name="replacement_model_id" min="0" value="{{ old('replacement_model_id', $model->replacement_model_id) }}" class="ad-input" style="padding:8px">
      </label>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">پنجرهٔ کانتکست
        <input type="number" name="context_tokens" min="0" value="{{ old('context_tokens', $model->context_tokens) }}" class="ad-input" style="padding:8px">
      </label>
      <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">سقفِ خروجی
        <input type="number" name="max_output_tokens" min="0" value="{{ old('max_output_tokens', $model->max_output_tokens) }}" class="ad-input" style="padding:8px">
      </label>
      <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">اولویتِ ارائه‌دهنده
        <input type="number" name="provider_priority" min="0" max="65535" value="{{ old('provider_priority', $model->provider_priority) }}" class="ad-input" style="padding:8px">
      </label>
    </div>

    <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">مستندات (URL)
      <input type="url" name="docs_url" dir="ltr" value="{{ old('docs_url', $model->docs_url) }}" class="ad-input" style="padding:8px">
    </label>

    <label style="display:flex;gap:6px;align-items:center;font-size:13px">
      <input type="checkbox" name="claude_code_compatible" value="1" @checked(old('claude_code_compatible', $model->claude_code_compatible))>
      سازگار با Claude Code
    </label>

    <div>
      <button class="btn btn-primary">ثبت</button>
    </div>
  </form>
</div>
@endsection
