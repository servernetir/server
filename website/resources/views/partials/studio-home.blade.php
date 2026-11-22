@if(app(\App\Services\Studio\StudioAuthorization::class)->configured())
@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/studio-home.css') }}">
<script src="{{ asset_ver('assets/js/studio-home.js') }}" defer></script>
@endpush
@php
  $studioModes = ['image' => 'image', 'chat' => 'message', 'video' => 'video', 'audio' => 'mic'];
@endphp
<section class="sn-studio" id="studio" aria-labelledby="studio-title" data-studio-home>
  <div class="container">
    <div class="sn-studio-topline">
      <span class="sn-studio-wordmark" dir="ltr">ServerNet <b>Studio</b><svg class="icon" aria-hidden="true"><use href="#i-sparkles"/></svg></span>
      <span class="sn-studio-new">{{ __('studio_home.new') }}</span>
    </div>
    <div class="sn-studio-grid">
      <div class="sn-studio-copy">
        <span class="sn-studio-eyebrow">{{ __('studio_home.eyebrow') }}</span>
        <h2 id="studio-title">{{ __('studio_home.title') }}<span>{{ __('studio_home.title_accent') }}</span></h2>
        <p class="sn-studio-lead">{{ __('studio_home.lead') }}</p>
        <div class="sn-studio-actions">
          <a class="sn-studio-launch" href="https://studio.servernet.cloud/">{{ __('studio_home.open') }}<svg class="icon" aria-hidden="true"><use href="#i-sparkles"/></svg></a>
          <a class="sn-studio-explore" href="#studio-capabilities">{{ __('studio_home.explore') }}</a>
        </div>
        <p class="sn-studio-billing"><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>{{ __('studio_home.no_code') }}<span aria-hidden="true">·</span>{{ __('studio_home.billing') }}</p>
      </div>
      <div class="sn-studio-showcase">
        <div class="sn-studio-window">
          <div class="sn-studio-windowbar"><span dir="ltr">studio.servernet.cloud</span><span>{{ __('studio_home.preview') }}</span></div>
          <div class="sn-studio-panels">
            @foreach($studioModes as $mode => $icon)
            <div class="sn-studio-panel" id="studio-panel-{{ $mode }}" data-studio-panel="{{ $mode }}" @if($mode !== 'image') hidden @endif>
              @if($mode === 'image' || $mode === 'video')
              <div class="sn-studio-art sn-studio-art-{{ $mode }}">
                <img src="{{ asset_ver('assets/img/studio-imagination.svg') }}" width="960" height="680" alt="" @if($mode === 'image') fetchpriority="high" @else loading="lazy" @endif>
                @if($mode === 'video')<span class="sn-studio-play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m9 5 11 7-11 7z"/></svg></span><span class="sn-studio-film" aria-hidden="true"></span>@endif
                <span class="sn-studio-art-tag"><svg class="icon" aria-hidden="true"><use href="#i-{{ $icon }}"/></svg>{{ __('studio_home.'.$mode.'_title') }}</span>
              </div>
              @elseif($mode === 'chat')
              <div class="sn-studio-chat">
                <div class="sn-studio-chat-orb" aria-hidden="true"><svg class="icon"><use href="#i-sparkles"/></svg></div>
                <p class="sn-studio-chat-question">{{ __('studio_home.chat_prompt') }}</p>
                <p class="sn-studio-chat-answer">{{ __('studio_home.chat_reply') }}</p>
              </div>
              @else
              <div class="sn-studio-audio">
                <span class="sn-studio-audio-label"><svg class="icon" aria-hidden="true"><use href="#i-mic"/></svg>{{ __('studio_home.audio_title') }}</span>
                <div class="sn-studio-wave" aria-hidden="true">@foreach([18,30,48,24,56,78,42,65,90,54,72,100,66,84,50,96,70,46,88,60,38,74,52,28,64,42,22,46,30,18,36,24] as $height)<i style="--wave:{{ $height }}%;--delay:{{ $loop->index * -65 }}ms"></i>@endforeach</div>
                <p>{{ __('studio_home.audio_prompt') }}</p>
              </div>
              @endif
              <div class="sn-studio-prompt"><svg class="icon" aria-hidden="true"><use href="#i-sparkles"/></svg><div><span>{{ __('studio_home.prompt_label') }}</span><p>{{ __('studio_home.'.$mode.'_prompt') }}</p></div></div>
            </div>
            @endforeach
          </div>
        </div>
        <p class="sn-studio-preview-note">{{ __('studio_home.preview_note') }}</p>
      </div>
    </div>
    <div class="sn-studio-capabilities" id="studio-capabilities">
      <h3>{{ __('studio_home.capabilities') }}</h3>
      <div class="sn-studio-modes" data-studio-modes aria-label="{{ __('studio_home.capabilities') }}">
        @foreach($studioModes as $mode => $icon)
        <button type="button" class="sn-studio-mode" data-studio-mode="{{ $mode }}" aria-controls="studio-panel-{{ $mode }}" aria-pressed="{{ $mode === 'image' ? 'true' : 'false' }}" hidden>
          <svg class="icon" aria-hidden="true"><use href="#i-{{ $icon }}"/></svg><span><b>{{ __('studio_home.'.$mode) }}</b><span>{{ __('studio_home.'.$mode.'_description') }}</span></span>
        </button>
        @endforeach
      </div>
      <noscript><p class="sn-studio-nojs">{{ __('studio_home.image_description') }} {{ __('studio_home.chat_description') }} {{ __('studio_home.video_description') }} {{ __('studio_home.audio_description') }}</p></noscript>
    </div>
    <div class="sn-studio-benefits">
      <div><b>{{ __('studio_home.account_title') }}</b><span>{{ __('studio_home.account_description') }}</span></div>
      <div><b>{{ __('studio_home.wallet_title') }}</b><span>{{ __('studio_home.wallet_description') }}</span></div>
      <div><b>{{ __('studio_home.web_title') }}</b><a href="{{ lroute('studio.download') }}#studio-web-install">{{ __('studio_home.web_guide') }}</a></div>
    </div>
    <div class="sn-studio-foot"><p>{{ __('studio_home.availability') }}</p><p>{{ __('studio_home.infrastructure') }} <a href="#products">{{ __('studio_home.infrastructure_link') }}</a></p></div>
  </div>
</section>
@endif
