@extends('layouts.site')

@section('title', __('ui.status_title').' — '.__('ui.brand'))
@section('description', __('ui.status_meta_d'))

@section('content')

{{-- صفحهٔ وضعیت عمداً سبک است: دقیقاً وقتی خوانده می‌شود که اوضاع خراب است و
     شاید شبکه کند باشد. هیچ چیزِ اضافه، هیچ تماسِ شبکه‌ایِ سنگین. --}}

<section class="hero hero-sub">
  <div class="container">
    <div class="hero-sub-inner">
      <span class="badge reveal"><span class="pulse"></span><span>{{ __('ui.status_badge') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ __('ui.status_title') }}</h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ __('ui.status_lead') }}</p>
    </div>
  </div>
</section>

<section class="section" style="padding-top:20px">
  <div class="container" style="max-width:900px">

    {{-- ── وضعیت کلی ── --}}
    @php $worst = $open->firstWhere('impact', 'major') ?? $open->first(); @endphp
    <div class="st-hero reveal" style="border-color:{{ $worst?->color() ?? '#34d399' }}44">
      <span class="st-dot" style="background:{{ $worst?->color() ?? '#34d399' }}"></span>
      <div>
        <b>{{ $open->isEmpty() ? __('ui.status_all_ok') : __('ui.status_has_issue') }}</b>
        <small>{{ __('ui.status_updated') }} {{ sdate(now()) }}</small>
      </div>
    </div>

    {{-- ── اختلال‌های باز ── --}}
    @if($open->isNotEmpty())
      <h2 class="st-h">{{ __('ui.status_open') }}</h2>
      @foreach($open as $inc)
        <article class="st-item reveal" style="border-inline-start-color:{{ $inc->color() }}">
          <div class="st-item-h">
            <b>{{ $inc->title }}</b>
            <span class="st-tag" style="background:{{ $inc->color() }}22;color:{{ $inc->color() }}">{{ $inc->impactLabel() }}</span>
          </div>
          <div class="st-meta">
            {{ $inc->stateLabel() }} · {{ __('ui.status_since') }} {{ sdate($inc->started_at) }}
            @if($inc->locations)· {{ implode('، ', $inc->locations) }}@endif
          </div>
          @if($inc->body)<p class="st-body">{{ $inc->body }}</p>@endif
        </article>
      @endforeach
    @endif

    {{-- ── تاریخچه ── --}}
    <h2 class="st-h">{{ __('ui.status_history') }}</h2>
    @if($history->isEmpty())
      <p class="st-empty">{{ __('ui.status_no_history') }}</p>
    @else
      @foreach($history as $inc)
        <article class="st-item st-done reveal">
          <div class="st-item-h">
            <b>{{ $inc->title }}</b>
            <span class="st-tag" style="background:#34d39922;color:#34d399">{{ __('ui.status_resolved') }}</span>
          </div>
          <div class="st-meta">
            {{ sdate($inc->started_at) }}
            @if($inc->resolved_at)— {{ sdate($inc->resolved_at) }}@endif
            @if($inc->locations)· {{ implode('، ', $inc->locations) }}@endif
          </div>
          @if($inc->body)<p class="st-body">{{ $inc->body }}</p>@endif
        </article>
      @endforeach
    @endif

    {{-- ⚠️ صداقت درباره‌ی چیزی که هنوز نداریم.
         تبلیغِ «۹۹٫۹٪ آپتایم» بدونِ اندازه‌گیری، ادعایی است که نه می‌شود اثباتش
         کرد نه ردّش — و همان چیزی است که مشتریِ سازمانی اول از همه می‌پرسد.
         گفتنِ «هنوز منتشر نمی‌کنیم» از نشان‌دادنِ عددِ ساختگی بهتر است. --}}
    <div class="st-note reveal">
      <b>{{ __('ui.status_note_t') }}</b>
      <p>{{ __('ui.status_note_d') }}</p>
      <a class="btn btn-glass" href="{{ lroute('sla') }}">{{ __('ui.status_sla_link') }}</a>
    </div>

  </div>
</section>
@endsection
