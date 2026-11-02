(function () {
  'use strict';
  function boot() {
    document.querySelectorAll('[data-hosting-connection]').forEach(function (box) {
      if (box.dataset.initialized) return;
      box.dataset.initialized = '1';
      var T;
      try { T = JSON.parse(box.dataset.messages); } catch (_) { return; }
      var busy = false, button = box.querySelector('[data-check-connection]');
      function state(s) {
        if (!['checking', 'connected', 'needs_setup', 'propagating', 'unknown', 'unconfigured'].includes(s)) s = 'unknown';
        box.dataset.state = s;
        box.querySelector('[data-connection-status]').textContent = T[s];
      }
      async function check() {
        if (busy) return;
        busy = true; button.disabled = true; state('checking');
        var abort = new AbortController(), timer = setTimeout(function () { abort.abort(); }, 12000);
        try {
          var url = new URL(box.dataset.url, location.href);
          if (url.origin !== location.origin) throw new Error('origin');
          var r = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: abort.signal, cache: 'no-store' });
          if (!r.ok) throw new Error('check');
          var d = await r.json(); state(d.state);
          var time = new Date(d.checked_at);
          box.querySelector('[data-connection-time]').textContent = !isNaN(time.getTime()) ? T.checked + ': ' + time.toLocaleTimeString(document.documentElement.lang || 'en', { hour: '2-digit', minute: '2-digit' }) : '';
          var observed = box.querySelector('[data-connection-observed]');
          observed.textContent = Array.isArray(d.observed_ns) ? d.observed_ns.join(' · ') : '';
          observed.parentElement.hidden = !observed.textContent;
        } catch (_) { state('unknown'); }
        finally { clearTimeout(timer); busy = false; button.disabled = false; }
      }
      button.addEventListener('click', check);
      box.querySelectorAll('[data-copy-ns]').forEach(function (b) {
        b.addEventListener('click', async function () {
          try { await navigator.clipboard.writeText(b.dataset.copyNs); b.textContent = T.copied; }
          catch (_) { b.textContent = T.copy_failed; }
          setTimeout(function () { b.textContent = T.copy; }, 2200);
        });
      });
      if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
          if (entries.some(function (e) { return e.isIntersecting; })) { observer.disconnect(); check(); }
        }); observer.observe(box);
      } else { check(); }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
}());
