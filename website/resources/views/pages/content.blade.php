@extends('layouts.site')

@section('title', lc($page)['tag'].' — '.__('ui.brand'))
@section('description', lc($page)['lead'])

@section('content')
@php $loc = app()->getLocale(); @endphp

<section class="hero hero-sub" style="padding-bottom:40px">
  <div class="container">
    <div class="hero-sub-inner">
      <span class="badge reveal"><span class="pulse"></span><span>{{ lc($page)['tag'] }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ lc($page)['h1a'] }} <span class="grad">{{ lc($page)['h1b'] }}</span></h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ lc($page)['lead'] }}</p>
    </div>
  </div>
</section>

@if($slug === 'about')
  {{-- آمار --}}
  <section class="section" style="padding-top:10px;padding-bottom:30px">
    <div class="container">
      <div class="about-stats reveal">
        @foreach($page['stats'] as $st)
        <div class="ast"><b>{{ $isFa ? fa_num($st['n']) : $st['n'] }}</b><span>{{ lc($st) }}</span></div>
        @endforeach
      </div>
    </div>
  </section>
  {{-- داستان --}}
  <section class="section" style="padding-top:20px">
    <div class="container">
      <div class="about-story">
        @foreach($page['story'] as $i => $s)
        <div class="story-block reveal" style="transition-delay:{{ $i * 80 }}ms">
          <h2>{{ lc($s)['t'] }}</h2>
          <p>{{ lc($s)['b'] }}</p>
        </div>
        @endforeach
      </div>
    </div>
  </section>
  {{-- ارزش‌ها --}}
  <section class="section" style="padding-top:20px">
    <div class="container">
      <div class="section-head reveal"><h2 style="font-size:28px">{{ $isFa ? 'ارزش‌های ما' : ($loc === 'tr' ? 'Değerlerimiz' : 'Our values') }}</h2></div>
      <div class="why-grid">
        @foreach($page['values'] as $i => $v)
        <div class="witem reveal" style="transition-delay:{{ $i * 50 }}ms">
          <div class="wicon"><svg class="icon"><use href="#i-{{ $v['icon'] }}"/></svg></div>
          <div><h4>{{ lc($v)['t'] }}</h4><p>{{ lc($v)['d'] }}</p></div>
        </div>
        @endforeach
      </div>
    </div>
  </section>
@else
  {{-- حریم خصوصی / قوانین --}}
  <section class="section" style="padding-top:10px">
    <div class="container">
      <div class="legal-doc reveal">
        @foreach($page['sections'] as $i => $sec)
        <div class="legal-sec">
          <h2><span class="legal-num">{{ $isFa ? fa_num($i + 1) : $i + 1 }}</span>{{ lc($sec)['t'] }}</h2>
          <p>{{ lc($sec)['b'] }}</p>
        </div>
        @endforeach
        <div class="legal-foot">
          <svg class="icon"><use href="#i-{{ $page['icon'] }}"/></svg>
          <span>{{ $isFa ? 'سوالی دارید؟ با ما تماس بگیرید:' : ($loc === 'tr' ? 'Sorunuz mu var? Bize ulaşın:' : 'Questions? Contact us:') }}</span>
          <a href="mailto:{{ $contact['email'] }}" dir="ltr">{{ $contact['email'] }}</a>
        </div>
      </div>
    </div>
  </section>
@endif

<section class="cta-wrap reveal" style="margin-top:30px">
  <div class="cta">
    <h2>{{ __('ui.cta_title') }}</h2>
    <p>{{ __('ui.cta_sub') }}</p>
    <a class="btn btn-primary" href="{{ lroute('contact') }}"><span>{{ __('ui.ent_cta') }}</span><svg class="icon dir" style="width:17px;height:17px"><use href="#i-arrow"/></svg></a>
  </div>
</section>
@endsection
