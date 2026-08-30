@extends('layouts.site')

@section('title', __('ui.cr_title').' — '.__('ui.brand'))
@section('description', __('ui.cr_meta_d'))

@section('content')

{{-- ============ HERO ============ --}}
<section class="hero hero-sub sol-hero sol-cyan" style="padding-bottom:44px">
  <div class="sol-hero-glow"></div>
  <div class="container">
    <div class="hero-sub-inner" style="max-width:760px">
      <span class="badge reveal"><span class="pulse"></span><span>{{ __('ui.cr_badge') }}</span></span>
      <h1 class="reveal" style="transition-delay:.08s">{{ __('ui.cr_h1a') }} <span class="grad">{{ __('ui.cr_h1b') }}</span></h1>
      <p class="lead reveal" style="transition-delay:.16s">{{ __('ui.cr_lead') }}</p>
      <div class="reveal" style="transition-delay:.24s;margin-top:24px">
        <a class="btn btn-primary" href="#positions">{{ __('ui.cr_see_jobs') }}<svg class="icon dir"><use href="#i-arrow"/></svg></a>
      </div>
    </div>
  </div>
</section>

{{-- ============ PERKS ============ --}}
<section class="section" style="padding-top:20px">
  <div class="container">
    <div class="section-head reveal"><h2>{{ __('ui.cr_perks_t') }}</h2><p>{{ __('ui.cr_perks_d') }}</p></div>
    <div class="sol-feat-grid" style="grid-template-columns:repeat(4,1fr)">
      @foreach($perks as $p)
      <div class="sol-feat reveal">
        <span class="sol-feat-ic"><svg class="icon"><use href="#i-{{ $p['icon'] }}"/></svg></span>
        <h3>{{ lc($p)['t'] }}</h3><p>{{ lc($p)['d'] }}</p>
      </div>
      @endforeach
    </div>
  </div>
</section>

{{-- ============ OPEN POSITIONS ============ --}}
<section class="section sol-steps-sec" id="positions" style="padding-top:44px">
  <div class="container" style="max-width:900px">
    <div class="section-head reveal"><span class="kicker">{{ __('ui.cr_open_badge') }}</span><h2>{{ __('ui.cr_open_t') }}</h2></div>
    <div class="cr-jobs">
      @foreach($positions as $job)
      <details class="cr-job reveal">
        <summary>
          <span class="cr-job-ic"><svg class="icon"><use href="#i-{{ $job['icon'] }}"/></svg></span>
          <span class="cr-job-tx"><b>{{ lc($job)['t'] }}</b><small>{{ lc($job)['d'] }}</small></span>
          <span class="cr-job-type">{{ __('ui.cr_fulltime') }}</span>
          <svg class="icon cr-job-chev"><use href="#i-chev"/></svg>
        </summary>
        <div class="cr-job-body">
          <h4>{{ __('ui.cr_req') }}</h4>
          <ul>@foreach(lc($job)['req'] as $r)<li><svg class="icon"><use href="#i-check"/></svg>{{ $r }}</li>@endforeach</ul>
          <a class="btn btn-glass" href="#apply" onclick="document.getElementById('cr-position').value='{{ lc($job)['t'] }}'">{{ __('ui.cr_apply_this') }}</a>
        </div>
      </details>
      @endforeach
    </div>
  </div>
</section>

{{-- ============ APPLICATION FORM ============ --}}
<section class="section" id="apply" style="padding-top:44px;padding-bottom:70px">
  <div class="container" style="max-width:720px">
    <div class="section-head reveal"><h2>{{ __('ui.cr_apply_t') }}</h2><p>{{ __('ui.cr_apply_d') }}</p></div>

    @if(session('career_status') === 'ok')
    <div class="blog-note ok reveal">{{ __('ui.cr_ok') }}</div>
    @elseif(session('career_status') === 'busy')
    <div class="blog-note reveal">{{ __('ui.cr_busy') }}</div>
    @endif
    @if($errors->any())<div class="blog-note err reveal">{{ __('ui.cr_err') }}</div>@endif

    <form class="cr-form reveal" method="post" action="{{ lroute('careers.apply') }}">
      @csrf
      <div class="cr-form-row">
        <label><span>{{ __('ui.cr_f_name') }} *</span><input type="text" name="name" maxlength="100" required value="{{ old('name') }}"></label>
        <label><span>{{ __('ui.cr_f_email') }} *</span><input type="email" name="email" maxlength="120" required dir="ltr" value="{{ old('email') }}"></label>
      </div>
      <div class="cr-form-row">
        <label><span>{{ __('ui.cr_f_phone') }}</span><input type="text" name="phone" maxlength="30" dir="ltr" value="{{ old('phone') }}"></label>
        <label><span>{{ __('ui.cr_f_position') }} *</span>
          <select name="position" id="cr-position" required>
            @foreach($positions as $job)<option value="{{ lc($job)['t'] }}" @selected(old('position')===lc($job)['t'])>{{ lc($job)['t'] }}</option>@endforeach
            <option value="{{ __('ui.cr_other') }}">{{ __('ui.cr_other') }}</option>
          </select>
        </label>
      </div>
      <label class="cr-full"><span>{{ __('ui.cr_f_resume') }}</span><input type="url" name="resume" maxlength="300" dir="ltr" placeholder="https://…" value="{{ old('resume') }}"><small>{{ __('ui.cr_resume_note') }}</small></label>
      <label class="cr-full"><span>{{ __('ui.cr_f_message') }}</span><textarea name="message" rows="4" maxlength="2000">{{ old('message') }}</textarea></label>
      <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
      <div class="cr-form-foot">
        <button class="btn btn-primary" type="submit">{{ __('ui.cr_submit') }}<svg class="icon dir"><use href="#i-send"/></svg></button>
        <span>{{ __('ui.cr_or_email') }} <a href="mailto:{{ $contact['email'] }}?subject=Resume">{{ $contact['email'] }}</a></span>
      </div>
    </form>
  </div>
</section>
@endsection
