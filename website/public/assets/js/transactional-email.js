(function () {
  'use strict';
  const root = document.querySelector('[data-email-product]');
  if (!root) return;
  const estimator = root.querySelector('[data-email-estimator]');
  const select = root.querySelector('#email-volume');
  const output = root.querySelector('#email-estimate');
  const form = root.querySelector('.tx-form');
  const config = JSON.parse(estimator.dataset.emailEstimator);
  function track(event, action, placement) {
    if (!window.ServerNetAnalytics || typeof window.ServerNetAnalytics.track !== 'function') return;
    const data = {product_line: 'transactional_email', source_page: window.location.pathname, content_language: config.locale};
    if (action) data.cta_action = action;
    if (placement) data.cta_placement = placement;
    window.ServerNetAnalytics.track(event, data);
  }
  root.querySelectorAll('a[href="#request"]').forEach(link => link.addEventListener('click', function () {
    track('product_cta_click', 'request_quote', this.hasAttribute('data-email-plan') ? 'plans' : 'page');
  }));
  if (root.querySelector('.tx-success')) track('generate_lead');
  function quote(volume) {
    const plan = config.plans.find(item => volume <= item.volume);
    const last = config.plans[config.plans.length - 1];
    const cents = plan ? plan.usd_cents : last.usd_cents + Math.ceil((volume - last.volume) / 1000) * config.extra;
    const amount = config.currency === 'IRT' ? Math.ceil(cents * config.usdRate / 100000) * 1000
      : config.currency === 'EUR' ? Math.ceil(cents * config.usdRate / config.eurRate) : cents;
    return config.currency === 'IRT' ? amount : amount / 100;
  }
  function update() {
    const volume = Number(select.value);
    output.textContent = new Intl.NumberFormat(config.locale, {
      minimumFractionDigits: config.currency === 'IRT' ? 0 : 2,
      maximumFractionDigits: config.currency === 'IRT' ? 0 : 2,
    }).format(quote(volume)) + ' ' + config.unit;
    form.elements.volume.value = volume;
  }
  select.addEventListener('change', update);
  root.querySelectorAll('[data-email-plan]').forEach(link => link.addEventListener('click', function () {
    select.value = this.dataset.emailPlan;
    update();
  }));
  root.querySelector('[data-email-estimate-cta]').addEventListener('click', update);
  // Server-rendered values preserve validation errors and work without JS.
  if (!root.querySelector('.tx-errors')) update();
  form.addEventListener('submit', function () {
    if (!form.checkValidity()) return;
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
  });
  window.addEventListener('pageshow', function () {
    const button = form.querySelector('button[type="submit"]');
    button.disabled = false;
    button.removeAttribute('aria-busy');
  });
})();
