/* ============================================================================
   DNSِ مدیریت‌شده — رفتارِ پنلِ مشتری
   ----------------------------------------------------------------------------
   همه‌چیز «افزایشی» است: بی‌جاوااسکریپت هم فرم‌ها ارسال می‌شوند و سرور همهٔ
   قاعده‌ها را خودش می‌سنجد. این فایل فقط راحتی اضافه می‌کند: نمایشِ فیلدهای
   مربوط به هر نوع، باز/بستنِ فرمِ ویرایش، کپی، جست‌وجو و فیلتر.
   ========================================================================== */
(function () {
  'use strict';

  /* ── کپی ─────────────────────────────────────────────────────────────── */
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy');
      var done = function () {
        var old = btn.innerHTML;
        btn.classList.add('done');
        btn.textContent = btn.getAttribute('data-copied') || 'OK';
        setTimeout(function () { btn.classList.remove('done'); btn.innerHTML = old; }, 1400);
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

    // برچسب و نمونهٔ مقدار به زبانِ همان نوع
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

  document.querySelectorAll('[data-dns-open]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var p = panel(btn.getAttribute('data-dns-open'));
      if (!p) return;
      var open = p.hidden;
      p.hidden = !open;
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) {
        var first = p.querySelector('select:not([disabled]),input:not([type=hidden]):not([disabled])');
        if (first) first.focus();
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

  /* ── تأییدِ حذف ─────────────────────────────────────────────────────── */
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      if (!window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  /* ── جست‌وجو و فیلترِ نوع ─────────────────────────────────────────── */
  var search = document.querySelector('[data-dns-search]');
  var chips = document.querySelectorAll('[data-dns-chip]');
  var activeType = '';

  function filter() {
    var q = search ? search.value.trim().toLowerCase() : '';
    document.querySelectorAll('tr.dns-row').forEach(function (row) {
      var ok = (!activeType || row.getAttribute('data-type') === activeType)
        && (!q || (row.getAttribute('data-q') || '').indexOf(q) !== -1);
      row.hidden = !ok;
      // ردیفِ ویرایشِ بازِ یک رکوردِ فیلترشده هم پنهان شود
      var edit = row.nextElementSibling;
      if (edit && edit.classList.contains('dns-edit') && !ok) edit.hidden = true;
    });
  }

  if (search) search.addEventListener('input', filter);
  chips.forEach(function (c) {
    c.addEventListener('click', function () {
      activeType = c.getAttribute('data-dns-chip');
      chips.forEach(function (x) { x.setAttribute('aria-pressed', x === c ? 'true' : 'false'); });
      filter();
    });
  });

  /* ── واردکردن: انتخابِ همه ───────────────────────────────────────── */
  var all = document.querySelector('[data-dns-all]');
  if (all) {
    all.addEventListener('change', function () {
      document.querySelectorAll('input[name="pick[]"]').forEach(function (c) { c.checked = all.checked; });
    });
  }
})();
