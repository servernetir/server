@if(app(\App\Services\Studio\StudioAuthorization::class)->configured())
@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/studio-carousel.css') }}">
<script src="{{ asset_ver('assets/js/studio-carousel.js') }}" defer></script>
@endpush
@php $studioModes = ['chat' => 'message', 'image' => 'image', 'video' => 'video', 'audio' => 'mic']; @endphp
<section class="sn-studio-hero" id="studio" data-studio-home data-hero-slide="studio" aria-labelledby="studio-title" aria-hidden="true" inert>
  <div class="container sn-studio-layout">
    <div class="sn-studio-copy">
      <div class="sn-studio-brand"><svg class="icon" aria-hidden="true"><use href="#i-sparkles"/></svg><span dir="ltr">ServerNet Studio</span><span class="sn-studio-new">{{ __('studio_carousel.new') }}</span></div>
      <h2 id="studio-title">{{ __('studio_carousel.title') }}<span>{{ __('studio_carousel.title_accent') }}</span></h2>
      <p class="sn-studio-lead">{{ __('studio_carousel.lead') }}</p>
      <ul class="sn-studio-use-cases" aria-label="{{ __('studio_carousel.capabilities') }}">
        @foreach(['writing', 'translation', 'creating', 'learning'] as $task)
        <li><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>{{ __('studio_carousel.'.$task) }}</li>
        @endforeach
      </ul>
      <div class="sn-studio-actions">
        <a class="btn btn-primary sn-studio-launch" href="https://studio.servernet.cloud/">{{ __('studio_carousel.open') }}<svg class="icon" aria-hidden="true"><use href="#i-sparkles"/></svg></a>
        <a class="btn btn-glass sn-studio-install" href="{{ lroute('studio.download') }}#studio-web-install">{{ __('studio_carousel.web_guide') }}</a>
      </div>
      <p class="sn-studio-billing">{{ __('studio_carousel.account') }}<span aria-hidden="true">·</span>{{ __('studio_carousel.billing') }}</p>
    </div>
    <div class="sn-studio-scene">
      <div class="sn-studio-orbit" aria-hidden="true"></div>
      <div class="sn-studio-glass">
        <div class="sn-studio-glass-head"><span class="sn-studio-glass-brand" dir="ltr"><svg class="icon" aria-hidden="true"><use href="#i-sparkles"/></svg>ServerNet <b>Studio</b></span><span>{{ __('studio_carousel.preview') }}</span></div>
        <div class="sn-studio-modes" data-studio-modes aria-label="{{ __('studio_carousel.capabilities') }}">
          @foreach($studioModes as $mode => $icon)
          <button type="button" data-studio-mode="{{ $mode }}" aria-controls="studio-panel-{{ $mode }}" aria-pressed="{{ $mode === 'image' ? 'true' : 'false' }}" hidden><svg class="icon" aria-hidden="true"><use href="#i-{{ $icon }}"/></svg>{{ __('studio_carousel.'.$mode) }}</button>
          @endforeach
        </div>
        <div class="sn-studio-panels">
          @foreach($studioModes as $mode => $icon)
          <div class="sn-studio-panel" id="studio-panel-{{ $mode }}" data-studio-panel="{{ $mode }}" @if($mode !== 'image') hidden @endif>
            @if($mode === 'image' || $mode === 'video')
            <div class="sn-studio-output sn-studio-output-{{ $mode }}">
              <img src="{{ asset_ver('assets/img/studio-coffee-campaign.webp') }}" width="1536" height="1024" alt="{{ __('studio_carousel.image_alt') }}" loading="lazy" decoding="async">
              @if($mode === 'video')<span class="sn-studio-motion-line" aria-hidden="true"></span><span class="sn-studio-video-label"><svg class="icon" aria-hidden="true"><use href="#i-video"/></svg>{{ __('studio_carousel.video_label') }}</span>@endif
              <span class="sn-studio-output-label">{{ __('studio_carousel.'.$mode.'_label') }}</span>
            </div>
            @elseif($mode === 'chat')
            <div class="sn-studio-chat">
              <span class="sn-studio-chat-kicker">{{ __('studio_carousel.chat_kicker') }}</span>
              <p class="sn-studio-question">{{ __('studio_carousel.chat_prompt') }}</p>
              <div class="sn-studio-answer"><svg class="icon" aria-hidden="true"><use href="#i-sparkles"/></svg><p>{{ __('studio_carousel.chat_reply') }}</p></div>
              <div class="sn-studio-text-lines" aria-hidden="true"><i></i><i></i><i></i></div>
            </div>
            @else
            <div class="sn-studio-audio">
              <span class="sn-studio-microphone"><svg class="icon" aria-hidden="true"><use href="#i-mic"/></svg></span>
              <h3>{{ __('studio_carousel.audio_label') }}</h3>
              <div class="sn-studio-wave" aria-hidden="true">@foreach([20,36,60,28,72,90,46,78,100,54,84,66,95,58,80,42,88,64,32,70,48,24,58,34] as $height)<i style="--wave:{{ $height }}%;--delay:{{ $loop->index * -75 }}ms"></i>@endforeach</div>
              <p>{{ __('studio_carousel.audio_quote') }}</p>
            </div>
            @endif
            <div class="sn-studio-prompt"><svg class="icon" aria-hidden="true"><use href="#i-sparkles"/></svg><p>{{ __('studio_carousel.'.$mode.'_prompt') }}</p><span class="sn-studio-prompt-mark" aria-hidden="true">+</span></div>
          </div>
          @endforeach
        </div>
        <div class="sn-studio-glass-foot"><span>{{ __('studio_carousel.preview_note') }}</span><span dir="ltr">studio.servernet.cloud</span></div>
      </div>
      <div class="sn-studio-float sn-studio-float-top" aria-hidden="true"><svg class="icon"><use href="#i-message"/></svg><span>{{ __('studio_carousel.float_writing') }}</span></div>
      <div class="sn-studio-float sn-studio-float-bottom" aria-hidden="true"><svg class="icon"><use href="#i-image"/></svg><span>{{ __('studio_carousel.float_creating') }}</span><span class="sn-studio-float-spark">✦</span></div>
    </div>
  </div>
</section>
@endif
