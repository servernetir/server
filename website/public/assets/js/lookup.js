/* ServerNet — DNS & Network lookup: shared renderers + single-type form */
(function () {
  'use strict';
  const L = window.LOOKUP;
  if (!L) return;
  const T = L.i18n || {};
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const faNum = (s) => L.fa ? String(s).replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]) : String(s);
  const head = (title, sub) => `<div class="lkr-head"><b dir="ltr">${esc(title)}</b>${sub ? `<span>${esc(sub)}</span>` : ''}</div>`;

  /* ---------- renderers ---------- */
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
  function renderReverse(d) {
    if (!d.names.length) return head(d.ip) + `<div class="lkr-empty">${esc(T.rev_none)}</div>`;
    return head(d.ip, d.ptr) + `<div class="lkr-grid">
        <div class="lkr-item"><small>${esc(T.rev_names)}</small><span dir="ltr">${d.names.map((n) => esc(n)).join('<br>')}</span></div></div>`;
  }
  function renderSsl(d) {
    const cls = d.expired ? 'bad' : (d.days_left != null && d.days_left < 15 ? 'mid' : 'good');
    const badge = d.expired ? T.ssl_expired : T.ssl_valid;
    const rows = [[T.ssl_subject, d.subject], [T.ssl_issuer, d.issuer], [T.ssl_from, d.valid_from], [T.ssl_expires, d.valid_to], [T.ssl_algo, d.sig_alg]].filter((x) => x[1]);
    return `<div class="lkr-ssl ${cls}">
      <div class="lkr-ssl-top"><span class="lkr-ssl-badge">${esc(badge)}</span>${d.days_left != null ? `<b>${faNum(d.days_left)}</b> <small>${esc(T.ssl_days)}</small>` : ''}</div>
      <div class="lkr-head" style="margin-top:6px"><b dir="ltr">${esc(d.domain)}</b></div>
      <div class="lkr-grid">${rows.map(([k, v]) => `<div class="lkr-item"><small>${esc(k)}</small><span dir="ltr">${esc(v)}</span></div>`).join('')}</div>
      ${d.san && d.san.length ? `<div class="lkr-ns"><small>${esc(T.ssl_covers)}</small><div>${d.san.map((s) => `<code dir="ltr">${esc(s)}</code>`).join('')}</div></div>` : ''}</div>`;
  }
  function renderPing(d) {
    const cls = d.avg == null ? 'bad' : (d.avg < 100 ? 'good' : d.avg < 200 ? 'mid' : 'bad');
    const stat = (k, v) => `<div class="lkr-stat"><b dir="ltr">${v == null ? '—' : faNum(v)}</b><small>${esc(k)}</small></div>`;
    return head(d.domain, d.ip + ' · ' + T.ping_port + ' ' + faNum(d.port)) +
      `<div class="lkr-pings ${cls}">${stat(T.ping_min + ' (' + T.ping_ms + ')', d.min)}${stat(T.ping_avg + ' (' + T.ping_ms + ')', d.avg)}${stat(T.ping_max + ' (' + T.ping_ms + ')', d.max)}${stat(T.ping_loss + ' %', d.loss)}</div>`;
  }
  function renderPorts(d) {
    const klass = (p) => p.open ? 'open'
      : p.state === 'filtered' ? 'filt'
      : p.state === 'skipped'  ? 'skip' : 'closed';
    const label = (p) => p.open ? T.port_open
      : p.state === 'filtered' ? T.port_filtered
      : p.state === 'skipped'  ? T.port_skipped : T.port_closed;
    const cell = (p) => `<div class="lkr-port ${klass(p)}">
      <b dir="ltr">${faNum(p.port)}</b><small>${esc(p.name)}</small>
      <span>${label(p)}</span></div>`;
    // اگر بودجه‌ی زمانی تمام شده باشد، صریح بگوییم چند پورت اصلاً امتحان نشد
    const note = d.skipped > 0
      ? `<p class="lkr-note">${T.ports_skipped_note.replace(':n', faNum(d.skipped))}</p>` : '';
    return head(d.domain, d.ip + ' · ' + faNum(d.open_count) + ' ' + T.ports_open) +
      `<div class="lkr-ports">${d.ports.map(cell).join('')}</div>` + note;
  }
  function renderDnssec(d) {
    const yn = (b) => `<span class="lkr-yn ${b ? 'y' : 'n'}">${b ? T.yes : T.no}</span>`;
    return `<div class="lkr-ssl ${d.enabled ? 'good' : 'bad'}">
      <div class="lkr-ssl-top"><span class="lkr-ssl-badge">${d.enabled ? T.dnssec_on : T.dnssec_off}</span></div>
      <div class="lkr-head" style="margin-top:6px"><b dir="ltr">${esc(d.domain)}</b></div>
      <div class="lkr-grid">
        <div class="lkr-item"><small>${esc(T.dnssec_auth)}</small><span>${yn(d.authenticated)}</span></div>
        <div class="lkr-item"><small>${esc(T.dnssec_ds)}</small><span>${yn(d.has_ds)}</span></div>
        <div class="lkr-item"><small>${esc(T.dnssec_key)}</small><span>${yn(d.has_dnskey)}</span></div></div>
      ${d.ds && d.ds.length ? `<div class="lkr-ns"><small>${esc(T.dnssec_ds)}</small><div>${d.ds.map((s) => `<code dir="ltr">${esc(s)}</code>`).join('')}</div></div>` : ''}</div>`;
  }
  function renderProp(d) {
    const cls = d.consistent ? 'good' : 'mid';
    const nodes = d.nodes.map((n) => `<div class="lkr-node ${n.ok && n.values.length ? 'ok' : 'no'}">
      <div class="lkr-node-h"><b>${esc(n.resolver)}</b><small>${esc(n.loc)}</small></div>
      <div class="lkr-node-v" dir="ltr">${n.values.length ? n.values.map((v) => esc(v)).join('<br>') : esc(T.prop_noanswer)}</div></div>`).join('');
    return `<div class="lkr-ssl ${cls}"><div class="lkr-ssl-top"><span class="lkr-ssl-badge">${d.consistent ? T.prop_consistent : T.prop_pending}</span></div>
      <div class="lkr-head" style="margin-top:6px"><b dir="ltr">${esc(d.domain)}</b><span>${esc(d.type)}</span></div>
      <div class="lkr-nodes">${nodes}</div></div>`;
  }

  function renderEmail(d) {
    const badge = d.verdict === 'good' ? T.em_good : d.verdict === 'warn' ? T.em_warn : T.em_bad;
    const cls = d.verdict === 'good' ? 'good' : d.verdict === 'warn' ? 'mid' : 'bad';
    const yn = (b) => `<span class="lkr-yn ${b ? 'y' : 'n'}">${b ? T.em_found : T.em_missing}</span>`;
    const spfNote = d.spf.multiple ? ` <span class="lkr-yn n">${esc(T.em_multi)}</span>` : '';
    const rec = (r) => r ? `<div class="lkr-ns"><small>${esc(T.em_record)}</small><div><code dir="ltr">${esc(r)}</code></div></div>` : '';
    return `<div class="lkr-ssl ${cls}">
      <div class="lkr-ssl-top"><span class="lkr-ssl-badge">${esc(badge)}</span></div>
      <div class="lkr-head" style="margin-top:6px"><b dir="ltr">${esc(d.domain)}</b></div>
      <div class="lkr-grid">
        <div class="lkr-item"><small>MX</small><span>${d.mx.length ? d.mx.map((m) => `<code dir="ltr">${esc(m)}</code>`).join(' ') : yn(false)}</span></div>
        <div class="lkr-item"><small>SPF</small><span>${yn(d.spf.found)}${spfNote}</span></div>
        <div class="lkr-item"><small>DMARC</small><span>${yn(d.dmarc.found)}${d.dmarc.policy ? ` <code dir="ltr">p=${esc(d.dmarc.policy)}</code>` : ''}</span></div>
        <div class="lkr-item"><small>DKIM</small><span>${d.dkim.found.length ? d.dkim.found.map((s) => `<code dir="ltr">${esc(s)}</code>`).join(' ') : esc(T.em_dkim_none)}</span></div>
      </div>
      ${rec(d.spf.record)}${rec(d.dmarc.record)}</div>`;
  }
  function renderBlacklist(d) {
    const cls = d.listed > 0 ? 'bad' : 'good';
    const badge = d.listed > 0 ? T.bl_some.replace(':n', faNum(d.listed)) : T.bl_all_clean;
    const state = (z) => z.state === 'listed' ? `<span class="lkr-yn n">${T.bl_listed}</span>`
      : z.state === 'unknown' ? `<span>${T.bl_unknown}</span>` : `<span class="lkr-yn y">${T.bl_clean}</span>`;
    const rows = d.zones.map((z) => `<tr><td class="lkr-type" dir="ltr">${esc(z.label)}</td>
      <td>${state(z)}</td><td class="lkr-val" dir="ltr">${z.reason ? esc(z.reason) : '—'}</td></tr>`).join('');
    return `<div class="lkr-ssl ${cls}"><div class="lkr-ssl-top"><span class="lkr-ssl-badge">${esc(badge)}</span></div>
      <div class="lkr-head" style="margin-top:6px"><b dir="ltr">${esc(d.domain)}</b><span dir="ltr">${esc(d.ip)}</span></div>
      <div class="lkr-tablewrap"><table class="lkr-table">
        <thead><tr><th>${esc(T.bl_zone)}</th><th>${esc(T.bl_state)}</th><th>${esc(T.bl_reason)}</th></tr></thead>
        <tbody>${rows}</tbody></table></div></div>`;
  }
  function renderSpeed(d) {
    const stat = (k, v, unit) => `<div class="lkr-stat"><b dir="ltr">${v == null ? '—' : faNum(v)}</b><small>${esc(k)}${unit ? ' (' + unit + ')' : ''}</small></div>`;
    const vantage = (label, t) => `<div class="lkr-head" style="margin-top:14px"><b>${esc(label)}</b><span dir="ltr">HTTP ${esc(t.status)}</span></div>
      <div class="lkr-pings ${t.ttfb_ms != null && t.ttfb_ms < 500 ? 'good' : t.ttfb_ms < 1000 ? 'mid' : 'bad'}">
        ${stat('DNS', t.dns_ms, T.sp_ms)}${stat(T.sp_connect, t.connect_ms, T.sp_ms)}${stat('TLS', t.tls_ms, T.sp_ms)}${stat('TTFB', t.ttfb_ms, T.sp_ms)}${stat(T.sp_total, t.total_ms, T.sp_ms)}</div>`;
    let iran = '';
    if (d.iran.state === 'ok') {
      iran = `<div class="lkr-head" style="margin-top:14px"><b>${esc(T.sp_iran)}</b><span dir="ltr">HTTP ${esc(d.iran.status)}</span></div>
        <div class="lkr-pings ${d.iran.ok ? 'good' : 'bad'}">${stat(T.sp_total, d.iran.total_ms, T.sp_ms)}</div>`;
    } else if (d.iran.state === 'failed') {
      // probe زنده است ولی سایت از داخل ایران باز نشد — خودِ یافته است، نه خرابی
      iran = `<p class="lkr-note">${esc(T.sp_iran)}: ${esc(T.ac_unreach_iran)}</p>`;
    } else if (d.iran.state === 'unreachable') {
      iran = `<p class="lkr-note">${esc(T.sp_probe_down)}</p>`;
    } else {
      iran = `<p class="lkr-note">${esc(T.sp_noprobe)}</p>`;
    }
    return head(d.domain, d.url) + vantage(T.sp_eu, d.eu) + iran;
  }
  function renderHeaders(d) {
    const cls = d.score >= 70 ? 'good' : d.score >= 40 ? 'mid' : 'bad';
    const names = { hsts: 'HSTS', csp: 'CSP', frame: T.hd_frame, nosniff: 'X-Content-Type-Options', referrer: 'Referrer-Policy', permissions: 'Permissions-Policy' };
    const items = Object.keys(names).map((k) => `<div class="lkr-item"><small dir="ltr">${esc(names[k])}</small>
      <span class="lkr-yn ${d.checks[k] ? 'y' : 'n'}">${d.checks[k] ? T.hd_present : T.hd_absent}</span></div>`).join('');
    return `<div class="lkr-ssl ${cls}">
      <div class="lkr-ssl-top"><span class="lkr-ssl-badge">${esc(T.hd_grade)}: ${esc(d.grade)}</span><b>${faNum(d.score)}</b> <small>/ ${faNum(100)}</small></div>
      <div class="lkr-head" style="margin-top:6px"><b dir="ltr">${esc(d.domain)}</b><span dir="ltr">HTTP ${esc(d.status)}</span></div>
      <div class="lkr-grid">${items}</div>
      ${d.server ? `<div class="lkr-ns"><small dir="ltr">Server</small><div><code dir="ltr">${esc(d.server)}</code></div></div>` : ''}</div>`;
  }
  function renderRedirects(d) {
    const badge = d.loop ? T.rd_loop : d.count === 0 ? T.rd_none : faNum(d.count) + ' ' + T.rd_hops;
    const cls = d.loop ? 'bad' : d.count <= 1 ? 'good' : 'mid';
    const rows = d.hops.map((h2, i) => `<tr><td class="lkr-type" dir="ltr">${faNum(i + 1)}</td>
      <td class="lkr-val" dir="ltr">${esc(h2.url)}</td>
      <td class="lkr-ttl" dir="ltr">${h2.blocked ? esc(T.rd_blocked) : (h2.status == null ? '—' : faNum(h2.status))}</td></tr>`).join('');
    return `<div class="lkr-ssl ${cls}"><div class="lkr-ssl-top"><span class="lkr-ssl-badge">${esc(badge)}</span>
      ${d.https_upgrade ? `<span class="lkr-yn y">${esc(T.rd_https)}</span>` : ''}</div>
      <div class="lkr-head" style="margin-top:6px"><b dir="ltr">${esc(d.domain)}</b></div>
      <div class="lkr-tablewrap"><table class="lkr-table">
        <thead><tr><th>#</th><th>URL</th><th>${esc(T.rd_status)}</th></tr></thead><tbody>${rows}</tbody></table></div>
      <div class="lkr-ns"><small>${esc(T.rd_final)}</small><div><code dir="ltr">${esc(d.final)}</code>${d.final_status != null ? ` <code dir="ltr">HTTP ${esc(d.final_status)}</code>` : ''}</div></div></div>`;
  }
  function renderAccess(d) {
    const verdicts = { filtered: [T.ac_filtered, 'bad'], accessible: [T.ac_accessible, 'good'], likely_ok: [T.ac_likely, 'good'], unreachable_iran: [T.ac_unreach_iran, 'bad'], unknown: [T.ac_unknown, 'mid'] };
    const [label, cls] = verdicts[d.verdict] || verdicts.unknown;
    const iranRows = d.iran_dns.map((n) => `<div class="lkr-node ${n.blocked ? 'no' : n.ok && n.ips.length ? 'ok' : 'no'}">
      <div class="lkr-node-h"><b>${esc(n.resolver)}</b><small>IR</small></div>
      <div class="lkr-node-v" dir="ltr">${!n.ok ? esc(T.ac_noanswer) : n.blocked ? esc(T.ac_block_ip) : (n.ips.length ? n.ips.map((v) => esc(v)).join('<br>') : esc(T.ac_noanswer))}</div></div>`).join('');
    let iranHttp = '';
    if (d.iran_http.state === 'ok') iranHttp = `<div class="lkr-item"><small>${esc(T.ac_http_iran)}</small><span dir="ltr">HTTP ${esc(d.iran_http.status)}${d.iran_http.total_ms != null ? ' · ' + faNum(d.iran_http.total_ms) + T.sp_ms : ''}</span></div>`;
    else if (d.iran_http.state === 'failed') iranHttp = `<div class="lkr-item"><small>${esc(T.ac_http_iran)}</small><span class="lkr-yn n">${esc(T.ac_unreach_iran)}</span></div>`;
    else if (d.iran_http.state === 'unreachable') iranHttp = `<div class="lkr-item"><small>${esc(T.ac_http_iran)}</small><span>${esc(T.ac_unknown)}</span></div>`;
    return `<div class="lkr-ssl ${cls}"><div class="lkr-ssl-top"><span class="lkr-ssl-badge">${esc(label)}</span></div>
      <div class="lkr-head" style="margin-top:6px"><b dir="ltr">${esc(d.domain)}</b></div>
      <div class="lkr-grid">
        <div class="lkr-item"><small>${esc(T.ac_dns_global)}</small><span dir="ltr">${d.global_ips.length ? d.global_ips.map((v) => esc(v)).join('<br>') : esc(T.ac_noanswer)}</span></div>
        <div class="lkr-item"><small>${esc(T.ac_http_world)}</small><span dir="ltr">${d.world_http != null ? 'HTTP ' + esc(d.world_http) : esc(T.ac_unknown)}</span></div>
        ${iranHttp}</div>
      <div class="lkr-ns"><small>${esc(T.ac_dns_iran)}</small></div>
      <div class="lkr-nodes">${iranRows}</div></div>`;
  }

  function renderByKind(kind, d) {
    switch (kind) {
      case 'dns': return renderRecords(d);
      case 'reverse': return renderReverse(d);
      case 'ssl': return renderSsl(d);
      case 'ping': return renderPing(d);
      case 'ports': return renderPorts(d);
      case 'dnssec': return renderDnssec(d);
      case 'propagation': return renderProp(d);
      case 'email': return renderEmail(d);
      case 'blacklist': return renderBlacklist(d);
      case 'speed': return renderSpeed(d);
      case 'headers': return renderHeaders(d);
      case 'redirects': return renderRedirects(d);
      case 'access': return renderAccess(d);
      default: return '<pre dir="ltr">' + esc(JSON.stringify(d, null, 2)) + '</pre>';
    }
  }
  function errMsg(code) {
    return ({ invalid_domain: T.invalid_domain, invalid_ip: T.invalid_ip, empty: T.empty, unreachable: T.unreachable, no_ssl: T.no_ssl, no_cert: T.no_ssl, no_records: T.no_records, bad_ports: T.bad_ports })[code] || T.generic;
  }
  const actionsBar = () => `<div class="lkr-actions">
    <button type="button" class="lkr-act" data-act="copy"><svg class="icon"><use href="#i-code"/></svg>${esc(T.json)}</button>
    <button type="button" class="lkr-act" data-act="download"><svg class="icon"><use href="#i-box"/></svg>${esc(T.download)}</button></div>`;
  function wireActions(box, d, filename) {
    const json = JSON.stringify(d, null, 2);
    box.querySelector('[data-act=copy]')?.addEventListener('click', async (e) => {
      try { await navigator.clipboard.writeText(json); const t = e.currentTarget.lastChild; const old = t.textContent; t.textContent = ' ' + T.copied; setTimeout(() => (t.textContent = old), 1500); } catch {}
    });
    box.querySelector('[data-act=download]')?.addEventListener('click', () => {
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([json], { type: 'application/json' }));
      a.download = (filename || 'result') + '.json'; a.click(); URL.revokeObjectURL(a.href);
    });
  }
  function renderResult(box, kind, d, filename, scroll) {
    box.hidden = false;
    box.innerHTML = renderByKind(kind, d) + actionsBar();
    wireActions(box, d, filename);
    if (scroll !== false) box.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  window.SNLookup = { esc, faNum, T, head, errMsg, renderByKind, renderResult, actionsBar, wireActions };

  /* ---------- single-type form (individual /lookup pages) ---------- */
  const form = document.getElementById('lk-form');
  if (!form) return;
  const input = document.getElementById('lk-input');
  const select = document.getElementById('lk-type');
  const box = document.getElementById('lk-result');
  const errBox = document.getElementById('lk-error');
  const csrf = () => document.querySelector('meta[name=csrf-token]')?.content || '';

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
    errBox.hidden = true; spin(true);
    try {
      const pEl = document.getElementById('lk-ports');
      const payload = { type: L.type, query: q };
      if (pEl && pEl.value.trim()) payload.ports = pEl.value.trim();
      const res = await fetch(form.dataset.endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify(payload) });
      const d = await res.json();
      if (!d.ok) { showErr(errMsg(d.error)); return; }
      renderResult(box, L.kind, d, L.type + '-' + (d.domain || d.ip || 'result'));
    } catch { showErr(T.generic); } finally { spin(false); }
  }
  form.addEventListener('submit', (e) => { e.preventDefault(); run(); });
  if (form.dataset.auto === '1') run();
})();
