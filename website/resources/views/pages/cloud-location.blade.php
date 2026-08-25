{{--
  صفحهٔ اختصاصیِ یک مکانِ سرورِ مجازی (/cloud/{location}).

  نقشِ سئویی: صفحهٔ فرودِ «سرور مجازی <کشور> — <شهر>». متنش باید **یکتا** باشد،
  وگرنه ده صفحه با قالبِ یکسان می‌سازیم که مصداقِ محتوای نازک است. یکتایی از سه
  جا می‌آید: جملهٔ ویژهٔ کشور، عددِ تأخیرِ تقریبی، و فهرستِ «مناسبِ چه کاری» بر
  پایهٔ قاره — همه در CloudCatalogController.

  ⚠️ هیچ نام یا شناسهٔ زیرساخت این‌جا نیست. مکان کدِ خودِ ماست (de-frankfurt) و
     پلن نامِ خودِ ما (CV-2-4).
--}}
@extends('layouts.site')

@php
  $metaT = strtr($t['cloud_loc_meta_t'], [':loc' => $locLabel]);
  $metaD = strtr($t['cloud_loc_meta_d'], [
      ':loc'   => $locLabel,
      ':n'     => fa_num(number_format(count($rows))),
      ':price' => $fromLabel ?: '—',
  ]);
@endphp

@section('title', $metaT)
@section('description', $metaD)

{{-- مکانِ بدونِ پلنِ قابل‌فروش = صفحهٔ «۰ پلن، از —» در هر سه زبان؛ گوگل
     همین‌ها را Duplicate/کم‌ارزش گزارش کرد (ممیزی ۲۴ اوت ۲۰۲۶). تا وقتی
     پلن برگردد noindex — با برگشتنِ پلن، خودکار دوباره ایندکس‌پذیر است. --}}
@if(count($rows) === 0)
@section('noindex', 'y')
@endif

@section('content')

<script type="application/ld+json">{!! schema_ld($ld['crumbs'], 'BreadcrumbList') !!}</script>
@if($rows)
<script type="application/ld+json">{!! schema_ld($ld['list'], 'ItemList') !!}</script>
@endif
@if($faq)
<script type="application/ld+json">{!! schema_ld($ld['faq'], 'FAQPage') !!}</script>
@endif

<section class="section cvl-top">
  <div class="container">

    <nav class="cvl-crumbs" aria-label="breadcrumb">
      <a href="{{ $homeUrl }}">{{ __('ui.brand') }}</a>
      <span aria-hidden="true">/</span>
      <a href="{{ $cloudUrl }}">{{ $t['cloud_badge'] }}</a>
      <span aria-hidden="true">/</span>
      <span>{{ $locLabel }}</span>
    </nav>

    <div class="cvl-head">
      {{-- بالای صفحه است، پس eager: با lazy روی نخستین نقاشی جای خالی می‌ماند. --}}
      <span class="cvl-flag" aria-hidden="true">@include('partials.flag', ['flagSrc' => $loc->flagSvg(), 'flagEmoji' => $loc->flagEmoji(), 'flagSize' => 34, 'flagEager' => true])</span>
      <h1>{{ strtr($t['cloud_loc_h1'], [':loc' => $locLabel]) }}</h1>

      <div class="cvl-meta">
        @if($fromLabel)
          <span class="cvl-pill cvl-pill-p">{{ __('ui.from') }} <b>{{ $fromLabel }}</b> {{ __('ui.mo') }}</span>
        @endif
        <span class="cvl-pill">{{ strtr($t['cloud_n_plans'], [':n' => fa_num(number_format(count($rows)))]) }}</span>
        <span class="cvl-pill">{{ $t['cloud_feat_1'] }}</span>
        <span class="cvl-pill">{{ $t['cloud_feat_2'] }}</span>
        <span class="cvl-pill">{{ $t['cloud_feat_3'] }}</span>
      </div>
    </div>

  </div>
</section>

{{-- ═══════════ چرا این مکان — متنِ یکتای سئو ═══════════ --}}
<section class="section cvl-sec" id="why">
  <div class="container">
    <div class="cvl-why">
      {{-- h2 با نامِ شهر: هم برای خواننده مسیرِ روشنی است، هم عبارتِ
           «چرا فرانکفورت» را که واقعاً جست‌وجو می‌شود پوشش می‌دهد. --}}
      <h2>{{ strtr($t['cloud_loc_why'], [':city' => $cityLabel]) }}</h2>
      <p>{{ $seo['why'] }}</p>
      @if($seo['note'])
        <p class="cvl-note">{{ $seo['note'] }}</p>
      @endif
    </div>
  </div>
