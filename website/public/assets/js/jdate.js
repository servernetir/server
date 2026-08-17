/*
 |-----------------------------------------------------------------------------
 | دیت‌پیکرِ شمسی — بی‌کتابخانه، و بی‌هیچ ریاضیِ جلالی در مرورگر
 |-----------------------------------------------------------------------------
 |
 | 🔴 دو قیدِ ثبت‌شدهٔ این پروژه که شکلِ این فایل را تعیین کرده‌اند:
 |
 |  ۱) CSP هر منبعِ خارجی را **بی‌صدا** بلاک می‌کند. پس هیچ CDN و هیچ کتابخانهٔ
 |     آمادهٔ تقویمی در کار نیست؛ همه‌چیز خودمیزبان و وانیلا.
 |
 |  ۲) «ریاضیِ جلالی فقط در PHP است» — وگرنه دو پیاده‌سازی روزی یک روز اختلاف
 |     پیدا می‌کنند. این فیلد `services.next_due_at` را می‌نویسد، یعنی تاریخی
 |     که کرونِ تمدید و کرونِ تعلیق رویش تصمیم می‌گیرند: یک روز خطا یعنی
 |     فاکتورِ زودهنگام یا تعلیقِ ناخواسته.
 |
 | پس این فایل **هیچ تبدیلی نمی‌کند**. شبکهٔ ماه را از سرور می‌گیرد و هر خانه
 | تاریخِ میلادیِ آماده‌اش را با خودش دارد؛ کلیک فقط همان رشته را در فیلد
 | می‌گذارد.
 |
 | استفاده:
 |     <input type="hidden" name="next_due_at" data-jdate data-min="2026-08-18">
 | یک دکمهٔ نمایشی کنارش ساخته می‌شود و متنِ شمسی را نشان می‌دهد.
 */
(function () {
  'use strict';

  var ENDPOINT = '/admin/jdate';
  var open = null;   // پاپ‌آورِ باز (فقط یکی هم‌زمان)

  function el(tag, cls, txt) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (txt !== undefined) { n.textContent = txt; }
    return n;
  }

  function close() {
    if (open) { open.pop.remove(); open = null; }
  }

  /* ⚠️ مقایسه روی رشتهٔ ISO درست است چون قالبش ثابتِ YYYY-MM-DD است؛
     تبدیل به Date این‌جا فقط منطقهٔ زمانی را وارد بازی می‌کرد. */
  function before(iso, min) { return min && iso < min; }

  function render(host, input, box, state) {
    box.textContent = '';

    var head = el('div', 'jd-head');
    var prev = el('button', 'jd-nav', '‹');
    var next = el('button', 'jd-nav', '›');
    prev.type = 'button';
    next.type = 'button';
    var title = el('b', null, state.title);

    // ⚠️ در RTL جهتِ بصری برعکس است: «قبلی» سمتِ راست می‌نشیند
    head.appendChild(next);
    head.appendChild(title);
    head.appendChild(prev);
    box.appendChild(head);

    var grid = el('div', 'jd-grid');

    state.dows.forEach(function (d) { grid.appendChild(el('span', 'jd-dow', d)); });

    for (var i = 0; i < state.lead; i++) { grid.appendChild(el('span', 'jd-pad')); }

    state.cells.forEach(function (c) {
      var b = el('button', 'jd-day', c.label);
      b.type = 'button';

      if (c.today) { b.classList.add('is-today'); }
      if (input.value === c.iso) { b.classList.add('is-on'); }

      if (before(c.iso, host.dataset.min)) {
        // 🔴 روزِ غیرمجاز خاکستر می‌شود، ولی محافظِ واقعی سمتِ سرور است
        //    (`after:today` در کنترلر). این فقط راهنماست، نه امنیت.
        b.disabled = true;
        b.classList.add('is-off');
      } else {
        b.addEventListener('click', function () {
          input.value = c.iso;
          host.textContent = state.title.split(' ')[0] + ' ' + c.label;
          host.classList.add('has-val');
          close();
          input.dispatchEvent(new Event('change', { bubbles: true }));
        });
      }

      grid.appendChild(b);
    });

    box.appendChild(grid);

    function go(delta) {
      load(host, input, box, state.jy, state.jm + delta);
    }

    prev.addEventListener('click', function () { go(-1); });
    next.addEventListener('click', function () { go(1); });
  }

  function load(host, input, box, y, m) {
    var q = (y && m) ? ('?y=' + y + '&m=' + m) : '';

    box.textContent = '';
    box.appendChild(el('div', 'jd-load', '…'));

    fetch(ENDPOINT + q, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.ok) { throw new Error('bad'); }
        render(host, input, box, j);
      })
      .catch(function () {
        /* ⚠️ شکست باید **دیده شود**. تقویمی که بی‌صدا خالی بماند، کاربر را
           وادار می‌کند فکر کند صفحه خراب است؛ این‌جا صریح می‌گوید و راهِ دوم
           (تایپِ دستی) را باز می‌کند. */
        box.textContent = '';
        var e = el('div', 'jd-err', 'تقویم بارگذاری نشد.');
        var man = el('input', 'jd-manual');
        man.type = 'date';
        man.value = input.value || '';
        if (host.dataset.min) { man.min = host.dataset.min; }
        man.addEventListener('change', function () {
          input.value = man.value;
          host.textContent = man.value || 'انتخاب تاریخ';
          host.classList.toggle('has-val', !!man.value);
          close();
          input.dispatchEvent(new Event('change', { bubbles: true }));
        });
        box.appendChild(e);
        box.appendChild(man);
      });
  }

  function attach(input) {
    if (input.dataset.jdReady) { return; }
    input.dataset.jdReady = '1';

    var host = el('button', 'jd-btn', input.value || 'انتخاب تاریخ');
    host.type = 'button';
    if (input.dataset.min) { host.dataset.min = input.dataset.min; }
    if (input.value) { host.classList.add('has-val'); }

    input.parentNode.insertBefore(host, input);

    host.addEventListener('click', function (ev) {
      ev.stopPropagation();

      if (open && open.host === host) { close(); return; }

      close();

      var pop = el('div', 'jd-pop');
      document.body.appendChild(pop);

      var r = host.getBoundingClientRect();
      pop.style.top = (window.scrollY + r.bottom + 6) + 'px';
      // ⚠️ در RTL از لبهٔ راست چیده می‌شود تا از صفحه بیرون نزند
      pop.style.left = (window.scrollX + Math.max(8, Math.min(r.left, window.innerWidth - 268))) + 'px';

      open = { host: host, pop: pop };
      load(host, input, pop, null, null);
    });
  }

  function scan(root) {
    (root || document).querySelectorAll('input[data-jdate]').forEach(attach);
  }

  document.addEventListener('click', function (e) {
    if (open && !open.pop.contains(e.target)) { close(); }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { close(); }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { scan(); });
  } else {
    scan();
  }

  window.jdateScan = scan;   // برای محتوایی که بعداً به صفحه اضافه می‌شود
})();
