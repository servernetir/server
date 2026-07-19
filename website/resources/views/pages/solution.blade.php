@extends('layouts.site')

@php
    $s = lc($sol);                       // محتوای زبان جاری
    $accent = $sol['accent'] ?? 'cyan';
    // حل آدرس: انکور/URL خارجی همان‌طور، وگرنه نام روت داخلی (مثل contact)
    $hrefOf = function ($h) {
        if (! $h) return lroute('contact');
        if (str_starts_with($h, '#') || str_starts_with($h, 'http')) return $h;
        if (str_contains($h, ':')) { [$n, $p] = explode(':', $h, 2); return lroute($n, $p); }
        return lroute($h);
    };
    $isExt = fn ($h) => is_string($h) && str_starts_with($h, 'http');
@endphp

@section('title', $s['meta_t'].' — '.__('ui.brand'))
@section('description', $s['meta_d'])

@section('content')

{{-- ============ HERO ============ --}}
<section class="hero hero-sub sol-hero sol-{{ $accent }}">
  <div class="sol-hero-glow"></div>
  <div class="container">
    <div class="sol-hero-inner">
      <div class="sol-hero-txt">
        @if(!empty($s['badge']))
        <span class="badge reveal"><span class="pulse"></span><span>{{ $s['badge'] }}</span></span>
        @endif
        <h1 class="reveal" style="transition-delay:.06s">{{ $s['h1a'] }} <span class="grad">{{ $s['h1b'] }}</span></h1>
        <p class="lead reveal" style="transition-delay:.14s">{{ $s['lead'] }}</p>
        <div class="sol-hero-cta reveal" style="transition-delay:.22s">
          @if(!empty($s['cta1']))
          <a class="btn btn-primary" href="{{ $hrefOf($s['cta1']['href']) }}" @if($isExt($s['cta1']['href'])) target="_blank" rel="noopener" @endif>{{ $s['cta1']['label'] }}<svg class="icon dir"><use href="#i-arrow"/></svg></a>
          @endif
          @if(!empty($s['cta2']))
          <a class="btn btn-glass" href="{{ $hrefOf($s['cta2']['href']) }}">{{ $s['cta2']['label'] }}</a>
          @endif
        </div>
      </div>
      @if(!empty($s['stats']))
      <div class="sol-hero-stats reveal" style="transition-delay:.3s">
        @foreach($s['stats'] as $st)
        <div class="sol-stat"><b>{{ $st['n'] }}</b><span>{{ $st['l'] }}</span></div>
        @endforeach
      </div>
      @endif
    </div>
  </div>
</section>

{{-- ============ TRUST STRIP ============ --}}
@if(!empty($s['trust']))
<section class="sol-trust">
  <div class="container">
    <div class="sol-trust-row reveal">
      @foreach($s['trust'] as $tr)
      <span class="sol-trust-item"><svg class="icon"><use href="#i-{{ $tr['icon'] }}"/></svg>{{ $tr['t'] }}</span>
      @endforeach
    </div>
  </div>
</section>
@endif

{{-- ============ FEATURES ============ --}}
@if(!empty($s['features']))
<section class="section">
  <div class="container">
    <div class="section-head reveal">
      @if(!empty($s['features_badge']))<span class="kicker">{{ $s['features_badge'] }}</span>@endif
      <h2>{{ $s['features_t'] ?? '' }}</h2>
      @if(!empty($s['features_d']))<p>{{ $s['features_d'] }}</p>@endif
    </div>
    <div class="sol-feat-grid">
      @foreach($s['features'] as $f)
      <div class="sol-feat reveal">
        <span class="sol-feat-ic"><svg class="icon"><use href="#i-{{ $f['icon'] }}"/></svg></span>
        <h3>{{ $f['t'] }}</h3>
        <p>{{ $f['d'] }}</p>
      </div>
      @endforeach
    </div>
  </div>
</section>
@endif

{{-- ============ STEPS ============ --}}
@if(!empty($s['steps']))
<section class="section sol-steps-sec" id="steps">
  <div class="container">
    <div class="section-head reveal">
      @if(!empty($s['steps_badge']))<span class="kicker">{{ $s['steps_badge'] }}</span>@endif
      <h2>{{ $s['steps_t'] ?? '' }}</h2>
      @if(!empty($s['steps_d']))<p>{{ $s['steps_d'] }}</p>@endif
    </div>
    <div class="sol-steps">
      @foreach($s['steps'] as $i => $st)
      <div class="sol-step reveal">
        <span class="sol-step-n">{{ $isFa ? fa_num($i + 1) : $i + 1 }}</span>
        <h3>{{ $st['t'] }}</h3>
        <p>{{ $st['d'] }}</p>
      </div>
      @endforeach
    </div>
  </div>
</section>
@endif

