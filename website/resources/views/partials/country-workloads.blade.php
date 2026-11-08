@once
@push('head')
<style id="vps-workload-styles">
.vps-workloads h2{font-size:clamp(22px,3vw,30px);line-height:1.5;margin:0 0 16px}
.vps-workloads p{color:var(--muted);line-height:1.9;margin:0 0 18px}
.vps-workload-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:18px;margin:22px 0}
.vps-workload-grid article{border:1px solid var(--line);border-radius:18px;background:var(--surface);padding:22px}
.vps-workload-grid h3{font-size:18px;line-height:1.6;margin:0 0 12px}
.vps-workload-links{display:flex;flex-wrap:wrap;gap:12px 24px;line-height:1.9}
</style>
@endpush
@endonce
<section class="section vps-workloads" id="vps-use-cases" aria-labelledby="vps-use-cases-title">
  <div class="container">
    <h2 id="vps-use-cases-title">{{ __('vps-guide.title', ['country' => $country]) }}</h2>
    <p>{{ __('vps-guide.intro') }}</p>
    <div class="vps-workload-grid">
      <article>
        <h3>{{ __('vps-guide.web_title') }}</h3>
        <p>{{ __('vps-guide.web_text') }}</p>
        <a href="{{ lroute('hosting', 'linux') }}">{{ __('vps-guide.web_link') }}</a>
      </article>
      <article>
        <h3>{{ __('vps-guide.dev_title') }}</h3>
        <p>{{ __('vps-guide.dev_text') }}</p>
        <a href="{{ lroute('docs', 'connecting-to-linux-server-ssh') }}">{{ __('vps-guide.dev_link') }}</a>
      </article>
      <article>
        <h3>{{ __('vps-guide.worker_title') }}</h3>
        <p>{{ __('vps-guide.worker_text') }}</p>
        <a href="{{ lroute('gpu') }}">{{ __('vps-guide.worker_link') }}</a>
      </article>
    </div>
    <div class="vps-workload-links">
      @if($hasHourlyPlans)
        <a href="{{ lroute('vps.hourly') }}#hourly-calculator">{{ __('vps-guide.hourly') }}</a>
      @endif
      <a href="{{ lroute('cloud.index') }}">{{ __('vps-guide.catalog') }}</a>
    </div>
  </div>
</section>
