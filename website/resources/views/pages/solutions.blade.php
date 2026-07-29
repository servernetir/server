{{--
  هابِ راهکارها (/solutions).

  نقشِ سئویی: والدِ موضوعیِ همهٔ صفحات راهکار. تا پیش از این، این صفحات فقط از
  کارت‌های صفحهٔ اول لینک می‌گرفتند و یکی‌شان (تلفن ابری) کلاً یتیم بود. این
  صفحه ساختارِ سیلو را کامل می‌کند: خانه → راهکارها → راهکارِ مشخص.

  BreadcrumbList و CollectionPage با schema_ld ساخته می‌شوند (نه @context خام،
  چون Blade آن را می‌بلعد).
--}}
@extends('layouts.site')

@section('title', __('ui.sol_meta_t'))
@section('description', __('ui.sol_meta_d'))

@section('content')

{{-- ⚠️ آرایه‌ها اول در بلوکِ php ساخته می‌شوند، نه درون‌خطی: آرایهٔ درون‌خطی
     پارسرِ Blade را می‌شکند. schema_ld نوع را پارامترِ دوم می‌گیرد و فقط JSON
     برمی‌گرداند، پس خودمان تگِ script می‌گذاریم.
     ⚠️⚠️ و در همین کامنت هم نامِ دایرکتیوها را با علامتِ @ نمی‌نویسیم: Blade
     هر «@کلمه» را — حتی داخلِ کامنت — دایرکتیو می‌شمارد. یک‌بار همین‌جا کلِ
     بدنهٔ صفحه را بلعید و فقط CSS رندر شد. --}}
@php
  $crumbItems = [
    ['@type' => 'ListItem', 'position' => 1, 'name' => __('ui.brand'), 'item' => $homeUrl],
    ['@type' => 'ListItem', 'position' => 2, 'name' => __('ui.sol_h1'), 'item' => url()->current()],
  ];
  $crumbs = ['itemListElement' => $crumbItems];
  $collection = [
    'name'        => __('ui.sol_meta_t'),
    'description' => __('ui.sol_meta_d'),
    'url'         => url()->current(),
    'inLanguage'  => app()->getLocale(),
  ];
@endphp
<script type="application/ld+json">{!! schema_ld($crumbs, 'BreadcrumbList') !!}</script>
<script type="application/ld+json">{!! schema_ld($collection, 'CollectionPage') !!}</script>

<section class="section sol-hub">
  <div class="container">

    <nav class="crumbs" aria-label="breadcrumb">
      <a href="{{ $homeUrl }}">{{ __('ui.brand') }}</a>
      <span aria-hidden="true">/</span>
      <span>{{ __('ui.sol_h1') }}</span>
    </nav>

    <div class="sec-head">
      <span class="badge">{{ __('ui.sol_badge') }}</span>
      <h1>{{ __('ui.sol_h1') }}</h1>
      <p>{{ __('ui.sol_lead') }}</p>
    </div>

    <div class="sol-grid">
      @foreach($solutions as $i => $s)
        <a class="sol-card reveal" href="{{ lroute('solution', $s['slug']) }}"
           style="transition-delay:{{ $i * 50 }}ms">
          <span class="sol-ic sol-{{ $s['accent'] }}"><svg class="icon"><use href="#i-{{ $s['icon'] }}"/></svg></span>
          @if($s['badge'])<span class="sol-tag">{{ $s['badge'] }}</span>@endif
          <h2>{{ $s['title'] }}</h2>
          <p>{{ \Illuminate\Support\Str::limit($s['lead'], 155) }}</p>
          <span class="sol-more">{{ __('ui.ent_learn') }}<svg class="icon dir"><use href="#i-arrow"/></svg></span>
        </a>
      @endforeach
    </div>

    {{-- لینک‌سازیِ داخلی: از هابِ راهکارها به دسته‌های محصول. گوگل از این‌جا
         مسیرِ موضوعی به هاست/سرور را هم می‌بیند. --}}
    <div class="sol-cross">
      <h2>{{ __('ui.sol_cross_t') }}</h2>
      <div class="sol-cross-links">
        <a href="{{ lroute('hosting', 'linux') }}">{{ __('ui.f_p1') }}</a>
        <a href="{{ lroute('catalog', ['category' => 'vps', 'slug' => 'iran']) }}">{{ __('ui.f_p2') }}</a>
        <a href="{{ lroute('catalog', ['category' => 'dedicated', 'slug' => 'iran']) }}">{{ __('ui.f_p3') }}</a>
        <a href="{{ lroute('catalog', ['category' => 'cloud', 'slug' => 'iaas']) }}">{{ __('ui.f_p5') }}</a>
        <a href="{{ lroute('knowledge') }}">{{ __('ui.nav_knowledge') }}</a>
        <a href="{{ lroute('contact') }}">{{ __('ui.nav_contact') }}</a>
      </div>
    </div>

  </div>
</section>

<style>
.sol-hub{ padding-top:120px }
.crumbs{ display:flex; align-items:center; gap:8px; font-size:12.5px; color:var(--dim); margin-bottom:18px }
.crumbs a{ color:var(--muted) }
.crumbs a:hover{ color:var(--cyan) }

.sol-grid{ display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:18px; margin-top:38px }
.sol-card{ position:relative; display:flex; flex-direction:column; gap:10px; padding:26px 24px;
  border:1px solid var(--line); border-radius:20px; background:var(--surface);
  transition:transform .22s var(--ease), border-color .22s var(--ease) }
.sol-card:hover{ transform:translateY(-4px); border-color:var(--line-2) }
.sol-ic{ width:46px; height:46px; border-radius:14px; display:grid; place-items:center; margin-bottom:4px }
.sol-ic .icon{ width:22px; height:22px }
.sol-cyan{ background:rgba(34,211,238,.12); color:var(--cyan) }
.sol-violet{ background:rgba(139,92,246,.12); color:var(--violet) }
.sol-green{ background:rgba(52,211,153,.12); color:var(--green) }
.sol-blue{ background:rgba(59,130,246,.12); color:var(--blue) }
.sol-card h2{ font-size:17px; font-weight:700; line-height:1.5; margin:0; text-wrap:balance }
.sol-card p{ font-size:13.2px; line-height:2; color:var(--muted); margin:0; flex:1 }
.sol-tag{ position:absolute; top:18px; inset-inline-end:18px; font-size:10.5px; font-weight:700;
  color:var(--cyan); background:rgba(34,211,238,.1); border:1px solid rgba(34,211,238,.25);
  padding:3px 9px; border-radius:20px }
.sol-more{ display:inline-flex; align-items:center; gap:7px; font-size:12.5px; font-weight:700; color:var(--cyan) }
.sol-more .icon{ width:15px; height:15px }
html[dir="rtl"] .sol-more .dir{ transform:scaleX(-1) }

.sol-cross{ margin-top:54px; padding-top:28px; border-top:1px solid var(--line) }
.sol-cross h2{ font-size:15px; font-weight:700; margin:0 0 14px; color:var(--text) }
.sol-cross-links{ display:flex; flex-wrap:wrap; gap:9px }
.sol-cross-links a{ font-size:12.8px; color:var(--muted); border:1px solid var(--line);
  border-radius:30px; padding:7px 15px; transition:.16s }
.sol-cross-links a:hover{ border-color:var(--cyan); color:var(--cyan) }

@media(max-width:640px){ .sol-hub{ padding-top:96px } }
</style>
@endsection
