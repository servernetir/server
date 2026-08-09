/*
 * تقویمِ کسب‌وکار — /admin/calendar
 * ---------------------------------------------------------------------------
 * وانیلا، بی‌وابستگی. سه دلیل:
 *   ۱) این پروژه هیچ فریم‌ورکِ فرانتی ندارد (Vite و Tailwind نصب‌اند ولی هیچ
 *      صفحهٔ واقعی از آن‌ها استفاده نمی‌کند؛ CSS مستقیم در public/assets است).
 *   ۲) CSP هر منبعِ خارجی را بی‌صدا بلاک می‌کند.
 *   ۳) فایلِ جدا و نه اسکریپتِ درون‌خطی، چون Blade هر `@word` را directive و هر
 *      `{{` را درون‌یابی می‌گیرد — دو تلهٔ ثبت‌شدهٔ همین پروژه.
 *
 * 🔴 هیچ ریاضیِ تقویمِ جلالی این‌جا نیست. تعدادِ روزهای ماه و روزِ هفتهٔ اولِ
 * ماه را **سرور** می‌دهد (پارامترهای y/m در /admin/calendar/events). دو
 * پیاده‌سازیِ جلالی یعنی روزی یک روز اختلاف، و آن اختلاف در صفحه‌ای که سررسیدِ
 * فاکتور نشان می‌دهد بی‌صدا غلط می‌شود.
 */
