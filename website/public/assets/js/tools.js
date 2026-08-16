/* ServerNet free tools — SEO audit, Whois, IP lookup */
(function () {
  'use strict';
  const csrf = () => document.querySelector('meta[name=csrf-token]')?.content || '';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const faNum = (s, on) => on ? String(s).replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]) : String(s);
  async function post(url, body) {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
      body: JSON.stringify(body),
    });
    return res.json();
  }
  function spin(btn, on) {
    const s = btn.querySelector('.dr-spin'), l = btn.querySelector('.tsb-label, span:not(.dr-spin)');
    if (s) s.hidden = !on;
    if (l && l !== s) l.style.opacity = on ? '.5' : '1';
    btn.disabled = on;
  }

  /* ============ SEO / SITE AUDIT ============ */
  /*
   * 🔴 شرط روی **نتیجه** است، نه روی فرم.
   *
   * حالا دو صفحه همین گزارش را نشان می‌دهند: ابزارِ /tools/seo (فرم دارد) و
   * صفحهٔ عمومیِ /report/{token} که برای صاحبِ سایت فرستاده می‌شود و **فرم
   * ندارد**. اگر ورودیِ این بلوک `seo-form` بمانَد، صفحهٔ گزارش هیچ‌وقت رندر
   * نمی‌شود و بازدیدکننده یک صفحهٔ خالی می‌بیند — با کدِ ۲۰۰ و بی‌هیچ خطایی.
   */
  const seoForm = document.getElementById('seo-form');
  if (document.getElementById('seo-results')) {
    const M = window.SEO_META, input = document.getElementById('seo-input');
    const results = document.getElementById('seo-results'), errBox = document.getElementById('seo-error');
    const num = (n) => faNum(n, M.fa);

    /*
     * نوارِ پیشرفت — چون این بررسی **واقعاً** چند ثانیه طول می‌کشد.
     *
     * هفت بُعد یعنی گواهیِ TLS، چند پرس‌وجوی DNS، و دو کاوشِ جانبی؛ روی سایتِ
     * کند تا ده ثانیه هم می‌شود. با یک اسپینرِ ساکت، کاربر بعد از سه ثانیه فکر
     * می‌کند ابزار خراب است و صفحه را می‌بندد. این‌جا مرحله‌ها به‌ترتیب نشان
     * داده می‌شوند تا معلوم باشد کار در جریان است و دارد چه می‌کند.
     *
     * ⚠️ مرحله‌ها **تخمینی**‌اند نه گزارشِ واقعیِ سرور: پاسخ یک‌جا برمی‌گردد و
     * پیشرفتِ میانی‌ای در کار نیست. عمداً هم هیچ درصدی نشان نمی‌دهد — درصدِ
     * ساختگی همان دروغی است که این ابزار قرار است نگوید.
     */
    const stages = M.stages || [];
    let stageTimer = null;

    function startStages() {
      const el = document.getElementById('seo-stage');
      if (!el || !stages.length) return;
      let i = 0;
      el.hidden = false;
      el.textContent = stages[0];
      stageTimer = setInterval(() => {
        i = Math.min(i + 1, stages.length - 1);
        el.textContent = stages[i];
      }, 2200);
    }

    function stopStages() {
      if (stageTimer) { clearInterval(stageTimer); stageTimer = null; }
      const el = document.getElementById('seo-stage');
      if (el) el.hidden = true;
    }

    seoForm?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const url = input.value.trim();
      if (!url) return;
      errBox.hidden = true;
      spin(seoForm.querySelector('button'), true);
      startStages();
      try {
        const d = await post(seoForm.dataset.endpoint, { url });
        if (!d.ok) { showErr(d.error === 'invalid_url' ? M.i18n.errInvalid : M.i18n.errUnreachable); return; }
        render(d);
        showShare(d.report_url);
      } catch { showErr(M.i18n.errGeneric); }
      finally { stopStages(); spin(seoForm.querySelector('button'), false); }
    });
    document.getElementById('seo-rescan')?.addEventListener('click', () => {
      results.style.display = 'none';
      input.focus(); window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    /*
     * لینکِ اشتراکِ گزارش.
     *
     * ⚠️ اگر سرور لینکی نداد (جدولِ گزارش روی این نصب هنوز ساخته نشده)، بخش
     * اصلاً نشان داده نمی‌شود. دکمهٔ اشتراکی که به هیچ‌جا نرود از نبودنش بدتر
     * است — همان قاعدهٔ «به‌زودی»ِ صفحهٔ ریموت.
     */
    function showShare(url) {
      const box = document.getElementById('au-share');
      if (!box || !url) return;
      const field = document.getElementById('au-share-url');
      field.value = url;
      box.hidden = false;
      const pu = document.getElementById('au-print-url');
      if (pu && !pu.textContent.trim()) pu.textContent = url;
    }

    /* سربرگِ چاپ — روی ابزار، میزبان و تاریخ از این‌جا پر می‌شوند.
       ⚠️ تاریخ از `M.today` می‌آید که PHP ساخته؛ در JS تاریخِ شمسی نمی‌سازیم
       (قاعدهٔ «ریاضیِ جلالی فقط در PHP»). */
    function fillPrintHead(d) {
      const h = document.getElementById('au-print-host');
      if (h && !h.textContent.trim()) h.textContent = d.host || '';
      const dt = document.getElementById('au-print-date');
      if (dt && !dt.textContent.trim()) dt.textContent = M.today || '';
    }

    document.getElementById('au-share-copy')?.addEventListener('click', (e) => {
      const field = document.getElementById('au-share-url');
      if (!field || !field.value) return;
      field.select();
      navigator.clipboard?.writeText(field.value).catch(() => {});
      const btn = e.currentTarget, old = btn.textContent;
      btn.textContent = M.i18n.shareCopied || M.i18n.copied;
      setTimeout(() => { btn.textContent = old; }, 1600);
    });

    function showErr(msg) {
      errBox.textContent = msg; errBox.hidden = false;
      errBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    const gradeClass = (s) => s >= 75 ? 'good' : s >= 50 ? 'mid' : 'bad';

    /*
     * `opts.scroll` — چه کسی این اسکرول را خواسته؟
     *
     * 🔴 روی ابزار، کاربر فرم را فرستاده و منتظرِ نتیجه است: اسکرول درست است.
     * روی صفحهٔ عمومیِ گزارش، **خودِ صفحه** نتیجه است و کسی چیزی نخواسته —
     * اسکرولِ خودکار یعنی گیرندهٔ ایمیل وسطِ صفحه‌ای می‌افتد که هنوز عنوان و
     * نامِ دامنه‌اش را ندیده. همان تلهٔ ثبت‌شدهٔ `/tools/ip` با `data-auto`.
     */
    function render(d, opts) {
      results.style.display = 'block';
      // gauge
      const ring = document.getElementById('ag-ring'), circ = 2 * Math.PI * 52;
      ring.style.strokeDasharray = circ;
      ring.style.strokeDashoffset = circ;
      ring.className.baseVal = 'ag-fg ' + gradeClass(d.overall);
      document.querySelector('.audit-gauge').className = 'audit-gauge ' + gradeClass(d.overall);
      document.getElementById('ag-grade').textContent = d.grade;
      const scoreEl = document.getElementById('ag-score');
      let cur = 0; const step = Math.max(1, Math.round(d.overall / 30));
      const anim = setInterval(() => {
        cur = Math.min(d.overall, cur + step);
        scoreEl.textContent = num(cur);
        ring.style.strokeDashoffset = circ * (1 - cur / 100);
        if (cur >= d.overall) clearInterval(anim);
      }, 22);

      // summary counts
      let p = 0, w = 0, f = 0;
      Object.values(d.checks).forEach((arr) => arr.forEach((c) => { c.status === 'pass' ? p++ : c.status === 'warn' ? w++ : f++; }));
      const badge = document.getElementById('au-badge');
      badge.innerHTML = `<span style="color:var(--green)">✓ ${num(p)} ${M.i18n.passes}</span> · <span style="color:#FBBF24">${num(w)} ${M.i18n.warns}</span> · <span style="color:#FF8A84">${num(f)} ${M.i18n.fails}</span>`;
      document.getElementById('au-host').textContent = d.host;
      document.getElementById('au-title').textContent = d.meta.title || '';
      fillPrintHead(d);

      const facts = [];
      if (d.meta.ip) facts.push([M.i18n.ip, d.meta.ip]);
      facts.push([M.i18n.load, num(d.meta.load_ms) + ' ms']);
      facts.push([M.i18n.size, num(d.meta.size_kb) + ' KB']);
      if (d.meta.server) facts.push([M.i18n.server, d.meta.server]);
      facts.push([M.i18n.code, num(d.meta.code)]);
      document.getElementById('au-facts').innerHTML = facts.map(([k, v]) =>
        `<div><small>${esc(k)}</small><b dir="ltr">${esc(v)}</b></div>`).join('');

      // ── برنامهٔ اقدام ──────────────────────────────────────────────
      /* گزارشِ قبلی می‌گفت «۱۷ مورد قرمز است» و کاربر را با ۱۷ تصمیم تنها
         می‌گذاشت. ترتیب از سرور می‌آید (وزنِ چک × شدت) تا «مهم» یک تعریف
         داشته باشد، نه دو تا. */
      renderPlan(d);

      // category bars
      document.getElementById('audit-cats').innerHTML = Object.entries(d.scores).map(([key, sc]) => {
        const m = M.cats[key] || { t: key, icon: 'check' };
        return `<button class="acat ${gradeClass(sc)}" data-cat="${key}" title="${esc(M.i18n.jump)}">
          <span class="acat-ico"><svg class="icon"><use href="#i-${m.icon}"/></svg></span>
          <span class="acat-name">${esc(m.t)}${m.who ? `<small>${esc(m.who)}</small>` : ''}</span>
          <span class="acat-bar"><i style="width:${sc}%"></i></span>
          <b class="acat-score">${num(sc)}</b>
        </button>`;
      }).join('');

      // vitals
      const vBox = document.getElementById('audit-vitals');
      if (d.vitals) {
        const items = [['LCP', d.vitals.lcp], ['CLS', d.vitals.cls], ['FCP', d.vitals.fcp], ['TBT', d.vitals.tbt], ['Speed Index', d.vitals.si]].filter((x) => x[1]);
        vBox.hidden = false;
        vBox.innerHTML = `<h3><svg class="icon"><use href="#i-zap"/></svg>${esc(M.i18n.vitals)} — ${num(d.vitals.perf)}/100</h3>
          <div class="vitals-row">${items.map(([k, v]) => `<div class="vitem"><small>${k}</small><b dir="ltr">${esc(v)}</b></div>`).join('')}</div>`;
      } else { vBox.hidden = true; }

      // detail groups
      document.getElementById('audit-detail').innerHTML = Object.entries(d.checks).map(([key, arr]) => {
        const m = M.cats[key] || { t: key, icon: 'check' };
        const rows = arr.slice().sort((a, b) => order(a.status) - order(b.status)).map((c) => row(c)).join('');
        return `<div class="adetail-group" data-group="${key}">
          <h3><span class="acat-ico sm"><svg class="icon"><use href="#i-${m.icon}"/></svg></span>${esc(m.t)}<span class="adg-score ${gradeClass(d.scores[key])}">${num(d.scores[key])}</span></h3>
          <div class="acheck-list">${rows}</div></div>`;
      }).join('');

      document.querySelectorAll('.acat').forEach((b) => b.addEventListener('click', () => {
        document.querySelector(`.adetail-group[data-group="${b.dataset.cat}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }));

      setupFilter();

      requestAnimationFrame(() => document.querySelectorAll('.acat-bar i, .adg-score').forEach((el) => el.classList.add('in')));
      if (!opts || opts.scroll !== false) results.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    /* ============ برنامهٔ اقدام ============ */

    function renderPlan(d) {
      const box = document.getElementById('audit-plan');
      if (!box) return;
      const plan = Array.isArray(d.plan) ? d.plan : [];
      box.hidden = false;

      if (!plan.length) {
        box.innerHTML = `<div class="aplan-clear">
          <svg class="icon"><use href="#i-check"/></svg><span>${esc(M.i18n.planNone)}</span></div>`;
        return;
      }

      box.innerHTML = `
        <div class="aplan-head">
          <h3><svg class="icon"><use href="#i-sparkles"/></svg>${esc(M.i18n.planTitle)}</h3>
          <p>${esc(M.i18n.planLead)}</p>
        </div>
        <ol class="aplan-list">${plan.map((p, i) => planItem(p, i)).join('')}</ol>`;
    }

    function planItem(p, i) {
      const meta = M.checks[p.key] || { t: p.key, d: '' };
      const cat = M.cats[p.cat] || { t: p.cat, icon: 'check' };
      const fix = (M.fixes || {})[p.key];
      return `<li class="aplan-item ${p.status}">
        <span class="aplan-n">${num(i + 1)}</span>
        <div class="aplan-body">
          <div class="aplan-t">
            <b>${esc(meta.t)}</b>
            <span class="aplan-tag"><svg class="icon"><use href="#i-${cat.icon}"/></svg>${esc(cat.t)}</span>
            <span class="aplan-sev ${p.status}">${esc(p.status === 'fail' ? M.i18n.fail : M.i18n.warn)}</span>
          </div>
          <p class="aplan-why">${esc(meta.d)}</p>
          ${fix ? fixBlock(fix) : ''}
        </div>
      </li>`;
    }

    /* بلوکِ راهکار — همان چیزی که این ابزار را از «نمره‌دهنده» به «راهنما»
       تبدیل می‌کند. کد در <pre> با دکمهٔ کپی، چون کسی کدِ چندخطی را تایپ نمی‌کند. */
    function fixBlock(fix) {
      return `<details class="afix">
        <summary><svg class="icon"><use href="#i-wrench"/></svg>${esc(M.i18n.howFix)}</summary>
        <p>${esc(fix.fix)}</p>
        ${fix.code ? `<div class="afix-code">
            <button type="button" class="afix-copy" data-code="${esc(fix.code)}">${esc(M.i18n.copy)}</button>
            <pre dir="ltr"><code>${esc(fix.code)}</code></pre>
          </div>` : ''}
      </details>`;
    }

    /* ============ فیلترِ شدت ============ */

    function setupFilter() {
      const bar = document.getElementById('audit-filter');
      if (!bar) return;
      bar.hidden = false;
      const labels = { all: M.i18n.fAll, fail: M.i18n.fFail, warn: M.i18n.fWarn };
      bar.querySelectorAll('button').forEach((b) => {
        const f = b.dataset.f;
        const n = f === 'all' ? null : document.querySelectorAll('.acheck.' + f).length;
        b.textContent = labels[f] + (n === null ? '' : ' (' + num(n) + ')');
        b.onclick = () => {
          bar.querySelectorAll('button').forEach((x) => x.classList.toggle('on', x === b));
          apply(f);
        };
      });
      apply('all');

      function apply(f) {
        document.querySelectorAll('.acheck').forEach((el) => {
          el.style.display = (f === 'all' || el.classList.contains(f)) ? '' : 'none';
        });
        /* گروهی که بعد از فیلتر هیچ ردیفی ندارد پنهان می‌شود — وگرنه صفحه پر
           می‌شود از عنوان‌های خالی و کاربر فکر می‌کند چیزی خراب است. */
        document.querySelectorAll('.adetail-group').forEach((g) => {
          const any = [...g.querySelectorAll('.acheck')].some((el) => el.style.display !== 'none');
          g.style.display = any ? '' : 'none';
        });
      }
    }

    /* کپیِ نمونه‌کد — واگذارشده، چون کارت هر بار از نو ساخته می‌شود */
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.afix-copy');
      if (!btn) return;
      navigator.clipboard.writeText(btn.dataset.code || '').then(() => {
        const old = btn.textContent;
        btn.textContent = M.i18n.copied;
        btn.classList.add('ok');
        setTimeout(() => { btn.textContent = old; btn.classList.remove('ok'); }, 1500);
      }).catch(() => {});
    });

    document.getElementById('seo-print')?.addEventListener('click', () => {
      /* پیش از چاپ همه‌چیز باز می‌شود، وگرنه راهکارها که در <details> جمع‌اند
         روی کاغذ غایب‌اند — و کاغذ دقیقاً چیزی است که کاربر پیشِ توسعه‌دهنده
         می‌بَرد. */
      document.querySelectorAll('#seo-results details').forEach((x) => { x.open = true; });
      window.print();
    });


    const order = (s) => s === 'fail' ? 0 : s === 'warn' ? 1 : 2;
    const icon = (s) => s === 'pass' ? '<svg class="icon"><use href="#i-check"/></svg>' : s === 'warn' ? '!' : '<svg class="icon"><use href="#i-x"/></svg>';

    function row(c) {
      const meta = M.checks[c.key] || { t: c.key, d: '' };
      let val = '';
      if (c.value != null && c.value !== '') val = String(c.value);
      else if (c.ms != null) val = num(c.ms) + ' ms';
      else if (c.days != null) val = num(c.days);
      else if (c.kb != null) val = num(c.kb) + ' KB';
      else if (c.count != null) val = num(c.count);
      else if (c.len != null) val = num(c.len) + ' ch';
      else if (c.total != null) val = num(c.missing || 0) + '/' + num(c.total);
      const label = { pass: M.i18n.pass, warn: M.i18n.warn, fail: M.i18n.fail }[c.status];
      const fix = c.status === 'pass' ? null : (M.fixes || {})[c.key];
      return `<div class="acheck ${c.status}">
        <div class="ac-row">
          <span class="ac-mark">${icon(c.status)}</span>
          <span class="ac-txt"><b>${esc(meta.t)}</b><small>${esc(meta.d)}</small></span>
          ${val ? `<span class="ac-val" dir="ltr" title="${esc(val)}">${esc(val.length > 42 ? val.slice(0, 42) + '…' : val)}</span>` : ''}
          <span class="ac-status">${esc(label)}</span>
        </div>
        ${fix ? fixBlock(fix) : ''}
      </div>`;
    }

    /*
     * صفحهٔ عمومیِ گزارش: نتیجه از سرور با صفحه می‌آید، پس همان‌جا رندر می‌شود.
     *
     * ⚠️ هیچ درخواستِ تازه‌ای زده نمی‌شود. گزارش عکسِ همان لحظه‌ای است که گرفته
     * شد؛ اگر این صفحه دوباره بررسی می‌کرد، عددی که به مشتری نشان می‌دهیم با
     * عددی که در ایمیل نوشته‌ایم یکی نمی‌مانْد — و بدتر، هر بار بازکردنِ لینک
     * یک بررسیِ کاملِ سمتِ سرور روی سایتِ او می‌شد.
     *
     * 🔴 جایگاهِ این فراخوان **آخرِ بلوک** است و نباید بالاتر برود.
     * `render()` تابعِ اعلان‌شده است و hoist می‌شود، ولی داخلش `order` و `icon`
     * را صدا می‌زند که با `const` پایین‌تر تعریف شده‌اند. فراخوانی از بالاتر یعنی
     * ReferenceErrorِ منطقهٔ مردهٔ زمانی (TDZ) **وسطِ** رندر: امتیاز و دسته‌ها و
     * برنامهٔ اقدام می‌آیند، ولی فهرستِ چک‌ها و فیلتر هرگز ساخته نمی‌شوند.
     * صفحه ۲۰۰ است، خطا در کنسول دیده نمی‌شود، و گزارشی که برای مشتری فرستاده‌ایم
     * نصفه باز می‌شود. یک بار دقیقاً همین شد.
     */
    if (window.AUDIT_DATA && window.AUDIT_DATA.ok) {
      render(window.AUDIT_DATA, { scroll: false });
    }
  }

  /* ============ WHOIS ============ */
  const whoisForm = document.getElementById('whois-form');
  if (whoisForm) {
    const T = window.TOOL_I18N, input = document.getElementById('whois-input');
    const box = document.getElementById('whois-result'), err = document.getElementById('whois-error');
    const num = (n) => faNum(n, T.fa);
    whoisForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!input.value.trim()) return;
      err.hidden = true; spin(whoisForm.querySelector('button'), true);
      try {
        const d = await post(whoisForm.dataset.endpoint, { domain: input.value.trim() });
        if (!d.ok) { err.textContent = d.error === 'invalid_domain' ? T.invalid : T.nodata; err.hidden = false; box.hidden = true; }
        else renderWhois(d);
      } catch { err.textContent = T.generic; err.hidden = false; }
      finally { spin(whoisForm.querySelector('button'), false); }
    });
    function renderWhois(d) {
      const p = d.parsed, reg = p.registered;
      const fields = [
        [T.status, reg ? `<span class="wk-badge no">${T.taken}</span>` : `<span class="wk-badge ok">${T.free}</span>`, true],
        [T.registrar, p.registrar], [T.created, p.created], [T.updated, p.updated], [T.expires, p.expires],
        [T.org, p.org], [T.country, p.country], [T.dnssec, p.dnssec],
      ].filter((x) => x[1]);
      box.hidden = false;
      box.innerHTML = `
        <div class="wk-head"><b dir="ltr">${esc(d.domain)}</b><span>${esc(d.server)}</span></div>
        <div class="wk-grid">${fields.map(([k, v, raw]) => `<div class="wk-item"><small>${esc(k)}</small><span dir="ltr">${raw ? v : esc(v)}</span></div>`).join('')}</div>
        ${p.nameservers && p.nameservers.length ? `<div class="wk-ns"><small>${esc(T.ns)}</small><div>${p.nameservers.map((n) => `<code dir="ltr">${esc(n)}</code>`).join('')}</div></div>` : ''}
        <details class="wk-raw"><summary>${esc(T.raw)}</summary><pre dir="ltr">${esc(d.raw)}</pre></details>
        ${cta(d, reg)}`;
      box.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    /*
      قدمِ بعدیِ کاربر — که تا امروز فقط برای دامنهٔ **آزاد** وجود داشت.

      🔴 هر دو دکمه به فروشگاهِ خودمان می‌روند و در **همان تب** باز می‌شوند.
      قبلاً `target="_blank"` به WHMCSِ بیرونی می‌رفت؛ حالا که مقصد داخلی است،
      تبِ تازه فقط قیف را می‌شکند.

      ⚠️ دامنهٔ گرفته‌شده هم قدمِ بعدی دارد: تا امروز کاربر با یک «گرفته شده»
      تنها گذاشته می‌شد و صفحه بن‌بست بود. جستجوی نامِ مشابه دقیقاً همان کاری
      است که آن لحظه می‌خواهد بکند.
    */
    function cta(d, registered) {
      const sld = String(d.domain).split('.')[0];

      if (!registered) {
        return `<a class="btn btn-primary wk-buy" href="${esc(T.registerUrl)}${encodeURIComponent(d.domain)}">
          ${esc(T.register)} <span dir="ltr">${esc(d.domain)}</span></a>`;
      }

      return T.similar
        ? `<a class="btn btn-glass wk-buy" href="${esc(T.registerUrl)}${encodeURIComponent(sld)}">${esc(T.similar)}</a>`
        : '';
    }
  }

  /* ============ IP LOOKUP ============ */
  const ipForm = document.getElementById('ip-form');
  if (ipForm) {
    const T = window.TOOL_I18N, input = document.getElementById('ip-input');
    const box = document.getElementById('ip-result'), err = document.getElementById('ip-error');
    const num = (n) => faNum(n, T.fa);
    const flags = new Set(T.flags || []);
    let clockTimer = null;

    /* پرچمِ SVGِ خودمیزبان؛ اگر آن کشور را نداشتیم، ایموجیِ سرور.
       ⚠️ فهرست از سرور می‌آید تا مرورگر تصویرِ ۴۰۴ نزند — روی کارتی که همان
       لحظه دیده می‌شود، تصویرِ شکسته از نبودِ تصویر بدتر است.
       ⚠️ ایموجیِ پرچم روی ویندوز به دو حرف تبدیل می‌شود و همین دلیلِ اصلیِ
       SVG است؛ ولی برای کشوری که فایلش را نداریم، همان دو حرف از هیچ بهتر است. */
    function flagHtml(d, cls) {
      const code = String(d.countryCode || '').toLowerCase();
      const name = d.country || code;
      if (flags.has(code)) {
        return `<img class="${cls} is-svg" src="${T.flagBase}${code}.svg" alt="${esc(name)}" width="64" height="64" loading="lazy">`;
      }
      return `<span class="${cls} is-emoji" role="img" aria-label="${esc(name)}">${esc(d.flag || '')}</span>`;
    }

    async function lookup(ip, opts) {
      const o = opts || {};
      const scroll = o.scroll !== false;
      /* اجرای خودکار بی‌صدا شکست می‌خورد: کاربر چیزی نخواسته بود، پس کادرِ
         قرمز در لحظهٔ ورود فقط می‌ترسانَد. (روی IPِ رزروشده — لوکال‌هاست،
         CG-NAT — یا قطعیِ گذرای سرویسِ ژئو دقیقاً همین رخ می‌دهد.) */
      const fail = (msg) => { if (o.quiet) return; err.textContent = msg; err.hidden = false; };
      err.hidden = true; spin(ipForm.querySelector('button'), true);
      try {
        const d = await post(ipForm.dataset.endpoint, { ip: ip || '' });
        if (!d.ok) { fail(d.error === 'invalid_ip' ? T.invalid : T.generic); box.hidden = true; }
        else renderIp(d, scroll);
      } catch { fail(T.generic); }
      finally { spin(ipForm.querySelector('button'), false); }
    }
    ipForm.addEventListener('submit', (e) => { e.preventDefault(); lookup(input.value.trim()); });

    /* ساعتِ محلیِ همان IP — `offset` ثانیه‌ی اختلاف با UTC است، پس وقت را از
       اجزای UTC می‌سازیم تا ساعتِ خودِ بازدیدکننده اثری نگذارد. */
    function startClock(offset) {
      if (clockTimer) { clearInterval(clockTimer); clockTimer = null; }
      const el = box.querySelector('#ip-clock');
      if (!el || typeof offset !== 'number') return;
      const tick = () => {
        const t = new Date(Date.now() + offset * 1000);
        const p = (n) => String(n).padStart(2, '0');
        el.textContent = num(p(t.getUTCHours()) + ':' + p(t.getUTCMinutes()) + ':' + p(t.getUTCSeconds()));
      };
      tick();
      clockTimer = setInterval(tick, 1000);
    }

    function item(label, value, opt) {
      if (value == null || value === '' || value === 'undefined') return '';
      const o = opt || {};
      return `<div class="wk-item${o.copy ? ' has-copy' : ''}">
        <small>${esc(label)}</small>
        <span dir="${o.dir || 'ltr'}">${o.raw ? value : esc(value)}</span>
        ${o.copy ? copyBtn(o.copy) : ''}
      </div>`;
    }
    const copyBtn = (text) =>
      `<button type="button" class="ipr-copy" data-copy="${esc(text)}" aria-label="${esc(T.copy)}" title="${esc(T.copy)}"><svg class="icon"><use href="#i-copy"/></svg></button>`;

    function renderIp(d, scroll) {
      const tags = [];
      if (d.hosting) tags.push(['hosting', T.hosting]);
      if (d.proxy) tags.push(['proxy', T.proxy]);
      if (d.mobile) tags.push(['mobile', T.mobile]);
      if (!tags.length) tags.push(['clean', T.residential]);

      /* ⚠️ یکتاسازی لازم است: در بسیاری از کشورها نامِ شهر و استان یکی است
         (تهران/تهران، Tehran/Tehran) و بی‌این، زیرِ آدرس «Tehran، Tehran، Iran»
         نوشته می‌شد. */
      const place = [...new Set([d.city, d.regionName, d.country].filter(Boolean))]
        .join(T.fa ? '، ' : ', ');
      const hasGeo = typeof d.lat === 'number' && typeof d.lon === 'number';
      const mapUrl = hasGeo
        ? `https://www.openstreetmap.org/export/embed.html?bbox=${d.lon - 0.6}%2C${d.lat - 0.4}%2C${d.lon + 0.6}%2C${d.lat + 0.4}&layer=mapnik&marker=${d.lat}%2C${d.lon}`
        : '';

      const tiles = [
        ['pin', T.country, flagHtml(d, 'ipr-tile-flag') + esc(d.country || '—')],
        ['globe', T.city, esc(d.city || d.regionName || '—')],
        ['server', T.isp, esc(d.isp || d.org || '—')],
        ['clock', T.localTime, `<span id="ip-clock" dir="ltr">—</span>`],
      ];

      box.hidden = false;
      box.innerHTML = `
        <div class="ipr-head">
          <span class="ipr-flagwrap">${flagHtml(d, 'ipr-flag')}</span>
          <div class="ipr-id">
            <small>${esc(T.yourIp)}</small>
            <b dir="ltr">${esc(d.query)}</b>
            <span class="ipr-place">${esc(place)}</span>
          </div>
          <div class="ipr-head-act">${copyBtn(d.query)}</div>
        </div>

        <div class="ip-tags">${tags.map(([c, l]) => `<span class="ip-tag ${c}">${esc(l)}</span>`).join('')}</div>

        <div class="ipr-tiles">
          ${tiles.map(([ic, k, v]) => `<div class="ipr-tile">
            <span class="ipr-tile-ic"><svg class="icon"><use href="#i-${ic}"/></svg></span>
            <small>${esc(k)}</small><b>${v}</b></div>`).join('')}
        </div>

        ${hasGeo ? `<div class="ipr-map">
          <iframe loading="lazy" title="${esc(T.mapTitle)}" src="${mapUrl}"></iframe>
          <span class="ipr-coords" dir="ltr">${num(Number(d.lat).toFixed(3))}, ${num(Number(d.lon).toFixed(3))}</span>
        </div>` : ''}

        <div class="ipr-sec">
          <h3><svg class="icon"><use href="#i-pin"/></svg>${esc(T.secGeo)}</h3>
          <div class="wk-grid">
            ${item(T.country, flagHtml(d, 'ipr-inline-flag') + esc(d.country || '') + (d.countryCode ? ` <code>${esc(d.countryCode)}</code>` : ''), { raw: true })}
            ${item(T.continent, d.continent)}
            ${item(T.region, d.regionName)}
            ${item(T.city, d.city)}
            ${item(T.zip, d.zip)}
            ${item(T.timezone, d.timezone)}
          </div>
        </div>

        <div class="ipr-sec">
          <h3><svg class="icon"><use href="#i-server"/></svg>${esc(T.secNet)}</h3>
          <div class="wk-grid">
            ${item(T.isp, d.isp)}
            ${item(T.org, d.org)}
            ${item(T.asn, d.as, { copy: d.as })}
            ${item(T.reverse, d.reverse, { copy: d.reverse })}
          </div>
        </div>`;

      startClock(typeof d.offset === 'number' ? d.offset : null);
      if (scroll) box.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    /* کپی — واگذارشده به ظرف، چون کارت هر بار از نو ساخته می‌شود */
    box.addEventListener('click', (e) => {
      const btn = e.target.closest('.ipr-copy');
      if (!btn) return;
      navigator.clipboard.writeText(btn.dataset.copy || '').then(() => {
        btn.classList.add('ok');
        setTimeout(() => btn.classList.remove('ok'), 1400);
      }).catch(() => {});
    });

    /* اجرای خودکار برای IP خود کاربر.
       🔴 `scroll:false` عمدی است: این تنها اجرایی است که کاربر نخواسته، و
       اسکرولِ خودکار در لحظهٔ ورود، هدر و عنوانِ صفحه را از دید می‌بُرد —
       بازدیدکننده وسطِ صفحه‌ای می‌افتاد که هنوز ندیده بود کجاست. */
    if (ipForm.dataset.auto === '1') lookup('', { scroll: false, quiet: true });
  }
})();
