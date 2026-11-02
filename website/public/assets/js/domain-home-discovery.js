/* View-scoped homepage domain discovery; legacy supplier/payment contracts stay unchanged. */
(function () {
  'use strict';
  const form = document.getElementById('discovery-domain-form');
  const input = document.getElementById('discovery-domain-input');
  const result = document.getElementById('discovery-domain-result');
  if (!form || !input || !result || form.dataset.discoveryReady) return;
  form.dataset.discoveryReady = '1';
  const t = input.dataset;
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
  const digits = value => document.documentElement.lang === 'fa'
    ? String(value).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]) : String(value);
  const esc = value => {
    const el = document.createElement('span'); el.textContent = value == null ? '' : String(value);
    return el.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  };
  const safeHref = value => {
    if (typeof value !== 'string' || !value.trim()) return null;
    try { const url = new URL(value, location.href); return ['https:', 'http:'].includes(url.protocol) ? url.href : null; }
    catch (_) { return null; }
  };
  let requestId = 0, activeRequest, promotionTimer;
  function ideasLink(submitted) {
    // Capture the exact submitted input. A legacy more_url may normalize/drop its suffix.
    const url = new URL(form.dataset.suggestionsUrl || form.action, location.href);
    url.searchParams.set('q', submitted);
    return '<div class="ddh-actions"><a class="btn btn-outline ddh-ideas" href="' + esc(url.href) + '">' + esc(t.i18nIdeas || t.i18nSuggest) + '<span aria-hidden="true">↗</span></a></div>';
  }
  function expired(r) {
    const end = Date.parse(r.promotion_ends_at || '');
    return r.is_promotion === true && (!Number.isFinite(end) || end <= Date.now());
  }
  function promotion(r) {
    if (r.is_promotion !== true || expired(r) || typeof r.regular_price !== 'string' || !r.regular_price.trim()) return '';
    const percent = Number(r.discount_percent);
    const badge = Number.isInteger(percent) && percent > 0 && percent < 100
      ? '<span class="ddh-discount">' + esc((t.i18nDiscount || ':percent%').replace(':percent', digits(percent))) + '</span>' : '';
    return '<span class="ddh-reference"><del aria-label="' + esc(t.i18nRegular) + '">' + esc(digits(r.regular_price)) + '</del>' + badge + '</span>';
  }
  function render(data, submitted, id) {
    const r = data.result || {}, discovery = ideasLink(submitted), campaignEnd = Date.parse(r.promotion_ends_at || '');
    clearTimeout(promotionTimer);
    let state = expired(r) ? 'no_price' : r.state || (r.available === true ? 'free' : (r.available === false && data.lookup_ok === true ? 'taken' : 'unchecked'));
    const cart = safeHref(r.cart_url);
    if ((state === 'free' || state === 'premium') && (!r.price || !cart)) state = 'no_price';
    if (state === 'free' || state === 'premium') {
      result.className = 'domain-result ok';
      result.innerHTML = '<div class="dr-row"><span class="dr-domain">' + esc(r.domain) + '</span><span class="dr-msg">' + esc(t.i18nFree) + '</span><span class="dr-price">' + promotion(r) + esc(digits(r.price)) + ' <small>' + esc(r.period_label || t.i18nYear) + '</small></span><a class="btn btn-primary ddh-cart" href="' + esc(cart) + '">' + esc(t.i18nCart) + '</a></div>' + discovery;
      if (r.is_promotion === true && Number.isFinite(campaignEnd)) {
        promotionTimer = setTimeout(() => { if (id === requestId) render(data, submitted, id); }, Math.min(campaignEnd - Date.now() + 25, 2147483647));
      }
      return;
    }
    const alts = (Array.isArray(data.suggestions) ? data.suggestions : []).slice(0, 3).map(s => {
      const href = safeHref(s.cart_url);
      if (!href || !s.price || expired(s)) return '';
      return '<a class="dr-alt" href="' + esc(href) + '"><b>' + esc(s.domain) + '</b><i>' + promotion(s) + esc(digits(s.price)) + (s.period_label ? ' <small>' + esc(s.period_label) + '</small>' : '') + '</i></a>';
    }).join('');
    const suggestions = alts ? '<div class="dr-suggest"><p>' + esc(t.i18nSuggest) + '</p><div class="dr-alts">' + alts + '</div></div>' : '';
    if (state === 'taken') {
      result.className = 'domain-result no';
      result.innerHTML = '<div class="dr-row"><span class="dr-domain">' + esc(r.domain) + '</span><span class="dr-msg">' + esc(t.i18nTaken) + '</span></div>' + suggestions + discovery;
      return;
    }
    const message = expired(r) ? t.i18nPromotionExpired
      : state === 'unsupported' ? t.i18nUnsupported
      : state === 'no_price' || state === 'noPrice' ? t.i18nNoprice : t.i18nUnchecked;
    result.className = 'domain-result warn';
    result.innerHTML = '<div class="dr-row"><span class="dr-domain">' + esc(r.domain || submitted) + '</span><span class="dr-msg">' + esc(message) + '</span></div>' + suggestions + discovery;
  }
  form.addEventListener('submit', async event => {
    event.preventDefault();
    const submitted = input.value.trim();
    if (!submitted) { input.focus(); return; }
    activeRequest?.abort(); clearTimeout(promotionTimer);
    const id = ++requestId;
    activeRequest = new AbortController();
    result.hidden = false; result.setAttribute('aria-busy', 'true'); result.className = 'domain-result';
    result.innerHTML = '<div class="dr-row"><span class="dr-spin" aria-hidden="true"></span><span class="dr-msg">' + esc(t.i18nChecking) + '</span></div>';
    if (window.ServerNetAnalytics) window.ServerNetAnalytics.domainSearch(submitted);
    try {
      const response = await fetch(form.dataset.endpoint, {method:'POST',signal:activeRequest.signal,headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify({domain:submitted})});
      if (!response.ok) throw new Error('domain_lookup_unavailable');
      const data = await response.json();
      if (id === requestId) render(data, submitted, id);
    } catch (error) {
      if (error.name === 'AbortError' || id !== requestId) return;
      result.className = 'domain-result warn';
      result.innerHTML = '<div class="dr-row"><span class="dr-msg">' + esc(t.i18nError) + '</span></div>' + ideasLink(submitted);
    } finally {
      if (id === requestId) result.setAttribute('aria-busy', 'false');
    }
  });
  const chips = document.getElementById('discovery-tld-strip');
  chips?.addEventListener('click', event => {
    const chip = event.target.closest('[data-tld]');
    if (!chip || !chips.contains(chip)) return;
    const tld = String(chip.dataset.tld || '').replace(/^\./, '');
    if (!/^[a-z0-9-]+(?:\.[a-z0-9-]+)*$/i.test(tld)) return;
    const label = input.value.trim().replace(/^https?:\/\//i, '').replace(/^www\./i, '').split(/[./?#]/)[0];
    if (!label) { input.focus(); return; }
    input.value = label + '.' + tld; input.focus();
  });
  // Preserve the Atlas mobile behavior without changing its shared script or IDs.
  if ('IntersectionObserver' in window) {
    new IntersectionObserver(entries => { document.body.dataset.domainDiscoveryVisible = entries.some(entry => entry.isIntersecting) ? 'true' : 'false'; }, {rootMargin:'0px 0px 70px 0px'}).observe(form);
  }
})();
