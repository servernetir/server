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
  const seoForm = document.getElementById('seo-form');
  if (seoForm) {
    const M = window.SEO_META, input = document.getElementById('seo-input');
    const results = document.getElementById('seo-results'), errBox = document.getElementById('seo-error');
    const num = (n) => faNum(n, M.fa);

    seoForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const url = input.value.trim();
      if (!url) return;
      errBox.hidden = true;
      spin(seoForm.querySelector('button'), true);
      try {
        const d = await post(seoForm.dataset.endpoint, { url });
        if (!d.ok) { showErr(d.error === 'invalid_url' ? M.i18n.errInvalid : M.i18n.errUnreachable); return; }
        render(d);
      } catch { showErr(M.i18n.errGeneric); }
      finally { spin(seoForm.querySelector('button'), false); }
    });
    document.getElementById('seo-rescan')?.addEventListener('click', () => {
      results.style.display = 'none';
      input.focus(); window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    function showErr(msg) {
      errBox.textContent = msg; errBox.hidden = false;
      errBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    const gradeClass = (s) => s >= 75 ? 'good' : s >= 50 ? 'mid' : 'bad';

    function render(d) {
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

      const facts = [];
      if (d.meta.ip) facts.push([M.i18n.ip, d.meta.ip]);
      facts.push([M.i18n.load, num(d.meta.load_ms) + ' ms']);
      facts.push([M.i18n.size, num(d.meta.size_kb) + ' KB']);
      if (d.meta.server) facts.push([M.i18n.server, d.meta.server]);
      facts.push([M.i18n.code, num(d.meta.code)]);
      document.getElementById('au-facts').innerHTML = facts.map(([k, v]) =>
        `<div><small>${esc(k)}</small><b dir="ltr">${esc(v)}</b></div>`).join('');

      // category bars
      document.getElementById('audit-cats').innerHTML = Object.entries(d.scores).map(([key, sc]) => {
        const m = M.cats[key] || { t: key, icon: 'check' };
        return `<button class="acat ${gradeClass(sc)}" data-cat="${key}">
          <span class="acat-ico"><svg class="icon"><use href="#i-${m.icon}"/></svg></span>
          <span class="acat-name">${esc(m.t)}</span>
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

      requestAnimationFrame(() => document.querySelectorAll('.acat-bar i, .adg-score').forEach((el) => el.classList.add('in')));
      results.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    const order = (s) => s === 'fail' ? 0 : s === 'warn' ? 1 : 2;
    const icon = (s) => s === 'pass' ? '<svg class="icon"><use href="#i-check"/></svg>' : s === 'warn' ? '!' : '<svg class="icon"><use href="#i-x"/></svg>';

    function row(c) {
      const meta = M.checks[c.key] || { t: c.key, d: '' };
      let val = '';
      if (c.value != null && c.value !== '') val = String(c.value);
      else if (c.ms != null) val = num(c.ms) + ' ms';
      else if (c.kb != null) val = num(c.kb) + ' KB';
      else if (c.count != null) val = num(c.count);
      else if (c.len != null) val = num(c.len) + ' ch';
      else if (c.total != null) val = num(c.missing || 0) + '/' + num(c.total);
      const label = { pass: M.i18n.pass, warn: M.i18n.warn, fail: M.i18n.fail }[c.status];
      return `<div class="acheck ${c.status}">
        <span class="ac-mark">${icon(c.status)}</span>
        <span class="ac-txt"><b>${esc(meta.t)}</b><small>${esc(meta.d)}</small></span>
        ${val ? `<span class="ac-val" dir="ltr" title="${esc(val)}">${esc(val.length > 42 ? val.slice(0, 42) + '…' : val)}</span>` : ''}
        <span class="ac-status">${esc(label)}</span>
      </div>`;
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
        ${!reg ? `<a class="btn btn-primary wk-buy" href="${esc(T.registerUrl)}${encodeURIComponent(d.domain)}" target="_blank" rel="noopener">${esc(T.register)} <span dir="ltr">${esc(d.domain)}</span></a>` : ''}`;
      box.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  /* ============ IP LOOKUP ============ */
  const ipForm = document.getElementById('ip-form');
  if (ipForm) {
    const T = window.TOOL_I18N, input = document.getElementById('ip-input');
    const box = document.getElementById('ip-result'), err = document.getElementById('ip-error');
    async function lookup(ip) {
      err.hidden = true; spin(ipForm.querySelector('button'), true);
      try {
        const d = await post(ipForm.dataset.endpoint, { ip: ip || '' });
        if (!d.ok) { err.textContent = d.error === 'invalid_ip' ? T.invalid : T.generic; err.hidden = false; box.hidden = true; }
        else renderIp(d);
      } catch { err.textContent = T.generic; err.hidden = false; }
      finally { spin(ipForm.querySelector('button'), false); }
    }
    ipForm.addEventListener('submit', (e) => { e.preventDefault(); lookup(input.value.trim()); });
    function renderIp(d) {
      const tags = [];
      if (d.hosting) tags.push(['hosting', T.hosting]);
      if (d.proxy) tags.push(['proxy', T.proxy]);
      if (d.mobile) tags.push(['mobile', T.mobile]);
      const rows = [
        [T.country, `${d.flag} ${esc(d.country)} (${esc(d.countryCode)})`, true],
        [T.region, esc(d.regionName)], [T.city, esc(d.city)], [T.zip, esc(d.zip)],
        [T.timezone, esc(d.timezone)], [T.isp, esc(d.isp)], [T.org, esc(d.org)], [T.asn, esc(d.as)],
        [T.reverse, esc(d.reverse)],
      ].filter((x) => x[1] && x[1] !== 'undefined');
      box.hidden = false;
      box.innerHTML = `
        <div class="ip-head"><span class="ip-flag">${d.flag}</span><div><b dir="ltr">${esc(d.query)}</b><small>${esc(d.city)}${d.city ? '، ' : ''}${esc(d.country)}</small></div>
        ${tags.length ? `<div class="ip-tags">${tags.map(([c, l]) => `<span class="ip-tag ${c}">${esc(l)}</span>`).join('')}</div>` : ''}</div>
        <div class="ip-map"><iframe loading="lazy" src="https://www.openstreetmap.org/export/embed.html?bbox=${d.lon - 0.6}%2C${d.lat - 0.4}%2C${d.lon + 0.6}%2C${d.lat + 0.4}&layer=mapnik&marker=${d.lat}%2C${d.lon}"></iframe></div>
        <div class="wk-grid">${rows.map(([k, v, raw]) => `<div class="wk-item"><small>${esc(k)}</small><span dir="ltr">${raw ? v : esc(v)}</span></div>`).join('')}</div>`;
      box.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    // اجرای خودکار برای IP خود کاربر
    if (ipForm.dataset.auto === '1') lookup('');
  }
})();
