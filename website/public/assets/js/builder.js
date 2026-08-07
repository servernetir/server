/* ServerNet AI site builder — chat, live preview, deploy bundle */
(function () {
  'use strict';
  const root = document.querySelector('.aib');
  if (!root) return;
  const I = window.AIB_I18N;
  const csrf = () => document.querySelector('meta[name=csrf-token]')?.content || '';
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const faNum = (s) => I.faNum ? String(s).replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]) : String(s);
  const money = (n) => faNum(Number(n).toLocaleString('en-US')) + ' ' + I.currency;

  const session = 'sb-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
  let currentHtml = null;

  const messages = document.getElementById('aib-messages');
  const examples = document.getElementById('aib-examples');
  const form = document.getElementById('aib-form');
  const text = document.getElementById('aib-text');
  const frame = document.getElementById('aib-frame');
  const empty = document.getElementById('aib-empty');
  const loading = document.getElementById('aib-loading');
  const loadTxt = document.getElementById('aib-loading-txt');
  const leftEl = document.getElementById('aib-left');
  const deploy = document.getElementById('aib-deploy');
  const dlBtn = document.getElementById('aib-download');

  const progBar = document.getElementById('aib-progress-bar');
  const progPct = document.getElementById('aib-progress-pct');

  /* نوار پیشرفت شبیه‌سازی‌شده — تولید یک عملیات نامعین است، پس تا ~۹۲٪
     نرم پیش می‌رود و با رسیدن پاسخ به ۱۰۰٪ می‌رسد (بازخورد به کاربر منتظر). */
  let progTimer = null, progVal = 0;
  function startProgress() {
    progVal = 0;
    const steps = I.steps || [I.building];
    let si = -1;
    if (progBar) progBar.style.width = '0%';
    if (progPct) progPct.textContent = faNum(0) + '٪';
    clearInterval(progTimer);
    progTimer = setInterval(() => {
      progVal += Math.max(0.35, (92 - progVal) * 0.045);
      if (progVal > 92) progVal = 92;
      if (progBar) progBar.style.width = progVal.toFixed(1) + '%';
      if (progPct) progPct.textContent = faNum(Math.floor(progVal)) + '٪';
      const ni = Math.min(steps.length - 1, Math.floor(progVal / (93 / steps.length)));
      if (ni !== si) { si = ni; loadTxt.textContent = steps[si]; }
    }, 420);
  }
  function finishProgress() {
    clearInterval(progTimer); progTimer = null;
    if (progBar) progBar.style.width = '100%';
    if (progPct) progPct.textContent = faNum(100) + '٪';
  }

  const scrollDown = () => { messages.scrollTop = messages.scrollHeight; };
  function addMsg(txt, who) {
    const d = document.createElement('div');
    d.className = 'aib-msg ' + who;
    d.textContent = txt;
    messages.appendChild(d);
    scrollDown();
    return d;
  }

  // auto-grow textarea
  text.addEventListener('input', () => { text.style.height = 'auto'; text.style.height = Math.min(120, text.scrollHeight) + 'px'; });
  text.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); } });

  // example chips
  examples?.querySelectorAll('.aib-chip').forEach((c) => c.addEventListener('click', () => {
    text.value = c.textContent; form.requestSubmit();
  }));

  function setPreview(html) {
    if (!html) return;
    currentHtml = html;
    empty.hidden = true;
    frame.hidden = false;
    frame.srcdoc = html;
    dlBtn.disabled = false;
    if (deploy.hidden) { deploy.hidden = false; deploy.classList.add('in'); }
  }

  let busy = false;
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const msg = text.value.trim();
    if (!msg || busy) return;
    busy = true;
    examples?.remove();
    addMsg(msg, 'user');
    text.value = ''; text.style.height = 'auto';
    const typing = addMsg(I.thinking, 'bot typing');
    empty.hidden = true; loading.hidden = false; frame.hidden = true;
    loadTxt.textContent = I.building;
    startProgress();

    try {
      const res = await fetch(root.dataset.chat, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({ session, message: msg, pro: document.getElementById('aib-pro').checked }),
      });
      const d = await res.json();
      typing.remove();
      finishProgress();
      loading.hidden = true;
      if (!d.ok) {
        addMsg(d.error === 'not_configured' ? I.notConfigured : d.error === 'limit' ? I.limit : d.error === 'ai_busy' ? (I.busy || I.err) : I.err, 'bot');
        if (d.html) { setPreview(d.html); } else if (currentHtml) { frame.hidden = false; } else { empty.hidden = false; }
        busy = false; return;
      }
      addMsg(d.reply || '✓', 'bot');
      setPreview(d.html);
      if (typeof d.left === 'number') leftEl.textContent = d.left <= 5 ? I.left.replace(':n', faNum(d.left)) : '';
    } catch {
      typing.remove(); finishProgress(); loading.hidden = true;
      if (currentHtml) frame.hidden = false; else empty.hidden = false;
      addMsg(I.err, 'bot');
    }
    busy = false;
  });

  // device toggle
  root.querySelectorAll('.aib-dev').forEach((b) => b.addEventListener('click', () => {
    root.querySelectorAll('.aib-dev').forEach((x) => x.classList.toggle('active', x === b));
    frame.style.maxWidth = b.dataset.w;
  }));

  // reset
  document.getElementById('aib-refresh').addEventListener('click', () => {
    if (currentHtml) frame.srcdoc = currentHtml;
  });

  // download
  dlBtn.addEventListener('click', () => {
    if (!currentHtml) return;
    const blob = new Blob([currentHtml], { type: 'text/html' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'servernet-site.html';
    a.click();
    URL.revokeObjectURL(a.href);
  });

  /* ---------- DEPLOY BUNDLE ---------- */
  const domainInput = document.getElementById('aib-domain');
  const domainPrice = document.getElementById('aib-domain-price');
  const planSel = document.getElementById('aib-plan');
  const totalVal = document.getElementById('aib-total-val');
  let domainCost = null; // {irt|eur, ok}

  function planCost() {
    const o = planSel.options[planSel.selectedIndex];
    return I.fa ? Number(o.dataset.irt) : Number(o.dataset.eur);
  }
  function recalc() {
    const plan = planCost();
    if (domainCost && domainCost.ok) {
      totalVal.textContent = money(plan + domainCost.val);
    } else {
      totalVal.textContent = money(plan) + ' + ' + (I.fa ? 'دامنه' : 'domain');
    }
  }
  planSel.addEventListener('change', recalc);
  recalc();

  let domTimer;
  domainInput.addEventListener('input', () => {
    clearTimeout(domTimer);
    domainCost = null; domainPrice.textContent = '';
    const v = domainInput.value.trim();
    if (v.length < 4 || !v.includes('.')) { recalc(); return; }
    domTimer = setTimeout(() => checkDomain(v), 700);
  });

  async function checkDomain(v) {
    domainPrice.textContent = I.domainChecking;
    domainPrice.className = '';
    try {
      const res = await fetch(root.dataset.domaincheck, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({ domain: v }),
      });
      const d = await res.json();

      /*
       * 🔴 پاسخ داخلِ `result` است، نه سطحِ بالا.
       *
       * قبلاً `d.available` خوانده می‌شد که همیشه `undefined` است، پس شرط
       * همیشه false می‌شد و کاربر برای **هر** دامنه — حتی کاملاً آزاد — پیامِ
       * قرمزِ «این دامنه قبلاً ثبت شده» می‌گرفت. هیچ خطایی هم تولید نمی‌شد.
       */
      const r = (d && d.result) || d;

      if (r && r.available) {
        // استخراج عدد از رشته قیمت فرمت‌شده (فارسی: تومان صحیح، لاتین: یورو اعشاری)
        const latin = String(r.price || '').replace(/[۰-۹]/g, (ch) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(ch));
        let val = 0;
        if (I.fa) { val = parseInt(latin.replace(/[^\d]/g, ''), 10) || 0; }
        else { const m = latin.replace(/,/g, '').match(/[\d.]+/); val = m ? parseFloat(m[0]) : 0; }
        domainCost = { ok: true, val };
        domainPrice.textContent = (d.price || '') + ' · ' + I.domainFree;
        domainPrice.className = 'ok';
      } else {
        domainCost = null;
        domainPrice.textContent = I.domainTaken;
        domainPrice.className = 'no';
      }
    } catch { domainPrice.textContent = ''; }
    recalc();
  }

  document.getElementById('aib-deploy-btn').addEventListener('click', async () => {
    const pid = planSel.value;
    const domain = domainInput.value.trim();
    const refEl = document.getElementById('aib-deploy-ref');
    // ذخیره سایت + اطلاع فروش
    try {
      const res = await fetch(root.dataset.save, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify({ session, domain, plan: planSel.options[planSel.selectedIndex].text }),
      });
      const d = await res.json();
      if (d.ok) { refEl.hidden = false; refEl.textContent = I.saved.replace(':ref', d.ref); }
    } catch { /* ادامه به سبد خرید در هر صورت */ }
    // باز کردن سبد خرید WHMCS با هاست (+ دامنه)
    let url = root.dataset.cart + '?a=add&pid=' + encodeURIComponent(pid);
    if (domain && domain.includes('.')) {
      url += '&domain=register&query=' + encodeURIComponent(domain);
    }
    window.open(url, '_blank', 'noopener');
  });
})();