</section>

{{-- ═══════════ تأخیر + مناسبِ چه کاری ═══════════ --}}
<section class="section cvl-sec">
  <div class="container">
    <div class="cvl-two">

      <div class="cvl-card">
        <h2>{{ $t['cloud_loc_lat_t'] }}</h2>
        <div class="cvl-lats">
          <div class="cvl-lat">
            <b>{{ $seo['lat_ir'] }} <i>ms</i></b>
            <span>{{ $t['cloud_loc_lat_ir'] }}</span>
          </div>
          <div class="cvl-lat">
            <b>{{ $seo['lat_eu'] }} <i>ms</i></b>
            <span>{{ $t['cloud_loc_lat_eu'] }}</span>
          </div>
        </div>
        <p class="cvl-fine">{{ $t['cloud_loc_lat_note'] }}</p>
      </div>

      <div class="cvl-card">
        <h2>{{ $t['cloud_loc_good_t'] }}</h2>
        <ul class="cvl-good">
          @foreach($seo['good'] as $g)
            <li>{{ $g }}</li>
          @endforeach
        </ul>
      </div>

    </div>
  </div>
</section>

{{-- ═══════════ پلن‌های همین مکان ═══════════ --}}
<section class="section cvl-sec" id="plans">
  <div class="container">
    <div class="cvl-sec-h">
      <h2>{{ strtr($t['cloud_loc_plans_t'], [':city' => $cityLabel]) }}</h2>
    </div>

    @if(! $rows)

      <div class="cvl-empty">
        <p>{{ $t['cloud_loc_empty'] }}</p>
        <div class="cvl-empty-acts">
          <a class="btn btn-primary" href="{{ $cloudUrl }}">{{ $t['cloud_loc_all'] }}</a>
          <a class="btn btn-glass" href="{{ lroute('contact') }}">{{ __('ui.nav_contact') }}</a>
        </div>
      </div>

    @else

      <div class="cvl-plans">
        @foreach($rows as $r)
          <article class="cvl-plan">
            <header>
              <b>{{ $r['name'] }}</b>
              <span>{{ $r['cpu_kind'] }}</span>
            </header>
            <dl>
              <div><dt>{{ $t['cloud_th_cpu'] }}</dt><dd>{{ fa_num($r['vcpu']) }} vCPU</dd></div>
              <div><dt>{{ $t['cloud_th_ram'] }}</dt><dd>{{ fa_num($r['ram']) }}</dd></div>
              <div><dt>{{ $t['cloud_th_disk'] }}</dt><dd>{{ fa_num($r['disk']) }}</dd></div>
              <div><dt>{{ __('ui.traffic') }}</dt><dd>{{ fa_num($r['traffic']) }}</dd></div>
            </dl>
            <footer>
              <span class="cvl-price"><b>{{ $r['price'] }}</b>{{ __('ui.mo') }}</span>
              <a class="cvl-buy" href="{{ $r['buy_url'] }}" rel="nofollow">{{ __('ui.choose') }}</a>
            </footer>
          </article>
        @endforeach
      </div>

    @endif
  </div>
</section>

{{-- ═══════════ مکان‌های نزدیک — لینک‌سازیِ داخلی ═══════════ --}}
@if($nearby)
  <section class="section cvl-sec">
    <div class="container">
      <div class="cvl-sec-h">
        <h2>{{ $t['cloud_loc_near_t'] }}</h2>
      </div>
      <div class="cvl-near">
        @foreach($nearby as $n)
          <a href="{{ $n['url'] }}">@include('partials.flag', ['flagSrc' => $n['flag_svg'] ?? null, 'flagEmoji' => $n['flag'], 'flagSize' => 18]) {{ $n['label'] }}</a>
        @endforeach
        <a class="cvl-near-all" href="{{ $cloudUrl }}">{{ $t['cloud_loc_all'] }}</a>
      </div>
    </div>
  </section>
@endif

