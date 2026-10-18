/* Progressive enhancement for the existing server-owned catalogue. */
(() => {
  'use strict';
  const form = document.querySelector('.cvx-store');
  const input = document.getElementById('cvx-search');
  if (!form || !input) return;
  const countries = [...form.querySelectorAll('.cvx-country')];
  const count = document.getElementById('cvx-count');
  const empty = document.getElementById('cvx-empty');
  const controls = [...form.querySelectorAll('[data-cvx-region]')];
  const locale = document.documentElement.lang || 'en';
  const number = new Intl.NumberFormat(locale);
  let region = 'all';
  const fold = value => String(value || '').normalize('NFKD').replace(/\p{M}/gu, '').replace(/[يى]/g, 'ی').replace(/ك/g, 'ک').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
  let english;
  try { english = new Intl.DisplayNames(['en'], {type:'region'}); } catch {}
  countries.forEach(country => {
    const summary = country.querySelector('summary');
    const code = country.dataset.country;
    country.searchTerms = fold([code, summary.dataset.label, english?.of(code), code === 'GB' ? 'UK Britain England انگلیس بریتانیا' : '', code === 'US' ? 'USA America آمریکا امریکا' : '', code === 'TR' ? 'Turkey Türkiye ترکیه' : '', code === 'AE' ? 'UAE Emirates امارات' : ''].join(' '));
    [...country.querySelectorAll('[data-city]')].forEach(city => { city.searchTerms = fold([city.dataset.city, city.dataset.label, city.dataset.cityLabel].join(' ')); });
  });
  function showGlobe(element) {
    const country = element.closest('.cvx-country');
    if (!country) return;
    const summary = country.querySelector('summary');
    const city = element.hasAttribute('data-city');
    document.dispatchEvent(new CustomEvent('cvx:location', {detail:{
      country:country.dataset.country, code:city ? element.dataset.city : '',
      city:city ? element.dataset.cityLabel : '', label:element.dataset.label || summary.dataset.label,
      approximate:!city || element.dataset.approximate === 'true'
    }}));
  }
  function filter() {
    const query = fold(input.value);
    const terms = query.split(' ').filter(Boolean);
    let total = 0, firstMatch;
    countries.forEach(country => {
      const regionMatch = region === 'all' || country.dataset.region === region;
      const countryMatch = terms.every(term => country.searchTerms.includes(term));
      let visibleCities = 0;
      country.querySelectorAll('[data-city]').forEach(city => {
        const matches = countryMatch || terms.every(term => (country.searchTerms + ' ' + city.searchTerms).includes(term));
        city.hidden = !regionMatch || !matches;
        if (!city.hidden) { visibleCities++; if (query && !firstMatch) firstMatch = countryMatch ? country.querySelector('summary') : city; }
      });
      country.hidden = !regionMatch || (!countryMatch && !visibleCities);
      if (!country.hidden) total++;
      if (query) country.open = !country.hidden;
      else country.open = !!country.querySelector('[aria-current="true"]');
    });
    count.textContent = number.format(total) + ' / ' + number.format(countries.length);
    empty.hidden = total > 0;
    if (firstMatch) showGlobe(firstMatch);
  }
  input.addEventListener('input', filter);
  controls.forEach(button => button.addEventListener('click', () => {
    region = button.dataset.cvxRegion;
    controls.forEach(control => control.setAttribute('aria-pressed', String(control === button)));
    filter();
  }));
  countries.forEach(country => {
    country.addEventListener('toggle', () => { if (country.open && !input.value.trim()) showGlobe(country.querySelector('summary')); });
    country.querySelectorAll('summary, [data-city]').forEach(element => {
      element.addEventListener('pointerenter', () => showGlobe(element));
      element.addEventListener('focus', () => showGlobe(element));
    });
  });
  function targets() {
    return [...form.querySelectorAll('#cvx-results summary, #cvx-results [data-city]')].filter(element => !element.closest('[hidden]') && (element.tagName === 'SUMMARY' || element.closest('details').open));
  }
  input.addEventListener('keydown', event => {
    if (event.key === 'ArrowDown') { event.preventDefault(); targets()[0]?.focus(); }
    if (event.key === 'Escape' && input.value) { event.preventDefault(); input.value = ''; filter(); }
  });
  document.getElementById('cvx-results').addEventListener('keydown', event => {
    if (!['ArrowDown','ArrowUp','Home','End'].includes(event.key)) return;
    const items = targets();
    const index = items.indexOf(document.activeElement);
    if (index < 0) return;
    event.preventDefault();
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : index + (event.key === 'ArrowDown' ? 1 : -1);
    if (next < 0) input.focus(); else items[Math.min(next, items.length - 1)]?.focus();
  });
  document.addEventListener('keydown', event => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k' && !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)) {
      event.preventDefault(); form.querySelector('[data-spine="1"]')?.click(); requestAnimationFrame(() => input.focus({preventScroll:true}));
    }
  });
  document.addEventListener('cvx:globe-ready', () => {
    const current = form.querySelector('[data-city][aria-current="true"]');
    if (current) showGlobe(current);
  });
})();
