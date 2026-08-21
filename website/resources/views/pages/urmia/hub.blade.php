@extends('layouts.site')

@section('title', 'خدمات طراحی سایت و نرم‌افزار در ارومیه | سرورنت — از سال ۱۳۸۸')
@section('description', 'هاب خدمات سرورنت در ارومیه: طراحی سایت، فروشگاه اینترنتی، اپلیکیشن، سئو، اتوماسیون اداری و ERP — توسط شرکت ثبت‌شده در ارومیه با زیرساخت میزبانی خودش و پشتیبانی حضوری.')
@section('faOnly', '1')

@section('content')

{{--
  هابِ بخشِ محلی ارومیه — /urmia
  مقصدِ اصلیِ ۳۰۱های «طراحی سایت در ارومیه»ی servernet.ir و نقطهٔ لینکِ
  داخلی به همهٔ زیرصفحات. فقط فارسی (faOnly).
--}}

@php
  $tel = $identity['phone'] ? 'tel:'.preg_replace('/[^0-9+]/', '', $identity['phone']) : null;

  // ترتیبِ نمایش: پرتقاضاترین خدمات اول
  $order = ['web-design', 'ecommerce-website', 'corporate-website', 'web-design-price',
            'seo', 'app-development', 'software-company', 'office-automation',
            'erp', 'support', 'portfolio'];
  $icons = ['web-design' => 'i-globe', 'ecommerce-website' => 'i-box', 'corporate-website' => 'i-factory',
            'web-design-price' => 'i-coins', 'seo' => 'i-trend', 'app-development' => 'i-smartphone',
            'software-company' => 'i-code', 'office-automation' => 'i-file', 'erp' => 'i-flow',
            'support' => 'i-shield', 'portfolio' => 'i-sparkles'];
@endphp

