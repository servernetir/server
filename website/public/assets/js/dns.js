/* ============================================================================
   DNSِ مدیریت‌شده — رفتارِ پنلِ مشتری
   ----------------------------------------------------------------------------
   همه‌چیز «افزایشی» است: بی‌جاوااسکریپت هم فرم‌ها ارسال می‌شوند و سرور همهٔ
   قاعده‌ها را خودش می‌سنجد. این فایل فقط راحتی و حسِ پویایی اضافه می‌کند:
   فیلدهای وابسته به نوع، باز/بستنِ فرم‌ها، کپی، جست‌وجو و فیلتر، زبانه‌ها،
   رهاکردنِ فایل، شمارشِ انتخاب، دکمهٔ «در حالِ ارسال» و شمارندهٔ آمار.

   ⚠️ هیچ دیالوگِ مسدودکننده‌ای جز confirmِ حذف نیست؛ آن هم فقط وقتی کاربر
   خودش دکمهٔ حذف را زده است.
   ========================================================================== */
(function () {
  'use strict';

  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var locale = (document.documentElement.getAttribute('lang') || 'fa') === 'fa' ? 'fa-IR' : 'en-US';
  var fmt = function (n) { try { return new Intl.NumberFormat(locale).format(n); } catch (e) { return String(n); } };

  /* ── کپی ─────────────────────────────────────────────────────────────── */
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy');
      var done = function () {
        if (btn.classList.contains('done')) return;
        var old = btn.innerHTML;
        btn.classList.add('done');
        btn.textContent = '✓ ' + (btn.getAttribute('data-copied') || 'OK');
        setTimeout(function () { btn.classList.remove('done'); btn.innerHTML = old; }, 1600);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done, function () {});
      } else {
        var t = document.createElement('textarea');
        t.value = text; t.style.position = 'fixed'; t.style.opacity = '0';
        document.body.appendChild(t); t.select();
        try { document.execCommand('copy'); done(); } catch (e) {}
        t.remove();
      }
    });
  });

  /* ── فیلدهای وابسته به نوع ────────────────────────────────────────────
     ⚠️ فیلدِ پنهان `disabled` هم می‌شود تا ارسال نشود. فقط `hidden` کافی
     نبود: مقدارِ کهنهٔ یک نوعِ دیگر (مثلاً پورتِ SRV) با فرم می‌رفت. */
  function applyType(form) {
    var sel = form.querySelector('[data-dns-type]');
    var hiddenType = form.querySelector('input[type="hidden"][name="type"]');
    var type = sel ? sel.value : (hiddenType ? hiddenType.value : 'A');

    form.querySelectorAll('[data-types]').forEach(function (f) {
      var on = (' ' + f.getAttribute('data-types') + ' ').indexOf(' ' + type + ' ') !== -1;
      f.hidden = !on;
      f.querySelectorAll('input,select,textarea').forEach(function (i) { i.disabled = !on; });
    });

    form.querySelectorAll('[data-label-for]').forEach(function (l) {
      var map = parse(l.getAttribute('data-label-for'));
      if (map[type]) l.textContent = map[type];
    });
    form.querySelectorAll('[data-ph]').forEach(function (i) {
      var map = parse(i.getAttribute('data-ph'));
      i.placeholder = map[type] || '';
    });
  }

  function parse(s) {
    var out = {};
    (s || '').split('|').forEach(function (p) {
      var k = p.indexOf(':');
      if (k > 0) out[p.slice(0, k)] = p.slice(k + 1);
    });
    return out;
  }

  document.querySelectorAll('[data-dns-form]').forEach(function (form) {
    applyType(form);
    var sel = form.querySelector('[data-dns-type]');
    if (sel) sel.addEventListener('change', function () { applyType(form); });
  });

  /* ── باز/بستنِ فرم‌ها ─────────────────────────────────────────────── */
  function panel(key) { return document.querySelector('[data-dns-panel="' + key + '"]'); }

  function reveal(el) {
    if (!el || !el.getBoundingClientRect) return;
    var r = el.getBoundingClientRect();
    var head = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--header-h'), 10) || 112;
    if (r.top < head || r.bottom > window.innerHeight) {
      window.scrollTo({ top: window.scrollY + r.top - head - 16, behavior: reduced ? 'auto' : 'smooth' });
    }
  }

  document.querySelectorAll('[data-dns-open]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var key = btn.getAttribute('data-dns-open');
      var p = panel(key);
      if (!p) return;
      var open = p.hidden;
      p.hidden = !open;
      document.querySelectorAll('[data-dns-open="' + key + '"]').forEach(function (b) { b.setAttribute('aria-expanded', open ? 'true' : 'false'); });
      if (open) {
        reveal(p);
        var first = p.querySelector('select:not([disabled]),input:not([type=hidden]):not([disabled])');
        if (first) setTimeout(function () { first.focus({ preventScroll: true }); }, 60);
      }
    });
  });

  document.querySelectorAll('[data-dns-cancel]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var p = btn.closest('[data-dns-panel]');
      if (!p) return;
      p.hidden = true;
      var opener = document.querySelector('[data-dns-open="' + p.getAttribute('data-dns-panel') + '"]');
      if (opener) { opener.setAttribute('aria-expanded', 'false'); opener.focus(); }
    });
  });

  // فرمی که با خطا برگشته دیده شود، نه جایی پایینِ صفحه
  var openWithError = document.querySelector('[data-dns-panel]:not([hidden]) .dns-err');
  if (openWithError) openWithError.scrollIntoView({ block: 'center' });

  // ردیفِ تازه‌ذخیره‌شده را نشان بده
  var fresh = document.querySelector('tr.dns-row.is-new');
  if (fresh && !openWithError) setTimeout(function () { reveal(fresh); }, 250);

  /* ── تأییدِ حذف ─────────────────────────────────────────────────────── */
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  /* ── «در حالِ ارسال»: جلوگیری از کلیکِ دوباره ─────────────────────── */
  document.querySelectorAll('form[data-busy], form[data-dns-form]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (e.defaultPrevented) return;
      var b = e.submitter || f.querySelector('button[type="submit"]');
      if (b) setTimeout(function () { b.classList.add('is-busy'); b.setAttribute('aria-busy', 'true'); }, 0);
    });
  });
  // برگشت با دکمهٔ «عقب» مرورگر دکمه را چرخان نگه ندارد
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) document.querySelectorAll('.is-busy').forEach(function (b) { b.classList.remove('is-busy'); b.removeAttribute('aria-busy'); });
  });

  /* ── جست‌وجو و فیلترِ نوع ─────────────────────────────────────────── */
  var search = document.querySelector('[data-dns-search]');
  var chips = document.querySelectorAll('[data-dns-chip]');
  var noMatch = document.querySelector('[data-dns-nomatch]');
  var activeType = '';

  function filter() {
    var q = search ? search.value.trim().toLowerCase() : '';
    var shown = 0;
    document.querySelectorAll('tr.dns-row[data-type]').forEach(function (row) {
      var ok = (!activeType || row.getAttribute('data-type') === activeType)
        && (!q || (row.getAttribute('data-q') || '').indexOf(q) !== -1);
      row.hidden = !ok;
      if (ok) shown++;
      // ردیفِ ویرایشِ بازِ یک رکوردِ فیلترشده هم پنهان شود
      var edit = row.nextElementSibling;
      if (edit && edit.classList.contains('dns-edit') && !ok) edit.hidden = true;
    });
    if (noMatch) noMatch.hidden = shown !== 0;
  }

  if (search) search.addEventListener('input', filter);
  chips.forEach(function (c) {
    c.addEventListener('click', function () {
      activeType = c.getAttribute('data-dns-chip');
      chips.forEach(function (x) { x.setAttribute('aria-pressed', x === c ? 'true' : 'false'); });
      filter();
    });
  });

  /* ── زبانه‌ها (واردکردن: فایل / اسکن) ──────────────────────────────── */
  document.querySelectorAll('[data-dns-tabs]').forEach(function (box) {
    var tabs = box.querySelectorAll('[data-dns-tab]');
    function select(t) {
      tabs.forEach(function (x) {
        var on = x === t;
        x.setAttribute('aria-selected', on ? 'true' : 'false');
        var pane = document.getElementById(x.getAttribute('data-dns-tab'));
        if (pane) pane.hidden = !on;
      });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { select(t); }); });
    if (tabs.length) select(tabs[0]);
  });

  /* ── رهاکردنِ فایل ─────────────────────────────────────────────────── */
  document.querySelectorAll('[data-dns-drop]').forEach(function (drop) {
    var input = drop.querySelector('input[type="file"]');
    var name = drop.querySelector('[data-dns-drop-name]');
    ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.add('is-over'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function () { drop.classList.remove('is-over'); }); });
    if (input && name) input.addEventListener('change', function () {
      if (input.files && input.files[0]) name.textContent = input.files[0].name;
    });
  });

  /* ── واردکردن: انتخابِ همه + شمارش ───────────────────────────────── */
  var all = document.querySelector('[data-dns-all]');
  var picks = document.querySelectorAll('input[name="lines[]"]');
  var counter = document.querySelector('[data-dns-selected]');
  function recount() {
    var n = 0;
    picks.forEach(function (c) { if (c.checked) n++; });
    if (counter) counter.textContent = fmt(n);
    var submit = counter && counter.closest('form') ? counter.closest('form').querySelector('button[type="submit"]') : null;
    if (submit) submit.disabled = n === 0;
    if (all) { all.checked = n === picks.length && n > 0; all.indeterminate = n > 0 && n < picks.length; }
  }
  if (all) {
    all.addEventListener('change', function () {
      picks.forEach(function (c) { c.checked = all.checked; });
      recount();
    });
  }
  picks.forEach(function (c) { c.addEventListener('change', recount); });

  /* ── شمارندهٔ آمار ───────────────────────────────────────────────────── */
  if (!reduced) {
    document.querySelectorAll('[data-count]').forEach(function (el) {
      var to = parseInt(el.getAttribute('data-count'), 10) || 0;
      if (to < 2) return;
      var start = null, dur = Math.min(1200, 500 + to * 20);
      el.textContent = fmt(0);
      function step(ts) {
        if (start === null) start = ts;
        var p = Math.min(1, (ts - start) / dur);
        var eased = 1 - Math.pow(1 - p, 3);
        el.textContent = fmt(Math.round(to * eased));
        if (p < 1) requestAnimationFrame(step);
      }
      requestAnimationFrame(step);
      // تبِ پس‌زمینه rAF نمی‌دهد؛ عدد نباید روی صفر بماند
      setTimeout(function () { el.textContent = fmt(to); }, dur + 300);
    });
  }
})();
