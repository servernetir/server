<section class="sn-service-hero sn-n8n-hero" id="hero-n8n" data-hero-slide="n8n" aria-labelledby="hero-n8n-title" aria-hidden="true" inert>
  <div class="container sn-service-layout">
    <div class="sn-service-copy">
      <span class="sn-service-eyebrow"><span class="sn-service-mark" dir="ltr">n8n</span>{{ __('home_slides.n8n_eyebrow') }}</span>
      <h2 id="hero-n8n-title">{{ __('home_slides.n8n_title') }}<span>{{ __('home_slides.n8n_accent') }}</span></h2>
      <p class="sn-service-lead">{{ __('home_slides.n8n_lead') }}</p>
      <ul class="sn-service-benefits">
        @foreach(['connect', 'automate', 'insight'] as $benefit)
        <li><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>{{ __('home_slides.n8n_'.$benefit) }}</li>
        @endforeach
      </ul>
      <div class="sn-service-actions"><a class="btn btn-primary" href="{{ lroute('cloud.location', 'n8n') }}">{{ __('home_slides.n8n_cta') }}<svg class="icon dir" aria-hidden="true"><use href="#i-arrow"/></svg></a><a class="btn btn-glass" href="{{ lroute('contact') }}">{{ __('home_slides.consult') }}</a></div>
      <p class="sn-service-note">{{ __('home_slides.n8n_note') }}</p>
    </div>
    <div class="sn-flow-scene" role="img" aria-label="{{ __('home_slides.flow_alt') }}">
      <div class="sn-flow-grid" aria-hidden="true"></div>
      <svg class="sn-flow-core" viewBox="0 0 640 440" fill="none" aria-hidden="true">
        <defs>
          <linearGradient id="sn-flow-top" x1="180" y1="120" x2="440" y2="340" gradientUnits="userSpaceOnUse"><stop stop-color="#254b68"/><stop offset="1" stop-color="#0c2239"/></linearGradient>
          <linearGradient id="sn-flow-edge" x1="160" y1="200" x2="480" y2="300" gradientUnits="userSpaceOnUse"><stop stop-color="#2f92d7"/><stop offset="1" stop-color="#45dbe4"/></linearGradient>
        </defs>
        <ellipse cx="320" cy="278" rx="252" ry="115" stroke="currentColor" opacity=".12"/>
        <ellipse cx="320" cy="278" rx="214" ry="95" stroke="currentColor" opacity=".08"/>
        <g class="sn-flow-paths" stroke="url(#sn-flow-edge)" stroke-width="1.5"><path d="M102 138H160L252 192"/><path d="M540 166H486L390 221"/><path d="M108 327H180L263 280"/><path d="M535 332H475L390 284"/></g>
        <g class="sn-flow-layer sn-flow-layer-bottom"><path d="m178 268 142-80 142 80v25l-142 80-142-80Z" fill="#091e32" stroke="#5386a7" stroke-opacity=".4"/><path d="m178 268 142-80 142 80-142 80Z" fill="url(#sn-flow-top)" stroke="#6ba4c6" stroke-opacity=".5"/><path d="m192 269 128 72 128-72" stroke="url(#sn-flow-edge)" stroke-width="2" opacity=".6"/></g>
        <g class="sn-flow-layer sn-flow-layer-middle"><path d="m195 226 125-71 125 71v22l-125 71-125-71Z" fill="#0b2940" stroke="#5ca5ca" stroke-opacity=".45"/><path d="m195 226 125-71 125 71-125 71Z" fill="url(#sn-flow-top)" stroke="url(#sn-flow-edge)"/><path d="m218 226 102 58 102-58" stroke="#54d7e5" stroke-width="2" opacity=".8"/></g>
        <g class="sn-flow-layer sn-flow-layer-top"><path d="m220 181 100-57 100 57v18l-100 57-100-57Z" fill="#103e58" stroke="#6ac8e1" stroke-opacity=".6"/><path d="m220 181 100-57 100 57-100 57Z" fill="url(#sn-flow-top)" stroke="#87dce9" stroke-opacity=".7"/><g transform="translate(269 175) matrix(.866 .5 -.866 .5 0 0)" stroke="#6de2ee" stroke-width="5"><path d="M9 0h27l19-25h25M36 0l19 25h25"/><circle cx="5" r="9" fill="#12364c"/><circle cx="86" cy="-25" r="9" fill="#12364c"/><circle cx="86" cy="25" r="9" fill="#12364c"/></g></g>
        <g fill="#56d6e6"><circle class="sn-flow-bead" cx="160" cy="138" r="4"/><circle class="sn-flow-bead" cx="486" cy="166" r="4"/><circle class="sn-flow-bead" cx="180" cy="327" r="4"/><circle class="sn-flow-bead" cx="475" cy="332" r="4"/></g>
      </svg>
      @foreach(['source' => 'mail', 'logic' => 'sparkles', 'action' => 'check', 'result' => 'trend'] as $step => $icon)
      <div class="sn-flow-card sn-flow-card-{{ $step }}"><span class="sn-flow-card-icon"><svg class="icon" aria-hidden="true"><use href="#i-{{ $icon }}"/></svg></span><span><small>{{ __('home_slides.flow_'.$step) }}</small><strong>{{ __('home_slides.flow_'.$step.'_value') }}</strong></span></div>
      @endforeach
      <span class="sn-flow-caption">{{ __('home_slides.flow_caption') }}</span>
    </div>
  </div>
