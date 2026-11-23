<section class="sn-service-hero sn-gpu-hero" id="hero-gpu" data-hero-slide="gpu" aria-labelledby="hero-gpu-title" aria-hidden="true" inert>
  <div class="container sn-service-layout">
    <div class="sn-service-copy">
      <span class="sn-service-eyebrow"><span class="sn-service-mark" dir="ltr">GPU</span>{{ __('home_slides.gpu_eyebrow') }}</span>
      <h2 id="hero-gpu-title">{{ __('home_slides.gpu_title') }}<span>{{ __('home_slides.gpu_accent') }}</span></h2>
      <p class="sn-service-lead">{{ __('home_slides.gpu_lead') }}</p>
      <ul class="sn-service-benefits">
        @foreach(['create', 'models', 'compute'] as $benefit)
        <li><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>{{ __('home_slides.gpu_'.$benefit) }}</li>
        @endforeach
      </ul>
      <div class="sn-service-actions"><a class="btn btn-primary" href="{{ lroute('gpu') }}">{{ __('home_slides.gpu_cta') }}</a><span class="sn-gpu-usage">{{ __('home_slides.gpu_note') }}</span></div>
    </div>
    <div class="sn-gpu-scene" role="img" aria-label="{{ __('home_slides.gpu_visual') }}">
      <div class="sn-gpu-halo" aria-hidden="true"></div>
      <div class="sn-gpu-orbit sn-gpu-orbit-one" aria-hidden="true"></div><div class="sn-gpu-orbit sn-gpu-orbit-two" aria-hidden="true"></div>
      <div class="sn-gpu-board" aria-hidden="true"><div class="sn-gpu-board-grid"></div><i class="sn-gpu-trace sn-gpu-trace-one"></i><i class="sn-gpu-trace sn-gpu-trace-two"></i><i class="sn-gpu-trace sn-gpu-trace-three"></i><div class="sn-gpu-chip"><span>SERVERNET</span><strong>GPU</strong><small>CLOUD COMPUTE</small></div><div class="sn-gpu-board-status"><i></i>POWER YOUR NEXT IDEA</div></div>
      <div class="sn-gpu-output sn-gpu-output-art"><div class="sn-gpu-art" aria-hidden="true"><i></i><b></b><span></span></div><strong>{{ __('home_slides.gpu_art') }}</strong><small>ComfyUI</small></div>
      <div class="sn-gpu-output sn-gpu-output-model"><span class="sn-gpu-wave" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span><strong>{{ __('home_slides.gpu_model') }}</strong><small>Ollama · Jupyter</small></div>
      <span class="sn-gpu-scene-caption">{{ __('home_slides.gpu_caption') }}</span>
    </div>
  </div>
</section>
