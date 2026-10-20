/* Scoped acquisition CTA events. Existing checkout and purchase events remain authoritative. */
(function (w, d) {
  'use strict';
  if (w.__servernetGrowthCtaReady) return;
  w.__servernetGrowthCtaReady = true;
  var pathname = w.location.pathname;
  var path = pathname.replace(/^\/(en|tr)(?=\/|$)/, '') || '/';
  var products = {'/vps/hourly':'hourly_vps','/gpu':'hourly_gpu','/cloud/n8n':'n8n','/hosting/python':'python_hosting','/vps/gpu':'gpu_vps'};
  var product = products[path];
  if (!product) return;
  function send(action, placement) {
    if (!w.ServerNetAnalytics || typeof w.ServerNetAnalytics.track !== 'function') return;
    w.ServerNetAnalytics.track('product_cta_click', {
      product_line: product,
      cta_action: action,
      cta_placement: placement,
      source_page: pathname,
      content_language: /^(fa|en|tr)$/.test(d.documentElement.lang) ? d.documentElement.lang : 'fa'
    });
  }
  d.addEventListener('click', function (event) {
    if (event.defaultPrevented) return;
    var link = event.target.closest && event.target.closest('a[href]');
    if (!link) return;
    var destination;
    try { destination = new URL(link.href, w.location.href); } catch (_) { return; }
    if (destination.protocol !== 'http:' && destination.protocol !== 'https:') return;
    if (destination.host !== w.location.host && destination.hostname !== 'console.servernet.cloud') return;
    var destPath = destination.pathname.replace(/^\/(en|tr)(?=\/|$)/, '');
    var placement = link.closest('#rates') ? 'rates' : link.closest('#pricing, #plans') ? 'plans' : link.closest('.hv-card') ? 'plan_card' : 'page';
    if (/^\/(?:account\/(?:cloud-store|store)(?:\/|$)|order\/)/.test(destPath)) send('order', placement);
    else if (destination.host === w.location.host && destination.pathname === pathname && destination.hash === '#gpu-configure') send('configure', 'hero');
  });
  d.addEventListener('submit', function (event) {
    if (event.defaultPrevented || path !== '/gpu' || event.target.id !== 'gpu-configure') return;
    if (typeof event.target.checkValidity === 'function' && !event.target.checkValidity()) return;
    send('request_gpu', 'configurator');
  });
})(window, document);
