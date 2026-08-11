/*
 * فیلتر و مرتب‌سازیِ **همهٔ** جدول‌های پنلِ مدیریت — یک پیاده‌سازی، بی‌تغییر در ویوها.
 *
 * ═══ چرا این‌جا و نه در تک‌تکِ صفحه‌ها ═══
 *
 * پنل ۲۴ ویو با `.ad-table` دارد. اگر هر کدام فیلترِ خودش را داشته باشد، سه چیز
 * حتمی است: چند تای آخر هرگز ساخته نمی‌شوند، رفتارشان با هم فرق می‌کند، و رفعِ
 * یک باگ باید ۲۴ بار تکرار شود. پس ارتقا **عمومی** است و روی مارک‌آپِ موجود
 * می‌نشیند؛ هیچ ویویی لازم نیست چیزی اضافه کند.
 *
 * انصراف: `<table class="ad-table" data-no-enhance>`
 *
 * ⚠️ سه تلهٔ واقعیِ همین پنل که این فایل عمداً برایشان کد دارد:
 *
 *   ۱) **رقمِ فارسی.** ستون‌ها با `fa_num()` رندر می‌شوند، پس «۱۲» و «۹» به‌صورت
 *      رشته‌ای مقایسه می‌شوند و ۱۲ کوچک‌تر از ۹ در می‌آید. هر کلیدِ مرتب‌سازی
 *      اول به رقمِ لاتین نرمال می‌شود.
 *   ۲) **ردیفِ دنباله.** چند جدول (مثلِ حساب‌های پرداخت) هر آیتم را در **دو**
 *      `<tr>` می‌گذارند؛ دومی یک `td[colspan]`ِ تمام‌عرض است. مرتب‌سازیِ ساده
 *      آن دو را از هم جدا می‌کرد و فرمِ ویرایش زیرِ ردیفِ اشتباه می‌نشست. این‌جا
 *      ردیفِ دنباله به ردیفِ بالایی **چسبیده** می‌مانَد، هم در مرتب‌سازی هم در فیلتر.
 *   ۳) **صفحه‌بندی.** فیلترِ سمتِ مرورگر فقط ردیف‌های همین صفحه را می‌بیند. روی
 *      جدولِ صفحه‌بندی‌شده اگر این را نگوییم، مدیر «چیزی پیدا نشد» می‌بیند در
 *      حالی که همان مشتری در صفحهٔ بعد هست — یعنی ابزار به او دروغ می‌گوید.
 *      پس روی این جدول‌ها صریح نوشته می‌شود که دامنهٔ فیلتر همین صفحه است.
 */