<section class="hero hero-sub sol-hero sol-cyan">
  <div class="sol-hero-glow"></div>
  <div class="container">
    <div class="hero-sub-inner">
      <span class="badge reveal"><span class="pulse"></span><span>{{ $identity['city'] }} · شماره ثبت {{ $identity['reg_no'] }} · از سال {{ $identity['since'] }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">طراحی سایت و خدمات نرم‌افزاری در ارومیه</h1>
      <p class="lead reveal" style="transition-delay:.16s">سرورنت از سال {{ $identity['since'] }} در ارومیه سایت، نرم‌افزار و زیرساخت ساخته است — شرکتی ثبت‌شده در همین شهر که سایت شما را روی سرورهای خودش میزبانی می‌کند و پشتیبانی‌اش حضوری است، نه تیکتی.</p>
      <div class="sol-hero-cta reveal" style="transition-delay:.22s">
        @if($tel)
        <a class="btn btn-primary" href="{{ $tel }}"><span>{{ fa_num($identity['phone']) }}</span></a>
        @else
        <a class="btn btn-primary" href="{{ lroute('contact') }}"><span>مشاوره رایگان</span>
          <svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></a>
        @endif
        <a class="btn btn-glass" href="{{ route('urmia.page', 'portfolio') }}">نمونه‌کارها</a>
      </div>
    </div>
  </div>
</section>

{{-- ═══ خدمات ═══ --}}
<section class="section">
  <div class="container">
    <div class="section-head reveal">
      <span class="kicker">خدمات</span>
      <h2>چه کاری برایتان انجام می‌دهیم؟</h2>
      <p>هر خدمت یک صفحهٔ کامل دارد — با شرح روش کار، محدودهٔ قیمت و پاسخ سؤال‌های رایج.</p>
    </div>
    <div class="sol-feat-grid cols-4">
      @foreach($order as $slug)
        @if(isset($pages[$slug]))
        <a class="sol-feat reveal" href="{{ route('urmia.page', $slug) }}" style="text-decoration:none;color:inherit">
          <span class="sol-feat-ic"><svg class="icon"><use href="#{{ $icons[$slug] ?? 'i-check' }}"/></svg></span>
          <h3>{{ $pages[$slug]['h1'] }}</h3>
          <p>{{ \Illuminate\Support\Str::limit($pages[$slug]['lead'], 110) }}</p>
        </a>
        @endif
      @endforeach
    </div>
  </div>
</section>

{{-- ═══ تمایز: زیرساخت خودمان ═══ --}}
<section class="section" style="padding-top:0">
  <div class="container" style="max-width:860px">
    <div class="sla-doc reveal">
      <h2>سایت شما روی زیرساخت خودمان میزبانی می‌شود، نه هاست اجاره‌ای</h2>
      <p>تقریباً همهٔ آژانس‌های طراحی سایت، میزبانی را از یک شرکت دیگر اجاره می‌کنند؛ اگر مشکلی پیش بیاید فقط می‌توانند تیکت بزنند و منتظر بمانند. سرورنت خودش شرکت میزبانی است: کلاستر سرورهای ما در دیتاسنترهای ایران و آلمان زیر مدیریت مستقیم تیم فنی خودمان است — از سخت‌افزار تا شبکه.</p>
      <p>برای شما یعنی: یک مسئول مشخص برای همهٔ لایه‌ها، سرعت و پایداری‌ای که خودمان ضمانتش را می‌دهیم، و <b>تداوم کسب‌وکار در روزهای اختلال اینترنت</b> — سایت‌هایی که در ایران میزبانی می‌شوند، در قطعی اینترنت بین‌الملل برای مشتری داخلی همچنان باز می‌مانند. برای کسب‌وکار محلی، این تفاوت بقاست نه تجمل.</p>
      <h2>پانزده سال در یک شهر</h2>
      <p>«{{ $identity['brand'] }}» برند شرکت <b>{{ $identity['company'] }}</b> است — ثبت‌شده در {{ $identity['city'] }} به شمارهٔ {{ $identity['reg_no'] }}، فعال از سال {{ $identity['since'] }}. مشتریان ما در همین شهرند، همدیگر را می‌شناسند و می‌توانید پیش از هر قراردادی با چندتایشان حرف بزنید. اعتباری که در یک شهر کوچک ساخته می‌شود، شکننده‌تر و در نتیجه واقعی‌تر از هر تبلیغی است.
      @if($identity['address'])
      دفتر ما: {{ $identity['address'] }}.
      @endif
      </p>
    </div>
  </div>
</section>

{{-- ═══ شهرستان‌ها ═══ --}}
<section class="section" style="padding-top:0">
  <div class="container" style="max-width:860px">
    <div class="section-head reveal">
      <span class="kicker">آذربایجان غربی</span>
      <h2>خدمات ما در شهرستان‌های استان</h2>
      <p>از خوی تا مهاباد، پروژه‌ها از دفتر ارومیه مدیریت و در صورت نیاز حضوری مستقر می‌شوند.</p>
    </div>
    <div class="sol-hero-cta reveal" style="flex-wrap:wrap">
      @foreach($cities as $slug => $c)
      <a class="btn btn-glass" href="{{ route('urmia.city', $slug) }}">طراحی سایت در {{ $c['name'] }}</a>
      @endforeach
    </div>
  </div>
</section>

{{-- ═══ فراخوان ═══ --}}
<section class="section" style="padding-top:0">
  <div class="container">
    <div class="sol-cta reveal">
      <div class="sol-cta-glow"></div>
      <h2>از یک جلسهٔ بی‌تعهد شروع کنید</h2>
      <p>کارتان را برایمان تعریف کنید؛ صادقانه می‌گوییم چه چیزی لازم دارید، چقدر هزینه دارد و چقدر طول می‌کشد.</p>
      <div class="sol-cta-btns">
        <a class="btn btn-primary" href="{{ lroute('contact') }}">
          <span>درخواست جلسه</span>
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
      'name'        => $identity['brand'].' — خدمات طراحی سایت و نرم‌افزار در ارومیه',
      'description' => 'طراحی سایت، فروشگاه اینترنتی، اپلیکیشن، سئو، اتوماسیون اداری و ERP در ارومیه و آذربایجان غربی.',
      'url'         => url()->current(),
      'telephone'   => $identity['phone'] ?: null,
      'foundingDate' => '2009',
      'areaServed'  => array_merge(
          [[$T => 'City', 'name' => $identity['city']], [$T => 'State', 'name' => $identity['province']]],
          array_map(fn ($c) => [$T => 'City', 'name' => $c['name']], array_values($cities))
      ),
      'address' => array_filter([
          $T                => 'PostalAddress',
          'addressLocality' => $identity['city'],
          'addressRegion'   => $identity['province'],
          'addressCountry'  => 'IR',
          'streetAddress'   => $identity['address'] ?: null,
      ]),
      'geo' => [$T => 'GeoCoordinates', 'latitude' => $identity['geo']['lat'], 'longitude' => $identity['geo']['lng']],
      'parentOrganization' => [$T => 'Organization', 'name' => $identity['company']],
  ]);
@endphp
<script type="application/ld+json">{!! schema_ld($ldService, 'ProfessionalService') !!}</script>

@endsection
