/* ServerNet Cloud — site behaviour (effects, i18n digits, chat) */
(function () {
  'use strict';

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const isRTL = document.documentElement.dir === 'rtl';
  const faDigits = (s) => isRTL ? String(s).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]) : String(s);

  /* ---- HEADER / SCROLL FX ---- */
  const header = document.getElementById('header');
  const progress = document.getElementById('progress');
  window.addEventListener('scroll', () => {
    if (header) header.classList.toggle('scrolled', window.scrollY > 24);
    if (progress) {
      const h = document.documentElement;
      progress.style.width = (h.scrollTop / (h.scrollHeight - h.clientHeight) * 100) + '%';
    }
  }, { passive: true });

  /* ---- MOBILE NAV ---- */
  const burger = document.getElementById('hamburger');
  const mobileNav = document.getElementById('mobile-nav');
  if (burger && mobileNav) {
    burger.addEventListener('click', () => {
      const open = mobileNav.style.display === 'flex';
      mobileNav.style.display = open ? 'none' : 'flex';
      burger.setAttribute('aria-expanded', String(!open));
    });
    mobileNav.querySelectorAll('a').forEach((a) => a.addEventListener('click', () => {
      mobileNav.style.display = 'none';
      burger.setAttribute('aria-expanded', 'false');
    }));
  }

  /* ---- REVEAL + COUNTERS ---- */
  function animateCount(el) {
    if (el.dataset.done) return;
    el.dataset.done = 1;
    const to = parseFloat(el.dataset.to), dec = parseInt(el.dataset.dec || 0), suf = el.dataset.suffix || '';
    if (reduceMotion) { el.textContent = faDigits(to.toFixed(dec)) + suf; return; }
    const dur = 1600, t0 = performance.now();
    const step = (t) => {
      const p = Math.min((t - t0) / dur, 1), e = 1 - Math.pow(1 - p, 3);
      el.textContent = faDigits((to * e).toFixed(dec)) + suf;
      if (p < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  }

  const io = new IntersectionObserver((entries) => {
    entries.forEach((en) => {
      if (en.isIntersecting) {
        en.target.classList.add('in');
        if (en.target.classList.contains('count')) animateCount(en.target);
        io.unobserve(en.target);
      }
    });
  }, { threshold: .12, rootMargin: '0px 0px -40px 0px' });
  document.querySelectorAll('.reveal,.count').forEach((el) => io.observe(el));

  /* ---- TERMINAL TYPING ---- */
  const TERM_LINES = [
    { h: '<span class="p">$</span> <span class="w">servernet deploy --region fra1 --plan business</span>', type: true },
    { h: '<span class="c">→ Provisioning VPS instance…</span>' },
    { h: '<span class="ok">✓</span> <span class="c">2 vCPU · 4 GB RAM · 60 GB NVMe allocated</span>' },
    { h: '<span class="ok">✓</span> <span class="c">IPv4 + IPv6 assigned · Firewall enabled</span>' },
    { h: '<span class="ok">✓</span> <span class="c">SSL certificate issued</span>' },
    { h: '<span class="p">$</span> <span class="w">servernet status</span>', type: true },
    { h: '<span class="ok">● all systems operational — uptime 99.98%</span>' },
  ];
  (function runTerminal() {
    const body = document.getElementById('term-body');
    if (!body) return;
    if (reduceMotion) { body.innerHTML = TERM_LINES.map((l) => `<div class="ln">${l.h}</div>`).join(''); return; }
    let i = 0;
    function next() {
      if (i >= TERM_LINES.length) {
        body.insertAdjacentHTML('beforeend', '<div class="ln"><span class="p">$</span> <span class="caret"></span></div>');
        return;
      }
      const l = TERM_LINES[i++];
      const div = document.createElement('div'); div.className = 'ln';
      body.appendChild(div);
      if (l.type) {
        const tmp = document.createElement('div'); tmp.innerHTML = l.h;
        const txt = tmp.textContent; let j = 0;
        const iv = setInterval(() => {
          j++; div.textContent = txt.slice(0, j);
          if (j >= txt.length) { clearInterval(iv); div.innerHTML = l.h; setTimeout(next, 320); }
        }, 26);
      } else {
        div.innerHTML = l.h; div.style.opacity = 0; div.style.transition = 'opacity .4s';
        requestAnimationFrame(() => div.style.opacity = 1);
        setTimeout(next, 380);
      }
    }
    setTimeout(next, 700);
  })();

  /* ---- PARTICLE NETWORK (hero) ---- */
  (function initNet() {
    const cv = document.getElementById('net');
    if (!cv || reduceMotion) return;
    const ctx = cv.getContext('2d'); let W, H, pts = [];
    function size() {
      const r = cv.parentElement.getBoundingClientRect();
      W = cv.width = r.width; H = cv.height = r.height;
      const n = Math.min(70, Math.floor(W / 22));
      pts = Array.from({ length: n }, () => ({ x: Math.random() * W, y: Math.random() * H, vx: (Math.random() - .5) * .35, vy: (Math.random() - .5) * .35 }));
    }
    size(); window.addEventListener('resize', size);
    (function tick() {
      ctx.clearRect(0, 0, W, H);
      for (const p of pts) {
        p.x += p.vx; p.y += p.vy;
        if (p.x < 0 || p.x > W) p.vx *= -1;
        if (p.y < 0 || p.y > H) p.vy *= -1;
        ctx.beginPath(); ctx.arc(p.x, p.y, 1.3, 0, 7); ctx.fillStyle = 'rgba(96,165,250,.5)'; ctx.fill();
      }
      for (let i = 0; i < pts.length; i++) for (let j = i + 1; j < pts.length; j++) {
        const a = pts[i], b = pts[j], dx = a.x - b.x, dy = a.y - b.y, d = dx * dx + dy * dy;
        if (d < 130 * 130) {
          ctx.strokeStyle = `rgba(59,130,246,${.14 * (1 - d / (130 * 130))})`;
          ctx.lineWidth = 1; ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
        }
      }
      requestAnimationFrame(tick);
    })();
  })();

  /* ---- AI CHAT WIDGET ---- */
  const fab = document.getElementById('chat-fab');
  const panel = document.getElementById('chat-panel');
  const chatBody = document.getElementById('chat-body');
  const chatForm = document.getElementById('chat-form');
  const chatText = document.getElementById('chat-text');
  if (fab && panel && chatBody && chatForm) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    let greeted = false, busy = false;

    /* شناسه سشن گفتگو — حافظه مکالمه دستیار هوشمند به آن گره می‌خورد */
    const chatSession = () => {
      let sid;
      try {
        sid = sessionStorage.getItem('sn_chat_sid');
        if (!sid) {
          sid = 'web-' + Math.random().toString(36).slice(2, 10) + Date.now().toString(36);
          sessionStorage.setItem('sn_chat_sid', sid);
        }
      } catch (e) {
        sid = 'web-' + Math.random().toString(36).slice(2, 10);
      }
      return sid;
    };

    const scrollBottom = () => { chatBody.scrollTop = chatBody.scrollHeight; };
    const addMsg = (text, who) => {
      const d = document.createElement('div');
      d.className = 'chat-msg ' + who;
      d.textContent = text;
      chatBody.appendChild(d);
      scrollBottom();
      return d;
    };
    const addActions = (actions) => {
      if (!actions || !actions.length) return;
      const wrap = document.createElement('div');
      wrap.className = 'chat-actions';
      actions.forEach((a) => {
        // 🔴 آدرسِ نبود = لینکِ ساخته‌نشده، نه لینکِ خراب. `href` عضوِ
        //    [LegacyNullToEmptyString] نیست، پس مقدارِ null رشتهٔ «null»
        //    می‌شود و مرورگر آن را نسبی حل می‌کند ⇒ /null، /cloud/null، …
        //    (همان ۴۰۴هایی که در ردیاب دیده شد).
        if (!a || typeof a.url !== 'string' || a.url === '') { return; }

        const link = document.createElement('a');
        link.textContent = a.label;
        link.href = a.url;
        if (!a.url.startsWith('#')) { link.target = '_blank'; link.rel = 'noopener'; }
        else link.addEventListener('click', close);
        wrap.appendChild(link);
      });
      chatBody.appendChild(wrap);
      scrollBottom();
    };

    function open() {
      panel.classList.add('open');
      requestAnimationFrame(() => panel.classList.add('in'));
      fab.setAttribute('aria-expanded', 'true');
      if (!greeted) { greeted = true; setTimeout(() => addMsg(chatBody.dataset.hello, 'bot'), 350); }
      chatText.focus();
    }
    function close() {
      panel.classList.remove('in');
      fab.setAttribute('aria-expanded', 'false');
      setTimeout(() => panel.classList.remove('open'), 280);
    }
    fab.addEventListener('click', () => panel.classList.contains('open') ? close() : open());
    document.getElementById('chat-close')?.addEventListener('click', close);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && panel.classList.contains('open')) close(); });

    chatForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const msg = chatText.value.trim();
      if (!msg || busy) return;
      busy = true;
      chatText.value = '';
      addMsg(msg, 'user');
      const typing = document.createElement('div');
      typing.className = 'chat-typing';
      typing.innerHTML = '<i></i><i></i><i></i>';
      chatBody.appendChild(typing);
      scrollBottom();
      try {
        const res = await fetch(chatBody.dataset.endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
          body: JSON.stringify({ message: msg, session: chatSession() }),
        });
        if (!res.ok) throw new Error(res.status);
        const data = await res.json();
        typing.remove();
        addMsg(data.reply, 'bot');
        addActions(data.actions);
      } catch (err) {
        typing.remove();
        addMsg(chatBody.dataset.error, 'bot');
      } finally {
        busy = false;
        chatText.focus();
      }
    });
  }
})();