{{-- ============ DOWNLOADS ============ --}}
@if(!empty($s['downloads']))
<section class="section" id="download">
  <div class="container">
    <div class="section-head reveal">
      @if(!empty($s['downloads_badge']))<span class="kicker">{{ $s['downloads_badge'] }}</span>@endif
      <h2>{{ $s['downloads_t'] ?? '' }}</h2>
      @if(!empty($s['downloads_d']))<p>{{ $s['downloads_d'] }}</p>@endif
    </div>
    <div class="sol-dl-grid">
      @foreach($s['downloads'] as $d)
      <a class="sol-dl reveal" href="{{ $hrefOf($d['href'] ?? '') }}" @if($isExt($d['href'] ?? '')) target="_blank" rel="noopener" @endif>
        <span class="sol-dl-ic"><svg class="icon"><use href="#i-{{ $d['icon'] }}"/></svg></span>
        <b>{{ $d['t'] }}</b>
        <small>{{ $d['meta'] }}</small>
        <span class="sol-dl-btn">{{ $d['btn'] ?? ($s['downloads_btn'] ?? '') }}</span>
      </a>
      @endforeach
    </div>
  </div>
</section>
@endif

{{-- ============ PACKAGES ============ --}}
@if(!empty($s['packages']))
<section class="section" id="packages">
  <div class="container">
    <div class="section-head reveal">
      @if(!empty($s['packages_badge']))<span class="kicker">{{ $s['packages_badge'] }}</span>@endif
      <h2>{{ $s['packages_t'] ?? '' }}</h2>
      @if(!empty($s['packages_d']))<p>{{ $s['packages_d'] }}</p>@endif
    </div>
    <div class="sol-plans">
      @foreach($s['packages'] as $p)
      <div class="sol-plan reveal @if(!empty($p['featured'])) featured @endif">
        @if(!empty($p['featured']))<span class="sol-plan-tag">{{ $s['popular'] ?? '' }}</span>@endif
        <h3>{{ $p['name'] }}</h3>
        @if(!empty($p['tagline']))<p class="sol-plan-tag2">{{ $p['tagline'] }}</p>@endif
        <div class="sol-plan-price"><b>{{ $p['price'] }}</b>@if(!empty($p['unit']))<span>{{ $p['unit'] }}</span>@endif</div>
        <ul class="sol-plan-feats">
          @foreach($p['features'] as $pf)
          <li><svg class="icon"><use href="#i-check"/></svg>{{ $pf }}</li>
          @endforeach
        </ul>
        <a class="btn @if(!empty($p['featured'])) btn-primary @else btn-glass @endif" href="{{ $p['href'] ?? lroute('contact') }}">{{ $p['cta'] ?? ($s['cta_btn'] ?? '') }}</a>
      </div>
      @endforeach
    </div>
    @if(!empty($s['packages_note']))<p class="sol-plans-note reveal">{{ $s['packages_note'] }}</p>@endif
  </div>
</section>
@endif

{{-- ============ COMPARISON ============ --}}
@if(!empty($s['compare']))
<section class="section">
  <div class="container" style="max-width:900px">
    <div class="section-head reveal"><h2>{{ $s['compare_t'] ?? '' }}</h2></div>
    <div class="sol-compare reveal">
      <table>
        <thead><tr><th>{{ $s['compare_col0'] ?? '' }}</th><th class="us">{{ $s['compare_us'] ?? 'ServerNet' }}</th><th>{{ $s['compare_them'] ?? '' }}</th></tr></thead>
        <tbody>
          @foreach($s['compare'] as $row)
          <tr><td>{{ $row['f'] }}</td><td class="us"><svg class="icon ok"><use href="#i-check"/></svg>{{ $row['us'] }}</td><td>{{ $row['them'] }}</td></tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
</section>
@endif

{{-- ============ FAQ ============ --}}
@if(!empty($s['faq']))
<section class="section">
  <div class="container" style="max-width:820px">
    <div class="section-head reveal"><h2>{{ $s['faq_t'] ?? __('ui.lk_faq') }}</h2></div>
    <div class="lk-faq reveal">
      @foreach($s['faq'] as $f)
      <details class="lk-faq-item"><summary>{{ $f['q'] }}</summary><p>{{ $f['a'] }}</p></details>
      @endforeach
    </div>
  </div>
</section>
@endif

{{-- ============ CTA ============ --}}
<section class="section" style="padding-top:0;padding-bottom:80px">
  <div class="container">
    <div class="sol-cta reveal">
      <div class="sol-cta-glow"></div>
      <h2>{{ $s['cta_t'] ?? '' }}</h2>
      <p>{{ $s['cta_d'] ?? '' }}</p>
      <div class="sol-cta-btns">
        <a class="btn btn-primary" href="{{ $s['cta_href'] ?? lroute('contact') }}" @if(!empty($s['cta_href']) && str_starts_with($s['cta_href'],'http')) target="_blank" rel="noopener" @endif>{{ $s['cta_btn'] ?? '' }}<svg class="icon dir"><use href="#i-arrow"/></svg></a>
        <a class="btn btn-glass" href="{{ lroute('contact') }}">{{ $s['cta_btn2'] ?? __('ui.nav_contact') }}</a>
      </div>
    </div>
  </div>
</section>

{{-- ============ JSON-LD ============ --}}
@if(!empty($s['faq']))
<script type="application/ld+json">{!! json_encode([
    '@'.'context' => 'https://schema.org', '@type' => 'FAQPage',
    'mainEntity' => array_map(fn ($f) => ['@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]], $s['faq']),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endif
@endsection
