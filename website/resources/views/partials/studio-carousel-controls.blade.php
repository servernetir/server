<div class="container sn-hero-controls">
  <div class="sn-hero-selectors" role="group" aria-label="{{ __('studio_carousel.choose_slide') }}">
    <button type="button" data-hero-target="infrastructure" aria-controls="hero-infrastructure" aria-label="{{ __('home_slides.vps_label') }}" aria-pressed="true"><span class="sn-hero-progress" aria-hidden="true"><i></i></span><span class="sn-hero-wide">{{ __('home_slides.vps_label') }}</span><span class="sn-hero-compact">{{ __('home_slides.vps_short') }}</span></button>
    <button type="button" data-hero-target="gpu" aria-controls="hero-gpu" aria-label="{{ __('home_slides.gpu_label') }}" aria-pressed="false"><span class="sn-hero-progress" aria-hidden="true"><i></i></span><span class="sn-hero-wide">{{ __('home_slides.gpu_label') }}</span><span class="sn-hero-compact" dir="ltr">GPU</span></button>
    @if($studioCarouselAvailable)
    <button type="button" data-hero-target="studio" aria-controls="studio" aria-label="{{ __('studio_carousel.brand') }}" aria-pressed="false"><span class="sn-hero-progress" aria-hidden="true"><i></i></span><span class="sn-hero-wide">{{ __('studio_carousel.label') }}</span><span class="sn-hero-compact">{{ __('studio_carousel.short') }}</span></button>
    @endif
    @foreach(['n8n', 'hosting', 'email', 'parts'] as $slide)
    <button type="button" data-hero-target="{{ $slide }}" aria-controls="hero-{{ $slide }}" aria-label="{{ __('home_slides.'.$slide.'_label') }}" aria-pressed="false"><span class="sn-hero-progress" aria-hidden="true"><i></i></span><span class="sn-hero-wide">{{ __('home_slides.'.$slide.'_label') }}</span><span class="sn-hero-compact">{{ __('home_slides.'.$slide.'_short') }}</span></button>
    @endforeach
  </div>
  <button type="button" class="sn-hero-autoplay" data-hero-autoplay aria-label="{{ __('studio_carousel.pause') }}" data-pause-label="{{ __('studio_carousel.pause') }}" data-play-label="{{ __('studio_carousel.play') }}" aria-pressed="false"><svg class="icon sn-hero-pause-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14M16 5v14"/></svg><svg class="icon sn-hero-play-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m8 5 10 7-10 7Z"/></svg></button>
  <p class="sn-hero-announcement" aria-live="polite" aria-atomic="true" data-hero-announcement></p>
</div>
<noscript><div class="container sn-hero-nojs"><a href="{{ lroute('gpu') }}">{{ __('home_slides.gpu_label') }}</a> · <a href="{{ lroute('cloud.location', 'n8n') }}">{{ __('home_slides.n8n_label') }}</a> · <a href="{{ lroute('hosting', 'linux') }}">{{ __('home_slides.hosting_label') }}</a> · <a href="{{ lroute('email.transactional') }}">{{ __('home_slides.email_label') }}</a>@if($studioCarouselAvailable) · <a href="https://studio.servernet.cloud/">{{ __('studio_carousel.brand') }}</a>@endif · <a href="{{ lroute('parts.index') }}">{{ __('home_slides.parts_label') }}</a></div></noscript>
