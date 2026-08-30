/* ServerNet — consolidated DNS / Network hub tools */
(function () {
  'use strict';
  const H = window.TOOLHUB;
  const SN = window.SNLookup;
  if (!H || !SN) return;
  const form = document.getElementById('hub-form');
  if (!form) return;

  const input = document.getElementById('hub-input');
  const box = document.getElementById('hub-result');
  const errBox = document.getElementById('hub-error');
  const T = SN.T;
  const csrf = () => document.querySelector('meta[name=csrf-token]')?.content || '';
  const esc = SN.esc;

  function spin(on) {
    const btn = form.querySelector('button[type=submit]');
    btn.querySelector('.dr-spin').hidden = !on;
    btn.querySelector('.tsb-label').style.opacity = on ? '.5' : '1';
    btn.disabled = on;
  }
  function showErr(msg) { errBox.textContent = msg; errBox.hidden = false; box.hidden = true; }

  async function post(url, body) {
    const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify(body) });
    return res.json();
  }

  /* ---------- DNS mode: full report of all record types ---------- */
  async function runDns() {
    const q = input.value.trim();
    if (!q) { showErr(T.empty); return; }
    errBox.hidden = true; spin(true);
    try {
      const d = await post(H.dnsEndpoint, { query: q });
      if (!d.ok) { showErr(SN.errMsg(d.error)); return; }
      renderDnsReport(d);
    } catch { showErr(T.generic); } finally { spin(false); }
  }
  function renderDnsReport(d) {
    const sections = d.groups.map((g) =>
      SN.renderByKind('dns', { domain: d.domain, type: g.type, records: g.records, count: g.count })
    ).map((html) => `<div class="hub-sec">${html}</div>`).join('');
    box.hidden = false;
    box.innerHTML = `<div class="hub-report-head"><b dir="ltr">${esc(d.domain)}</b><span>${SN.faNum(d.total)} ${esc(T.records)}</span></div>` + sections + SN.actionsBar();
    SN.wireActions(box, d, 'dns-' + d.domain);
    box.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  /* ---------- Network mode: tabs, each runs one check ---------- */
  const tabs = Array.from(document.querySelectorAll('.hub-tab'));
  let active = tabs[0] || null;
  tabs.forEach((tab) => tab.addEventListener('click', () => {
    tabs.forEach((t) => t.classList.remove('active'));
    tab.classList.add('active'); active = tab;
    if (input.value.trim()) runNetwork();
  }));

  async function runNetwork() {
    const q = input.value.trim();
    const kind = active?.dataset.kind;
    const inputType = active?.dataset.input;
    if (!q && inputType !== 'ip') { showErr(T.empty); return; }
    errBox.hidden = true; spin(true);
    try {
      const d = await post(H.lookupEndpoint, { type: active.dataset.check, query: q });
      if (!d.ok) { showErr(SN.errMsg(d.error)); return; }
      SN.renderResult(box, kind, d, active.dataset.check + '-' + (d.domain || d.ip || 'result'));
    } catch { showErr(T.generic); } finally { spin(false); }
  }

  form.addEventListener('submit', (e) => { e.preventDefault(); H.mode === 'dns' ? runDns() : runNetwork(); });
})();