/* ===== Mega menu / dropdowns / drawer / domain check / billing ===== */
(function () {
  'use strict';

  const isRTL = document.documentElement.dir === 'rtl';
  const faDigits = (s) => isRTL ? String(s).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]) : String(s);

  /* ---- DESKTOP MENU PANELS ---- */
  const navItems = document.querySelectorAll('.nav-item[data-menu]');
  let openItem = null, closeTimer = null;

  function openMenu(item) {
    clearTimeout(closeTimer);
    if (openItem === item) return;
    closeMenu(true);
    openItem = item;
    item.classList.add('open');
    item.querySelector('.nav-link').setAttribute('aria-expanded', 'true');
    document.getElementById('menu-' + item.dataset.menu)?.classList.add('open');
  }
  function closeMenu(instant) {
    if (!openItem) return;
    openItem.classList.remove('open');
    openItem.querySelector('.nav-link').setAttribute('aria-expanded', 'false');
    document.getElementById('menu-' + openItem.dataset.menu)?.classList.remove('open');
    openItem = null;
  }
  function scheduleClose() {
    clearTimeout(closeTimer);
    closeTimer = setTimeout(() => closeMenu(), 220);
  }

  navItems.forEach((item) => {
    const panel = document.getElementById('menu-' + item.dataset.menu);
    item.addEventListener('mouseenter', () => { if (window.innerWidth > 1020) openMenu(item); });
    item.addEventListener('mouseleave', scheduleClose);
    item.querySelector('.nav-link').addEventListener('click', () => {
      openItem === item ? closeMenu() : openMenu(item);
    });
    if (panel) {
      panel.addEventListener('mouseenter', () => clearTimeout(closeTimer));
      panel.addEventListener('mouseleave', scheduleClose);
    }
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeMenu(); });
  document.addEventListener('click', (e) => {
    if (openItem && !e.target.closest('.nav-item') && !e.target.closest('.menu-panel')) closeMenu();
  });
  window.addEventListener('scroll', () => { if (openItem && window.scrollY > 120) closeMenu(); }, { passive: true });

  /* mega tabs */
  const megaTabs = document.querySelectorAll('.mega-tab');
  function activateTab(tab) {
    megaTabs.forEach((t) => t.classList.toggle('active', t === tab));
    document.querySelectorAll('.mega-pane').forEach((p) =>
      p.classList.toggle('active', p.dataset.pane === tab.dataset.tab));
  }
  megaTabs.forEach((tab) => {
    tab.addEventListener('mouseenter', () => activateTab(tab));
    tab.addEventListener('click', () => activateTab(tab));
  });

  /* ---- MOBILE DRAWER ---- */
  const drawer = document.getElementById('drawer');
  const backdrop = document.getElementById('drawer-backdrop');
  const burger = document.getElementById('hamburger');
  if (drawer && backdrop && burger) {
    const setDrawer = (open) => {
      drawer.classList.toggle('open', open);
      backdrop.classList.toggle('open', open);
      burger.classList.toggle('active', open);
      burger.setAttribute('aria-expanded', String(open));
      document.body.style.overflow = open ? 'hidden' : '';
    };
    burger.addEventListener('click', () => setDrawer(!drawer.classList.contains('open')));
    document.getElementById('drawer-close')?.addEventListener('click', () => setDrawer(false));
    backdrop.addEventListener('click', () => setDrawer(false));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') setDrawer(false); });
    drawer.querySelectorAll('.acc-head').forEach((head) => {
      head.addEventListener('click', () => {
        const acc = head.parentElement;
        const wasOpen = acc.classList.contains('open');
        drawer.querySelectorAll('.acc.open').forEach((a) => a.classList.remove('open'));
        if (!wasOpen) {
          acc.classList.add('open');
          // آکاردئون بازشده بیاید بالای دید تا همه‌ی گروه‌ها قابل‌دیدن باشند
          setTimeout(() => acc.scrollIntoView({ behavior: 'smooth', block: 'start' }), 380);
        }
      });
    });
  }

  /* ---- FOOTER ACCORDION (mobile) ---- */
  document.querySelectorAll('.f-col .f-head').forEach((head) => {
    head.addEventListener('click', () => {
      if (window.innerWidth > 640) return;
      head.parentElement.classList.toggle('open');
    });
  });

  /* ---- TLD CHIPS: نمایش چرخشی ---- */
  const strip = document.getElementById('tld-strip');
  if (strip) {
    const chips = Array.from(strip.querySelectorAll('.tld-chip'));
    const VISIBLE = Math.min(6, chips.length);
    chips.forEach((c, i) => { if (i >= VISIBLE) c.classList.add('hidden-chip'); });
    if (chips.length > VISIBLE && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      let nextIdx = VISIBLE;
      setInterval(() => {
        const visible = chips.filter((c) => !c.classList.contains('hidden-chip'));
        const out = visible[Math.floor(Math.random() * visible.length)];
        const inChip = chips[nextIdx % chips.length];
        if (!out || out === inChip) return;
        nextIdx = (nextIdx + 1) % chips.length;
        if (!inChip.classList.contains('hidden-chip')) return;
        out.classList.add('fading');
        setTimeout(() => {
          out.classList.add('hidden-chip'); out.classList.remove('fading');
          inChip.classList.add('fading'); inChip.classList.remove('hidden-chip');
          requestAnimationFrame(() => requestAnimationFrame(() => inChip.classList.remove('fading')));
        }, 450);
      }, 3200);
    }
    /* کلیک روی چیپ → پسوند در باکس جستجو */
    const input = document.getElementById('domain-input');
    chips.forEach((c) => c.addEventListener('click', () => {
      if (!input) return;
      const name = (input.value.trim().split('.')[0] || 'mycompany');
      input.value = name + c.dataset.tld;
      input.focus();
    }));
  }

  /* ---- LIVE DOMAIN CHECK ---- */
  const dForm = document.getElementById('domain-form');
  const dInput = document.getElementById('domain-input');
  const dResult = document.getElementById('domain-result');
  if (dForm && dInput && dResult) {
    const t = dInput.dataset;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    let checking = false;

    dForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const q = dInput.value.trim();
      if (!q || checking) return;
      checking = true;
      dResult.hidden = false;
      dResult.className = 'domain-result';
      dResult.innerHTML = `<div class="dr-row"><span class="dr-spin"></span><span class="dr-msg">${t.i18nChecking}</span></div>`;
      try {
        const res = await fetch(dForm.dataset.endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
          body: JSON.stringify({ domain: q }),
        });
        if (!res.ok) throw new Error(res.status);
        const data = await res.json();
        renderResult(data);
      } catch (err) {
        dResult.className = 'domain-result no';
        dResult.innerHTML = `<div class="dr-row"><span class="dr-msg">${t.i18nError}</span></div>`;
      } finally {
        checking = false;
      }
    });

    function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    /*
     * 🔴 سه حالت، نه دو تا.
     *
     * نسخهٔ قبلی `if (r.available) … else «قبلاً ثبت شده است»` بود. یعنی هر
     * چیزی که «آزاد» نبود — از جمله یک قطعیِ رجیسترار، نبودِ اعتبارنامه، یا
     * پسوندی که اصلاً نمی‌فروشیم — با اطمینان «گرفته‌شده» اعلام می‌شد، روی
     * پرترافیک‌ترین ورودیِ فروشِ دامنهٔ سایت. مشتری آن را راستی‌آزمایی نمی‌کند؛
     * فقط می‌رود، و ما هیچ شکایتی نمی‌شنویم تا بفهمیم چیزی خراب است.
     *
     * وضعیت را **سرور** می‌گوید (`DomainSearch::stateOf`). شاخهٔ پشتیبان فقط
     * برای پاسخِ کهنه‌ای است که هنوز `state` ندارد؛ آن‌جا هم `available=false`
     * تنها وقتی «گرفته‌شده» خوانده می‌شود که استعلام موفق بوده باشد.
     */
    function renderResult(data) {
      const r = data.result;
      const st = r.state || (r.available ? 'free' : (data.lookup_ok === false ? 'unchecked' : 'taken'));

      if (st === 'free' || st === 'premium') {
        dResult.className = 'domain-result ok';
        dResult.innerHTML =
          `<div class="dr-row">
             <span class="dr-domain">${esc(r.domain)}</span>
             <span class="dr-msg">${t.i18nFree}</span>
             ${r.price ? `<span class="dr-price">${faDigits(esc(r.price))} <small>${t.i18nYear}</small></span>` : ''}
             <a class="btn btn-primary" href="${esc(r.cart_url)}" target="_blank" rel="noopener">${t.i18nCart}</a>
           </div>`;
        return;
      }

      let alts = (data.suggestions || []).slice(0, 3).map((s) =>
        `<a class="dr-alt" href="${esc(s.cart_url)}" target="_blank" rel="noopener"><b>${esc(s.domain)}</b>${s.price ? `<i>${esc(s.price)}</i>` : ''}</a>`).join('');
      if (alts && data.more_url) {
        alts += `<a class="dr-alt dr-more" href="${esc(data.more_url)}" target="_blank" rel="noopener" aria-label="more">…</a>`;
      }
      const suggest = alts ? `<div class="dr-suggest"><p>${t.i18nSuggest}</p><div class="dr-alts">${alts}</div></div>` : '';

      if (st === 'taken') {
        dResult.className = 'domain-result no';
        dResult.innerHTML =
          `<div class="dr-row"><span class="dr-domain">${esc(r.domain)}</span><span class="dr-msg">${t.i18nTaken}</span></div>` + suggest;
        return;
      }

      // unchecked / unsupported / no_price — «نمی‌دانیم»، هرگز «گرفته‌شده»
      const msg = st === 'unsupported' ? t.i18nUnsupported
                : st === 'noPrice' || st === 'no_price' ? t.i18nNoprice
                : t.i18nUnchecked;

      dResult.className = 'domain-result warn';
      dResult.innerHTML =
        `<div class="dr-row"><span class="dr-domain">${esc(r.domain)}</span><span class="dr-msg">${msg}</span></div>` + suggest;
    }
  }

  /* ---- BILLING TOGGLE ---- */
  const billToggle = document.querySelector('.bill-toggle');
  const plansGrid = document.getElementById('plans');
  if (billToggle && plansGrid) {
    billToggle.querySelectorAll('button').forEach((btn) => {
      btn.addEventListener('click', () => {
        billToggle.querySelectorAll('button').forEach((b) => b.classList.toggle('active', b === btn));
        const yearly = btn.dataset.bill === 'yearly';
        plansGrid.classList.toggle('yearly', yearly);
        plansGrid.querySelectorAll('.plan-buy').forEach((a) => {
          // ⚠️ data-url-y/-m که نباشد، `dataset` مقدارِ undefined می‌دهد و
          //    نوشتنش در href رشتهٔ «undefined» می‌سازد — دکمهٔ خرید به
          //    /undefined می‌رود. آدرسِ قبلی را نگه می‌داریم؛ لینکِ قدیمی از
          //    لینکِ ۴۰۴ بهتر است.
          const u = yearly ? a.dataset.urlY : a.dataset.urlM;
          if (u) { a.href = u; }
        });
      });
    });
  }
})();

