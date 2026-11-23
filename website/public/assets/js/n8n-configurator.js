(() => {
  'use strict';
  const cycles = ['monthly','quarterly','semiannual','yearly'];
  function selection(cards, country, index, cycle, hourly) {
    const card = Number.isInteger(index) && index >= 0 ? cards[index] : null;
    const plan = card && card.country === country ? card : null;
    return {country, plan, cycle:cycles.includes(cycle) ? cycle : 'monthly', hourly:Boolean(plan && plan.country !== 'TR' && plan.hourly > 0 && hourly)};
  }
  if (typeof module !== 'undefined' && module.exports) module.exports = {selection};
  if (typeof document === 'undefined') return;
  document.querySelectorAll('[data-n8n-config]').forEach(root => {
    let cards, labels;
    try {
      cards = JSON.parse(root.querySelector('[data-n8n-catalog]').textContent);
      labels = JSON.parse(root.querySelector('[data-n8n-labels]').textContent);
      if (!Array.isArray(cards)) return;
    } catch (_) { return; }
    const form = root.querySelector('[data-n8n-order]');
    const input = name => form.elements.namedItem(name);
    const countryLinks = [...root.querySelectorAll('[data-n8n-country]')];
    const planLinks = [...root.querySelectorAll('[data-n8n-plan]')];
    const hourly = input('billing_mode');
    let country = root.dataset.country;
    let chosenIndex = cards.findIndex(card => card.slug === input('plan').value && card.location === input('location').value);
    const write = (selector, value) => { root.querySelector(selector).textContent = value ?? ''; };
    const show = (selector, visible) => { root.querySelector(selector).hidden = !visible; };
    function render() {
      const state = selection(cards, country, chosenIndex, input('cycle').value, hourly.checked);
      const card = state.plan;
      hourly.checked = state.hourly;
      hourly.disabled = !card || card.hourly <= 0 || card.country === 'TR';
      root.dataset.country = country;
      countryLinks.forEach(link => {
        const current = link.dataset.n8nCountry === country;
        link.classList.toggle('is-selected', current);
        if (current) link.setAttribute('aria-current','true'); else link.removeAttribute('aria-current');
      });
      root.querySelectorAll('[data-n8n-country-packages]').forEach(group => { group.hidden = group.dataset.n8nCountryPackages !== country; });
      planLinks.forEach(link => {
        const current = card && Number(link.dataset.n8nPlan) === chosenIndex;
        link.classList.toggle('is-selected', Boolean(current));
        if (current) link.setAttribute('aria-current','true'); else link.removeAttribute('aria-current');
      });
      show('[data-n8n-country-prompt]', !country);
      show('[data-n8n-details]', Boolean(card));
      show('[data-n8n-summary-selection]', Boolean(card));
      show('[data-n8n-summary-empty]', !card);
      show('[data-n8n-hourly-choice]', Boolean(card && card.hourly > 0 && card.country !== 'TR'));
      show('[data-n8n-hourly-note]', state.hourly);
      show('[data-n8n-turkey-note]', Boolean(card && card.country === 'TR'));
      form.querySelector('.n8n-terms').dataset.mode = state.hourly ? 'hourly' : 'cycle';
      input('location').value = card?.location || '';
      input('plan').value = card?.slug || '';
      input('image').value = card?.image || '';
      form.querySelector('button[type="submit"]').disabled = !card || root.dataset.enabled !== '1';
      if (card) {
        write('[data-n8n-summary-plan]', card.name);
        write('[data-n8n-summary-location]', card.countryLabel);
        write('[data-n8n-summary-cpu]', card.cpu+' vCPU');
        write('[data-n8n-summary-ram]', card.ram);
        write('[data-n8n-summary-disk]', card.disk);
        write('[data-n8n-summary-price]', state.hourly ? card.hourlyPrice : card.prices[state.cycle]);
        write('[data-n8n-summary-term]', state.hourly ? labels.hourly : labels[state.cycle]);
      }
    }
    function goToStep(id) {
      const element = root.querySelector(id);
      const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
      element.scrollIntoView({behavior:reduced ? 'auto' : 'smooth',block:'start'});
      const heading = element.querySelector('h3');
      heading?.setAttribute('tabindex','-1');
      heading?.focus({preventScroll:true});
    }
    function remember(link) {
      const url = new URL(link.href, window.location.href);
      if (url.origin === window.location.origin) history.replaceState(null,'',url);
    }
    countryLinks.forEach(link => link.addEventListener('click', event => {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault();country = link.dataset.n8nCountry;chosenIndex = -1;hourly.checked = false;
      render();remember(link);goToStep('#n8n-packages');
    }));
    planLinks.forEach(link => link.addEventListener('click', event => {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault();chosenIndex = Number(link.dataset.n8nPlan);
      render();remember(link);goToStep('#n8n-details');
    }));
    form.querySelectorAll('input[name="cycle"]').forEach(radio => radio.addEventListener('change', () => { hourly.checked = false;render(); }));
    hourly.addEventListener('change', render);
    form.addEventListener('submit', () => { if (form.checkValidity()) form.querySelector('button[type="submit"]').disabled = true; });
    window.addEventListener('pageshow', render);
    render();
  });
})();
