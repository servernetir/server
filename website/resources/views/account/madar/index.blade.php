@extends('panel.layout')
@section('title', __('madar.nav').' — ServerNet')
@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/madar.css') }}">
<script src="{{ asset_ver('assets/js/madar.js') }}" defer></script>
@endpush
@section('panel')
@php $available = collect($campaigns)->filter(fn ($c) => $c['open']); @endphp
<div class="mdr">
  <header class="mdr-hero">
    <div class="mdr-hero-copy">
      <span class="mdr-eyebrow" dir="ltr">{{ __('madar.brand') }}</span>
      <h1>{{ __('madar.nav') }}</h1><p class="mdr-tagline">{{ __('madar.tagline') }}</p><p>{{ __('madar.intro') }}</p>
      <nav class="mdr-actions" aria-label="{{ __('madar.nav') }}">
        <a class="pnl-btn primary" href="#missions">{{ __('madar.missions') }} <span aria-hidden="true">↗</span></a>
        <a class="pnl-btn" href="#my-work">{{ __('madar.my_work') }}</a><a class="mdr-text-link" href="#terms">{{ __('madar.rules') }}</a>
      </nav>
    </div>
    <div class="mdr-orbit" aria-hidden="true"><span class="mdr-ring mdr-ring-1"></span><span class="mdr-ring mdr-ring-2"></span><span class="mdr-ring mdr-ring-3"></span><b>M</b><i>GPU</i><em>&lt;/&gt;</em><small>PLAY</small></div>
  </header>
  @include('madar.feedback')
  @unless($ready)
    <p class="mdr-notice" role="status">{{ __('madar.not_ready') }}</p>
  @endunless
  <section class="mdr-steps" aria-label="{{ __('madar.how') }}">
    @foreach([1,2,3] as $step)
      <div><b>{{ fa_num($step) }}</b><span>{{ __('madar.step'.$step) }}</span></div>
    @endforeach
  </section>
  <section id="missions" class="mdr-section">
    <div class="mdr-section-head"><h2>{{ __('madar.missions') }}</h2><span>{{ __('madar.fixed_intro') }}</span></div>
    <div class="mdr-campaigns">
      @foreach($campaigns as $key => $campaign)
      <article class="mdr-campaign mdr-campaign-{{ $campaign['tag'] }}" data-campaign="{{ $key }}">
        <div class="mdr-card-top"><span class="mdr-icon"><svg class="icon"><use href="#i-{{ $campaign['icon'] }}"/></svg></span><span class="mdr-badge {{ $campaign['open'] ? 'is-open' : '' }}">{{ __('madar.'.($campaign['open'] ? 'claim_open' : 'closed')) }}</span></div>
        <h3>{{ $campaign['copy']['title'] }}</h3><p>{{ $campaign['copy']['description'] }}</p>
        <div class="mdr-price"><b>{{ invoice_money($campaign['amount_irt'], 'IRT') }}</b><small>{{ __('madar.reward_type') }}</small></div>
        <p class="mdr-meta">{{ __('madar.audience') }}: {{ $campaign['copy']['audience'] }}</p>
        <p class="mdr-meta">{{ __('madar.channel') }}: {{ $campaign['copy']['channel'] }}</p>
        <details>
          <summary>{{ __('madar.requirements') }}</summary><p>{{ $campaign['copy']['requirements'] }}</p>
          <strong>{{ __('madar.example') }}</strong><p>{{ $campaign['copy']['example'] }}</p><p>{{ __('madar.sponsorship') }}</p>
          @if($campaign['published'])
            <p>{{ __('madar.claim_until') }}: {{ blog_date($campaign['published']->submissions_until) }}</p>
            <p>{{ __('madar.reward_window', ['days' => fa_num(intdiv($campaign['published']->snapshot['validity_hours'], 24))]) }}</p>
            <p>{{ __('madar.minimum') }}: {{ invoice_money($campaign['published']->snapshot['rule']['min_purchase'], 'IRT') }}</p>
            <p>{{ __('madar.scope') }}: {{ collect($campaign['published']->snapshot['rule']['families'])->map(fn ($f) => \Illuminate\Support\Facades\Lang::has('madar.families.'.$f) ? __('madar.families.'.$f) : __('madar.scope'))->join(' / ') }}</p>
            <p>{{ __('madar.operations') }}: {{ collect($campaign['published']->snapshot['rule']['operations'])->map(fn ($op) => __('madar.op.'.$op))->join(' / ') }}</p>
            <p>{{ __('madar.margin') }} {{ __('madar.hourly_notice') }}</p>
            <p>{{ __('madar.capacity', ['count' => fa_num($campaign['capacity'])]) }}</p>
          @endif
          <label class="mdr-share">{{ __('madar.share_link') }}<input type="text" dir="ltr" readonly value="{{ $campaign['share_url'] }}" aria-label="{{ __('madar.share_link') }}"></label>
        </details>
        <footer><span>{{ __('madar.review_eta') }}</span>
          @if($campaign['open'])
            <a href="{{ lroute('account.madar') }}?mission={{ $campaign['published']->id }}#claim" class="pnl-btn">{{ __('madar.claim_cta') }} <span aria-hidden="true">↗</span></a>
          @else
            <small class="mdr-meta">{{ __('madar.paused_note') }}</small>
          @endif
        </footer>
      </article>
      @endforeach
    </div>
  </section>
  <section id="my-work" class="mdr-section">
    <div class="mdr-section-head"><h2>{{ __('madar.my_work') }}</h2><span>{{ __('madar.review_states') }}</span></div>
    @forelse($participations as $p)
      <a class="mdr-case-row" href="{{ lroute('account.madar.show', $p) }}"><div><strong>{{ $p->title }}</strong><small>{{ __('madar.campaigns.'.$p->campaign_key.'.title') }} · {{ blog_date($p->updated_at) }}</small></div><div class="mdr-row-end">
      @if($p->customer_read_at === null)
        <span class="mdr-badge is-open">{{ __('madar.unread') }}</span>
      @endif
      <span class="mdr-badge">{{ __('madar.statuses.'.$p->status) }}</span><span aria-hidden="true">↗</span></div></a>
    @empty
      <div class="mdr-empty"><b>{{ __('madar.empty') }}</b><p>{{ __('madar.empty_note') }}</p></div>
    @endforelse
    @if($ready)
      {{ $participations->links() }}
    @endif
  </section>
  @if($catalogReady && $available->isNotEmpty())
  <section id="claim" class="mdr-form-card mdr-section">
    <div><span class="mdr-eyebrow" dir="ltr">MADE IT? SHARE IT.</span><h2>{{ __('madar.claim_cta') }}</h2><p>{{ __('madar.claim_hint') }}</p><p class="mdr-notice">{{ __('madar.limits') }} {{ __('madar.capacity_note') }}</p></div>
    <form method="post" enctype="multipart/form-data" action="{{ lroute('account.madar.claim') }}" data-madar-claim>
      @csrf
      <input type="hidden" name="request_key" value="{{ old('request_key', $requestKey) }}"><input type="hidden" name="terms_version" value="{{ config('madar.terms_version') }}">
      <label>{{ __('madar.campaign') }}<select name="mission_id" required>
        @foreach($available as $campaign)
          <option value="{{ $campaign['published']->id }}" data-public="{{ (int) $campaign['public_content'] }}" data-distribution="{{ (int) $campaign['distribution_required'] }}" data-terms="{{ $campaign['published']->snapshot['terms_version'] }}" @selected((int) old('mission_id', request('mission')) === $campaign['published']->id)>{{ $campaign['copy']['title'] }} · {{ invoice_money($campaign['amount_irt'], 'IRT') }}</option>
        @endforeach
      </select></label>
      <label>{{ __('madar.project_title') }}<input name="title" required minlength="5" maxlength="180" value="{{ old('title') }}" placeholder="{{ __('madar.project_placeholder') }}"></label>
      <label>{{ __('madar.body') }}<textarea name="body" rows="4" required minlength="5" maxlength="5000" placeholder="{{ __('madar.claim_body_placeholder') }}">{{ old('body') }}</textarea></label>
      <label>{{ __('madar.evidence_url') }}<input name="evidence_url" type="url" dir="ltr" maxlength="1500" placeholder="https://" value="{{ old('evidence_url') }}"></label>
      <label>{{ __('madar.distribution_url') }}<input name="distribution_url" type="url" dir="ltr" maxlength="1500" placeholder="https://" value="{{ old('distribution_url') }}"><small>{{ __('madar.distribution_hint') }}</small></label>
      <label>{{ __('madar.attachment') }}<input name="attachment" type="file" accept="image/jpeg,image/png,image/webp"><small>{{ __('madar.attachment_hint') }}</small></label>
      <label class="mdr-check"><input type="checkbox" name="rights_confirmed" value="1" required><span>{{ __('madar.rights') }}</span></label>
      <label class="mdr-check" data-madar-disclosure><input type="checkbox" name="disclosure_confirmed" value="1"><span>{{ __('madar.disclosure') }}</span></label>
      <label class="mdr-check"><input type="checkbox" name="terms" value="1" required><span>{{ __('madar.terms_accept') }} <a href="#terms">{{ __('madar.rules') }}</a></span></label>
      <p class="mdr-small">{{ __('madar.reuse') }}</p><button class="pnl-btn primary" type="submit">{{ __('madar.send_claim') }}</button>
    </form>
  </section>
  @endif
  <section id="terms" class="mdr-terms mdr-section"><h2>{{ __('madar.terms_title') }}</h2><p>{{ __('madar.terms_text') }}</p><p>{{ __('madar.limits') }}</p><p>{{ __('madar.sponsorship') }}</p><p>{{ __('madar.hourly_notice') }}</p><small>{{ __('madar.terms_version', ['version' => config('madar.terms_version')]) }}</small></section>
</div>
@endsection