/* ===== Theme toggle (dark / light) ===== */
(function () {
  const btn = document.getElementById('theme-toggle');
  if (!btn) return;
  btn.addEventListener('click', () => {
    const light = document.documentElement.dataset.theme === 'light';
    if (light) delete document.documentElement.dataset.theme;
    else document.documentElement.dataset.theme = 'light';
    const val = light ? 'dark' : 'light';
    try { localStorage.setItem('snet-theme', val); } catch (e) {}
    // کوکیِ تم روی دامنهٔ ریشه ست می‌شود تا بین سایت و کنسول (زیردامنه) یکی بماند؛
    // روی localhost بدونِ domain (host-only) تا محلی هم کار کند.
    try {
      var h = location.hostname, d = /(^|\.)servernet\.cloud$/i.test(h) ? '; domain=.servernet.cloud' : '';
      document.cookie = 'snet-theme=' + val + '; path=/; max-age=31536000; samesite=lax' + d + (location.protocol === 'https:' ? '; secure' : '');
    } catch (e) {}
  });
})();

/* ══════════ فیلتر و مرتب‌سازیِ جدولِ پلن‌ها (صفحاتِ کشور) ══════════
 *
 * فیلترها داخلِ هدرِ جدول‌اند: هر ستونِ فیلترپذیر یک `<details>` است که با کلیک
 * روی آیکنِ قیف باز می‌شود. `<details>` عمداً به‌جای منوی جاوااسکریپتی انتخاب
 * شد — بدونِ JS هم باز و بسته می‌شود و صفحه‌خوان خودش وضعیت را اعلام می‌کند.
 *
 * ⚠️ مقایسه روی `data-*`ِ عددی انجام می‌شود نه متنِ سلول: متنِ فارسی «۴ گیگ»
 * را نمی‌شود با عدد سنجید و `parseInt` روی رقمِ فارسی NaN می‌دهد.
 */
