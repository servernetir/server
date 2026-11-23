(() => {
  'use strict';
  if (typeof document === 'undefined') return;
  document.querySelectorAll('[data-n8n-service]').forEach(card => {
    const form = card.querySelector('[data-n8n-reveal]');
    const password = card.querySelector('[data-n8n-password]');
    const copy = card.querySelector('[data-n8n-copy]');
    const notice = card.querySelector('[data-n8n-access-notice]');
    if (!form || !password || !notice) return;
    form.addEventListener('submit', async event => {
      if (!window.fetch) return;
      event.preventDefault();
      const button = form.querySelector('button');
      if (button.disabled) return;
      button.disabled = true;
      try {
        const response = await fetch(form.action, {method:'POST', body:new FormData(form), credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});
        if (!response.ok || !response.headers.get('Content-Type')?.includes('application/json')) throw new Error('unavailable');
        const data = await response.json();
        if (typeof data.password !== 'string' || !data.password) throw new Error('unavailable');
        password.textContent = data.password;
        if (copy) copy.hidden = false;
        button.hidden = true;
      } catch (_) {
        notice.textContent = notice.dataset.failure;
      } finally {
        button.disabled = false;
      }
    });
    copy?.addEventListener('click', async () => {
      try {
        if (!navigator.clipboard) throw new Error('unavailable');
        await navigator.clipboard.writeText(password.textContent);
        notice.textContent = notice.dataset.copied;
      } catch (_) { notice.textContent = notice.dataset.failure; }
    });
    // Private access stays in this page's DOM only; back/forward restores a masked card.
    window.addEventListener('pagehide', () => {
      password.textContent = '••••••••••••';
      if (copy) copy.hidden = true;
      form.querySelector('button').hidden = false;
    });
  });
})();
