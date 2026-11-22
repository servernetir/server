<div class="container sn-hero-controls">
  <div class="sn-hero-selectors" role="group" aria-label="{{ __('studio_carousel.choose_slide') }}">
    <button type="button" data-hero-target="infrastructure" aria-controls="hero-infrastructure" aria-label="{{ __('studio_carousel.slide_infrastructure') }}" aria-pressed="true"><span class="sn-hero-progress" aria-hidden="true"><i></i></span><span class="sn-hero-wide">{{ __('studio_carousel.slide_infrastructure') }}</span><span class="sn-hero-compact">{{ __('studio_carousel.slide_short') }}</span></button>
    <button type="button" data-hero-target="studio" aria-controls="studio" aria-label="ServerNet Studio" aria-pressed="false"><span class="sn-hero-progress" aria-hidden="true"><i></i></span><span class="sn-hero-wide" dir="ltr">ServerNet Studio</span><span class="sn-hero-compact" dir="ltr">Studio</span></button>
  </div>
  <button type="button" class="sn-hero-autoplay" data-hero-autoplay aria-label="{{ __('studio_carousel.pause') }}" data-pause-label="{{ __('studio_carousel.pause') }}" data-play-label="{{ __('studio_carousel.play') }}" aria-pressed="false"><svg class="icon sn-hero-pause-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14M16 5v14"/></svg><svg class="icon sn-hero-play-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m8 5 10 7-10 7Z"/></svg></button>
  <p class="sn-hero-announcement" aria-live="polite" aria-atomic="true" data-hero-announcement></p>
</div>
<noscript><div class="container sn-hero-nojs"><p>{{ __('studio_carousel.lead') }}</p><a class="btn btn-primary" href="https://studio.servernet.cloud/">{{ __('studio_carousel.open') }}</a></div></noscript>
