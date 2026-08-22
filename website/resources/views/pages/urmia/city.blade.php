@extends('layouts.site')

@section('title', 'طراحی سایت در '.$city['name'].' | سرورنت — شرکت ثبت‌شده در آذربایجان غربی')
@section('description', 'طراحی سایت در '.$city['name'].' توسط سرورنت: سایت شرکتی، فروشگاهی و خدماتی با میزبانی روی زیرساخت خودمان، سئوی محلی و پشتیبانی واقعی از مرکز استان. از سال ۱۳۸۸.')
@section('faOnly', '1')

@section('content')

{{--
  صفحهٔ شهری — /urmia/cities/{slug}
  متنِ معرفی هر شهر یکتاست (config/urmia.php) و بلوک‌های خدمات مشترک‌اند.
  جانشینِ صفحات «سرورنت طراحی سایت در …»ی servernet.ir.
--}}

@php
  $tel = $identity['phone'] ? 'tel:'.preg_replace('/[^0-9+]/', '', $identity['phone_link'] ?? $identity['phone']) : null;
  $others = collect($cities)->except($slug)->take(8);
@endphp

<section class="hero hero-sub">
  <div class="container">
    <div class="hero-sub-inner">
      <span class="badge reveal"><span class="pulse"></span><span>{{ $identity['brand'] }} · {{ $identity['province'] }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">طراحی سایت در {{ $city['name'] }}</h1>
      <p class="lead reveal" style="transition-delay:.16s">طراحی سایت، فروشگاه اینترنتی و نرم‌افزار برای کسب‌وکارهای {{ $city['name'] }} — توسط شرکتی که از سال {{ $identity['since'] }} در همین استان ثبت شده و زیرساخت میزبانی‌اش را خودش مدیریت می‌کند.</p>
      <div class="sol-hero-cta reveal" style="transition-delay:.22s">
        @if($tel)
        <a class="btn btn-primary" href="{{ $tel }}"><span>{{ fa_num($identity['phone']) }}</span></a>
        @else
        <a class="btn btn-primary" href="{{ lroute('contact') }}"><span>مشاوره رایگان</span>
          <svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></a>
        @endif
        <a class="btn btn-glass" href="{{ route('urmia.hub') }}">همه خدمات ما</a>
      </div>
    </div>
  </div>
</section>

<section class="section" style="padding-top:20px">
  <div class="container" style="max-width:860px">
    <div class="sla-doc reveal">
      <h2>چرا کسب‌وکار {{ $city['name'] }} به سایت حرفه‌ای نیاز دارد؟</h2>
      @foreach($city['p'] as $para)
      <p>{{ $para }}</p>
      @endforeach

      <h2>چه خدماتی در {{ $city['name'] }} ارائه می‌دهیم؟</h2>
      <ul>
        <li><a href="{{ route('urmia.page', 'web-design') }}">طراحی سایت</a> — از سایت معرفی تا پرتال سازمانی، با طراحی اختصاصی و سئوی محلی</li>
        <li><a href="{{ route('urmia.page', 'ecommerce-website') }}">فروشگاه اینترنتی</a> — درگاه پرداخت، مدیریت موجودی و تعرفه‌ی ارسال تنظیم‌شده برای {{ $city['name'] }}</li>
        <li><a href="{{ route('urmia.page', 'corporate-website') }}">سایت شرکتی</a> — برای شرکت‌هایی که طرف قرارداد سازمانی و مناقصه‌اند</li>
        <li><a href="{{ route('urmia.page', 'seo') }}">سئوی محلی</a> — دیده‌شدن در جستجوهای «در {{ $city['name'] }}» که هنوز رقابتشان کم است</li>
        <li><a href="{{ route('urmia.page', 'app-development') }}">اپلیکیشن موبایل</a> و <a href="{{ route('urmia.page', 'software-company') }}">نرم‌افزار سفارشی</a> — برای کسب‌وکارهایی که ابزار اختصاصی می‌خواهند</li>
        <li><a href="{{ route('urmia.page', 'support') }}">پشتیبانی و نگهداری سایت</a> — سایت فعلی‌تان را هر کسی ساخته باشد، تحویل می‌گیریم</li>
      </ul>

      <h2>روال همکاری با شهرستان‌ها</h2>
      <p>جلسهٔ شناخت اول تلفنی یا تصویری برگزار می‌شود و در صورت نیاز، حضوری در {{ $city['name'] }} هماهنگ می‌کنیم. قرارداد و پرداخت مرحله‌ای است، آموزش پنل مدیریت از راه دور و با ویدیوی اختصاصی انجام می‌شود، و پشتیبانی بعد از تحویل با شماره‌ی مستقیم تیم فنی در مرکز استان است — نه صف تیکت یک شرکت آن‌سر کشور.</p>
      <p>میزبانی سایت روی زیرساخت خود سرورنت است؛ یعنی سرعت، بکاپ روزانه و امنیت یک مسئول مشخص دارد و در روزهای اختلال اینترنت، گزینه‌ی میزبانی داخل ایران، سایت شما را برای مشتری داخلی باز نگه می‌دارد.</p>
    </div>
  </div>
</section>

{{-- ═══ شهرهای دیگر ═══ --}}
<section class="section" style="padding-top:0">
  <div class="container" style="max-width:860px">
    <div class="section-head reveal">
      <span class="kicker">{{ $identity['province'] }}</span>
      <h2>شهرهای دیگر استان</h2>
    </div>
    <div class="sol-hero-cta reveal" style="flex-wrap:wrap">
      @foreach($others as $oslug => $o)
      <a class="btn btn-glass" href="{{ route('urmia.city', $oslug) }}">{{ $o['name'] }}</a>
      @endforeach
      <a class="btn btn-glass" href="{{ route('urmia.hub') }}">ارومیه (هاب)</a>
    </div>
  </div>
</section>

{{-- ═══ فراخوان ═══ --}}
<section class="section" style="padding-top:0">
  <div class="container">
    <div class="sol-cta reveal">
      <div class="sol-cta-glow"></div>
      <h2>کسب‌وکار شما در {{ $city['name'] }}، سایت شما آماده‌ی کار</h2>
      <p>در یک تماس کوتاه بگویید چه می‌خواهید؛ پیشنهاد شفاف فنی و مالی می‌گیرید — بدون تعهد.</p>
      <div class="sol-cta-btns">
        <a class="btn btn-primary" href="{{ lroute('contact') }}">
          <span>شروع گفتگو</span>
          <svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg>
        </a>
        @if($tel)
        <a class="btn btn-glass" href="{{ $tel }}">{{ fa_num($identity['phone']) }}</a>
        @endif
      </div>
    </div>
  </div>
</section>

@php
  $T = '@'.'type';
  $ldService = array_filter([
      'name'        => $identity['brand'].' — طراحی سایت در '.$city['name'],
      'description' => 'طراحی سایت و خدمات نرم‌افزاری برای کسب‌وکارهای '.$city['name'].' در '.$identity['province'].'.',
      'url'         => url()->current(),
      'telephone'   => $identity['phone'] ?: null,
      'areaServed'  => [
          [$T => 'City', 'name' => $city['name']],
          [$T => 'State', 'name' => $identity['province']],
      ],
      'address' => [
          $T                => 'PostalAddress',
          'addressLocality' => $identity['city'],
          'addressRegion'   => $identity['province'],
          'addressCountry'  => 'IR',
      ],
      'parentOrganization' => [$T => 'Organization', 'name' => $identity['company']],
  ]);

  $ldCrumb = [
      'itemListElement' => [
          [$T => 'ListItem', 'position' => 1, 'name' => 'خانه', 'item' => url('/')],
          [$T => 'ListItem', 'position' => 2, 'name' => 'خدمات ارومیه', 'item' => route('urmia.hub')],
          [$T => 'ListItem', 'position' => 3, 'name' => 'طراحی سایت در '.$city['name'], 'item' => url()->current()],
      ],
  ];
@endphp
<script type="application/ld+json">{!! schema_ld($ldService, 'ProfessionalService') !!}</script>
<script type="application/ld+json">{!! schema_ld($ldCrumb, 'BreadcrumbList') !!}</script>

@endsection
