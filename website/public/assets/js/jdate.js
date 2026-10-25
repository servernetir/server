/* Self-hosted Jalali picker. Calendar arithmetic and canonical ISO dates come only from PHP. */
(function () {
  'use strict';
  var endpoint = '/admin/jdate', open = null, serial = 0;
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }
  function fa(value) { return String(value).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); }
  function label(date) { return fa(String(date).replace(/-/g, '/')); }
  function allowed(input, iso) { return (!input.dataset.min || iso >= input.dataset.min) && (!input.dataset.max || iso <= input.dataset.max); }
  function close(focus) {
    if (!open) return;
    var old = open; open = null;
    old.host.setAttribute('aria-expanded', 'false'); old.pop.remove();
    if (focus) old.host.focus();
  }
  function position(host, pop) {
    var r = host.getBoundingClientRect(), width = Math.min(304, window.innerWidth - 16);
    pop.style.width = width + 'px';
    pop.style.left = Math.max(8, Math.min(r.left, window.innerWidth - width - 8)) + 'px';
    pop.style.maxHeight = Math.max(180, window.innerHeight - 16) + 'px';
    var height = pop.offsetHeight;
    pop.style.top = Math.max(8, Math.min(r.bottom + 6, window.innerHeight - height - 8)) + 'px';
  }
  function request(params) {
    var q = new URLSearchParams(params || {});
    return fetch(endpoint + '?' + q.toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('calendar'); return r.json(); })
      .then(function (j) { if (!j || !j.ok) throw new Error('calendar'); return j; });
  }
  function choose(input, host, iso, jalali) {
    if (iso && !allowed(input, iso)) return false;
    input.value = iso; input.dataset.jalali = jalali || '';
    host.textContent = jalali ? label(jalali) : 'انتخاب تاریخ شمسی';
    host.classList.toggle('has-val', !!iso);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
    close(true); return true;
  }
  function manual(host, input, box) {
    var row = el('div', 'jd-manual-row'), entry = el('input', 'jd-manual'), apply = el('button', 'jd-action', 'ثبت تاریخ');
    entry.type = 'text'; entry.dir = 'ltr'; entry.placeholder = '۱۴۰۵/۰۸/۰۳'; entry.maxLength = 10;
    entry.setAttribute('aria-label', 'تاریخ شمسی به صورت سال، ماه و روز');
    if (input.dataset.jalali) entry.value = label(input.dataset.jalali);
    apply.type = 'button';
    function accept() {
      apply.disabled = true;
      request({ jalali: entry.value }).then(function (j) {
        if (!open || open.pop !== box) return;
        if (!j.selected_iso || !choose(input, host, j.selected_iso, j.selected_jalali)) throw new Error('date');
      }).catch(function () { entry.setCustomValidity('تاریخ معتبر و در محدودهٔ مجاز وارد کنید؛ اتصال تقویم را هم بررسی کنید.'); entry.reportValidity(); })
        .finally(function () { apply.disabled = false; });
    }
    entry.addEventListener('input', function () { entry.setCustomValidity(''); });
    entry.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); accept(); } });
    apply.addEventListener('click', accept);
    row.append(entry, apply); box.appendChild(row);
  }
  function render(host, input, box, state) {
    box.textContent = '';
    var head = el('div', 'jd-head'), prev = el('button', 'jd-nav', '›'), next = el('button', 'jd-nav', '‹');
    var month = el('select', 'jd-month'), year = el('select', 'jd-year');
    month.setAttribute('aria-label', 'ماه شمسی'); year.setAttribute('aria-label', 'سال شمسی');
    state.month_names.forEach(function (name, i) { var o = el('option', '', name); o.value = i + 1; month.appendChild(o); });
    for (var y = state.min_year; y <= state.max_year; y++) { var option = el('option', '', fa(y)); option.value = y; year.appendChild(option); }
    month.value = state.jm; year.value = state.jy;
    prev.type = next.type = 'button';
    prev.setAttribute('aria-label', 'ماه قبل'); next.setAttribute('aria-label', 'ماه بعد');
    prev.disabled = state.jy === state.min_year && state.jm === 1;
    next.disabled = state.jy === state.max_year && state.jm === 12;
    function go(y, m) { load(host, input, box, { y: y, m: m }); }
    prev.addEventListener('click', function () { go(state.jy, state.jm - 1); });
    next.addEventListener('click', function () { go(state.jy, state.jm + 1); });
    month.addEventListener('change', function () { go(+year.value, +month.value); });
    year.addEventListener('change', function () { go(+year.value, +month.value); });
    head.append(prev, month, year, next); box.appendChild(head);
    var caption = el('div', 'jd-caption', state.title); caption.setAttribute('aria-live', 'polite'); box.appendChild(caption);
    var grid = el('div', 'jd-grid');
    state.dows.forEach(function (d) { grid.appendChild(el('span', 'jd-dow', d)); });
    for (var i = 0; i < state.lead; i++) grid.appendChild(el('span', 'jd-pad'));
    state.cells.forEach(function (c) {
      var b = el('button', 'jd-day', c.label); b.type = 'button'; b.dataset.iso = c.iso;
      b.setAttribute('aria-label', label(c.jalali)); b.disabled = !allowed(input, c.iso);
      b.classList.toggle('is-today', c.today); b.classList.toggle('is-on', input.value === c.iso); b.classList.toggle('is-off', b.disabled);
      if (c.today) b.setAttribute('aria-current', 'date');
      if (input.value === c.iso) b.setAttribute('aria-pressed', 'true');
      b.addEventListener('click', function () { choose(input, host, c.iso, c.jalali); });
      grid.appendChild(b);
    });
    grid.addEventListener('keydown', function (e) {
      var delta = { ArrowLeft: 1, ArrowRight: -1, ArrowDown: 7, ArrowUp: -7 }[e.key];
      if (!delta) return;
      var days = Array.from(grid.querySelectorAll('button')), index = days.indexOf(e.target), target = days[index + delta];
      if (target && !target.disabled) { e.preventDefault(); target.focus(); }
    });
    box.appendChild(grid);
    var actions = el('div', 'jd-actions'), today = el('button', 'jd-action', 'امروز'), done = el('button', 'jd-action', 'بستن');
    today.type = done.type = 'button'; today.disabled = !allowed(input, state.today_iso);
    today.addEventListener('click', function () { choose(input, host, state.today_iso, state.today_jalali); });
    done.addEventListener('click', function () { close(true); }); actions.appendChild(today);
    if (input.dataset.jdRequired !== '1') {
      var clear = el('button', 'jd-action', 'پاک کردن'); clear.type = 'button'; clear.dataset.jdClear = '1';
      clear.addEventListener('click', function () { choose(input, host, '', ''); }); actions.appendChild(clear);
    }
    actions.appendChild(done); box.appendChild(actions); manual(host, input, box); position(host, box);
    var focus = grid.querySelector('.is-on:not(:disabled)') || grid.querySelector('.is-today:not(:disabled)') || grid.querySelector('button:not(:disabled)');
    if (focus) focus.focus({ preventScroll: true });
  }
  function load(host, input, box, params) {
    var generation = ++serial; box.dataset.generation = generation;
    box.textContent = ''; box.appendChild(el('div', 'jd-load', 'در حال دریافت تقویم شمسی…')); position(host, box);
    request(params).then(function (j) {
      if (!open || open.pop !== box || String(generation) !== box.dataset.generation) return;
      render(host, input, box, j);
    }).catch(function () {
      if (!open || open.pop !== box || String(generation) !== box.dataset.generation) return;
      box.textContent = ''; box.appendChild(el('div', 'jd-err', 'تقویم بارگذاری نشد. دوباره تلاش کنید یا تاریخ شمسی را وارد کنید.'));
      var retry = el('button', 'jd-action', 'تلاش دوباره'); retry.type = 'button'; retry.addEventListener('click', function () { load(host, input, box, params); });
      box.appendChild(retry); manual(host, input, box); position(host, box);
    });
  }
  function attach(input) {
    if (input.dataset.jdReady) return;
    input.dataset.jdReady = '1'; input.dataset.jdRequired = input.required ? '1' : '0';
    var raw = input.value;
    if (input.hasAttribute('data-iso')) input.value = input.dataset.iso || (raw ? raw : '');
    input.type = 'hidden';
    var host = el('button', 'jd-btn', input.dataset.label || 'انتخاب تاریخ شمسی'); host.type = 'button';
    host.classList.toggle('has-val', !!input.value); host.setAttribute('aria-haspopup', 'dialog'); host.setAttribute('aria-expanded', 'false');
    input.parentNode.insertBefore(host, input);
    if (input.value && !input.dataset.jalali) {
      request({ date: input.value }).then(function (j) {
        if (input.value === j.selected_iso) { input.dataset.jalali = j.selected_jalali; host.textContent = j.selected_label; }
      }).catch(function () { if (!input.dataset.label) host.textContent = 'انتخاب تاریخ شمسی'; });
    }
    host.addEventListener('click', function (e) {
      e.stopPropagation(); if (open && open.host === host) { close(true); return; } close(false);
      var pop = el('div', 'jd-pop'); pop.dir = 'rtl'; pop.setAttribute('role', 'dialog'); pop.setAttribute('aria-label', 'انتخاب تاریخ شمسی');
      pop.addEventListener('click', function (ev) { ev.stopPropagation(); });
      document.body.appendChild(pop); open = { host: host, pop: pop }; host.setAttribute('aria-expanded', 'true');
      load(host, input, pop, input.value ? { date: input.value } : {});
    });
    if (input.form && !input.form.dataset.jdGuard) {
      input.form.dataset.jdGuard = '1';
      input.form.addEventListener('submit', function (e) {
        var missing = Array.from(input.form.querySelectorAll('input[data-jdate]')).find(function (n) {
          return (n.dataset.jdRequired === '1' && !n.value) || (n.value && !/^\d{4}-\d{2}-\d{2}$/.test(n.value));
        });
        if (missing) { e.preventDefault(); var button = missing.previousElementSibling; if (button) button.click(); }
      });
    }
  }
  function scan(root) { (root || document).querySelectorAll('input[data-jdate]').forEach(attach); }
  document.addEventListener('click', function (e) { if (open && !open.pop.contains(e.target) && e.target !== open.host) close(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && open) { e.preventDefault(); close(true); } });
  window.addEventListener('resize', function () { if (open) position(open.host, open.pop); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { scan(); }); else scan();
  window.jdateScan = scan;
})();
