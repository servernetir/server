@php
    // Link to a product page, never an order form or a guessed content URL.
    $toolProduct = match (true) {
        in_array($slug, ['whois', 'domain-ideas', 'qr-generator', 'barcode-generator'], true) => 'domain',
        in_array($slug, ['ip', 'speedtest', 'json-formatter', 'json-tree', 'jwt-decoder', 'base64', 'hash-generator', 'uuid-generator', 'password-generator', 'byte-converter', 'timestamp-converter'], true) => 'vps',
        in_array($catKey ?? '', ['web', 'design', 'image'], true) || $slug === 'seo' => 'hosting',
        default => 'project',
    };
    $toolProductUrl = match ($toolProduct) {
        'domain' => lroute('domain.search'),
        'vps' => lroute('vps.hourly'),
        default => lroute('hosting', 'linux'),
    };
@endphp

@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/tool-next-step.css') }}">
@endpush

<section class="tool-next-step" aria-labelledby="tool-next-step-title">
  <div>
    <p class="tool-next-step-label">{{ __('tool-next-step.label') }}</p>
    <h2 id="tool-next-step-title">{{ __('tool-next-step.'.$toolProduct.'.title') }}</h2>
    <p>{{ __('tool-next-step.'.$toolProduct.'.description') }}</p>
  </div>
  <div class="tool-next-step-actions">
    <a class="btn btn-primary" href="{{ $toolProductUrl }}"
       data-tool-product="{{ $toolProduct }}" data-tool-slug="{{ $slug }}">{{ __('tool-next-step.'.$toolProduct.'.action') }}</a>
    <nav aria-label="{{ __('tool-next-step.service_information') }}">
      <a href="{{ lroute('status') }}">{{ __('tool-next-step.status') }}</a>
      <span aria-hidden="true">·</span>
      <a href="{{ lroute('sla') }}">{{ __('tool-next-step.sla') }}</a>
    </nav>
  </div>
</section>

<script>
(function () {
  var link = document.querySelector('[data-tool-product]');
  if (!link) return;
  link.addEventListener('click', function () {
    if (window.ServerNetAnalytics) {
      window.ServerNetAnalytics.track('tool_product_click', {
        tool_slug: link.dataset.toolSlug,
        product_family: link.dataset.toolProduct
      });
    }
  });
})();
</script>
