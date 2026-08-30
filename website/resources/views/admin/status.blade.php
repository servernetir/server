@extends('admin.layout')
@section('title', 'صفحهٔ وضعیت')
@section('nav_status', 'active')
@section('content')

<div class="ad-panel">
  <div class="ad-panel-h"><h2>اعلام اختلال</h2></div>

  <p style="padding:0 18px 14px;color:var(--muted);font-size:13px;line-height:1.9">
    هرچه این‌جا ثبت کنید بلافاصله در <a href="/status" target="_blank" style="color:#22d3ee">صفحهٔ وضعیت</a>
    دیده می‌شود.
    <br>در اختلال واقعی، گران‌ترین چیز سکوت است: مشتری از جای دیگری خبردار می‌شود،
    پشتیبانی زیر بار تیکت تکراری می‌رود، و بعداً هیچ ثبتی از رویداد نداریم که به آن استناد کنیم.
    <b>یک خط زودهنگام بهتر از یک گزارش کامل با دو ساعت تأخیر است.</b>
  </p>

  @if(session('ok'))<div class="ad-flash ok" style="margin:0 18px 14px">{{ session('ok') }}</div>@endif
  @if($errors->any())
    <div class="ad-flash err" style="margin:0 18px 14px">
      @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
  @endif

  <form method="post" action="/admin/status" style="padding:0 18px 18px">
    @csrf
    <div class="ad-field">
      <label>عنوان — همان چیزی که مشتری اول می‌بیند</label>
      <input class="ad-input" type="text" name="title" required maxlength="200"
             value="{{ old('title') }}" placeholder="اختلال در دسترسی به سرورهای تهران">
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 18px">
      <div class="ad-field">
        <label>شدت</label>
        <select class="ad-input" name="impact" required>
          @foreach($impacts as $k => $label)
            <option value="{{ $k }}" @selected(old('impact', 'minor') === $k)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="ad-field">
        <label>مرحله</label>
        <select class="ad-input" name="state" required>
          @foreach($states as $k => $label)
            <option value="{{ $k }}" @selected(old('state', 'investigating') === $k)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
    </div>

    <div class="ad-field">
      <label>مکان‌های درگیر — خالی یعنی همه‌جا</label>
      <select class="ad-input" name="locations[]" multiple size="4">
        @foreach($locations as $l)
          <option value="{{ $l->code }}">{{ $l->label('fa') }}</option>
        @endforeach
      </select>
    </div>

    <div class="ad-field">
      <label>توضیح برای مشتری</label>
      <textarea class="ad-input" name="body" rows="3" maxlength="4000">{{ old('body') }}</textarea>
      <small style="color:var(--muted);font-size:11.5px">
        ساده و بی‌اصطلاح فنی. چه چیزی کار نمی‌کند، از کِی، و کِی خبر بعدی می‌دهید.
      </small>
    </div>

    <button class="btn btn-primary" style="font-size:13px">اعلام کن</button>
  </form>
</div>

@foreach([['اختلال‌های باز', $open, true], ['تاریخچه', $past, false]] as [$heading, $rows, $editable])
  @if($rows->isNotEmpty())
  <div class="ad-panel" style="margin-top:16px">
    <div class="ad-panel-h"><h3>{{ $heading }}</h3></div>
    <div style="padding:0 18px 18px">
      @foreach($rows as $inc)
        <form method="post" action="/admin/status/{{ $inc->id }}"
              style="border:1px solid var(--line2);border-radius:11px;padding:14px;margin-bottom:12px">
          @csrf
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px">
            <span class="ad-badge" style="background:{{ $inc->color() }}22;color:{{ $inc->color() }}">{{ $inc->impactLabel() }}</span>
            <b style="font-size:13.5px">{{ $inc->title }}</b>
            <small style="color:var(--dim)">{{ sdate($inc->started_at) }}</small>
          </div>
          @if($editable)
            <input type="hidden" name="title" value="{{ $inc->title }}">
            <input type="hidden" name="impact" value="{{ $inc->impact }}">
            <input type="hidden" name="body" value="{{ $inc->body }}">
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
              <select class="ad-input" name="state" style="max-width:230px">
                @foreach($states as $k => $label)
                  <option value="{{ $k }}" @selected($inc->state === $k)>{{ $label }}</option>
                @endforeach
              </select>
              <button class="btn btn-glass" style="font-size:12.5px">به‌روزرسانی</button>
            </div>
          @else
            <small style="color:var(--muted)">{{ $inc->stateLabel() }} · برطرف شد {{ sdate($inc->resolved_at) }}</small>
          @endif
        </form>
      @endforeach
    </div>
  </div>
  @endif
@endforeach
@endsection