</section>
<section class="sn-service-hero sn-hosting-hero" id="hero-hosting" data-hero-slide="hosting" aria-labelledby="hero-hosting-title" aria-hidden="true" inert>
  <div class="container sn-service-layout">
    <div class="sn-service-copy">
      <span class="sn-service-eyebrow"><svg class="icon" aria-hidden="true"><use href="#i-server"/></svg>{{ __('home_slides.hosting_eyebrow') }}</span>
      <h2 id="hero-hosting-title">{{ __('home_slides.hosting_title') }}<span>{{ __('home_slides.hosting_accent') }}</span></h2>
      <p class="sn-service-lead">{{ __('home_slides.hosting_lead') }}</p>
      <div class="sn-hosting-categories" aria-label="{{ __('home_slides.hosting_types') }}">
        @foreach(['wordpress', 'woocommerce', 'python', 'email', 'backup', 'download'] as $type)
        <a href="{{ lroute('hosting', $type) }}">{{ __('home_slides.host_'.$type) }}<svg class="icon dir" aria-hidden="true"><use href="#i-arrow"/></svg></a>
        @endforeach
      </div>
      <div class="sn-service-actions"><a class="btn btn-primary" href="{{ lroute('hosting', 'linux') }}">{{ __('home_slides.host_linux') }}<svg class="icon dir" aria-hidden="true"><use href="#i-arrow"/></svg></a><a class="btn btn-glass" href="{{ lroute('hosting', 'windows') }}">{{ __('home_slides.host_windows') }}</a></div>
      <p class="sn-service-note">{{ __('home_slides.hosting_note') }}</p>
    </div>
    <div class="sn-hosting-scene">
      <div class="sn-hosting-orbit" aria-hidden="true"></div>
      <div class="sn-hosting-browser">
        <div class="sn-hosting-browser-bar"><span aria-hidden="true"><i></i><i></i><i></i></span><span dir="ltr">your-next-website</span><svg class="icon" aria-hidden="true"><use href="#i-lock"/></svg></div>
        <div class="sn-hosting-site">
          <div class="sn-hosting-site-nav" aria-hidden="true"><i></i><span></span><span></span><span></span></div>
          <span class="sn-hosting-site-eyebrow">{{ __('home_slides.hosting_visual_eyebrow') }}</span>
          <strong>{{ __('home_slides.hosting_visual_title') }}</strong>
          <div class="sn-hosting-site-lines" aria-hidden="true"><i></i><i></i></div>
          <div class="sn-hosting-site-preview" aria-hidden="true"><div><svg class="icon"><use href="#i-globe"/></svg></div><div><svg class="icon"><use href="#i-code"/></svg></div><div><svg class="icon"><use href="#i-mail"/></svg></div></div>
          <div class="sn-hosting-server-strip"><svg class="icon" aria-hidden="true"><use href="#i-server"/></svg><span>ServerNet Cloud</span><i aria-hidden="true"></i></div>
        </div>
      </div>
      <div class="sn-hosting-float sn-hosting-float-start"><svg class="icon" aria-hidden="true"><use href="#i-globe"/></svg><span>{{ __('home_slides.host_wordpress') }}<small>{{ __('home_slides.hosting_visual_site') }}</small></span></div>
      <div class="sn-hosting-float sn-hosting-float-end"><svg class="icon" aria-hidden="true"><use href="#i-code"/></svg><span>Linux / Windows<small>PHP · Python · .NET</small></span></div>
    </div>
  </div>
</section>