(function () {
  var tools = document.querySelector('.pt-tools');
  var groups = [].slice.call(document.querySelectorAll('.pt-group'));
  if (!tools || !groups.length) return;

  var state = { city: '', cpu: 0, ram: 0, sort: 'price' };
  var countEl = document.getElementById('pt-count');
  var countTpl = countEl ? (countEl.getAttribute('data-tpl') || '') : '';
  var clearBtn = tools.querySelector('.pt-clear');
  var faDigits = document.documentElement.lang === 'fa';

  function fa(n) {
    if (!faDigits) return String(n);
    return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; });
  }

  // دکمه‌های فیلتر در هدرِ **هر دو** جدول‌اند و باید هم‌زمان کار کنند، پس
  // شنونده روی document است نه روی یک جدول.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.pt-pop button[data-f]') : null;
    if (!btn) return;

    var f = btn.getAttribute('data-f');
    var v = btn.getAttribute('data-v') || '';
    state[f] = (f === 'city' || f === 'sort') ? v : (+v || 0);

    // منو را ببند — وگرنه روی موبایل جدول را می‌پوشاند
    var d = btn.closest('details');
    if (d) d.open = false;

    apply();
  });

  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      state = { city: '', cpu: 0, ram: 0, sort: 'price' };
      apply();
    });
  }

  // کلیک بیرون از منو آن را می‌بندد
  document.addEventListener('click', function (e) {
    [].slice.call(document.querySelectorAll('.pt-menu[open]')).forEach(function (d) {
      if (!d.contains(e.target)) d.open = false;
    });
  });

  function apply() {
    var total = 0;
    var filtered = !!state.city || !!state.cpu || !!state.ram || state.sort !== 'price';

    groups.forEach(function (g) {
      var body = g.querySelector('tbody');
      var empty = g.querySelector('.pt-empty');
      var wrap = g.querySelector('.plan-table-wrap');
      var shown = [];

      [].slice.call(body.querySelectorAll('tr')).forEach(function (tr) {
        /* ⚠️ یک ردیف حالا چند شهر می‌فروشد (شهر یک انتخابِ داخلِ ردیف است، نه
           ردیفِ تکراری). `data-city` فقط شهرِ سرصفحه‌ای است، پس تطبیقِ فیلتر
           باید روی `data-cities` باشد — وگرنه ردیفی که تهران و شیراز دارد با
           فیلترِ «شیراز» ناپدید می‌شد و مشتری فکر می‌کرد موجودی نداریم. */
        var cities = tr.getAttribute('data-cities') || '';
        var cityOk = !state.city
          || (cities ? cities.indexOf('|' + state.city + '|') >= 0
                     : tr.getAttribute('data-city') === state.city);

        var ok = cityOk
          && (!state.cpu || +tr.getAttribute('data-cpu') >= state.cpu)
          && (!state.ram || +tr.getAttribute('data-ram') >= state.ram);
        tr.hidden = !ok;
        if (ok) shown.push(tr);
      });

      var dir = state.sort.charAt(0) === '-' ? -1 : 1;
      var key = state.sort.replace('-', '');
      var attr = key === 'price' ? 'data-price' : (key === 'cpu' ? 'data-cpu' : 'data-ram');

      shown.sort(function (a, b) {
        return dir * ((+a.getAttribute(attr)) - (+b.getAttribute(attr)));
      });

      // شمارهٔ ردیف بعد از مرتب‌سازی بازنویسی می‌شود، وگرنه ستونِ «ردیف»
      // ترتیبِ اولیه را نشان می‌دهد و با چیزی که کاربر می‌بیند نمی‌خوانَد.
      shown.forEach(function (tr, i) {
        body.appendChild(tr);
        var num = tr.querySelector('.pt-num');
        if (num) num.textContent = fa(i + 1);
      });

      // گروهِ بی‌نتیجه پنهان نمی‌شود — پیام می‌دهد. ناپدیدشدنِ کاملِ یک جدول
      // به کاربر می‌گوید «چنین چیزی نداریم»، در حالی که فقط فیلتر تنگ است.
      if (empty) empty.hidden = shown.length > 0;
      if (wrap) wrap.hidden = shown.length === 0;

      total += shown.length;
    });

    // ستونی که فیلترِ فعال دارد علامت می‌خورد، وگرنه کاربر یادش می‌رود چرا
    // جدول کم‌ردیف است و فکر می‌کند موجودی نداریم.
    [].slice.call(document.querySelectorAll('.pt-menu')).forEach(function (d) {
      var f = d.querySelector('button[data-f]');
      if (!f) return;
      var k = f.getAttribute('data-f');
      var on = k === 'sort' ? state.sort !== 'price' : !!state[k];
      d.classList.toggle('is-on', on);
    });

    if (clearBtn) clearBtn.hidden = !filtered;
    if (countEl && countTpl) countEl.textContent = countTpl.replace('__N__', fa(total));
  }

  apply();
})();