(function () {
  'use strict';

  var FA = '۰۱۲۳۴۵۶۷۸۹', AR = '٠١٢٣٤٥٦٧٨٩';

  /** رقمِ فارسی/عربی → لاتین، و یکدست‌کردنِ فاصله‌ها (شاملِ نیم‌فاصله). */
  function norm(s) {
    var out = '', i, ch, k;
    s = String(s == null ? '' : s);
    for (i = 0; i < s.length; i++) {
      ch = s[i];
      k = FA.indexOf(ch);
      if (k < 0) k = AR.indexOf(ch);
      out += k >= 0 ? String(k) : ch;
    }
    return out.replace(/‌/g, ' ').replace(/\s+/g, ' ').trim();
  }

  /** متنِ قابلِ جستجوی یک ردیف (به‌علاوهٔ ردیفِ دنباله‌اش). */
  function rowText(group) {
    var t = '', i;
    for (i = 0; i < group.length; i++) t += ' ' + group[i].textContent;
    return norm(t).toLowerCase();
  }

  /**
   * کلیدِ مرتب‌سازیِ یک خانه.
   *
   * `data-sort` روی `<td>` همیشه برنده است — برای ستونی که متنش با ترتیبِ
   * واقعی نمی‌خوانَد (مثلِ برچسبِ وضعیت). وگرنه اگر کلِ متن عدد بود، عددی
   * مقایسه می‌شود و در غیرِ این‌صورت رشته‌ای.
   */
  function cellKey(td) {
    if (!td) return '';
    var raw = td.getAttribute('data-sort');
    var txt = norm(raw !== null ? raw : td.textContent);
    // «۱٬۲۳۴ تومان» یا «12.5 GB» → عددِ اولش. جداکنندهٔ هزارگان حذف می‌شود.
    var num = txt.replace(/[,٬،]/g, '');
    if (num !== '' && /^-?\d+(\.\d+)?$/.test(num)) return parseFloat(num);
    return txt.toLowerCase();
  }

  /** «خالی» یعنی خانه‌ای که چیزی برای مرتب‌کردن ندارد — «—» هم خالی است. */
  function blank(v) { return v === '' || v === '-' || v === '—' || v === '–'; }

  function cmp(a, b) {
    var na = typeof a === 'number', nb = typeof b === 'number';
    if (na && nb) return a - b;
    if (na) return -1;                                   // عدد پیش از متن
    if (nb) return 1;
    return String(a).localeCompare(String(b), 'fa');
  }

  /**
   * ردیف‌ها را به گروه می‌بندد: ردیفِ اصلی + ردیف‌های دنبالهٔ تمام‌عرضش.
   *
   * 🔴 `cols > 1` لازم است و نبودش یک باگِ واقعی ساخت: در جدولِ **تک‌ستونی**
   * هر ردیفِ عادی «یک خانه با colSpan ۱» است، پس شرطِ دنباله برای همه صادق
   * می‌شد و کلِ جدول یک گروه می‌شد — یعنی `groups.length` یک، زیرِ کفِ سه، و
   * جدول بی‌هیچ خطایی **اصلاً ارتقا نمی‌گرفت**. دقیقاً همان «شکست نمی‌خورد،
   * فقط اتفاق نمی‌افتد».
   *
   * `colSpan > 1` هم از همان جنس است: ردیفِ دنباله بنا به تعریف چند ستون را
   * می‌پوشاند.
   */
  function groupRows(tbody, cols) {
    var groups = [], rows = tbody.rows, i, tr, isCont;
    for (i = 0; i < rows.length; i++) {
      tr = rows[i];
      isCont = cols > 1
        && tr.cells.length === 1
        && tr.cells[0].colSpan > 1
        && tr.cells[0].colSpan >= cols
        && groups.length > 0;
      if (isCont) groups[groups.length - 1].push(tr);
      else groups.push([tr]);
    }
    return groups;
  }

  function enhance(table) {
    if (table.dataset.adtDone) return;

    var head = table.tHead && table.tHead.rows.length ? table.tHead.rows[0] : null;
    var tbody = table.tBodies[0];
    if (!head || !tbody) return;

    var cols = head.cells.length;
    var groups = groupRows(tbody, cols);

    // زیرِ سه ردیف، نوارِ فیلتر فقط شلوغی است.
    if (groups.length < 3) return;

    table.dataset.adtDone = '1';

    // ── نوارِ بالای جدول ──────────────────────────────────────────────
    var paged = !!(table.closest('.ad-content') || document).querySelector('a[href*="page="]');

    var bar = document.createElement('div');
    bar.className = 'adt-bar';
    bar.innerHTML =
      '<label class="adt-search">' +
      '<svg class="icon"><use href="#i-search"/></svg>' +
      '<input type="search" placeholder="فیلتر جدول…" autocomplete="off" spellcheck="false">' +
      '</label>' +
      '<span class="adt-count"></span>' +
      (paged ? '<span class="adt-scope">فیلتر و مرتب‌سازی روی همین صفحه است — برای جستجوی همهٔ ردیف‌ها از کادر جستجوی بالا استفاده کنید.</span>' : '');
    table.parentNode.insertBefore(bar, table);

    var input = bar.querySelector('input');
    var count = bar.querySelector('.adt-count');
    var total = groups.length;

    function render(shown) {
      count.textContent = shown === total
        ? faDigits(total) + ' ردیف'
        : faDigits(shown) + ' از ' + faDigits(total) + ' ردیف';
      count.classList.toggle('on', shown !== total);
      table.classList.toggle('adt-empty', shown === 0);
    }

    function faDigits(n) {
      return String(n).replace(/[0-9]/g, function (d) { return FA[d]; });
    }

    // ── فیلتر ────────────────────────────────────────────────────────
    var keys = groups.map(rowText);

    function applyFilter() {
      var q = norm(input.value).toLowerCase(), shown = 0, i, j, hit;
      for (i = 0; i < groups.length; i++) {
        hit = q === '' || keys[i].indexOf(q) >= 0;
        if (hit) shown++;
        for (j = 0; j < groups[i].length; j++) {
          groups[i][j].style.display = hit ? '' : 'none';
        }
      }
      render(shown);
    }

    input.addEventListener('input', applyFilter);
    // Esc کادر را پاک می‌کند بی‌آنکه فوکوس بپرد
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { input.value = ''; applyFilter(); }
    });

    // ── مرتب‌سازی ────────────────────────────────────────────────────
    var sortCol = -1, sortDir = 0;   // 0 = ترتیبِ اصلی

    function sortBy(idx) {
      if (sortCol === idx) sortDir = sortDir === 1 ? -1 : (sortDir === -1 ? 0 : 1);
      else { sortCol = idx; sortDir = 1; }

      var order = groups.slice();
      if (sortDir !== 0) {
        order = order.map(function (g, i) { return { g: g, i: i, k: cellKey(g[0].cells[idx]) }; });
        order.sort(function (a, b) {
          /* 🔴 خالی **در هر دو جهت** ته صف می‌مانَد، پس پیش از وارونگیِ جهت
             سنجیده می‌شود. اگر داخلِ `cmp` می‌ماند، نزولی همهٔ «—»ها را می‌آورد
             بالای جدول و ستون عملاً بی‌فایده می‌شد. */
          var ea = blank(a.k), eb = blank(b.k);
          if (ea !== eb) return ea ? 1 : -1;
          var r = cmp(a.k, b.k);
          if (r !== 0) return sortDir === 1 ? r : -r;
          return a.i - b.i;                    // پایدار: برابر ⇒ ترتیبِ اولیه
        });
        order = order.map(function (o) { return o.g; });
      }

      var frag = document.createDocumentFragment(), i, j;
      for (i = 0; i < order.length; i++) {
        for (j = 0; j < order[i].length; j++) frag.appendChild(order[i][j]);
      }
      tbody.appendChild(frag);

      for (i = 0; i < head.cells.length; i++) {
        head.cells[i].classList.remove('adt-asc', 'adt-desc');
        if (head.cells[i].hasAttribute('aria-sort')) head.cells[i].removeAttribute('aria-sort');
      }
      if (sortDir !== 0) {
        head.cells[idx].classList.add(sortDir === 1 ? 'adt-asc' : 'adt-desc');
        head.cells[idx].setAttribute('aria-sort', sortDir === 1 ? 'ascending' : 'descending');
      }
    }

    Array.prototype.forEach.call(head.cells, function (th, idx) {
      // ستونِ بی‌عنوان ستونِ عملیات است — مرتب‌سازی‌اش معنایی ندارد
      if (norm(th.textContent) === '') return;
      th.classList.add('adt-sortable');
      th.tabIndex = 0;
      th.addEventListener('click', function () { sortBy(idx); });
      th.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sortBy(idx); }
      });
    });

    render(total);
  }

  function run() {
    Array.prototype.forEach.call(
      document.querySelectorAll('table.ad-table:not([data-no-enhance])'),
      function (t) { try { enhance(t); } catch (e) { /* یک جدولِ عجیب نباید بقیه را بخواباند */ } }
    );
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
  else run();

  // جدولی که بعداً به صفحه اضافه شود (مثلِ تبِ تازه) هم ارتقا بگیرد
  window.adminTablesRefresh = run;
})();
