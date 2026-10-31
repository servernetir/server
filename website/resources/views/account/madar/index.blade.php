@extends('panel.layout')
@section('title', __('madar.nav').' — ServerNet')
@push('head')<link rel="stylesheet" href="{{ asset_ver('assets/css/madar.css') }}">@endpush
@section('panel')
<div class="mdr">
  <header class="mdr-hero">
    <div class="mdr-hero-copy">
      <span class="mdr-eyebrow" dir="ltr">{{ __('madar.brand') }}</span>
      <h1>{{ __('madar.nav') }}</h1>
      <p class="mdr-tagline">{{ __('madar.tagline') }}</p>
      <p>{{ __('madar.intro') }}</p>
      <nav class="mdr-actions" aria-label="{{ __('madar.nav') }}">
        <a class="pnl-btn primary" href="#missions">{{ __('madar.missions') }} <span aria-hidden="true">↗</span></a>
        <a class="pnl-btn" href="#my-work">{{ __('madar.my_work') }}</a>
        <a class="mdr-text-link" href="#terms">{{ __('madar.rules') }}</a>
      </nav>
    </div>
    <div class="mdr-orbit" aria-hidden="true"><span class="mdr-ring mdr-ring-1"></span><span class="mdr-ring mdr-ring-2"></span><span class="mdr-ring mdr-ring-3"></span><b>M</b><i>GPU</i><em>&lt;/&gt;</em><small>PLAY</small></div>
  </header>
  @include('madar.feedback')
  @unless($ready)<p class="mdr-notice" role="status">{{ __('madar.not_ready') }}</p>
@endunless

  <section class="mdr-steps" aria-label="{{ __('madar.how') }}">
    @foreach([1,2,3,4] as $step)<div><b>{{ fa_num($step) }}</b><span>{{ __('madar.step'.$step) }}</span></div>
@endforeach

  </section>
  <section id="missions" class="mdr-section">
    <div class="mdr-section-head"><h2>{{ __('madar.missions') }}</h2><span>{{ __('madar.reward_agreement') }}</span></div>
    <div class="mdr-campaigns">
      @foreach($campaigns as $key => $campaign)
      <article class="mdr-campaign mdr-campaign-{{ $campaign['tag'] }}">
        <div class="mdr-card-top"><span class="mdr-icon"><svg class="icon"><use href="#i-{{ $campaign['icon'] }}"/></svg></span><span class="mdr-badge {{ $campaign['open'] ? 'is-open' : '' }}">{{ __('madar.'.($campaign['open'] ? 'open' : 'closed')) }}</span></div>
        <h3>{{ __('madar.campaigns.'.$key.'.title') }}</h3>
        <p>{{ __('madar.campaigns.'.$key.'.description') }}</p>
        <details><summary>{{ __('madar.requirements') }}</summary><p>{{ __('madar.campaigns.'.$key.'.requirements') }}</p><strong>{{ __('madar.example') }}</strong><p>{{ __('madar.campaigns.'.$key.'.example') }}</p></details>
        <footer><span>{{ __('madar.reward_agreement') }}</span>@if($campaign['open'])<a href="{{ lroute('account.madar') }}?campaign={{ $key }}#apply" class="pnl-btn">{{ __('madar.apply') }} <span aria-hidden="true">↗</span></a>
@endif
</footer>
      </article>

@endforeach

    </div>
  </section>
  <section id="my-work" class="mdr-section">
    <div class="mdr-section-head"><h2>{{ __('madar.my_work') }}</h2><span>{{ __('madar.review_states') }}</span></div>
    @forelse($participations as $p)
    <a class="mdr-case-row" href="{{ lroute('account.madar.show', $p) }}"><div><strong>{{ $p->title }}</strong><small>{{ __('madar.campaigns.'.$p->campaign_key.'.title') }} · {{ blog_date($p->updated_at) }}</small></div><div class="mdr-row-end">@if($p->customer_read_at === null)<span class="mdr-badge is-open">{{ __('madar.unread') }}</span>
@endif
<span class="mdr-badge">{{ __('madar.statuses.'.$p->status) }}</span><span aria-hidden="true">↗</span></div></a>
    @empty<div class="mdr-empty"><b>{{ __('madar.empty') }}</b><p>{{ __('madar.empty_note') }}</p></div>
@endforelse

    @if($ready){{ $participations->links() }}
@endif

  </section>
  @if($ready)
  <section id="apply" class="mdr-form-card mdr-section">
    <div><span class="mdr-eyebrow" dir="ltr">LET'S MAKE SOMETHING</span><h2>{{ __('madar.apply') }}</h2><p>{{ __('madar.reward_hint') }}</p><p class="mdr-notice">{{ __('madar.proposal_only') }}</p></div>
    <form method="post" action="{{ lroute('account.madar.apply') }}">

@csrf
<input type="hidden" name="request_key" value="{{ old('request_key', $requestKey) }}"><input type="hidden" name="terms_version" value="{{ config('madar.terms_version') }}">
      <label>{{ __('madar.campaign') }}<select name="campaign" required>@foreach($campaigns as $key => $campaign)@if($campaign['open'])<option value="{{ $key }}" @selected(old('campaign', request('campaign')) === $key)>{{ __('madar.campaigns.'.$key.'.title') }}</option>
@endif

@endforeach
</select></label>
      <label>{{ __('madar.project_title') }}<input name="title" required minlength="5" maxlength="180" value="{{ old('title') }}" placeholder="{{ __('madar.project_placeholder') }}"></label>
      <label>{{ __('madar.body') }}<textarea name="body" rows="5" required minlength="20" maxlength="5000" placeholder="{{ __('madar.proposal_placeholder') }}">{{ old('body') }}</textarea></label>
      <label class="mdr-check"><input type="checkbox" name="terms" value="1" required @checked(old('terms'))><span>{{ __('madar.terms_accept') }} <a href="#terms">{{ __('madar.rules') }}</a></span></label>
      <button class="pnl-btn primary" type="submit">{{ __('madar.send_proposal') }}</button>
    </form>
  </section>

@endif

  <section id="terms" class="mdr-terms mdr-section"><h2>{{ __('madar.terms_title') }}</h2><p>{{ __('madar.terms_text') }}</p><p>{{ __('madar.hourly_notice') }}</p><small>{{ __('madar.terms_version', ['version' => config('madar.terms_version')]) }}</small></section>
</div>
@endsection