{{-- ═══════════ پرسش‌های متداول ═══════════ --}}
@if($faq)
  <section class="section cvl-sec" id="faq">
    <div class="container">
      <div class="cvl-sec-h">
        <h2>{{ __('ui.faq_title') }}</h2>
      </div>
      <div class="cvl-faq">
        @foreach($faq as $i => $row)
          <details @if($i === 0) open @endif>
            <summary>{{ $row['q'] }}</summary>
            <div>{{ $row['a'] }}</div>
          </details>
        @endforeach
      </div>
    </div>
  </section>
@endif

{{-- ═══════════ لینک‌سازیِ داخلی ═══════════ --}}
<section class="section cvl-sec cvl-cross-sec">
  <div class="container">
    <h2 class="cvl-cross-t">{{ $t['cloud_cross_t'] }}</h2>
    <div class="cvl-cross">
      <a href="{{ $cloudUrl }}">{{ $t['cloud_badge'] }}</a>
      <a href="{{ lroute('solutions.index') }}">{{ __('ui.sol_h1') }}</a>
      <a href="{{ lroute('catalog', ['category' => 'cloud', 'slug' => 'iaas']) }}">{{ __('ui.f_p5') }}</a>
      <a href="{{ lroute('catalog', ['category' => 'vps', 'slug' => 'iran']) }}">{{ __('ui.f_p2') }}</a>
      <a href="{{ lroute('hosting', 'linux') }}">{{ __('ui.f_p1') }}</a>
      <a href="{{ lroute('knowledge') }}">{{ __('ui.nav_knowledge') }}</a>
      <a href="{{ lroute('contact') }}">{{ __('ui.nav_contact') }}</a>
    </div>
  </div>
</section>

{{-- راهنماهای بلاگ (پل محصول→بلاگ — ممیزی ۳) --}}
@include('partials.product-guides', ['guidesCat' => config('blog.product_guides.cloud')])

<style>
/* صفحهٔ یک مکان — استایلِ درجا (site.css مرزِ agentِ دیگری است) */
.cvl-top{ padding:120px 0 34px }
.cvl-sec{ padding:44px 0 }
.cvl-crumbs{ display:flex; align-items:center; gap:8px; font-size:12.5px; color:var(--dim); margin-bottom:18px; flex-wrap:wrap }
.cvl-crumbs a{ color:var(--muted) }
.cvl-crumbs a:hover{ color:var(--cyan) }

.cvl-head{ max-width:820px }
.cvl-flag{ font-size:34px; line-height:1; display:block; margin-bottom:10px }
.cvl-head h1{ font-family:var(--font-disp); font-size:clamp(25px,4vw,38px); font-weight:700;
  letter-spacing:-.6px; line-height:1.25; margin-bottom:14px; text-wrap:balance }
.cvl-why{ max-width:860px }
.cvl-why h2{ font-family:var(--font-disp); font-size:clamp(19px,2.6vw,25px); font-weight:700;
  letter-spacing:-.4px; margin-bottom:14px }
.cvl-why p{ color:var(--muted); font-size:14.5px; line-height:2.1 }
.cvl-note{ margin-top:12px; padding-inline-start:14px; border-inline-start:2px solid var(--cyan) }

.cvl-meta{ display:flex; flex-wrap:wrap; gap:9px; margin-top:22px }
.cvl-pill{ font-size:12.5px; color:var(--muted); border:1px solid var(--line);
  border-radius:30px; padding:6px 14px; background:var(--surface) }
.cvl-pill-p{ border-color:rgba(34,211,238,.3); color:var(--text) }
.cvl-pill-p b{ color:var(--cyan); font-weight:700 }

.cvl-sec-h{ margin-bottom:22px }
.cvl-sec-h h2{ font-family:var(--font-disp); font-size:clamp(20px,3vw,27px); font-weight:700; letter-spacing:-.5px }