(function () {
  'use strict';

  var root = document.getElementById('cal-root');
  var bootEl = document.getElementById('cal-boot');
  if (!root || !bootEl) return;

  var boot = {};
  try { boot = JSON.parse(bootEl.textContent || '{}'); } catch (e) { return; }

  var csrfEl = document.querySelector('meta[name=csrf-token]');
  var CSRF = csrfEl ? csrfEl.content : '';

  /* حداکثر رویدادِ نمایش‌داده‌شده در یک خانهٔ روز؛ بقیه «+N» می‌شوند.
     خانهٔ ۱۱۸ پیکسلی بیش از این را جا نمی‌دهد و اسکرولِ داخلِ خانه در شبکه
     یعنی محتوایی که هیچ‌کس پیدایش نمی‌کند. */
  var MAX_IN_CELL = 3;

  var state = {
    year: boot.year,
    month: boot.month,
    view: 'month',
    events: boot.events || [],
    grid: boot.grid || { cells: [] },
    layers: boot.layers || {},          // { type: {label, tone, icon} }
    prefs: boot.prefs || {},            // { type: bool }
    truncated: boot.truncated || [],
    today: boot.today || '',
    statuses: boot.statuses || {},
    repeats: boot.repeats || { none: 'بدون تکرار' },
    googleConnected: !!boot.googleConnected,
    dueSoonDays: boot.dueSoonDays || 3,
    upcomingDays: boot.upcomingDays || 7,
    focusedCell: 0,
    openDate: null
  };

  var el = {
    title:   document.getElementById('cal-title'),
    grid:    document.getElementById('cal-grid'),
    weekRow: document.getElementById('cal-weekdays'),
    skel:    document.getElementById('cal-skel'),
    list:    document.getElementById('cal-list'),
    empty:   document.getElementById('cal-empty'),
    warn:    document.getElementById('cal-warn'),
    chips:   document.getElementById('cal-chips'),
    up:      document.getElementById('cal-up'),
    drawer:  document.getElementById('cal-drawer'),
    dTitle:  document.getElementById('cal-drawer-title'),
    dBody:   document.getElementById('cal-drawer-body'),
    back:    document.getElementById('cal-back'),
    live:    document.getElementById('cal-live')
  };

  /* ═══════════════════════════ کمکی‌ها ═══════════════════════════ */

  var FA_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

  /** ارقامِ لاتین → فارسی. معادلِ `fa_num()`ِ PHP، چون کلِ پنل فارسی است و یک
   *  عددِ لاتین وسطِ متنِ فارسی مثلِ وصله می‌زند. */
  function fa(v) {
    return String(v == null ? '' : v).replace(/[0-9]/g, function (d) { return FA_DIGITS[+d]; });
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function layerOf(type) {
    return state.layers[type] || { label: type, tone: 'task', icon: 'i-check' };
  }

  function toneClass(type) { return 't-' + layerOf(type).tone; }

  /** کلاسِ وضعیت روی چیپ/ردیف — «انجام‌شده» و «لغوشده» باید دیده شوند */
  function stateClass(ev) {
    if (ev.status === 'done') return ' is-done';
    if (ev.status === 'cancelled') return ' is-cancelled';
    return '';
  }

  function iconSvg(name, cls) {
    return '<svg class="' + (cls || 'icon') + '" aria-hidden="true"><use href="#' + esc(name) + '"/></svg>';
  }

  /** پیام برای صفحه‌خوان. `aria-live=polite` یعنی وسطِ حرفِ کاربر نمی‌پرد. */
  function announce(msg) { if (el.live) el.live.textContent = msg; }

  /** فهرستِ لایه‌های روشن. `[]` یعنی «هیچ‌کدام» و سرور همین را می‌فهمد. */
  function activeLayers() {
    return Object.keys(state.prefs).filter(function (k) { return state.prefs[k]; });
  }

  function qs(params) {
    var out = [];
    Object.keys(params).forEach(function (k) {
      var v = params[k];
      if (Array.isArray(v)) {
        // فهرستِ خالی هم باید فرستاده شود، وگرنه سرور «هیچ‌کدام» را با
        // «ترجیحِ من» اشتباه می‌گیرد و همه‌چیز را نشان می‌دهد.
        if (v.length === 0) { out.push(encodeURIComponent(k) + '[]='); return; }
        v.forEach(function (x) { out.push(encodeURIComponent(k) + '[]=' + encodeURIComponent(x)); });
      } else if (v != null) {
        out.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
      }
    });
    return out.join('&');
  }

  function jsonFetch(url, opts) {
    opts = opts || {};
    opts.headers = Object.assign({
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-TOKEN': CSRF
    }, opts.headers || {});
    if (opts.body && typeof opts.body !== 'string') {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(opts.body);
    }
    return fetch(url, opts).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'bad_json' }; })
        .then(function (j) { j.__status = r.status; return j; });
    });
  }

  function toast(msg, kind) {
    if (typeof window.snToast === 'function') window.snToast(msg, kind || 'ok');
  }

  /* ═══════════════════════════ گروه‌بندی ═══════════════════════════ */

  /** { '1405-05-12': [ev, ev] } */
  function byDate() {
    var map = {};
    state.events.forEach(function (ev) {
      (map[ev.date] = map[ev.date] || []).push(ev);
    });
    return map;
  }

  /* ═══════════════════════════ رندر ═══════════════════════════ */

  function render() {
    var map = byDate();
    renderChips();
    renderGrid(map);
    renderList(map);
    renderWarn();
    toggleEmpty();
    if (el.title) {
      el.title.innerHTML = esc(state.grid.month_name || '') + ' ' + fa(state.year) +
        '<small>' + fa(state.events.length) + ' رویداد در این ماه</small>';
    }
  }

  function renderChips() {
    if (!el.chips) return;
    Array.prototype.forEach.call(el.chips.querySelectorAll('.cal-chip'), function (chip) {
      var type = chip.getAttribute('data-layer');
      var on = !!state.prefs[type];
      chip.setAttribute('aria-pressed', on ? 'true' : 'false');
      var n = chip.querySelector('.n');
      if (n) {
        var count = state.events.filter(function (e) { return e.type === type; }).length;
        n.textContent = on ? fa(count) : '';
      }
    });
  }

  function renderGrid(map) {
    if (!el.grid) return;
    var cells = state.grid.cells || [];
    var html = '';

    cells.forEach(function (cell, i) {
      if (cell.day == null) {
        html += '<div class="cal-day is-blank" aria-hidden="true"></div>';
        return;
      }

      var evs = map[cell.date] || [];
      var isToday = cell.date === state.today;
      // ستونِ ششم (۰-پایه) = جمعه، چون هفته از شنبه شروع می‌شود
      var isFriday = (i % 7) === 6;

      var label = fa(cell.day) + ' ' + (state.grid.month_name || '') +
        (isToday ? '، امروز' : '') +
        '، ' + (evs.length ? fa(evs.length) + ' رویداد' : 'بدون رویداد');

      html += '<button type="button" class="cal-day' +
        (isToday ? ' is-today' : '') + (isFriday ? ' is-friday' : '') +
        (evs.length ? ' has-events' : '') + '"' +
        ' data-date="' + esc(cell.date) + '" data-cell="' + i + '"' +
        ' tabindex="' + (i === state.focusedCell ? '0' : '-1') + '"' +
        ' aria-label="' + esc(label) + '">' +
        '<span class="d" aria-hidden="true">' + fa(cell.day) + '</span>';

      evs.slice(0, MAX_IN_CELL).forEach(function (ev) {
        html += '<span class="cal-ev ' + toneClass(ev.type) + stateClass(ev) + '">' +
          iconSvg(layerOf(ev.type).icon) +
          '<span>' + esc(ev.title) + '</span></span>';
      });

      if (evs.length > MAX_IN_CELL) {
        html += '<span class="cal-more" aria-hidden="true">+' + fa(evs.length - MAX_IN_CELL) + ' مورد دیگر</span>';
      }

      html += '</button>';
    });

    el.grid.innerHTML = html;

    // اگر خانهٔ فوکوس‌دار حذف شد (ماه عوض شد و کوتاه‌تر بود)، فوکوس را به
    // اولین روزِ واقعی برگردان — وگرنه tabindex=0 روی هیچ خانه‌ای نیست و
    // ناوبریِ صفحه‌کلید از این شبکه رد می‌شود.
    if (!el.grid.querySelector('[tabindex="0"]')) {
      var first = el.grid.querySelector('.cal-day:not(.is-blank)');
      if (first) {
        first.setAttribute('tabindex', '0');
        state.focusedCell = +first.getAttribute('data-cell');
      }
    }
  }

  function renderList(map) {
    if (!el.list) return;
    var dates = Object.keys(map).sort();
    var html = '';

    dates.forEach(function (date) {
      var cell = (state.grid.cells || []).filter(function (c) { return c.date === date; })[0];
      var dayLabel = cell
        ? fa(cell.day) + ' ' + (state.grid.month_name || '')
        : fa(date);

      html += '<div class="cal-list-day">' + esc(dayLabel) +
        (date === state.today ? ' — امروز' : '') + '</div>';

      map[date].forEach(function (ev) {
        html += '<button type="button" class="cal-row ' + toneClass(ev.type) + stateClass(ev) + '"' +
          ' data-date="' + esc(date) + '" data-id="' + esc(ev.id) + '"' +
          ' aria-label="' + esc(ev.sr_label) + '">' +
          iconSvg(layerOf(ev.type).icon) +
          '<span class="t">' + esc(ev.title) + '</span>' +
          '<span class="m">' + esc(layerOf(ev.type).label) +
          (ev.time ? ' · ' + fa(ev.time) : '') + '</span>' +
          '</button>';
      });
    });

    el.list.innerHTML = html;
  }

  function renderWarn() {
    if (!el.warn) return;
    var msgs = [];

    Object.keys(boot.failures || {}).forEach(function (layer) {
      msgs.push('لایهٔ «' + (layerOf(layer).label) + '» در این بازه خوانده نشد.');
    });
    (state.truncated || []).forEach(function (layer) {
      msgs.push('لایهٔ «' + (layerOf(layer).label) + '» بیش از سقفِ نمایش رویداد داشت و بریده شد.');
    });

    el.warn.innerHTML = msgs.map(esc).join('<br>');
    el.warn.hidden = msgs.length === 0;
  }

  function toggleEmpty() {
    if (!el.empty) return;
    var none = state.events.length === 0;
    el.empty.hidden = !none;

    /*
     * ⚠️ «نمای شبکه‌ای» هم ماه است هم هفته.
     *
     * نسخهٔ اول فقط `=== 'month'` را می‌سنجید، پس در نمای **هفته** فهرست هم
     * زیرِ شبکه باز می‌مانْد و کاربر رویدادها را دو بار می‌دید — یک‌بار در
     * ردیفِ هفته و یک‌بار در فهرستِ کلِ ماه. چون `setView()` این تابع را در
     * انتهای خودش صدا می‌زند، همین‌جا تصمیمِ درستِ خودش را خنثی می‌کرد.
     */
    var monthish = state.view === 'month' || state.view === 'week';
    if (el.list) el.list.hidden = monthish || none;
  }

  function setView(view) {
    state.view = view;
    root.setAttribute('data-view', view);

    var monthish = view === 'month' || view === 'week';
    if (el.grid) el.grid.hidden = !monthish;
    if (el.weekRow) el.weekRow.hidden = !monthish;
    if (el.list) el.list.hidden = monthish || state.events.length === 0;

    Array.prototype.forEach.call(document.querySelectorAll('#cal-views button'), function (b) {
      b.setAttribute('aria-pressed', b.getAttribute('data-view') === view ? 'true' : 'false');
    });

    // نمای هفته = همان شبکه، فقط ردیفِ هفتهٔ جاری. سرور داربست را می‌دهد و
    // این‌جا فقط برش می‌خورد — باز هم بی‌ریاضیِ جلالی در مرورگر.
    if (view === 'week') applyWeekSlice(); else clearWeekSlice();
    toggleEmpty();
  }

  function weekStartIndex() {
    var cells = state.grid.cells || [];
    for (var i = 0; i < cells.length; i++) {
      if (cells[i].date === state.today) return Math.floor(i / 7) * 7;
    }
    return 0;   // ماهِ نمایش‌داده‌شده امروز را ندارد ⇒ هفتهٔ اولش
  }

  function applyWeekSlice() {
    if (!el.grid) return;
    var start = weekStartIndex();
    Array.prototype.forEach.call(el.grid.children, function (node, i) {
      node.style.display = (i >= start && i < start + 7) ? '' : 'none';
    });
  }

  function clearWeekSlice() {
    if (!el.grid) return;
    Array.prototype.forEach.call(el.grid.children, function (node) { node.style.display = ''; });
  }

  /* ═══════════════════════════ بارگذاری ═══════════════════════════ */

  var loading = false;

  function showSkeleton(on) {
    if (el.skel) el.skel.hidden = !on;
    if (el.grid) el.grid.hidden = on || state.view === 'list';
    if (on && el.empty) el.empty.hidden = true;
    if (on && el.list) el.list.hidden = true;
  }

  /**
   * @param {object} [opts] `silent` اسکلت را نشان نمی‌دهد و `reopen` روزی است
   *   که بعد از بارگذاری دوباره در کشو باز شود.
   *
   * ⚠️ حالتِ `silent` برای بعد از تغییرِ وضعیت است: پرشِ اسکلت وسطِ کشوی باز
   *   آزارنده است و کاربر فکر می‌کند کارش گم شد.
   */
  function load(year, month, opts) {
    opts = opts || {};
    if (loading) return;
    loading = true;
    if (!opts.silent) { showSkeleton(true); announce('در حال بارگذاری…'); }

    var url = '/admin/calendar/events?' + qs({
      y: year, m: month, layers: activeLayers(), with_upcoming: 1
    });

    jsonFetch(url).then(function (res) {
      loading = false;
      showSkeleton(false);

      if (!res || !res.ok) {
        toast('رویدادهای این ماه خوانده نشد.', 'err');
        announce('بارگذاری ناموفق بود.');
        return;
      }

      state.year = res.grid ? res.grid.year : year;
      state.month = res.grid ? res.grid.month : month;
      if (res.grid) state.grid = res.grid;
      if (res.today) state.today = res.today;
      state.events = res.events || [];
      state.truncated = res.truncated || [];
      boot.failures = res.failures || {};

      render();
      setView(state.view);
      renderUpcoming(res.upcoming);

      if (opts.reopen) openDay(opts.reopen);

      announce((state.grid.month_name || '') + ' ' + fa(state.year) + '، ' +
        fa(state.events.length) + ' رویداد.');

      // نشانیِ صفحه هم به‌روز شود تا رفرش و «بازکردن در تبِ جدید» همان ماه
      // را بیاورد — بی‌این، مدیر لینکِ ماهی که نگاه می‌کند را نمی‌تواند بفرستد.
      try {
        history.replaceState(null, '', '/admin/calendar?y=' + state.year + '&m=' + state.month);
      } catch (e) { /* مرورگرِ قدیمی — بی‌اهمیت */ }
    }).catch(function () {
      loading = false;
      showSkeleton(false);
      toast('ارتباط با سرور برقرار نشد.', 'err');
    });
  }

  function step(delta) {
    var m = state.month + delta;
    var y = state.year;
    if (m < 1) { m = 12; y--; }
    if (m > 12) { m = 1; y++; }
    load(y, m);
  }

  /* ═══════════════════ رویدادهای پیش‌رو (ستونِ کناری) ═══════════════════ */

  /**
   * ستونِ «رویدادهای پیش‌رو».
   *
   * بازه‌اش همیشه از **امروز** است، نه از ماهِ نمایش‌داده‌شده — پس سرور آن را
   * جدا حساب می‌کند (`with_upcoming=1`) و این‌جا فقط رندر می‌شود. اگر مرورگر
   * می‌خواست خودش «امروز + ۶ روز» را بسازد، باز هم به ریاضیِ جلالی می‌رسیدیم.
   */
  function renderUpcoming(items) {
    if (!el.up || !Array.isArray(items)) return;

    if (items.length === 0) {
      el.up.innerHTML = '<div class="cal-empty" style="padding:22px 10px">' +
        iconSvg('i-check') + '<p>هفتهٔ آرامی پیش رو دارید</p>' +
        '<small>در ' + fa(state.upcomingDays) + ' روزِ آینده سررسیدی نیست.</small></div>';
      return;
    }

    el.up.innerHTML = items.map(function (ev) {
      var soon = ev.days_away <= state.dueSoonDays;
      var when = ev.days_away <= 0 ? 'امروز'
        : (ev.days_away === 1 ? 'فردا' : fa(ev.days_away) + ' روز دیگر');

      return '<button type="button" class="cal-up-item ' + toneClass(ev.type) +
        (soon ? ' is-soon' : '') + '" data-date="' + esc(ev.date) + '"' +
        ' aria-label="' + esc(ev.sr_label) + '">' +
        '<span class="bar" aria-hidden="true"></span>' +
        '<span><span class="t">' + esc(ev.title) + '</span>' +
        '<span class="w">' + esc(layerOf(ev.type).label) + ' · ' + when + '</span></span>' +
        '</button>';
    }).join('');
  }

  /* ═══════════════════════════ کشو ═══════════════════════════ */

  var lastFocused = null;

  function openDrawer(title, bodyHtml) {
    if (!el.drawer) return;
    lastFocused = document.activeElement;
    if (el.dTitle) el.dTitle.textContent = title;
    if (el.dBody) el.dBody.innerHTML = bodyHtml;
    el.drawer.classList.add('on');
    el.drawer.removeAttribute('aria-hidden');
    if (el.back) el.back.classList.add('on');

    // فوکوس به داخلِ کشو می‌رود، وگرنه کاربرِ صفحه‌کلید در پسِ زمینه گیر
    // می‌کند و نمی‌داند چیزی باز شده.
    var first = el.drawer.querySelector('button, a, input, select, textarea');
    if (first) first.focus();
  }

  function closeDrawer() {
    if (!el.drawer) return;
    el.drawer.classList.remove('on');
    el.drawer.setAttribute('aria-hidden', 'true');
    if (el.back) el.back.classList.remove('on');
    state.openDate = null;
    if (lastFocused && document.contains(lastFocused)) lastFocused.focus();
  }

  function dayTitle(date) {
    var cell = (state.grid.cells || []).filter(function (c) { return c.date === date; })[0];
    return cell ? fa(cell.day) + ' ' + (state.grid.month_name || '') + ' ' + fa(state.year) : fa(date);
  }

  function eventCard(ev) {
    var L = layerOf(ev.type);
    var html = '<div class="cal-det ' + toneClass(ev.type) + '" data-id="' + esc(ev.id) + '">' +
      '<h4>' + iconSvg(L.icon) + ' ' + esc(ev.title) +
      '<span class="tag">' + esc(L.label) + '</span></h4>';

    if (ev.description) html += '<p>' + esc(ev.description) + '</p>';
    if (ev.time) html += '<p>ساعت ' + fa(ev.time) + '</p>';
    if (ev.status && ev.status !== 'pending') {
      html += '<p>وضعیت: ' + esc(ev.status_label || ev.status) + '</p>';
    }

    html += '<div class="cal-det-act">';
    if (ev.url) {
      html += '<a class="btn btn-ghost" href="' + esc(ev.url) + '">' + iconSvg('i-link') + 'مشاهده</a>';
    }
    if (ev.editable) {
      if (ev.status !== 'done') {
        html += '<button type="button" class="btn btn-primary" data-act="done" data-id="' + esc(ev.id) + '">' +
          iconSvg('i-check') + 'انجام شد</button>';
      }
      if (ev.status !== 'cancelled') {
        html += '<button type="button" class="btn btn-ghost" data-act="cancelled" data-id="' + esc(ev.id) + '">' +
          iconSvg('i-x') + 'لغو</button>';
      }
      if (ev.status !== 'pending') {
        html += '<button type="button" class="btn btn-ghost" data-act="pending" data-id="' + esc(ev.id) + '">' +
          iconSvg('i-restore') + 'بازگرداندن</button>';
      }
      html += '<button type="button" class="btn btn-danger" data-act="delete" data-id="' + esc(ev.id) + '">' +
        iconSvg('i-x') + 'حذف</button>';
    }
    html += '</div></div>';

    return html;
  }

  function openDay(date) {
    state.openDate = date;
    var evs = byDate()[date] || [];

    var html = '';
    if (evs.length === 0) {
      html += '<div class="cal-empty">' + iconSvg('i-calendar') +
        '<p>رویدادی برای این روز نیست</p>' +
        '<small>می‌توانید یک یادآوری برای همین روز اضافه کنید.</small></div>';
    } else {
      evs.forEach(function (ev) { html += eventCard(ev); });
    }

    html += '<button type="button" class="btn btn-primary" data-act="add" data-date="' + esc(date) + '" ' +
      'style="margin-top:10px">' + iconSvg('i-plus') + 'افزودن یادآوری برای این روز</button>';

    openDrawer(dayTitle(date), html);
  }

  function openAddForm(date) {
    var opts = Object.keys(state.layers).map(function (t) {
      return '<option value="' + esc(t) + '"' + (t === 'task' ? ' selected' : '') + '>' +
        esc(state.layers[t].label) + '</option>';
    }).join('');

    var repeatOpts = Object.keys(state.repeats).map(function (r) {
      return '<option value="' + esc(r) + '">' + esc(state.repeats[r]) + '</option>';
    }).join('');

    /*
     * انتخابِ مقصد فقط وقتی معنی دارد که گوگل وصل باشد.
     * ⚠️ گوگل تکرار و مبلغ را نمی‌فهمد؛ JS پایین آن دو را خاکستری می‌کند تا
     * کاربر چیزی پر نکند که بی‌صدا دور ریخته شود.
     */
    var targetRow = state.googleConnected
      ? '<label>ثبت در<select name="target">' +
        '<option value="local">دفترِ داخلی (تکرار و مبلغ دارد)</option>' +
        '<option value="google">تقویم گوگل من (روی گوشی هم می‌آید)</option>' +
        '</select></label>'
      : '';

    var html = '<form class="cal-form" id="cal-add" style="padding:0">' +
      '<label>عنوان<input type="text" name="title" required maxlength="200" ' +
      'placeholder="مثلاً تماس با مشتری برای تمدید"></label>' +
      targetRow +
      '<div class="row">' +
      '<label>نوع<select name="type">' + opts + '</select></label>' +
      /*
       * ارقامِ **فارسی**، مثلِ بقیهٔ پنل. یک فیلدِ تاریخ با ارقامِ لاتین وسطِ
       * صفحه‌ای که همه‌جایش `fa_num()` خورده، مثلِ وصله می‌زند.
       *
       * ⚠️ بی‌خطر است چون `Jalali::parse()` سمتِ سرور ارقامِ فارسی/عربی را
       * خودش به لاتین برمی‌گرداند، و `pattern` هم هر دو را می‌پذیرد. یعنی
       * کاربر می‌تواند با صفحه‌کلیدِ فارسی یا انگلیسی تایپ کند.
       */
      '<label>تاریخ (شمسی)<input type="text" name="event_date" required dir="ltr" ' +
      'value="' + esc(fa(date || state.today)) + '" placeholder="۱۴۰۵-۰۵-۱۲" ' +
      'inputmode="numeric" pattern="[0-9۰-۹]{4}[-/][0-9۰-۹]{1,2}[-/][0-9۰-۹]{1,2}"></label>' +
      '</div>' +
      '<div class="row">' +
      '<label>تکرار<select name="repeat">' + repeatOpts + '</select></label>' +
      '<label>مبلغ (تومان، اختیاری)<input type="number" name="amount" min="0" step="1" dir="ltr" ' +
      'placeholder="۵۰٬۰۰۰٬۰۰۰" style="text-align:left"></label>' +
      '</div>' +
      /* فقط وقتی تکرار انتخاب شده معنی دارد؛ JS پایین نشانش می‌دهد */
      '<label data-until hidden>تکرار تا (اختیاری — خالی یعنی بی‌پایان)' +
      '<input type="text" name="repeat_until" dir="ltr" placeholder="۱۴۰۶-۰۵-۰۵" ' +
      'inputmode="numeric" pattern="[0-9۰-۹]{4}[-/][0-9۰-۹]{1,2}[-/][0-9۰-۹]{1,2}"></label>' +
      '<label>توضیح<textarea name="description" maxlength="2000" rows="3"></textarea></label>' +
      '<div class="row">' +
      '<button type="submit" class="btn btn-primary">' + iconSvg('i-check') + 'ذخیره</button>' +
      '<button type="button" class="btn btn-ghost" data-act="close">انصراف</button>' +
      '</div></form>';

    openDrawer('افزودن یادآوری', html);
  }

  /* ═══════════════════════════ عمل‌ها ═══════════════════════════ */

  /**
   * شناسهٔ رویدادِ دستی → { id, occurrence }.
   *
   * `manual:12` یک رویدادِ تک‌باره است و `manual:12@2026-08-27` یک **تکرارِ
   * مشخص** از یک سری. غیرِ دستی نال می‌دهد، و همان چیزی است که جلوی
   * ویرایش/حذفِ رویدادهای خودکار را می‌گیرد.
   */
  function manualRef(id) {
    var m = /^manual:(\d+)(?:@(\d{4}-\d{2}-\d{2}))?$/.exec(String(id || ''));
    return m ? { id: m[1], occurrence: m[2] || null } : null;
  }

  function setStatus(id, status) {
    var ref = manualRef(id);
    if (!ref) return;

    var body = { status: status };
    // ⚠️ بدونِ این، «انجام شد»ِ اجارهٔ مرداد روی کلِ سری می‌نشست و همهٔ
    // ماه‌های بعد هم تیک می‌خوردند.
    if (ref.occurrence) body.occurrence = ref.occurrence;

    jsonFetch('/admin/calendar/events/' + ref.id, {
      method: 'PATCH',
      body: body
    }).then(function (res) {
      if (!res || !res.ok) { toast('تغییر وضعیت انجام نشد.', 'err'); return; }

      toast('وضعیت به‌روز شد.');
      announce('وضعیتِ رویداد به ' + (res.event.status_label || status) + ' تغییر کرد.');

      // بارگذاریِ خاموشِ دوباره — و نه وصله‌زدنِ ردیفِ محلی. ستونِ «پیش‌رو»
      // بازهٔ خودش را دارد و با وصلهٔ محلی کهنه می‌مانْد: کاری که همین الان
      // «انجام شد» علامت خورد، تا رفرشِ بعدی هنوز در فهرستِ کارهای پیش‌رو بود.
      load(state.year, state.month, { silent: true, reopen: state.openDate });
    });
  }

  function removeEvent(id) {
    var ref = manualRef(id);
    if (!ref) return;

    /*
     * ⚠️ حذفِ یک سری **همهٔ** تکرارها را می‌برد، نه فقط این یکی. متنِ تأیید
     * باید همین را بگوید — وگرنه مدیر فکر می‌کند اجارهٔ همین ماه را پاک کرده و
     * یادآوریِ کلِ سال را از دست می‌دهد.
     */
    var msg = ref.occurrence
      ? 'این یک رویدادِ تکرارشونده است. حذف، **همهٔ** تکرارهایش را پاک می‌کند. ادامه؟'
      : 'این یادآوری حذف شود؟';

    var go = function (ok) {
      if (!ok) return;
      jsonFetch('/admin/calendar/events/' + ref.id, { method: 'DELETE' }).then(function (res) {
        if (!res || !res.ok) { toast('حذف انجام نشد.', 'err'); return; }
        toast('یادآوری حذف شد.');
        announce('یادآوری حذف شد.');
        load(state.year, state.month, { silent: true, reopen: state.openDate });
      });
    };

    if (typeof window.snConfirm === 'function') {
      window.snConfirm(msg, { danger: true, ok: 'حذف' }).then(go);
    } else {
      go(window.confirm(msg));
    }
  }

  /** پیام‌های خطای سرور → متنِ فارسیِ قابل‌فهم، نه «ذخیره نشد» کلی */
  var SAVE_ERRORS = {
    bad_date: 'تاریخ معتبر نیست.',
    bad_until_date: 'تاریخِ پایانِ تکرار معتبر نیست.',
    until_before_start: 'تاریخِ پایانِ تکرار نمی‌تواند قبل از تاریخِ شروع باشد.',
    google_not_connected: 'تقویم گوگل وصل نیست.',
    google_insert_failed: 'گوگل رویداد را نپذیرفت.'
  };

  function submitAdd(form) {
    var body = {
      title: form.title.value.trim(),
      type: form.type.value,
      event_date: form.event_date.value.trim(),
      description: form.description.value.trim(),
      repeat: form.repeat ? form.repeat.value : 'none',
      target: form.target ? form.target.value : 'local'
    };

    // گوگل تکرار و مبلغ ندارد — نفرستادنشان صادقانه‌تر از دورریختنِ خاموش است
    if (body.target === 'google') { body.repeat = 'none'; delete body.amount; }

    if (form.amount && form.amount.value !== '') body.amount = parseInt(form.amount.value, 10);
    if (form.repeat_until && form.repeat_until.value.trim() !== '') {
      body.repeat_until = form.repeat_until.value.trim();
    }

    if (!body.title) { toast('عنوان لازم است.', 'err'); form.title.focus(); return; }

    jsonFetch('/admin/calendar/events', { method: 'POST', body: body }).then(function (res) {
      if (!res || !res.ok) {
        toast((res && SAVE_ERRORS[res.error])
          || (res && res.messages && res.messages[0])
          || 'ذخیره نشد.', 'err');
        return;
      }

      closeDrawer();
      toast('یادآوری اضافه شد.');
      announce('یادآوری اضافه شد.');
      // ماه را دوباره می‌خوانیم: رویدادِ تازه می‌تواند در ماهِ دیگری باشد و
      // افزودنِ محلی آن‌وقت یک ردیفِ سرگردان می‌ساخت.
      load(state.year, state.month);
    });
  }

  /* ═══════════════════════════ رویدادها ═══════════════════════════ */

  // چیپ‌های لایه — تغییر فوری در نمایش، ذخیره در پس‌زمینه
  if (el.chips) {
    el.chips.addEventListener('click', function (e) {
      var chip = e.target.closest('.cal-chip');
      if (!chip) return;

      var type = chip.getAttribute('data-layer');
      state.prefs[type] = !state.prefs[type];
      chip.setAttribute('aria-pressed', state.prefs[type] ? 'true' : 'false');

      load(state.year, state.month);

      jsonFetch('/admin/calendar/preferences', {
        method: 'POST',
        body: { layers: state.prefs }
      }).then(function (res) {
        if (!res || !res.ok) toast('ترجیحِ لایه‌ها ذخیره نشد.', 'err');
      });
    });
  }

  // ناوبریِ ماه و تعویضِ نما
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-cal]');
    if (!t) return;
    var act = t.getAttribute('data-cal');

    if (act === 'prev') step(-1);
    else if (act === 'next') step(1);
    else if (act === 'today') {
      var p = (state.today || '').split('-');
      if (p.length === 3) load(+p[0], +p[1]);
    } else if (act === 'add') openAddForm(state.today);
    else if (act === 'view') setView(t.getAttribute('data-view'));
  });

  // خانهٔ روز و ردیفِ فهرست
  document.addEventListener('click', function (e) {
    var day = e.target.closest('.cal-day:not(.is-blank)');
    if (day) { openDay(day.getAttribute('data-date')); return; }

    var row = e.target.closest('.cal-row');
    if (row) { openDay(row.getAttribute('data-date')); return; }

    var up = e.target.closest('.cal-up-item');
    if (up && up.getAttribute('data-date')) { openDay(up.getAttribute('data-date')); }
  });

  // اقدام‌های داخلِ کشو
  if (el.drawer) {
    el.drawer.addEventListener('click', function (e) {
      var b = e.target.closest('[data-act]');
      if (!b) return;
      var act = b.getAttribute('data-act');

      if (act === 'close') { closeDrawer(); return; }
      if (act === 'add') { openAddForm(b.getAttribute('data-date')); return; }
      if (act === 'delete') { removeEvent(b.getAttribute('data-id')); return; }
      if (act === 'done' || act === 'cancelled' || act === 'pending') {
        setStatus(b.getAttribute('data-id'), act);
      }
    });

    el.drawer.addEventListener('submit', function (e) {
      if (e.target.id !== 'cal-add') return;
      e.preventDefault();
      submitAdd(e.target);
    });

    // «تکرار تا» فقط وقتی معنی دارد که تکراری در کار باشد
    el.drawer.addEventListener('change', function (e) {
      if (e.target.name !== 'repeat') return;
      var until = el.drawer.querySelector('[data-until]');
      if (until) until.hidden = e.target.value === 'none';
    });
  }

  var closeBtn = document.getElementById('cal-close');
  if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
  if (el.back) el.back.addEventListener('click', closeDrawer);

  /* ── ناوبریِ صفحه‌کلید روی شبکه ─────────────────────────────────────────
     tabindexِ چرخشی: فقط یک خانه در ترتیبِ Tab است و جهت‌نماها بینِ خانه‌ها
     می‌برند. بی‌این، رسیدن به روزِ آخرِ ماه ۴۱ بار Tab لازم دارد.

     ⚠️ در RTL جهتِ بصری برعکس است: فلشِ راست یعنی «روزِ قبل». اگر ساده
     نگاشت می‌شد، ناوبری در همان صفحه‌ای که راست‌به‌چپ است وارونه می‌شد. */
  if (el.grid) {
    el.grid.addEventListener('keydown', function (e) {
      var cur = e.target.closest('.cal-day');
      if (!cur) return;

      var cells = Array.prototype.slice.call(el.grid.querySelectorAll('.cal-day:not(.is-blank)'));
      var i = cells.indexOf(cur);
      if (i < 0) return;

      var next = null;
      switch (e.key) {
        case 'ArrowRight': next = cells[i - 1]; break;   // RTL: راست = قبل
        case 'ArrowLeft':  next = cells[i + 1]; break;   // RTL: چپ  = بعد
        case 'ArrowUp':    next = cells[i - 7]; break;
        case 'ArrowDown':  next = cells[i + 7]; break;
        case 'Home':       next = cells[0]; break;
        case 'End':        next = cells[cells.length - 1]; break;
        case 'PageUp':     e.preventDefault(); step(-1); return;
        case 'PageDown':   e.preventDefault(); step(1); return;
        default: return;
      }

      if (!next) return;
      e.preventDefault();
      cur.setAttribute('tabindex', '-1');
      next.setAttribute('tabindex', '0');
      state.focusedCell = +next.getAttribute('data-cell');
      next.focus();
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && el.drawer && el.drawer.classList.contains('on')) closeDrawer();
  });

  /* ═══════════════════════════ شروع ═══════════════════════════ */

  root.setAttribute('data-view', 'month');
  render();
  setView('month');
})();