/* ══════════ انتخابِ شهر داخلِ ردیفِ جدولِ پلن‌ها ══════════
 *
 * صفحهٔ `/vps/iran` ۱۴۶ ردیف داشت چون هر پلن یک بار به ازای **هر شهر** تکرار
 * می‌شد — مشخصاتِ یکسان، قیمتِ یکسان، فقط نامِ شهر فرق داشت. حالا یک ردیف است و
 * شهر یک انتخابِ داخلِ همان ردیف.
 *
 * ⚠️ هر شهر یک `<a>`ِ واقعی با لینکِ تسویهٔ خودش است، پس **بدونِ جاوااسکریپت** هم
 * کلیک روی شهر مستقیم به خریدِ همان شهر می‌رود. این اسکریپت فقط تجربه را بهتر
 * می‌کند (قیمت و دکمهٔ خرید را در جا عوض می‌کند)؛ نبودش هیچ‌چیز را از دسترس خارج
 * نمی‌کند. اگر روزی این را به منوی جاوااسکریپتی تبدیل کردی، همان تضمین می‌شکند.
 *
 * ⚠️ قیمت‌ها **از قبل روی سرور قالب‌بندی شده‌اند** (`data-pf`). قالب‌بندی در
 * مرورگر یعنی دو تعریفِ متفاوت از قیمت: `price_toman()` و رقم‌های فارسی و واحدِ
 * ارزِ زبان همه سمتِ سرورند.
 */
