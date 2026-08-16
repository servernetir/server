/* ServerNet AI site builder — floating chat, guided intake, streaming preview, deploy bundle */
(function () {
  'use strict';
  const root = document.querySelector('.aib');
  if (!root) return;
  const I = window.AIB_I18N || {};
  const csrf = () => document.querySelector('meta[name=csrf-token]')?.content || '';
  const faNum = (s) => I.faNum ? String(s).replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]) : String(s);
  const money = (n) => faNum(Number(n).toLocaleString('en-US')) + ' ' + I.currency;

  const session = 'sb-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
  let currentHtml = null;

  const messages = document.getElementById('aib-messages');
  const form = document.getElementById('aib-form');
  const text = document.getElementById('aib-text');
  const frame = document.getElementById('aib-frame');
  const empty = document.getElementById('aib-empty');
  const loading = document.getElementById('aib-loading');
  const loadTxt = document.getElementById('aib-loading-txt');
  const leftEl = document.getElementById('aib-left');
  const deploy = document.getElementById('aib-deploy');
  const dlBtn = document.getElementById('aib-download');
  const reassure = document.getElementById('aib-reassure');

  const progBar = document.getElementById('aib-progress-bar');
  const progPct = document.getElementById('aib-progress-pct');

  /* ---------- پاپ‌آپ شناور: نمایش، کوچک‌سازی، درگ ---------- */
  const pop = document.getElementById('aib-pop');
  const popHead = document.getElementById('aib-pop-head');
  const fab = document.getElementById('aib-fab');
  const isMobile = () => window.matchMedia('(max-width: 720px)').matches;

  // اولین باری که بخشِ سایت‌ساز دیده شد، چت با انیمیشن بالا بیاید.
  // fallbackِ زمانی: اگر IntersectionObserver به هر دلیلی شلیک نکرد (تبِ
  // پس‌زمینه، محیطِ بدونِ compositing)، چت نباید برای همیشه غیب بماند.
  let popShown = false;
  const showPop = () => { if (!popShown) { popShown = true; pop.classList.add('on'); } };
  const io = new IntersectionObserver((es) => {
    if (es.some((e) => e.isIntersecting)) { showPop(); io.disconnect(); }
  }, { threshold: 0.15 });
  io.observe(root);
  setTimeout(showPop, 4000);

  document.getElementById('aib-pop-min').addEventListener('click', () => {
    pop.classList.remove('on');
    fab.hidden = false;
  });
  fab.addEventListener('click', () => {
    fab.hidden = true;
    pop.classList.add('on');
    text.focus();
  });

  // درگ با هدر — فقط دسکتاپ؛ موبایل شیتِ پایینی ثابت است
  let drag = null;
  popHead.addEventListener('pointerdown', (e) => {
    if (isMobile() || e.target.closest('.aib-pop-min')) return;
    const r = pop.getBoundingClientRect();
    drag = { dx: e.clientX - r.left, dy: e.clientY - r.top };
    pop.classList.add('dragging');
    popHead.setPointerCapture(e.pointerId);
  });
  popHead.addEventListener('pointermove', (e) => {
    if (!drag) return;
    const w = pop.offsetWidth, h = pop.offsetHeight;
    const x = Math.min(Math.max(8, e.clientX - drag.dx), window.innerWidth - w - 8);
    const y = Math.min(Math.max(8, e.clientY - drag.dy), window.innerHeight - h - 8);
    pop.style.left = x + 'px'; pop.style.top = y + 'px';
    pop.style.right = 'auto'; pop.style.bottom = 'auto';
  });
  const endDrag = () => { drag = null; pop.classList.remove('dragging'); };
  popHead.addEventListener('pointerup', endDrag);
  popHead.addEventListener('pointercancel', endDrag);

  /* ---------- گفتگو ---------- */
  const scrollDown = () => { messages.scrollTop = messages.scrollHeight; };
  function addMsg(txt, who) {
    const d = document.createElement('div');
    d.className = 'aib-msg ' + who;
    d.textContent = txt;
    messages.appendChild(d);
    scrollDown();
    return d;
  }
  function addChips(labels, onPick) {
    const wrap = document.createElement('div');
    wrap.className = 'aib-examples';
    labels.forEach((l) => {
      const b = document.createElement('button');
      b.type = 'button'; b.className = 'aib-chip'; b.textContent = l;
      b.addEventListener('click', () => { wrap.remove(); onPick(l); });
      wrap.appendChild(b);
    });
    messages.appendChild(wrap);
    scrollDown();
    return wrap;
  }

  // auto-grow textarea
  text.addEventListener('input', () => { text.style.height = 'auto'; text.style.height = Math.min(120, text.scrollHeight) + 'px'; });
  text.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); } });

  /* ---------- ویزاردِ شناخت — اول چند سؤال، بعد ساخت ---------- */
  // پاسخ‌ها با برچسبِ انگلیسی به مدل می‌روند (زبانِ خروجی را system prompt تعیین می‌کند)
  const QLABELS = ['Business/brand name', 'Field of work', 'Main services/products',
    'Contact details to show on the site (phone, address, social)', 'Color/mood preference', 'Extra notes'];
  const OPTIONAL = [3, 5];          // سؤال‌های قابلِ رد شدن
  const COLOR_STEP = 4;             // سؤالی که چیپِ رنگ دارد
  let wizard = { step: -1, answers: [], done: false };

  function askNext() {
    wizard.step++;
    if (wizard.step >= I.qs.length) { finishWizard(); return; }
    addMsg(I.qs[wizard.step], 'bot');
    if (wizard.step === COLOR_STEP) {
      addChips(I.colors, (l) => answerWizard(l));
    } else if (OPTIONAL.includes(wizard.step)) {
      addChips([I.skip], () => answerWizard(''));
    }
  }
  function answerWizard(v) {
    messages.querySelector('.aib-examples')?.remove();
    if (v !== '') addMsg(v, 'user');
    wizard.answers[wizard.step] = v;
    askNext();
  }
  function finishWizard() {
    wizard.done = true;
    const lines = QLABELS.map((l, i) => (wizard.answers[i] || '').trim() !== '' ? l + ': ' + wizard.answers[i].trim() : null)
      .filter(Boolean).join('\n');
    ask('Build a complete, professional website for this business:\n' + lines, I.sum);
  }
  // شروع: سؤال اول
  askNext();

  /* ---------- نوار پیشرفت ----------
     دو حالت: با استریم، پیشرفت **واقعی** از حجم کد رسیده ساخته می‌شود؛
     بی‌استریم (fallback) شبیه‌سازی است ولی هرگز ۱۰۰٪ نمایش نمی‌دهد و بعد از
     ۴۵ ثانیه پیامِ اطمینان می‌آید تا کاربر فکر نکند گیر کرده. */
  const EXPECTED_HTML = 9000;
  let progTimer = null, progVal = 0, reassureTimer = null;
  function setProgress(pct, label) {
    progVal = pct;
    if (progBar) progBar.style.width = pct.toFixed(1) + '%';
    if (progPct) progPct.textContent = faNum(Math.floor(pct)) + '٪';
    if (label) loadTxt.textContent = label;
  }
  function startProgress() {
    setProgress(0, I.steps[0]);
    reassure.hidden = true;
    clearTimeout(reassureTimer);
    reassureTimer = setTimeout(() => { reassure.hidden = false; }, 45000);
    clearInterval(progTimer);
    let si = 0;
    progTimer = setInterval(() => {
      // خزشِ آرام فقط تا ۹۵٪ — «۱۰۰٪ ولی هنوز منتظر» دروغِ رابط است
      const next = progVal + Math.max(0.15, (95 - progVal) * 0.02);
      const ni = Math.min(I.steps.length - 1, Math.floor(next / (96 / I.steps.length)));
      if (ni !== si) si = ni;
      setProgress(Math.min(95, next), I.steps[si]);
    }, 500);
  }
  function streamProgress(htmlChars) {
    // استریم فعال است: عدد از واقعیت می‌آید، شبیه‌سازی را خاموش کن
    clearInterval(progTimer); progTimer = null;
    const pct = Math.min(97, 12 + (htmlChars / EXPECTED_HTML) * 85);
    setProgress(Math.max(progVal, pct), I.writing.replace(':n', faNum(htmlChars.toLocaleString('en-US'))));
  }
  function finishProgress() {
    clearInterval(progTimer); progTimer = null;
    clearTimeout(reassureTimer);
    reassure.hidden = true;
    setProgress(100, I.steps[I.steps.length - 1]);
  }

  function setPreview(html) {
    if (!html) return;
    currentHtml = html;
    empty.hidden = true;
    frame.hidden = false;
    frame.srcdoc = html;
    dlBtn.disabled = false;
    if (deploy.hidden) { deploy.hidden = false; deploy.classList.add('in'); }
  }

  /* ---------- تماس با سازنده — اول SSE، بعد fallback به JSON ---------- */
  async function streamRequest(msg, pro, typing) {
    if (!root.dataset.stream) throw new Error('no-stream');
    const res = await fetch(root.dataset.stream, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'Accept': 'text/event-stream' },
      body: JSON.stringify({ session, message: msg, pro }),
    });
    if (!res.ok || !(res.headers.get('content-type') || '').includes('event-stream')) throw new Error('no-stream');
    const reader = res.body.getReader();
    const dec = new TextDecoder();
    let buf = '', acc = '', final = null;
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      buf += dec.decode(value, { stream: true });
      let i;
      while ((i = buf.indexOf('\n\n')) !== -1) {
        const raw = buf.slice(0, i); buf = buf.slice(i + 2);
        for (const line of raw.split('\n')) {
          if (!line.startsWith('data:')) continue;
          let j; try { j = JSON.parse(line.slice(5)); } catch { continue; }
          if (j.done) { final = j; continue; }
          if (typeof j.d === 'string') {
            acc += j.d;
            const fence = acc.indexOf('```');
            if (fence === -1) {
              // هنوز جملهٔ گفتگوست — زنده در حباب نشان بده
              if (acc.trim()) typing.textContent = acc.trim().slice(0, 220);
            } else {
              streamProgress(acc.length - fence);
            }
          }
        }
      }
    }
    if (!final) throw new Error('no-final');
    return final;
  }
  async function jsonRequest(msg, pro) {
    const res = await fetch(root.dataset.chat, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
      body: JSON.stringify({ session, message: msg, pro }),
    });
    return res.json();
  }

  let busy = false;
  async function ask(apiMsg, shownMsg) {
    if (busy) return;
    busy = true;
    addMsg(shownMsg || apiMsg, 'user');
    const typing = addMsg(I.thinking, 'bot typing');
    empty.hidden = true; loading.hidden = false; frame.hidden = true;
    startProgress();

    const pro = document.getElementById('aib-pro').checked;
    let d = null;
    try {
      try {
        d = await streamRequest(apiMsg, pro, typing);
      } catch {
        d = await jsonRequest(apiMsg, pro);
      }
    } catch { d = null; }

    typing.remove();
    finishProgress();
    loading.hidden = true;

    if (!d) {
      if (currentHtml) frame.hidden = false; else empty.hidden = false;
      addMsg(I.err, 'bot');
      busy = false; return;
    }
    if (!d.ok) {
      addMsg(d.error === 'not_configured' ? I.notConfigured : d.error === 'limit' ? I.limit : d.error === 'ai_busy' ? (I.busy || I.err) : I.err, 'bot');
      if (d.html) { setPreview(d.html); } else if (currentHtml) { frame.hidden = false; } else { empty.hidden = false; }
      busy = false; return;
    }
    addMsg(d.reply || '✓', 'bot');
    setPreview(d.html);
    if (typeof d.left === 'number') leftEl.textContent = d.left <= 5 ? I.left.replace(':n', faNum(d.left)) : '';
    busy = false;
  }

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const msg = text.value.trim();
    if (!msg || busy) return;
    text.value = ''; text.style.height = 'auto';
    if (!wizard.done) { answerWizard(msg); return; }
    ask(msg);
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
  let domainCost = null; // {ok, val}

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

  /* پسوندِ فروخته‌نشدنی (ir. و زیرشاخه‌هایش) — محلی رد می‌شود، بی‌تماسِ سرور */
  function unsoldTld(v) {
    const ext = v.split('.').slice(1).join('.').toLowerCase();
    if (!ext) return false;
    return (I.unsold || []).some((t) => ext === t || ext.endsWith('.' + t));
  }

  let domTimer;
  domainInput.addEventListener('input', () => {
    clearTimeout(domTimer);
    domainCost = null; domainPrice.textContent = ''; domainPrice.className = '';
    const v = domainInput.value.trim();
    if (v.length < 4 || !v.includes('.')) { recalc(); return; }
    if (unsoldTld(v)) { domainPrice.textContent = I.noIr; domainPrice.className = 'no'; recalc(); return; }
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
        domainPrice.textContent = (r.price || '') + ' · ' + I.domainFree;
        domainPrice.className = 'ok';
      } else if (r && (r.state === 'unchecked' || d.lookup_ok === false)) {
        // رجیسترار جواب نداد — «نمی‌دانیم» را «گرفته‌شده» گزارش نکن
        domainCost = null;
        domainPrice.textContent = I.domainUnknown;
        domainPrice.className = '';
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
