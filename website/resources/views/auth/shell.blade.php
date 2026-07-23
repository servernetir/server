{{--
  پوستهٔ صفحات ورود و ثبت‌نام.

  عمداً از پوستهٔ سایت ارث می‌برد (هدر، فوتر، فونت) ولی محتوا را در یک کارت
  باریک وسط می‌گذارد. کاربر باید حس کند هنوز در سرورنت است، نه در یک سرویس
  جدا — این حس اعتماد در لحظه‌ای که قرار است کد ملی بدهد، مهم است.
--}}
@extends('layouts.site')

@push('head')
<link rel="stylesheet" href="{{ asset('assets/css/auth.css') }}?v={{ filemtime(public_path('assets/css/auth.css')) }}">
<meta name="robots" content="noindex,follow">
@endpush

@section('content')
<section class="auth-wrap">
  <div class="container">
    <div class="auth-card">

      @hasSection('steps')
        <ol class="auth-steps" aria-label="مراحل ثبت‌نام">
          @yield('steps')
        </ol>
      @endif

      <header class="auth-head">
        <h1>@yield('heading')</h1>
        @hasSection('sub')<p>@yield('sub')</p>@endif
      </header>

      @if(session('ok'))
        <div class="auth-note ok">{{ session('ok') }}</div>
      @endif

      @if($errors->any())
        <div class="auth-note bad">
          @foreach($errors->all() as $e)<span>{{ $e }}</span>@endforeach
        </div>
      @endif

      @yield('form')

    </div>

    @hasSection('aside')
      <p class="auth-alt">@yield('aside')</p>
    @endif
  </div>
</section>
@endsection