.cvl-two{ display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:16px }
.cvl-card{ border:1px solid var(--line); border-radius:20px; background:var(--surface); padding:22px }
.cvl-card h2{ font-size:15.5px; font-weight:700; margin-bottom:16px }
.cvl-lats{ display:flex; gap:14px; flex-wrap:wrap }
.cvl-lat{ flex:1 1 120px; border:1px solid var(--line); border-radius:14px; padding:14px; background:var(--surface-2) }
.cvl-lat b{ font-size:23px; font-weight:700; letter-spacing:-.4px; color:var(--cyan) }
.cvl-lat b i{ font-size:12px; font-style:normal; color:var(--dim); margin-inline-start:3px }
.cvl-lat span{ display:block; font-size:12.2px; color:var(--dim); margin-top:4px }
.cvl-fine{ margin-top:14px; font-size:11.8px; color:var(--dim); line-height:1.9 }
.cvl-good{ list-style:none; display:flex; flex-direction:column; gap:10px }
.cvl-good li{ position:relative; padding-inline-start:20px; font-size:13.4px; color:var(--muted); line-height:1.9 }
.cvl-good li::before{ content:''; position:absolute; inset-inline-start:0; top:9px; width:8px; height:8px;
  border-radius:50%; background:var(--grad) }

.cvl-plans{ display:grid; grid-template-columns:repeat(auto-fill,minmax(258px,1fr)); gap:14px }
.cvl-plan{ display:flex; flex-direction:column; border:1px solid var(--line); border-radius:18px;
  background:var(--surface); padding:18px; transition:border-color .18s var(--ease), transform .18s var(--ease) }
.cvl-plan:hover{ border-color:var(--line-2); transform:translateY(-3px) }
.cvl-plan header{ display:flex; align-items:baseline; justify-content:space-between; gap:8px;
  padding-bottom:12px; border-bottom:1px solid var(--line) }
.cvl-plan header b{ font-size:15.5px; font-weight:700; letter-spacing:.2px }
.cvl-plan header span{ font-size:11.5px; color:var(--dim) }
.cvl-plan dl{ display:flex; flex-direction:column; gap:8px; margin:14px 0; flex:1 }
.cvl-plan dl div{ display:flex; align-items:baseline; justify-content:space-between; gap:10px }
.cvl-plan dt{ font-size:12.2px; color:var(--dim) }
.cvl-plan dd{ font-size:13px; font-weight:600 }
.cvl-plan footer{ display:flex; align-items:center; justify-content:space-between; gap:10px;
  padding-top:12px; border-top:1px solid var(--line) }
.cvl-price{ font-size:11.8px; color:var(--dim) }
.cvl-price b{ display:block; font-size:16px; font-weight:700; color:var(--text) }
.cvl-buy{ font-size:12.5px; font-weight:700; color:#fff; background:var(--grad);
  border-radius:10px; padding:8px 16px; white-space:nowrap }
.cvl-buy:hover{ box-shadow:0 6px 20px rgba(34,211,238,.32) }

.cvl-empty{ border:1px dashed var(--line-2); border-radius:20px; background:var(--surface);
  padding:34px 24px; text-align:center; max-width:600px }
.cvl-empty p{ color:var(--muted); font-size:13.6px; line-height:2 }
.cvl-empty-acts{ display:flex; flex-wrap:wrap; gap:10px; justify-content:center; margin-top:20px }

.cvl-near{ display:flex; flex-wrap:wrap; gap:9px }
.cvl-near a{ font-size:12.8px; color:var(--muted); border:1px solid var(--line);
  border-radius:30px; padding:7px 15px; transition:.16s }
.cvl-near a:hover{ border-color:var(--cyan); color:var(--cyan) }
.cvl-near-all{ color:var(--cyan) !important; border-color:rgba(34,211,238,.3) !important }

.cvl-faq{ display:flex; flex-direction:column; gap:10px; max-width:860px }
.cvl-faq details{ border:1px solid var(--line); border-radius:14px; background:var(--surface); padding:14px 18px }
.cvl-faq summary{ font-size:14px; font-weight:600; list-style:none }
.cvl-faq summary::-webkit-details-marker{ display:none }
.cvl-faq details[open] summary{ color:var(--cyan) }
.cvl-faq details div{ margin-top:10px; color:var(--muted); font-size:13.2px; line-height:2 }

.cvl-cross-sec{ padding-top:14px }
.cvl-cross-t{ font-size:15px; font-weight:700; margin-bottom:14px }
.cvl-cross{ display:flex; flex-wrap:wrap; gap:9px }
.cvl-cross a{ font-size:12.8px; color:var(--muted); border:1px solid var(--line);
  border-radius:30px; padding:7px 15px; transition:.16s }
.cvl-cross a:hover{ border-color:var(--cyan); color:var(--cyan) }

@media(max-width:640px){
  .cvl-top{ padding-top:96px }
  .cvl-sec{ padding:32px 0 }
}
</style>
@endsection
