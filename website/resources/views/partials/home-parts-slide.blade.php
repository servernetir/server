<section class="sn-service-hero sn-parts-hero" id="hero-parts" data-hero-slide="parts" aria-labelledby="hero-parts-title" aria-hidden="true" inert>
  <div class="sn-parts-halo" aria-hidden="true"></div>
  <div class="container sn-service-layout">
    <div class="sn-service-copy sn-parts-copy">
      <span class="sn-service-eyebrow"><span class="sn-parts-mark"><svg class="icon" aria-hidden="true"><use href="#i-server"/></svg></span>{{ __('home_slides.parts_eyebrow') }}</span>
      <h2 id="hero-parts-title">{{ __('home_slides.parts_title') }}<span>{{ __('home_slides.parts_accent') }}</span></h2>
      <p class="sn-service-lead">{{ __('home_slides.parts_lead') }}</p>
      <ul class="sn-service-benefits sn-parts-benefits">
        @foreach(['specifications', 'compatibility', 'compare'] as $benefit)
        <li><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>{{ __('home_slides.parts_'.$benefit) }}</li>
        @endforeach
      </ul>
      <div class="sn-service-actions"><a class="btn btn-primary" href="{{ lroute('parts.index') }}">{{ __('home_slides.parts_cta') }}</a><a class="btn btn-glass" href="{{ lroute('contact') }}">{{ __('home_slides.parts_advice') }}</a></div>
      <div class="sn-parts-generations">
        <span class="sn-parts-gen-label">{{ __('home_slides.parts_generation') }}</span>
        <nav aria-label="{{ __('home_slides.parts_generation') }}" class="sn-parts-gen-links" dir="ltr">
          @foreach([8,9,10,11,12] as $gen)
          <a href="{{ lroute('servers.generation', 'gen'.$gen) }}">Gen{{ $gen }}</a>
          @endforeach
        </nav>
      </div>
    </div>
    <div class="sn-parts-scene" data-parts-explore>
      <div class="sn-parts-scene-head" dir="ltr">
        <span class="sn-parts-collection"><span class="sn-parts-emblem"><svg class="icon" aria-hidden="true"><use href="#i-server"/></svg></span><span><b>ServerNet</b><small>HARDWARE COLLECTION</small></span></span>
        <span class="sn-parts-proliant">HPE ProLiant <i>·</i> Gen8–12</span>
      </div>
      <div class="sn-parts-visual">
        <div class="sn-parts-art">
          <img data-parts-chassis-art data-hero-image-src="{{ asset_ver('assets/img/home-parts-exploded.webp') }}" width="1200" height="800" decoding="async" alt="{{ __('home_slides.parts_visual_alt') }}">
          {{-- Each link is its own moving, cropped layer of the same lazy WebP.
               The category name and hit area travel with the component. --}}
          <nav class="sn-parts-hotspots" aria-label="{{ __('home_slides.parts_categories') }}" dir="ltr">
            @foreach(['cpu'=>['cpu','Xeon CPU','CPU'],'ram'=>['zap','ECC RAM','ECC RAM'],'disk'=>['db','SAS / SSD','SAS/SSD'],'raid'=>['box','RAID','RAID']] as $kind=>$part)
            <a class="sn-parts-hotspot sn-parts-spot-{{ $kind }}" data-parts-category="{{ $kind }}" href="{{ lroute('parts.category', $kind) }}" aria-label="{{ __('home_slides.parts_category_'.$kind) }}">
              <span class="sn-parts-piece" aria-hidden="true"><img data-hero-image-src="{{ asset_ver('assets/img/home-parts-exploded.webp') }}" width="1200" height="800" decoding="async" alt=""></span>
              <span class="sn-parts-hotspot-label" aria-hidden="true"><svg class="icon"><use href="#i-{{ $part[0] }}"/></svg><span class="sn-parts-label-full">{{ $part[1] }}</span><span class="sn-parts-label-short">{{ $part[2] }}</span></span>
            </a>
            @endforeach
          </nav>
        </div>
      </div>
      <div class="sn-parts-scene-foot">
        <div class="sn-parts-scene-note"><span class="sn-parts-art-caption">{{ __('home_slides.parts_illustration') }}</span><span>{{ __('home_slides.parts_explore') }}</span></div>
        <a class="sn-parts-chassis" href="{{ lroute('parts.category', 'chassis') }}">{{ __('home_slides.parts_chassis') }}</a>
      </div>
    </div>
  </div>
</section>
