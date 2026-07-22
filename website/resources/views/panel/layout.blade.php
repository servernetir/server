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
    <div class="pnl-layout">

      {{-- محتوا اول در DOM، سایدبار دوم: کاربر صفحه‌کلید زودتر به کار اصلی می‌رسد --}}
      <div class="pnl-main">
        @yield('panel')
      </div>

      <aside class="pnl-side">
        <div class="pnl-card">

          <div class="pnl-me">
            <span class="pnl-avatar">{{ mb_substr($pnlUser['name'] ?? '؟', 0, 1) }}</span>
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

        </div>
      </aside>

    </div>
  </div>
</section>
@endsection
