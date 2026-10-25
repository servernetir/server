(() => {
  'use strict';
  const validSlug = value => typeof value === 'string' && /^[a-zA-Z0-9_.-]{1,190}$/.test(value);
  function normaliseSelection(value) {
    return Array.isArray(value) ? [...new Set(value.filter(validSlug))].slice(0, 3) : [];
  }
  function toggleSelection(current, slug) {
    const selected = normaliseSelection(current);
    if (!validSlug(slug)) throw new TypeError('Invalid model ID');
    if (selected.includes(slug)) return selected.filter(item => item !== slug);
    if (selected.length === 3) throw new RangeError('Comparison is full');
    return [...selected, slug];
  }
  function estimate(rates, input, output, requests) {
    const values = [input, output, requests].map(String);
    if (values.some(v => !/^\d{1,12}$/.test(v))) throw new RangeError('Use whole counts');
    const [i, o, n] = values.map(BigInt);
    if (n < 1n || n > 10000000n || i > BigInt(rates.context) ||
        o > BigInt(rates.outputMax) || (rates.chat && i + o > BigInt(rates.context))) throw new RangeError('Usage exceeds limits');
    if (![rates.inputRate, rates.outputRate].every(v => /^\d+$/.test(String(v)))) throw new TypeError('Missing rates');
    // Display-rate planning estimate, not settlement or a price guarantee.
    const perRequest = (i * BigInt(rates.inputRate) + o * BigInt(rates.outputRate) + 999999n) / 1000000n;
    return {perRequest, total: perRequest * n};
  }
  if (typeof module !== 'undefined' && module.exports) module.exports = {estimate, normaliseSelection, toggleSelection};
  if (typeof document === 'undefined') return;
  const node = document.getElementById('ai-experience-config');
  if (!node) return;
  let config;
  try { config = JSON.parse(node.textContent); } catch { return; }
  const root = document.querySelector('[data-ai-workspace]');
  if (!root) return;
  let selected = [];
  try { selected = normaliseSelection(JSON.parse(sessionStorage.getItem('servernet.ai.compare.v1') || '[]')); } catch {}
  const tray = root.querySelector('[data-ai-compare-tray]');
  const status = root.querySelector('[data-ai-compare-status]');
  function updateSelection(message) {
    root.querySelectorAll('[data-ai-compare-add]').forEach(button => {
      const on = selected.includes(button.dataset.aiCompareAdd);
      button.hidden = false;
      button.setAttribute('aria-pressed', String(on));
      button.textContent = on ? config.picked : config.pick;
    });
    if (!tray) return;
    tray.hidden = selected.length === 0 && !message;
    status.textContent = message || config.count.replace(':count', String(selected.length));
    const link = tray.querySelector('[data-ai-compare-open]');
    const url = new URL(config.compareUrl, location.href);
    if (selected.length) url.searchParams.set('models', selected.join(','));
    link.href = url.href;
    try { sessionStorage.setItem('servernet.ai.compare.v1', JSON.stringify(selected)); } catch {}
  }
  function selectCode(tab) {
    const group = tab.closest('[data-ai-code-tabs]');
    group.querySelectorAll('[data-ai-language]').forEach(item => {
      const active = item === tab;
      item.setAttribute('aria-selected', String(active)); item.tabIndex = active ? 0 : -1;
    });
    group.querySelectorAll('[data-ai-code-panel]').forEach(panel => { panel.hidden = panel.dataset.aiCodePanel !== tab.dataset.aiLanguage; });
  }
  root.addEventListener('keydown', event => {
    const tab = event.target.closest('[data-ai-language]');
    if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const tabs = [...tab.closest('[data-ai-code-tabs]').querySelectorAll('[data-ai-language]')];
    const step = event.key === 'ArrowRight' ? 1 : -1;
    const index = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (tabs.indexOf(tab) + step + tabs.length) % tabs.length;
    selectCode(tabs[index]); tabs[index].focus();
  });
  root.addEventListener('click', async event => {
    const codeTab = event.target.closest('[data-ai-language]');
    if (codeTab) selectCode(codeTab);
    const add = event.target.closest('[data-ai-compare-add]');
    if (add) {
      try { selected = toggleSelection(selected, add.dataset.aiCompareAdd); updateSelection(); }
      catch (error) { updateSelection(config.limit); }
    }
    if (event.target.closest('[data-ai-compare-clear]')) { selected = []; updateSelection(); }
    const copy = event.target.closest('[data-ai-copy]');
    if (copy) {
      const source = document.getElementById(copy.dataset.aiCopy);
      if (!source) return;
      const original = copy.textContent;
      try { await navigator.clipboard.writeText(source.textContent); copy.textContent = config.copied; }
      catch { copy.textContent = config.copyFailed; }
      setTimeout(() => { copy.textContent = original; }, 2200);
    }
    const step = event.target.closest('[data-ai-step]');
    if (step) {
      const group = step.closest('[data-ai-walkthrough]');
      group.querySelectorAll('[data-ai-step]').forEach(item => item.setAttribute('aria-pressed', String(item === step)));
      group.querySelector('[data-ai-step-title]').textContent = step.querySelector('b').textContent;
      group.querySelector('[data-ai-step-text]').textContent = step.querySelector('[data-ai-step-detail]').textContent;
    }
  });
  updateSelection();
  const number = new Intl.NumberFormat(config.locale || 'en');
  root.querySelectorAll('[data-ai-estimator]').forEach(form => {
    const rates = JSON.parse(form.querySelector('[data-ai-estimate-config]').textContent);
    const total = form.querySelector('[data-ai-estimate-total]');
    const per = form.querySelector('[data-ai-estimate-per]');
    const error = form.querySelector('[data-ai-estimate-error]');
    function update() {
      try {
        const result = estimate(rates, form.elements.input.value, form.elements.output?.value || '0', form.elements.requests.value);
        total.textContent = number.format(result.total);
        per.textContent = number.format(result.perRequest);
        error.hidden = true;
      } catch {
        total.textContent = '—'; per.textContent = '—'; error.textContent = config.invalid; error.hidden = false;
      }
    }
    form.addEventListener('input', update);
    form.addEventListener('submit', event => { event.preventDefault(); update(); });
    update();
  });
})();