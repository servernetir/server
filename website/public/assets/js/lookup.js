/* ServerNet — DNS & Network lookup suite */
(function () {
  'use strict';
  const L = window.LOOKUP;
  if (!L) return;
  const form = document.getElementById('lk-form');
  if (!form) return;

  const T = L.i18n;
  const input = document.getElementById('lk-input');
  const select = document.getElementById('lk-type');
  const box = document.getElementById('lk-result');
  const errBox = document.getElementById('lk-error');
  const csrf = () => document.querySelector('meta[name=csrf-token]')?.content || '';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const faNum = (s) => L.fa ? String(s).replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]) : String(s);

  /* هر تغییر در نوع بررسی → صفحه‌ی سئوی همان ابزار */
  select?.addEventListener('change', () => { if (select.value) window.location.href = select.value; });

  function spin(on) {
    const btn = form.querySelector('button');
    btn.querySelector('.dr-spin').hidden = !on;
    btn.querySelector('.tsb-label').style.opacity = on ? '.5' : '1';
    btn.disabled = on;
  }
  function showErr(msg) { errBox.textContent = msg; errBox.hidden = false; box.hidden = true; }

  async function run() {
    const q = input.value.trim();
    if (!q && L.input !== 'ip') { showErr(T.empty); return; }
    errBox.hidden = true;
    spin(true);
    try {
      const res = await fetch(form.dataset.endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({ type: L.type, query: q }),
      });
      const d = await res.json();
      if (!d.ok) { showErr(errMsg(d.error)); return; }
      render(d);
    } catch { showErr(T.generic); }
    finally { spin(false); }
  }

  function errMsg(code) {
    return ({
      invalid_domain: T.invalid_domain, invalid_ip: T.invalid_ip, empty: T.empty,
      unreachable: T.unreachable, no_ssl: T.no_ssl, no_cert: T.no_ssl,
    })[code] || T.generic;
  }

  form.addEventListener('submit', (e) => { e.preventDefault(); run(); });
  if (form.dataset.auto === '1') run();

  /* ---------- render dispatcher ---------- */
  function render(d) {
    let html = '';
    switch (L.kind) {
      case 'dns': html = renderRecords(d); break;
      case 'reverse': html = renderReverse(d); break;
      case 'ssl': html = renderSsl(d); break;
      case 'ping': html = renderPing(d); break;
      case 'ports': html = renderPorts(d); break;
      case 'dnssec': html = renderDnssec(d); break;
      case 'propagation': html = renderProp(d); break;
      default: html = '<pre dir="ltr">' + esc(JSON.stringify(d, null, 2)) + '</pre>';
    }
    box.hidden = false;
    box.innerHTML = html + tools(d);
    wireTools(d);
    box.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  const head = (title, sub) =>
    `<div class="lkr-head"><b dir="ltr">${esc(title)}</b>${sub ? `<span>${esc(sub)}</span>` : ''}</div>`;

  /* DNS records table */
  function renderRecords(d) {
    if (!d.records.length) return head(d.domain, d.type) + `<div class="lkr-empty">${esc(T.no_records)}</div>`;
    const rows = d.records.map((r) => `<tr>
      <td class="lkr-type">${esc(r.type)}</td>
      <td class="lkr-val" dir="ltr">${esc(r.data)}</td>
      <td class="lkr-ttl" dir="ltr">${r.ttl != null ? faNum(r.ttl) : '—'}</td></tr>`).join('');
    return head(d.domain, d.type + ' · ' + faNum(d.count) + ' ' + T.records) +
      `<div class="lkr-tablewrap"><table class="lkr-table">
        <thead><tr><th>${esc(T.type_col)}</th><th>${esc(T.value)}</th><th>${esc(T.ttl)}</th></tr></thead>
        <tbody>${rows}</tbody></table></div>`;
  }

  /* Reverse DNS */
  function renderReverse(d) {
    if (!d.names.length) return head(d.ip) + `<div class="lkr-empty">${esc(T.rev_none)}</div>`;
    return head(d.ip, d.ptr) +
      `<div class="lkr-grid">
        <div class="lkr-item"><small>${esc(T.rev_names)}</small><span dir="ltr">${d.names.map((n) => esc(n)).join('<br>')}</span></div>
      </div>`;
  }

  /* SSL */
  function renderSsl(d) {
    const cls = d.expired ? 'bad' : (d.days_left != null && d.days_left < 15 ? 'mid' : 'good');
    const badge = d.expired ? T.ssl_expired : T.ssl_valid;
    const rows = [
      [T.ssl_subject, d.subject], [T.ssl_issuer, d.issuer], [T.ssl_from, d.valid_from],
      [T.ssl_expires, d.valid_to], [T.ssl_algo, d.sig_alg],
    ].filter((x) => x[1]);
    return `<div class="lkr-ssl ${cls}">
      <div class="lkr-ssl-top"><span class="lkr-ssl-badge">${esc(badge)}</span>
        ${d.days_left != null ? `<b>${faNum(d.days_left)}</b> <small>${esc(T.ssl_days)}</small>` : ''}</div>
      <div class="lkr-head" style="margin-top:6px"><b dir="ltr">${esc(d.domain)}</b></div>
      <div class="lkr-grid">${rows.map(([k, v]) => `<div class="lkr-item"><small>${esc(k)}</small><span dir="ltr">${esc(v)}</span></div>`).join('')}</div>
      ${d.san && d.san.length ? `<div class="lkr-ns"><small>${esc(T.ssl_covers)}</small><div>${d.san.map((s) => `<code dir="ltr">${esc(s)}</code>`).join('')}</div></div>` : ''}
    </div>`;
  }

  /* Ping */
  function renderPing(d) {
    const cls = d.avg == null ? 'bad' : (d.avg < 100 ? 'good' : d.avg < 200 ? 'mid' : 'bad');
    const stat = (k, v) => `<div class="lkr-stat"><b dir="ltr">${v == null ? '—' : faNum(v)}</b><small>${esc(k)}</small></div>`;
    return head(d.domain, d.ip + ' · ' + T.ping_port + ' ' + faNum(d.port)) +
      `<div class="lkr-pings ${cls}">
        ${stat(T.ping_min + ' (' + T.ping_ms + ')', d.min)}
        ${stat(T.ping_avg + ' (' + T.ping_ms + ')', d.avg)}
        ${stat(T.ping_max + ' (' + T.ping_ms + ')', d.max)}
        ${stat(T.ping_loss + ' %', d.loss)}
      </div>`;
  }

  /* Ports */
  function renderPorts(d) {
    const cell = (p) => `<div class="lkr-port ${p.open ? 'open' : (p.state === 'filtered' ? 'filt' : 'closed')}">
      <b dir="ltr">${faNum(p.port)}</b><small>${esc(p.name)}</small>
      <span>${p.open ? T.port_open : (p.state === 'filtered' ? T.port_filtered : T.port_closed)}</span></div>`;
    return head(d.domain, d.ip + ' · ' + faNum(d.open_count) + ' ' + T.ports_open) +
      `<div class="lkr-ports">${d.ports.map(cell).join('')}</div>`;
  }

  /* DNSSEC */
  function renderDnssec(d) {
    const yn = (b) => `<span class="lkr-yn ${b ? 'y' : 'n'}">${b ? T.yes : T.no}</span>`;
    return `<div class="lkr-ssl ${d.enabled ? 'good' : 'bad'}">
      <div class="lkr-ssl-top"><span class="lkr-ssl-badge">${d.enabled ? T.dnssec_on : T.dnssec_off}</span></div>
      <div class="lkr-head" style="margin-top:6px"><b dir="ltr">${esc(d.domain)}</b></div>
      <div class="lkr-grid">
        <div class="lkr-item"><small>${esc(T.dnssec_auth)}</small><span>${yn(d.authenticated)}</span></div>
        <div class="lkr-item"><small>${esc(T.dnssec_ds)}</small><span>${yn(d.has_ds)}</span></div>
        <div class="lkr-item"><small>${esc(T.dnssec_key)}</small><span>${yn(d.has_dnskey)}</span></div>
      </div>
      ${d.ds && d.ds.length ? `<div class="lkr-ns"><small>${esc(T.dnssec_ds)}</small><div>${d.ds.map((s) => `<code dir="ltr">${esc(s)}</code>`).join('')}</div></div>` : ''}
    </div>`;
  }

  /* Propagation */
  function renderProp(d) {
    const cls = d.consistent ? 'good' : 'mid';
    const nodes = d.nodes.map((n) => `<div class="lkr-node ${n.ok && n.values.length ? 'ok' : 'no'}">
      <div class="lkr-node-h"><b>${esc(n.resolver)}</b><small>${esc(n.loc)}</small></div>
      <div class="lkr-node-v" dir="ltr">${n.values.length ? n.values.map((v) => esc(v)).join('<br>') : esc(T.prop_noanswer)}</div></div>`).join('');
    return `<div class="lkr-ssl ${cls}"><div class="lkr-ssl-top"><span class="lkr-ssl-badge">${d.consistent ? T.prop_consistent : T.prop_pending}</span></div>
      <div class="lkr-head" style="margin-top:6px"><b dir="ltr">${esc(d.domain)}</b><span>${esc(d.type)}</span></div>
      <div class="lkr-nodes">${nodes}</div></div>`;
  }

  /* copy / download bar */
  function tools() {
    return `<div class="lkr-actions">
      <button type="button" class="lkr-act" data-act="copy"><svg class="icon"><use href="#i-code"/></svg>${esc(T.json)}</button>
      <button type="button" class="lkr-act" data-act="download"><svg class="icon"><use href="#i-box"/></svg>${esc(T.download)}</button>
    </div>`;
  }
  function wireTools(d) {
    const json = JSON.stringify(d, null, 2);
    box.querySelector('[data-act=copy]')?.addEventListener('click', async (e) => {
      try { await navigator.clipboard.writeText(json); const b = e.currentTarget; const t = b.lastChild; const old = t.textContent; t.textContent = ' ' + T.copied; setTimeout(() => (t.textContent = old), 1500); } catch {}
    });
    box.querySelector('[data-act=download]')?.addEventListener('click', () => {
      const blob = new Blob([json], { type: 'application/json' });
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = L.type + '-' + (d.domain || d.ip || 'result') + '.json';
      a.click(); URL.revokeObjectURL(a.href);
    });
  }
})();
