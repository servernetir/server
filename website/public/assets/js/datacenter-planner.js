/* Offline calculators. No customer data or input is sent to a server. */
(function () {
  'use strict';
  const number = (v, min, max, integer = false) => {
    if (v === '' || v == null || !['number', 'string'].includes(typeof v)) throw new RangeError('input');
    const n = Number(v);
    if (!Number.isFinite(n) || n < min || n > max || (integer && !Number.isInteger(n))) throw new RangeError('input');
    return n;
  };
  const growth = x => 1 + number(x, 0, 100) / 100;
  const calculators = {
    raid(v) {
      const n = number(v.drives, 2, 128, true), size = number(v.disk_tb, .01, 100);
      const mode = String(v.raid_mode);
      if (!['0','1','5','6','10'].includes(mode) || (mode === '5' && n < 3) ||
          (mode === '6' && n < 4) || (mode === '10' && (n < 4 || n % 2))) throw new RangeError('raid');
      const count = ({'0': n, '1': 1, '5': n-1, '6': n-2, '10': n/2})[mode];
      return {raw:n * size, usable:count * size};
    },
    rack(v) {
      const minimum_u = Math.ceil(number(v.used_u, 1, 192, true) * growth(v.growth));
      return {minimum_u, rack_option:[6,9,12,15,18,22,24,27,32,37,42,45,48].find(u=>u>=minimum_u) ?? null};
    },
    ups(v) {
      const watts = number(v.load, 1, 1000000) * growth(v.growth);
      const factor = number(v.pf,.1,1);
      return {minimum_w:Math.ceil(watts), minimum_va:Math.ceil(watts/factor)};
    },
    vm(v) {
      const count = number(v.vms,1,10000,true);
      return {ram_sum:Math.ceil(count*number(v.ram_per,.25,1024)*growth(v.growth)), vcpu_sum:count*number(v.vcpu_per,1,256,true)};
    },
    cost(v) {
      const months = number(v.months,1,120,true);
      const cents = x => Math.round(number(x,0,1e11)*100);
      return {owned_total:(cents(v.purchase)+months*cents(v.maintenance))/100, rent_total:months*cents(v.rent)/100};
    }
  };
  if (typeof module !== 'undefined' && module.exports) module.exports = calculators;
  if (typeof document === 'undefined') return;
  const root = document.querySelector('[data-planner]');
  if (!root) return;
  const fmt = new Intl.NumberFormat(document.documentElement.lang || 'en', {maximumFractionDigits:2});
  for (const section of root.querySelectorAll('[data-calculator]')) {
    const form = section.querySelector('form'), error = section.querySelector('[data-error]');
    const outputs = [...section.querySelectorAll('[data-result]')];
    const compute = () => {
      try {
        if (!form.checkValidity()) throw new RangeError('form');
        const result = calculators[section.dataset.calculator](Object.fromEntries(new FormData(form)));
        for (const output of outputs) {
          const value = result[output.dataset.result];
          output.textContent = value === null ? root.dataset.outOfRange : fmt.format(value);
        }
        error.hidden = true; error.textContent = '';
      } catch (_) {
        for (const output of outputs) output.textContent = '—';
        error.textContent = root.dataset.invalid; error.hidden = false;
      }
    };
    form.addEventListener('input', compute);
    form.addEventListener('change', compute);
    form.addEventListener('submit', event => {event.preventDefault(); compute();});
    compute();
  }
}());
