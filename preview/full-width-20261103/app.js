/* Isolated ServerNet design preview. No production API, billing or credentials. */
(() => {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const all = (query, root = document) => Array.from(root.querySelectorAll(query));
  const messages = window.SN_PREVIEW_MESSAGES;
  const mobile = window.matchMedia('(max-width: 800px)');
  const storage = {
    get(key, fallback) { try { return localStorage.getItem(`sn-preview:${key}`) || fallback; } catch (_) { return fallback; } },
    set(key, value) { try { localStorage.setItem(`sn-preview:${key}`, value); } catch (_) { /* file preview may block storage */ } }
  };
  const state = { language: storage.get('language', 'fa'), view: 'dashboard', service: 'linux', filter: 'all', query: '', dnsTimer: null };
  if (!messages[state.language]) state.language = 'fa';
  const services = {
    linux: { domain: 'studio.example', os: 'Linux', panel: 'cPanel', title: 'service.linuxTitle', plan: 'Linux Pro · 20 GB', location: 'location.iran', ip: '192.0.2.10', username: 'demo_studio', price: 480000, date: 'date.november18', days: 'date.in15Days', dns: 'pending', disk: [8.4,20], bandwidth:[32,200], email:[4,20], db:[3,10] },
    windows: { domain: 'north.example', os: 'Windows', panel: 'Plesk', title: 'service.windowsTitle', plan: 'Windows Business · 10 GB', location: 'location.germany', ip:'192.0.2.20', username:'demo_north', price:680000, date:'date.december16', days:'date.in43Days', dns:'connected', disk:[6.7,10], bandwidth:[48,150], email:[6,10], db:[2,5] }
  };
  const t = (key) => messages[state.language][key] || key;
  const number = (value, decimals = 1) => new Intl.NumberFormat(state.language === 'fa' ? 'fa-IR' : state.language === 'tr' ? 'tr-TR' : 'en-US', { maximumFractionDigits: decimals }).format(value);
    let toastTimer;
  function toast(text) {
    clearTimeout(toastTimer); $('toast-message').textContent = text; $('toast').hidden = false;
    toastTimer = setTimeout(() => { $('toast').hidden = true; }, 4000);
  }
  function updateDetail() {
    const service = services[state.service];
    $('detail-title').dataset.i18n = service.title;
    $('detail-title').textContent = t(service.title);
    $('detail-domain').textContent = service.domain;
    $('detail-primary-domain').textContent = service.domain;
    $('detail-domain-copy').dataset.copy = service.domain;
    $('detail-panel-name').textContent = `${service.os} / ${service.panel}`;
    $('detail-control-panel').textContent = service.panel;
    $('detail-panel-label').dataset.i18n = service.panel === 'Plesk' ? 'actions.plesk' : 'actions.cpanel';
    $('detail-panel-label').textContent = t($('detail-panel-label').dataset.i18n);
    $('detail-panel-button').dataset.demoAction = service.panel.toLowerCase();
    $('detail-service-icon').className = `service-symbol ${state.service === 'windows' ? 'violet' : 'cyan'} large`;
    $('detail-plan').removeAttribute('data-i18n');
    $('detail-plan').textContent = service.plan;
    $('detail-location').dataset.i18n = service.location;
    $('detail-location').textContent = t(service.location);
    ['ip','username'].forEach(key => { $(`detail-${key}`).textContent = service[key]; $(`detail-${key}-copy`).dataset.copy = service[key]; });
    $('detail-price').textContent = number(service.price,0);
    $('detail-renewal-date').removeAttribute('data-i18n');
    $('detail-renewal-date').textContent = `${t(service.date)} · ${t(service.days)}`;
    ['disk','bandwidth','email','db'].forEach(resource => {
      const [used, limit] = service[resource], percent = Math.round(used / limit * 100);
      $(`detail-${resource}-used`).textContent = number(used);
      $(`detail-${resource}-limit`).textContent = `/ ${number(limit)}${resource === 'disk' || resource === 'bandwidth' ? ' GB' : ''}`;
      $(`detail-${resource}-percent`).textContent = `${number(percent,0)}%`;
      $(`detail-${resource}-meter`).style.setProperty('--usage', `${percent}%`);
      const meter = $(`detail-${resource}-meter`).parentElement;
      meter.setAttribute('role','meter'); meter.setAttribute('aria-label', t(`resource.${resource === 'db' ? 'database' : resource}`));
      meter.setAttribute('aria-valuemin','0'); meter.setAttribute('aria-valuemax',String(limit)); meter.setAttribute('aria-valuenow',String(used));
      meter.setAttribute('aria-valuetext',`${number(used)} / ${number(limit)}`);
    });
    $('detail-disk-caption').removeAttribute('data-i18n');
    $('detail-disk-caption').textContent = `${number(service.disk[1] - service.disk[0])} ${t('resource.available')}`;
    const connected = service.dns === 'connected';
    const badge = $('detail-dns-badge'); badge.classList.toggle('warning', !connected);
    badge.querySelector('use').setAttribute('href',connected ? '#i-check' : '#i-alert');
    const badgeCopy = badge.querySelector('[data-i18n]');
    badgeCopy.dataset.i18n = connected ? 'dns.connected' : 'dns.setupStatus'; badgeCopy.textContent = t(badgeCopy.dataset.i18n);
    const notice = $('detail-dns-notice');
    notice.classList.toggle('is-connected', connected);
    notice.querySelector('use').setAttribute('href',connected ? '#i-check' : '#i-alert');
    const title = notice.querySelector('b'), description = notice.querySelector('p');
    title.dataset.i18n = connected ? 'dns.connectedTitle' : 'dns.noticeTitle'; title.textContent = t(title.dataset.i18n);
    description.dataset.i18n = connected ? 'dns.connectedDescription' : 'dns.noticeDescription'; description.textContent = t(description.dataset.i18n);
    $('dns-check-status').textContent = t('dns.notChecked');
  }
  function language(value) {
    if (!messages[value]) return;
    window.ServerNetSelects?.close(); state.language = value;
    document.documentElement.lang = value; document.documentElement.dir = value === 'fa' ? 'rtl' : 'ltr';
    document.title = t('page.title');
    all('[data-i18n]').forEach(el => { el.textContent = t(el.dataset.i18n); });
    ['aria-label','placeholder','title'].forEach(attr => all(`[data-i18n-${attr}]`).forEach(el => el.setAttribute(attr,t(el.getAttribute(`data-i18n-${attr}`)))));
    $('language-select').value = value; window.ServerNetSelects?.refresh($('language-select'));
    storage.set('language',value); updateDetail();
    $('sidebar-collapse').setAttribute('aria-label',t(document.body.classList.contains('sidebar-collapsed') ? 'a11y.expand' : 'a11y.collapse'));
    $('sidebar-collapse').setAttribute('aria-expanded',String(!document.body.classList.contains('sidebar-collapsed')));
    all('[data-view]').forEach(el => { if (document.body.classList.contains('sidebar-collapsed')) el.title = el.textContent.trim(); });
    if ($('modal').open) modal(lastAction);
  }
  function setSidebar(open) {
    document.body.classList.toggle('sidebar-open',open);
    $('sidebar-backdrop').hidden = !open;
    $('menu-toggle').setAttribute('aria-expanded',String(open));
    const shell = document.querySelector('.app-shell'), bottom = document.querySelector('.bottom-nav');
    shell.inert = open; bottom.inert = open;
    if (mobile.matches) $('sidebar').inert = !open;
    if (open) { $('sidebar').setAttribute('role','dialog'); $('sidebar').setAttribute('aria-modal','true'); $('sidebar').querySelector('button').focus(); }
    else { $('sidebar').removeAttribute('role'); $('sidebar').removeAttribute('aria-modal'); }
  }
  function view(name, service = state.service, focus = true) {
    if (!['dashboard','hosting','detail'].includes(name)) return;
    clearTimeout(state.dnsTimer); $('dns-check-button').disabled = false;
    window.ServerNetSelects?.close();
    state.view = name; state.service = services[service] ? service : 'linux';
    all('[data-view-panel]').forEach(el => { el.hidden = el.dataset.viewPanel !== name; });
    all('[data-view]').forEach(el => {
      const active = el.dataset.view === (name === 'detail' ? 'hosting' : name);
      el.classList.toggle('is-active',active);
      if (active) el.setAttribute('aria-current','page'); else el.removeAttribute('aria-current');
    });
    setSidebar(false); updateDetail();
    const hash = name === 'detail' ? `detail-${state.service}` : name;
    try { history.replaceState(null,'',`#${hash}`); } catch (_) { /* offline sandbox */ }
    if (focus) {
      const heading = $(name === 'detail' ? 'detail-title' : `${name}-title`);
      heading.tabIndex = -1; heading.focus({preventScroll:true}); window.scrollTo({top:0,behavior:'instant'});
    }
  }
  function filterHosting() {
    const cards = all('[data-service-card]');
    const query = state.query.trim().toLocaleLowerCase(state.language);
    cards.forEach(card => {
      const statusMatch = state.filter === 'all' || (state.filter === 'active' ? true : card.dataset.status === 'attention');
      card.hidden = !statusMatch || !(card.dataset.name + ' ' + card.textContent).toLocaleLowerCase(state.language).includes(query);
    });
    const sort = $('hosting-sort').value;
    cards.sort((a,b) => sort === 'name' ? a.dataset.name.localeCompare(b.dataset.name,state.language) : sort === 'renewal' ? a.dataset.renewal.localeCompare(b.dataset.renewal) : b.dataset.created.localeCompare(a.dataset.created));
    cards.forEach(card => $('hosting-list').appendChild(card));
    $('hosting-empty').hidden = cards.some(card => !card.hidden);
    all('[data-filter]').forEach(el => { const active = el.dataset.filter === state.filter; el.classList.toggle('is-active',active); el.setAttribute('aria-pressed',String(active)); });
  }
  const actionKey = {
    cloud:'nav.cloud',domains:'nav.domains',storage:'nav.storage',invoices:'nav.invoices',wallet:'nav.wallet',topup:'actions.topup',support:'nav.support',security:'nav.security',profile:'nav.accountShort',search:'a11y.search',notifications:'a11y.notifications',activity:'actions.history','new-service':'actions.newService','new-hosting':'actions.newHosting',cpanel:'actions.cpanel',plesk:'actions.plesk',files:'actions.fileManager',database:'actions.databases',email:'actions.emailAccounts',backup:'actions.backups',renew:'actions.renewService',settings:'detail.settings',password:'service.password',help:'footer.help',status:'footer.status'
  };
  let lastAction = 'profile';
  function modal(action) {
    lastAction = action;
    $('modal-title').textContent = t(actionKey[action] || 'modal.title');
    $('modal-description').textContent = t('modal.description');
    $('modal-extra').textContent = t(['cpanel','plesk'].includes(action) ? 'modal.panel' : action === 'password' ? 'modal.password' : action === 'support' ? 'modal.support' : 'modal.nextPhase');
    if (!$('modal').open) $('modal').showModal();
  }
  async function copy(value) {
    value = value.replace(/\\n/g,'\n');
    try {
      if (!navigator.clipboard?.writeText) throw new Error('Clipboard unavailable');
      await navigator.clipboard.writeText(value); toast(t('feedback.copied'));
    } catch (_) {
      modal('copy'); $('modal-title').textContent = t('feedback.copyFailed');
      const text = document.createElement('textarea'); text.value = value; text.readOnly = true; text.dir = 'ltr'; text.setAttribute('aria-label',t('feedback.copyFailed'));
      $('modal-extra').replaceChildren(text); text.focus(); text.select();
    }
  }
  document.addEventListener('click', event => {
    const target = event.target.closest('button, a'); if (!target) return;
    if (target.matches('[data-view]')) view(target.dataset.view,target.dataset.service || state.service);
    else if (target.matches('[data-service]')) view('detail',target.dataset.service);
    else if (target.matches('[data-filter]')) { state.filter = target.dataset.filter; filterHosting(); }
    else if (target.matches('[data-copy]')) copy(target.dataset.copy);
    else if (target.matches('[data-modal-close]')) $('modal').close();
    else if (target.matches('[data-demo-action]')) {
      if (target.dataset.demoAction === 'dns-check') {
        target.disabled = true; $('dns-check-status').textContent = t('feedback.checking');
        const service = state.service;
        state.dnsTimer = setTimeout(() => { target.disabled = false; if (state.service === service) $('dns-check-status').textContent = t(services[service].dns === 'connected' ? 'feedback.dnsConnected' : 'feedback.dnsPending'); },750);
      } else modal(target.dataset.demoAction);
    }
  });
  $('language-select').addEventListener('change',event => { language(event.target.value); filterHosting(); });
  $('hosting-search').addEventListener('input', event => { state.query = event.target.value; filterHosting(); });
  $('hosting-sort').addEventListener('change',filterHosting);
  $('clear-filters').addEventListener('click',() => { state.filter='all'; state.query=''; $('hosting-search').value=''; filterHosting(); $('hosting-search').focus(); });
  $('menu-toggle').addEventListener('click',() => setSidebar(!document.body.classList.contains('sidebar-open')));
  $('sidebar-backdrop').addEventListener('click',() => { setSidebar(false); $('menu-toggle').focus(); });
  $('sidebar-collapse').addEventListener('click',() => {
    if (mobile.matches) { setSidebar(false); $('menu-toggle').focus(); return; }
    document.body.classList.toggle('sidebar-collapsed');
    const collapsed = document.body.classList.contains('sidebar-collapsed'); storage.set('collapsed',String(collapsed));
    $('sidebar-collapse').setAttribute('aria-expanded',String(!collapsed));
    $('sidebar-collapse').setAttribute('aria-label',t(collapsed ? 'a11y.expand' : 'a11y.collapse'));
    all('.sidebar .nav-item').forEach(el => { el.title = collapsed ? el.textContent.trim() : ''; });
  });
  const theme = storage.get('theme','light'); document.documentElement.dataset.theme = theme === 'dark' ? 'dark' : 'light';
  $('theme-toggle').setAttribute('aria-pressed',String(document.documentElement.dataset.theme === 'dark'));
  $('theme-toggle').addEventListener('click',() => {
    const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = theme; storage.set('theme',theme); $('theme-toggle').setAttribute('aria-pressed',String(theme==='dark'));
  });
  $('modal').addEventListener('click',event => { if (event.target === $('modal')) { const r=$('modal').getBoundingClientRect(); if(event.clientX<r.left || event.clientX>r.right || event.clientY<r.top || event.clientY>r.bottom) $('modal').close(); } });
  document.addEventListener('keydown',event => {
    if (!document.body.classList.contains('sidebar-open')) return;
    if (event.key === 'Escape') { setSidebar(false); $('menu-toggle').focus(); }
    if (event.key === 'Tab') {
      const buttons = all('button:not([disabled]),a[href],input', $('sidebar')).filter(el=>el.getClientRects().length);
      if(event.shiftKey && document.activeElement===buttons[0]) { event.preventDefault(); buttons.at(-1).focus(); }
      else if(!event.shiftKey && document.activeElement===buttons.at(-1)) { event.preventDefault(); buttons[0].focus(); }
    }
  });
  function breakpoint() { setSidebar(false); $('sidebar').inert = mobile.matches; }
  mobile.addEventListener('change',breakpoint); breakpoint();
  if (storage.get('collapsed','false') === 'true') document.body.classList.add('sidebar-collapsed');
  language(state.language); filterHosting();
  const hash = location.hash.slice(1);
  view(hash.startsWith('detail-') ? 'detail' : hash || 'dashboard',hash.endsWith('windows') ? 'windows' : 'linux',false);
  // Pure state for review automation; not a production application API.
  window.SN_PREVIEW = Object.freeze({ languages:Object.keys(messages), serviceCount:Object.keys(services).length, snapshot:()=>({language:state.language,view:state.view,service:state.service,filter:state.filter}) });
})();
