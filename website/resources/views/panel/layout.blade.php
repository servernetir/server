{{--
  پوستهٔ پنل کاربری.
  از همان layouts/site.blade.php ارث می‌برد تا هدر، فوتر، فونت و تم یکی باشند —
  پنل باید ادامهٔ سایت حس شود، نه یک محصول جدا.
--}}
@extends('layouts.site')

@push('head')
<link rel="stylesheet" href="{{ asset('assets/css/panel.css') }}?v={{ filemtime(public_path('assets/css/panel.css')) }}">
@endpush

@section('content')
<section class="pnl-wrap">
  <div class="container">
    {{-- نوار موبایل: فقط زیر ۱۰۰۰px دیده می‌شود --}}
    <div class="pnl-mobar">
      <div class="pnl-mobar-me">
        @include('panel.avatar', ['user' => $pnlUser])
        <b>{{ $pnlUser['name'] ?? '' }}</b>
      </div>
      <button class="pnl-menu-btn" id="pnl-menu" aria-label="منو">
        <svg class="icon"><use href="#i-list"/></svg>
      </button>
    </div>

    <div class="pnl-layout">

      {{-- محتوا اول در DOM، سایدبار دوم: کاربر صفحه‌کلید زودتر به کار اصلی می‌رسد --}}
      <div class="pnl-main">
        @yield('panel')
      </div>

      <aside class="pnl-side">
        <div class="pnl-card">

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

    <div class="pnl-drawer-bd" id="pnl-bd"></div>
  </div>
</section>

<script>
(function () {
  var btn = document.getElementById('pnl-menu'),
      side = document.querySelector('.pnl-side'),
      bd = document.getElementById('pnl-bd');
  if (!btn || !side) return;
  function toggle(open){ side.classList.toggle('open', open); bd.classList.toggle('open', open); }
  btn.addEventListener('click', function(){ toggle(!side.classList.contains('open')); });
  bd.addEventListener('click', function(){ toggle(false); });
  // با کلیک روی هر لینک منو، کشو بسته شود
  side.querySelectorAll('a').forEach(function(a){ a.addEventListener('click', function(){ toggle(false); }); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') toggle(false); });
})();

</script>
@include('partials.ui-dialog')
@endsection
