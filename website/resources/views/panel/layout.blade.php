{{--
  پوستهٔ پنل کاربری.
  از همان layouts/site.blade.php ارث می‌برد تا هدر، فوتر، فونت و تم یکی باشند —
  پنل باید ادامهٔ سایت حس شود، نه یک محصول جدا.
--}}
@extends('layouts.site')

@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/panel.css') }}">
@endpush

@section('content')
{{-- نوارِ «جای مشتری نشسته‌اید» اینجا نیست — به partials/header.blade.php منتقل
     شد تا در صفحاتِ سایتِ اصلی هم دیده شود و زیرِ هدرِ ثابت نرود.
     تاریخ‌نگاری: نسخهٔ قبلی همین‌جا sticky با top صفر و z-indexِ ۱۲۰ بود و چون
     هدرِ سایت fixed با top صفر و z-indexِ ۲۰۰ است، کاملاً پشتِ هدر می‌افتاد —
     نه دیده می‌شد و نه کلیک را می‌گرفت؛ مدیر راهِ بازگشت نداشت.
     نگهبانش: tests/Feature/ImpersonateBannerTest.php --}}
<section class="pnl-wrap">
  <div class="container">
    {{-- نوار موبایل: فقط زیر ۱۰۰۰px دیده می‌شود --}}
    <div class="pnl-mobar">
      <div class="pnl-mobar-me">
        @include('panel.avatar', ['user' => $pnlUser])
        <b>{{ $pnlUser['name'] ?? '' }}</b>
      </div>
      <button type="button" class="pnl-menu-btn" id="pnl-menu" aria-label="{{ __('ui.menu') }}" aria-controls="pnl-side" aria-expanded="false">
        <svg class="icon"><use href="#i-list"/></svg>
      </button>
    </div>

    <div class="pnl-layout">

      {{-- محتوا اول در DOM، سایدبار دوم: کاربر صفحه‌کلید زودتر به کار اصلی می‌رسد --}}
      <div class="pnl-main">
        @yield('panel')
      </div>

      <aside class="pnl-side" id="pnl-side" tabindex="-1" aria-label="{{ __('ui.menu') }}">
        <div class="pnl-card">
          <button type="button" class="pnl-nav-close" id="pnl-close">{{ __('ui.close') }}</button>

          <div class="pnl-me">
            @include('panel.avatar', ['user' => $pnlUser])
            <span class="pnl-me-t">
              <b>{{ $pnlUser['name'] ?? '' }}</b>
              <small dir="ltr">{{ $pnlUser['code'] ?? '' }}</small>
            </span>
          </div>


          <nav class="pnl-nav">
            @foreach($pnlNav as $group)
              @if(!empty($group['label']))
                <div class="pnl-nav-sep">{{ $group['label'] }}</div>
              @endif
              @foreach($group['items'] as $item)
                <a href="{{ $item['url'] ?? '#' }}" class="{{ ($item['key'] ?? '') === ($pnlActive ?? '') ? 'on' : '' }}">
                  <svg class="icon"><use href="#i-{{ $item['icon'] }}"/></svg>
                  <span>{{ $item['label'] }}</span>
                  @if(!empty($item['badge']))<em>{{ fa_num($item['badge']) }}</em>@endif
                </a>
              @endforeach
            @endforeach
          </nav>

          {{-- خروج: تا امروز هیچ راهی برای بیرون آمدن از حساب نبود.
               POST و نه لینک — خروج با GET یعنی هر تصویر یا لینکی در یک
               سایت دیگر می‌تواند کاربر را بی‌اجازه بیرون بیندازد. --}}
          @auth('customer')
            <form method="POST" action="{{ lroute('logout') }}" class="pnl-signout">
              @csrf
              <button type="submit">
                <svg class="icon"><use href="#i-arrow"/></svg>
                <span>{{ __('ui.auth_logout') }}</span>
              </button>
            </form>
          @endauth

        </div>
      </aside>

    </div>

    <div class="pnl-drawer-bd" id="pnl-bd" aria-hidden="true"></div>
  </div>
</section>

<script src="{{ asset_ver('assets/js/panel-navigation.js') }}" defer></script>
@include('partials.ui-dialog')
@endsection
