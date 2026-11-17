(function () {
  'use strict';
  var TTL = 60000;
  function classify(data, now) {
    var checked = data && Date.parse(data.checked_at);
    if (!data || data.ok !== true || typeof data.available !== 'boolean'
      || !Number.isInteger(data.count) || data.count < 0
      || data.available !== (data.count > 0) || !Number.isFinite(checked)
      || Math.abs(now - checked) > 120000) { return 'unknown'; }
    return data.available ? 'available' : 'waiting';
  }
  if (typeof module !== 'undefined' && module.exports) { module.exports = { classify: classify }; }
  if (typeof document === 'undefined') { return; }
  var nodes = Array.prototype.slice.call(document.querySelectorAll('[data-gpu-live-capacity]'));
  if (!nodes.length) { return; }
  var visible = new Set(), results = new Map(), attempts = new Map(), requests = [], busy = false;
  var locale = document.documentElement.lang || 'en';
  function key(node) { return node.dataset.capacityUrl + '|' + node.dataset.plan; }
  function display(node, data) {
    var state = classify(data, Date.now()), label = node.querySelector('[data-gpu-capacity-label]');
    var time = node.querySelector('[data-gpu-capacity-time]');
    node.dataset.state = state;
    if (label) {
      label.textContent = state === 'available'
        ? node.dataset.available.replace('{count}', new Intl.NumberFormat(locale).format(data.count))
        : (state === 'waiting' ? node.dataset.waiting : node.dataset.unknown);
    }
    if (time) {
      time.textContent = state === 'unknown' ? '' : node.dataset.checked + ' '
        + new Date(data.checked_at).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
      if (state !== 'unknown') { time.dateTime = data.checked_at; } else { time.removeAttribute('datetime'); }
    }
  }
  function isVisible(node) {
    if (document.hidden || !node.isConnected || node.closest('[hidden]')) { return false; }
    if ('IntersectionObserver' in window) { return visible.has(node); }
    var r = node.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && r.bottom >= 0 && r.top <= window.innerHeight;
  }
  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) { if (entry.isIntersecting) { visible.add(entry.target); } else { visible.delete(entry.target); } });
      tick();
    });
    nodes.forEach(function (node) { observer.observe(node); });
  }
  async function tick() {
    var now = Date.now();
    nodes.forEach(function (node) {
      var result = results.get(key(node));
      if (result && now - result.received >= TTL) { display(node, null); }
    });
    requests = requests.filter(function (at) { return now - at < TTL; });
    // Bound shared API load below the account endpoint's 30/minute budget.
    if (busy || document.hidden || requests.length >= 20) { return; }
    var node = nodes.find(function (n) { return isVisible(n) && (!attempts.has(key(n)) || now - attempts.get(key(n)) >= TTL); });
    if (!node) { return; }
    var id = key(node), controller = new AbortController();
    busy = true; attempts.set(id, now); requests.push(now);
    var timer = setTimeout(function () { controller.abort(); }, 8000), data = null;
    try {
      var url = new URL(node.dataset.capacityUrl, window.location.href);
      if (url.origin !== window.location.origin) { throw new Error('origin'); }
      url.searchParams.set('plan', node.dataset.plan);
      var response = await fetch(url.href, { headers: { Accept: 'application/json' }, signal: controller.signal, credentials: 'same-origin' });
      if (!response.ok) { throw new Error('capacity'); }
      data = await response.json();
    } catch (_) { data = null; }
    finally { clearTimeout(timer); busy = false; }
    results.set(id, { data: data, received: Date.now() });
    nodes.filter(function (n) { return key(n) === id && n.isConnected; }).forEach(function (n) { display(n, data); });
  }
  document.querySelectorAll('[data-gpu-search]').forEach(function (input) {
    input.addEventListener('input', function () {
      var query = input.value.trim().toLocaleLowerCase(locale);
      document.querySelectorAll('[data-gpu-card]').forEach(function (card) {
        card.hidden = card.dataset.gpuName.toLocaleLowerCase(locale).indexOf(query) === -1;
      });
      tick();
    });
  });
  document.addEventListener('visibilitychange', tick);
  setInterval(tick, 2200);
  tick();
})();
