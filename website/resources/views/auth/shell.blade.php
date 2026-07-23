{{--
  پوستهٔ صفحه‌های ورود و ثبت‌نام.

  چیدمان: عنوان صفحه وسط و بیرون از قاب (زیر منوی سایت)، بعد یک قاب دو ستونه —
  فرم در ستون شروع، «ریل اطمینان» در ستون پایان. ریل دو کار می‌کند: می‌گوید
  کجای مسیریم، و می‌گوید با اطلاعاتی که می‌دهید چه می‌کنیم. دومی مهم‌تر است:
  کاربر قرار است کد ملی بدهد و باید همان‌جا ببیند چه تعهدی داریم، نه در صفحهٔ
  «حریم خصوصی» که کسی نمی‌خواند.

  زیر ۸۶۰px ریل حذف و جایش یک نوار پیشرفت باریک می‌نشیند.

  ⚠ عمداً <div> است نه <header>. در site.css یک قاعدهٔ سراسری روی خود تگ هست:
      header{position:fixed;top:0;left:0;right:0;z-index:200}
  که هر <header> را — هر جای صفحه — به گوشهٔ بالای viewport می‌چسباند. یک بار
  عنوان همین صفحه را برد بالای صفحه. اگر روزی اینجا <header> گذاشتید، باید
  position را صریح خنثی کنید.

  متغیرهای ورودی (از کنترلر):
    $authSteps  آرایه‌ای از ['key','title','desc']
    $authStep   کلید گام جاری — یا null برای صفحه‌هایی که گام ندارند (ورود)
--}}
@extends('layouts.site')

@push('head')
<link rel="stylesheet" href="{{ asset('assets/css/auth.css') }}?v={{ filemtime(public_path('assets/css/auth.css')) }}">
<meta name="robots" content="noindex,follow">
@endpush

@section('content')
@php
  $steps   = $authSteps ?? [];
  $current = $authStep ?? null;
  $index   = $current ? array_search($current, array_column($steps, 'key'), true) : false;
  $index   = $index === false ? 0 : $index;
  $total   = count($steps);
@endphp

<section class="auth-wrap">
  <div class="container">

    <div class="auth-title">
      <h1>@yield('heading')</h1>
      @hasSection('sub')<p>@yield('sub')</p>@endif
    </div>

    <div class="auth-shell">

      <div class="auth-main">

        @if($total)
          {{-- نوار پیشرفت موبایل — معادل ریل، نه اضافه بر آن --}}
          <div class="auth-prog">
            <div class="auth-prog-h">
              <b>{{ $steps[$index]['title'] }}</b>
              <span>گام {{ fa_num($index + 1) }} از {{ fa_num($total) }}</span>
            </div>
            <div class="auth-prog-bar">
              <i style="width:{{ round((($index + 1) / $total) * 100) }}%"></i>
            </div>
          </div>
        @endif

        @if(session('ok'))
          <div class="auth-note ok" role="status">{{ session('ok') }}</div>
        @endif

        @if($errors->any())
          <div class="auth-note bad" role="alert">
            @foreach($errors->all() as $e)<span>{{ $e }}</span>@endforeach
          </div>
        @endif

        @yield('form')

        @hasSection('aside')
          <p class="auth-alt">@yield('aside')</p>
        @endif
      </div>

      <aside class="auth-rail">
        @if($total)
          <div>
            <div class="auth-rail-t">مراحل</div>
            <ol class="stp" style="margin-top:16px">
              @foreach($steps as $i => $s)
                <li class="{{ $i < $index ? 'done' : ($i === $index ? 'on' : '') }}">
                  <b>{{ $i < $index ? '✓' : fa_num($i + 1) }}</b>
                  <i>{{ $s['title'] }}</i>
                  <small>{{ $s['desc'] }}</small>
                </li>
              @endforeach
            </ol>
          </div>
        @else
          <div>
            <div class="auth-rail-t">سرورنت</div>
            <p style="margin:14px 0 0;font-size:13px;color:var(--muted);line-height:2">
              پنل کاربری سرورنت — مدیریت سرویس‌ها، دامنه‌ها، فاکتورها و تیکت‌های
              پشتیبانی در یک جا.
            </p>
          </div>
        @endif

        {{-- نشانه‌های اطمینان: عمداً همان چیزی که کد واقعاً انجام می‌دهد،
             نه شعار. اگر روزی رفتار عوض شد، این متن هم باید عوض شود. --}}
        <div class="auth-assure">
          @hasSection('assure')
            @yield('assure')
          @else
            <div>
              <svg class="icon"><use href="#i-shield"/></svg>
              <span>ارتباط شما <b>رمزنگاری‌شده</b> است.</span>
            </div>
            <div>
              <svg class="icon"><use href="#i-check"/></svg>
              <span>اطلاعات هویتی فقط برای <b>احراز هویت</b> استفاده می‌شود.</span>
            </div>
          @endif
        </div>
      </aside>

    </div>
  </div>
</section>
@endsection
