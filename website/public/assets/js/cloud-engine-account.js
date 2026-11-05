(() => {
  'use strict';
  const panels = document.querySelectorAll('[data-engine-panel]');
  panels.forEach(panel => {
    let labels, endpoint;
    try {
      labels = JSON.parse(panel.dataset.labels);
      endpoint = new URL(panel.dataset.statusUrl, location.href);
      if (endpoint.origin !== location.origin) return;
    } catch (_) { return; }
    const text = (selector, value) => {
      const element = panel.querySelector(selector);
      if (element) element.textContent = value;
    };
    const bytes = value => {
      if (typeof value !== 'string' || !/^\d+$/.test(value)) return null;
      let output = value.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
      if (document.documentElement.lang === 'fa') output = output.replace(/\d/g, digit => '۰۱۲۳۴۵۶۷۸۹'[digit]);
      return output;
    };
    let busy = false;
    const refresh = async () => {
      if (busy || document.hidden) return;
      busy = true;
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), 8000);
      try {
        const response = await fetch(endpoint.href, {credentials: 'same-origin', headers: {Accept: 'application/json'}, signal: controller.signal});
        if (!response.ok) return;
        const body = await response.json();
        if (!body.ok || !body.data || !body.data.traffic) return;
        const data = body.data, operation = data.operation;
        if (data.billing) {
          if (typeof data.billing.label === 'string') text('[data-engine-billing-cycle]', data.billing.label);
          const amount = bytes(data.billing.amount);
          if (amount !== null) text('[data-engine-billing-amount]', amount);
          if (typeof data.billing.next_due_at === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(data.billing.next_due_at)) text('[data-engine-billing-due]', data.billing.next_due_at);
        }
        text('[data-engine-operation-action]', operation ? labels.actions[operation.action] || labels.none : labels.none);
        text('[data-engine-operation-status]', operation ? labels.statuses[operation.status] || labels.statuses.pending_review : '');
        text('[data-engine-coverage]', labels.coverage[data.traffic.coverage] || labels.coverage.unknown);
        const stale = panel.querySelector('[data-engine-stale]');
        if (stale) stale.hidden = data.traffic.stale !== true;
        for (const direction of ['in', 'out']) {
          const formatted = bytes(data.traffic[direction + '_bytes']);
          if (formatted !== null) text('[data-engine-' + direction + ']', formatted);
        }
        // A newly queued operation disables stale forms; refreshing the page
        // after completion obtains new per-image/state authorization controls.
        if (data.busy) panel.querySelectorAll('[data-engine-mutation]').forEach(button => {button.disabled = true;});
      } catch (_) {
        // Retain the last observed state; transport failure is not completion.
      } finally {
        clearTimeout(timer);
        busy = false;
      }
    };
    const interval = setInterval(refresh, 5000);
    let metricsInterval;
    if (panel.dataset.metricsUrl) {
      let metricsUrl;
      try {
        metricsUrl = new URL(panel.dataset.metricsUrl, location.href);
        if (metricsUrl.origin !== location.origin) metricsUrl = null;
      } catch (_) { metricsUrl = null; }
      if (metricsUrl) {
        let loadingMetrics = false;
        const metrics = async () => {
          if (loadingMetrics || document.hidden) return;
          loadingMetrics = true;
          const controller = new AbortController();
          const timer = setTimeout(() => controller.abort(), 8000);
          try {
            const response = await fetch(metricsUrl.href, {credentials: 'same-origin', headers: {Accept: 'application/json'}, signal: controller.signal});
            if (!response.ok) return;
            const body = await response.json(), current = body?.ok && body.current;
            if (!current) return;
            text('[data-engine-uptime]', Number.isSafeInteger(current.uptime_seconds) && current.uptime_seconds >= 0 ? String(current.uptime_seconds) : '—');
            text('[data-engine-cpu]', typeof current.cpu_fraction === 'number' && Number.isFinite(current.cpu_fraction)
              && current.cpu_fraction >= 0 && current.cpu_fraction <= 1 ? (current.cpu_fraction * 100).toLocaleString(document.documentElement.lang || 'en', {maximumFractionDigits: 1}) + '%' : '—');
            for (const [field, selector] of [['memory_bytes', '[data-engine-memory]'], ['disk_allocated_bytes', '[data-engine-disk]']]) {
              text(selector, Number.isSafeInteger(current[field]) && current[field] >= 0 ? bytes(String(current[field])) : '—');
            }
            if (Number.isSafeInteger(current.guest_filesystem_used_bytes) && current.guest_filesystem_used_bytes >= 0) {
              text('[data-engine-guest-disk]', bytes(String(current.guest_filesystem_used_bytes)));
            }
            for (const [field, selector] of [['net_in', '[data-engine-net-in]'], ['net_out', '[data-engine-net-out]']]) {
              const points = Array.isArray(body.series?.[field]) ? body.series[field] : [];
              const last = points.at(-1), value = Array.isArray(last) ? last[1] : null;
              text(selector, typeof value === 'number' && Number.isFinite(value) && value >= 0
                ? value.toLocaleString(document.documentElement.lang || 'en', {maximumFractionDigits: 2}) : '—');
            }
            const boot = typeof current.last_boot_at === 'string' ? new Date(current.last_boot_at) : null;
            const bootElement = panel.querySelector('[data-engine-boot]');
            if (bootElement) {
              bootElement.textContent = boot && Number.isFinite(boot.getTime()) && current.boot_time_estimated === true
                ? new Intl.DateTimeFormat(document.documentElement.lang || 'en', {dateStyle: 'medium', timeStyle: 'medium', timeZone: panel.dataset.timeZone || 'Asia/Tehran'}).format(boot) : '—';
              bootElement.title = typeof current.observed_at === 'string' ? current.observed_at : '';
            }
          } catch (_) {
            // Failed observation never manufactures an uptime or boot event.
          } finally {
            clearTimeout(timer);
            loadingMetrics = false;
          }
        };
        metrics();
        metricsInterval = setInterval(metrics, 30000);
      }
    }
    window.addEventListener('pagehide', () => {clearInterval(interval); if (metricsInterval) clearInterval(metricsInterval);}, {once: true});
  });
})();
