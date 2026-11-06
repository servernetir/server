/* Informational funding countdown. The server queue alone decides cancellation. */
(function () {
  'use strict';

  function mount() {
    var timers = [];
    document.querySelectorAll('[data-gpu-request-expiry]').forEach(function (notice) {
      if (notice.hasAttribute('data-expiry-mounted')) return;
      var deadlineValue = notice.getAttribute('data-deadline-ms') || '';
      var serverValue = notice.getAttribute('data-server-now-ms') || '';
      var output = notice.querySelector('[data-expiry-countdown]');
      if (!output || !/^\d+$/.test(deadlineValue) || !/^\d+$/.test(serverValue)) return;
      var deadline = Number(deadlineValue);
      var serverNow = Number(serverValue);
      if (!Number.isSafeInteger(deadline) || deadline <= 0 || !Number.isSafeInteger(serverNow) || serverNow <= 0) return;
      var locale = notice.getAttribute('data-locale');
      var number = new Intl.NumberFormat(['fa', 'en', 'tr'].indexOf(locale) >= 0 ? locale : 'en', { useGrouping: false });
      var format = notice.getAttribute('data-remaining-format') || ':hours h :minutes min';
      var passed = notice.getAttribute('data-passed-label') || '';
      var mountedAt = performance.now();
      notice.setAttribute('data-expiry-mounted', '1');
      timers.push(function () {
        var elapsed = Math.max(0, performance.now() - mountedAt);
        var minutes = Math.max(0, Math.ceil((deadline - serverNow - elapsed) / 60000));
        output.textContent = minutes > 0
          ? format.split(':hours').join(number.format(Math.floor(minutes / 60)))
            .split(':minutes').join(number.format(minutes % 60))
          : passed;
        return minutes > 0;
      });
    });
    if (!timers.length) return;
    var interval;
    function update() {
      var active = false;
      timers.forEach(function (render) { if (render()) active = true; });
      if (!active && interval !== undefined) clearInterval(interval);
      return active;
    }
    if (update()) interval = setInterval(update, 1000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) update(); });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, { once: true });
  else mount();
})();