(function () {
  var groups = document.querySelectorAll('.pt-group');
  if (!groups.length) return;

  document.addEventListener('click', function (e) {
    var chip = e.target.closest ? e.target.closest('.pt-cities a.pt-c') : null;
    if (!chip) return;

    var tr = chip.closest('tr');
    if (!tr) return;

    e.preventDefault();

    [].slice.call(tr.querySelectorAll('.pt-cities .pt-c')).forEach(function (el) {
      el.classList.remove('is-on');
      el.removeAttribute('aria-current');
    });
    chip.classList.add('is-on');
    chip.setAttribute('aria-current', 'true');

    /*
     * 🔴 `getAttribute('href')` وقتی ویژگی نباشد **null** می‌دهد، و
     * `setAttribute(name, null)` آن را به رشتهٔ «null» تبدیل می‌کند. نتیجه یک
     * لینکِ **نسبی** به نامِ «null» است که مرورگر کنارِ آدرسِ فعلی حلش می‌کند:
     *
     *     /cloud/gb-dedicated  →  /cloud/null
     *     /servers/dell-…      →  /servers/null
     *     /blog?tag=…          →  /null
     *
     * دقیقاً همان ۴۰۴هایی که در ردیابِ خطا دیده شد، با Referer صفحاتِ واقعیِ
     * خودمان. مشتری روی دکمهٔ خرید می‌زند و به صفحهٔ ۴۰۴ می‌رسد.
     *
     * ⚠️ اگر چیپ آدرس ندارد، دکمهٔ خرید **دست‌نخورده** می‌مانَد. آدرسِ قبلی
     * (شهرِ پیش‌فرض) بدترین حالتش یک انتخابِ نادرست است؛ /null یک بن‌بست است.
     */
    var buy = tr.querySelector('.pt-buy a');
    var href = chip.getAttribute('href');
    if (buy && href) { buy.setAttribute('href', href); }

    var val = tr.querySelector('.pt-price-v');
    var pf = chip.getAttribute('data-pf');
    if (val && pf) val.textContent = pf;

    // «شروع از» فقط تا وقتی درست است که ارزان‌ترین شهر انتخاب باشد. با انتخابِ
    // شهرِ گران‌تر، عددِ نشان‌داده‌شده دیگر «از» نیست، دقیقاً همان قیمت است.
    var from = tr.querySelector('.pt-from');
    if (from) from.hidden = chip.getAttribute('data-min') !== '1';
  });
})();
