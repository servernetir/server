<section class="sn-service-hero sn-email-hero" id="hero-email" data-hero-slide="email" aria-labelledby="hero-email-title" aria-hidden="true" inert>
  <div class="container sn-service-layout">
    <div class="sn-service-copy">
      <p class="sn-service-eyebrow"><span class="sn-service-mark"><svg class="icon" aria-hidden="true"><use href="#i-mail"/></svg></span>{{ __('transactional-email.badge') }}</p>
      <h2 id="hero-email-title">{{ __('transactional-email.title') }}<span>{{ __('transactional-email.title_accent') }}</span></h2>
      <p class="sn-service-lead">{{ __('transactional-email.lead') }}</p>
      <ul class="sn-service-benefits">
        @foreach(trans('transactional-email.slide_benefits') as $benefit)
        <li><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>{{ $benefit }}</li>
        @endforeach
      </ul>
      <div class="sn-service-actions sn-email-actions">
        <a class="btn btn-primary" href="{{ lroute('email.transactional') }}">{{ __('transactional-email.slide_cta') }}</a>
        <a class="btn btn-glass" href="{{ lroute('email.transactional') }}#pricing">{{ __('transactional-email.cta') }}</a>
      </div>
      <p class="sn-service-note">{{ __('transactional-email.slide_note') }}</p>
    </div>
    @include('partials.transactional-email-visual')
  </div>
</section>
