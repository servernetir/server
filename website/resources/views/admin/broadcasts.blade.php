@extends('admin.layout')
@section('title', 'اعلان به مشتریان')
@section('nav_broadcasts', 'on')
@section('content')

@if($errors->any())<div class="ad-note err">{{ $errors->first() }}</div>@endif

@if($notReady)
  <div class="ad-panel"><p style="padding:20px;color:#fbbf24">جدول‌های لازم روی این سرور هنوز ساخته نشده. پس از اجرای مهاجرت فعال می‌شود.</p></div>
@else

<div class="ad-panel">
  <div class="ad-panel-h"><h2>ارسال اعلان جدید</h2></div>
  <p style="padding:0 18px;color:var(--muted);font-size:13.5px;line-height:1.9">
    اعلان هم‌زمان از <b style="color:var(--text)">پیامک</b> و <b style="color:var(--text)">بله</b> برای مشتری می‌رود.
    ارسال گروهی هزینهٔ پیامک دارد و بازگشت‌ناپذیر است — پیش از ارسال تعداد گیرنده را می‌بینید.
  </p>

  <form method="post" action="/admin/broadcasts" style="padding:16px" id="bc-form" data-confirm="اعلان برای مخاطب انتخاب‌شده ارسال شود؟ (پیامک هزینه دارد)" data-confirm-title="ارسال اعلان" data-confirm-ok="بله، بفرست">
    @csrf
    <div style="margin-bottom:14px">
      <label style="font-size:13px;color:var(--muted);display:block;margin-bottom:8px">مخاطب</label>
      <div class="bc-aud">
        <label><input type="radio" name="audience" value="all" {{ $preselect ? '' : 'checked' }}><span>همهٔ مشتریان</span><i>{{ fa_num($counts['all']) }}</i></label>
        <label><input type="radio" name="audience" value="active"><span>مشتریان فعال</span><i>{{ fa_num($counts['active']) }}</i></label>
        <label><input type="radio" name="audience" value="verified"><span>احرازشده‌ها</span><i>{{ fa_num($counts['verified']) }}</i></label>
        <label><input type="radio" name="audience" value="one" {{ $preselect ? 'checked' : '' }}><span>یک مشتری خاص</span><i>ID</i></label>
      </div>
    </div>

    <div id="bc-one" style="margin-bottom:14px;{{ $preselect ? '' : 'display:none' }}">
      <label style="font-size:13px;color:var(--muted);display:block;margin-bottom:6px">شناسهٔ مشتری (id عددی)</label>
      <input type="number" name="customer_id" value="{{ $preselect }}" dir="ltr"
             style="background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:8px 12px;font:inherit;width:200px;text-align:left">
      <small style="color:var(--dim);display:block;margin-top:5px">از پروندهٔ هر مشتری دکمهٔ «ارسال اعلان» این را خودکار پر می‌کند.</small>
    </div>

    <div style="margin-bottom:14px">
      <label style="font-size:13px;color:var(--muted);display:block;margin-bottom:6px">عنوان (اختیاری)</label>
      <input type="text" name="title" value="{{ old('title') }}" maxlength="120"
             style="width:100%;background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:9px 12px;font:inherit">
    </div>

    <div style="margin-bottom:14px">
      <label style="font-size:13px;color:var(--muted);display:block;margin-bottom:6px">متن پیام</label>
      <textarea name="body" required maxlength="1000" rows="4"
                style="width:100%;background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:10px 12px;font:inherit;line-height:1.9;resize:vertical">{{ old('body') }}</textarea>
    </div>

    <div style="display:flex;justify-content:flex-end">
      <button class="btn btn-primary" type="submit">
        <svg class="icon"><use href="#i-send"/></svg>ارسال اعلان
      </button>
    </div>
  </form>
</div>

{{-- ══ تاریخچه ══ --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h3>اعلان‌های اخیر</h3></div>
  @if($history->isEmpty())
    <p style="padding:16px;color:var(--dim)">هنوز اعلانی ارسال نشده.</p>
  @else
    <table class="ad-table">
      <thead><tr><th>متن</th><th>مخاطب</th><th>گیرنده</th><th>توسط</th><th>زمان</th></tr></thead>
      <tbody>
        @foreach($history as $b)
        <tr>
          <td>{{ $b->title ? $b->title.' — ' : '' }}{{ Str::limit($b->body, 60) }}</td>
          <td>{{ $b->audienceLabel() }}@if($b->customer) <small style="color:var(--dim)" dir="ltr">{{ $b->customer->code }}</small>@endif</td>
          <td>{{ fa_num($b->recipients) }}</td>
          <td style="color:var(--muted)">{{ $b->sender?->name ?? '—' }}</td>
          <td dir="ltr" style="color:var(--muted)">{{ stime($b->created_at) }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>

<style>
.bc-aud{ display:grid; grid-template-columns:repeat(4,1fr); gap:10px }
.bc-aud label{ display:flex; flex-direction:column; gap:4px; padding:12px; background:var(--surface2); border:1px solid var(--line); border-radius:10px; cursor:pointer; position:relative }
.bc-aud label:has(input:checked){ border-color:#22d3ee; background:rgba(34,211,238,.06) }
.bc-aud input{ position:absolute; opacity:0 }
.bc-aud span{ font-size:13.5px; color:var(--text) }
.bc-aud i{ font-style:normal; font-size:12px; color:var(--dim); font-variant-numeric:tabular-nums }
@media(max-width:760px){ .bc-aud{ grid-template-columns:repeat(2,1fr) } }
</style>

<script>
document.querySelectorAll('input[name=audience]').forEach(function(r){
  r.addEventListener('change', function(){
    document.getElementById('bc-one').style.display = (this.value === 'one') ? '' : 'none';
  });
});
</script>
@endif
@endsection
