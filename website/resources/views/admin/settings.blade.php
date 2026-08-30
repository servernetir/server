@extends('admin.layout')
@section('title', 'تنظیمات — '.$tabs[$tab]['t'])
@section('nav_settings', 'on')
@section('content')

{{--
  پوستهٔ تنظیمات. تب‌ها **سمتِ سرور** انتخاب می‌شوند (`?tab=`)، پس فقط فیلدهای
  همان تب در DOM هستند و `back()` بعد از ذخیره به همان تب برمی‌گردد.

  ⚠️ هر تب فرمِ خودش را دارد و یک `<input type="hidden" name="tab">` می‌فرستد.
  آن فیلد تزئینی نیست: کنترلر فقط کلیدهای همان تب را می‌نویسد، وگرنه ذخیرهٔ یک
  تب تنظیماتِ تب‌های دیگر را بی‌صدا پاک می‌کند. توضیحِ کامل بالای SettingsController.
--}}

<div class="ad-tabs set-tabs">
  @foreach($tabs as $key => $meta)
    <a href="/admin/settings?tab={{ $key }}" class="{{ $tab === $key ? 'on' : '' }}">
      <svg class="icon"><use href="#{{ $meta['icon'] }}"/></svg>{{ $meta['t'] }}
    </a>
  @endforeach
</div>

@if($notReady && ! in_array($tab, ['guide'], true))
  <div class="ad-panel">
    <p style="padding:18px;color:#fbbf24">
      جدول تنظیمات روی این سرور هنوز ساخته نشده. پس از اجرای مهاجرت فعال می‌شود.
    </p>
  </div>
@else
  @include('admin.settings.'.$tab)
@endif

@endsection
