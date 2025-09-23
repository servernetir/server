(() => {
  const root = document.querySelector('#order-form');
  if (!root) return;

  const $  = (sel, ctx = root) => ctx.querySelector(sel);
  const $$ = (sel, ctx = root) => Array.from(ctx.querySelectorAll(sel));
  const nf2 = new Intl.NumberFormat('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  const state = {
    planEl: ($('[name="plan"]:checked') || {}).closest?.('.plan-card') || null,
    billing: ($('[name="billing"]:checked') || {}).value || 'month',
    qty: parseInt($('#quantity')?.value || '1', 10),
    backups: $('[name="backups"]')?.checked || false,
  };

  // --- random hostname
  const adjectives = ['fascinated','brisk','lively','cosmic','snappy','silver','quiet','rapid','tidy','rugged'];
  const nouns = ['spark','saturn','falcon','nebula','lynx','harbor','drift','vertex','mercury','beacon'];
  const rand = arr => arr[Math.floor(Math.random() * arr.length)];
  $('[data-action="randomize-hostname"]')?.addEventListener('click', () => {
    const input = $('#hostname');
    if (!input) return;
    input.value = `${rand(adjectives)}-${rand(nouns)}`;
  });

  // --- plan selection
  const planCards = $$('.plan-card');
  const planGroups = $$('.plan-group');

  function setActiveGroupByCard(card) {
    const group = card.closest('.plan-group');
    if (!group) return;
    planGroups.forEach(g => g.classList.toggle('is-active', g === group));
  }

  function setSelectedPlan(card) {
    planCards.forEach(c => c.classList.remove('is-selected'));
    card.classList.add('is-selected');
    state.planEl = card;
    const name = card.querySelector('.plan-name')?.textContent?.trim();
    if (name) $('.qty__label').textContent = name;
    setActiveGroupByCard(card);
  }

  planCards.forEach(card => {
    card.addEventListener('click', e => {
      if (e.target?.matches('input,select,button')) return;
      const input = card.querySelector('input[type="radio"][name="plan"]');
      if (!input) return;
      input.checked = true;
      setSelectedPlan(card);
      computeTotal();
    });
    card.querySelector('input[type="radio"][name="plan"]')?.addEventListener('change', () => {
      setSelectedPlan(card);
      computeTotal();
    });
  });

  // --- tabs (OS / Apps)
  const tabs = $$('.tab');
  function showPanel(id) {
    const osNote = root.querySelector('[data-os-note]');
    if (osNote) {
      const onOS = id === 'os-list';
      osNote.hidden = !onOS;
      osNote.style.display = onOS ? '' : 'none';
    }
    const osEl = document.getElementById('os-list');
    const appsEl = document.getElementById('apps-list');
    if (!osEl || !appsEl) return;

    const map = { 'os-list': osEl, 'apps-list': appsEl };
    Object.entries(map).forEach(([pid, el]) => {
      const active = pid === id;
      el.hidden = !active;
      el.style.display = active ? '' : 'none';
      el.setAttribute('aria-hidden', active ? 'false' : 'true');
    });
  }
  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      tabs.forEach(t => t.classList.remove('is-active'));
      tab.classList.add('is-active');
      tabs.forEach(t => t.setAttribute('aria-selected', t === tab ? 'true' : 'false'));
      showPanel(tab.getAttribute('data-tab'));
    });
  });
  showPanel('os-list');

  // --- OS & Apps summary
  function getSelectedOSName() {
    const osRadio = $('[name="os"]:checked');
    if (!osRadio) return '';
    const wrap = osRadio.closest('.os-card');
    const osName = wrap?.querySelector('.os-card__name')?.textContent?.trim() || osRadio.value;
    const versionSelect = wrap?.querySelector('select');
    const ver = versionSelect?.value ? ` ${versionSelect.value}` : '';
    return `${osName}${ver}`;
  }

  function refreshSelectedOSAndApps() {
    const osOut = $('[data-selected-os]');
    if (osOut) {
      osOut.innerHTML = `<span class="highlight-orange">${getSelectedOSName() || '—'}</span>`;
    }

    const appsWrap = $('[data-selected-apps-wrap]');
    const appsList = $('[data-selected-apps]');
    const checkedApps = $$('input[name="apps[]"]:checked');

    if (!appsWrap || !appsList) return;

    appsList.innerHTML = '';
    if (checkedApps.length) {
      checkedApps.forEach(ch => {
        const li = document.createElement('li');
        const label = ch.closest('label')?.textContent?.trim() || ch.value;
        li.innerHTML = `<span class="highlight-orange">${label}</span>`;
        appsList.appendChild(li);
      });
      appsWrap.hidden = false;
      appsWrap.style.display = '';
    } else {
      appsWrap.hidden = true;
      appsWrap.style.display = 'none';
    }
  }

  $$('input[name="os"]').forEach(r => r.addEventListener('change', () => {
    refreshSelectedOSAndApps();
  }));
  $$('#os-list select').forEach(s => s.addEventListener('change', () => {
    refreshSelectedOSAndApps();
  }));
  $$('input[name="apps[]"]').forEach(ch => ch.addEventListener('change', () => {
    refreshSelectedOSAndApps();
  }));

  // --- billing chips
  const billingRadios = $$('.chip input[name="billing"]');
  billingRadios.forEach(r => {
    r.addEventListener('change', () => {
      state.billing = r.value;
      $$('.chip').forEach(label => label.classList.toggle('is-active', label.contains(r) && r.checked));
      computeTotal();
    });
  });

  // --- quantity
  $('#quantity')?.addEventListener('input', e => {
    const v = Math.max(1, Math.round(Number(e.target.value || 1)));
    e.target.value = v;
    state.qty = v;
    computeTotal();
  });

  // --- backups
  $('[name="backups"]')?.addEventListener('change', e => {
    state.backups = e.target.checked;
    computeTotal();
  });

  // --- pricing core
  function getSelectedPlanNumbers() {
    const input = state.planEl?.querySelector('input[name="plan"]');
    if (!input) return null;
    const m = Number(input.dataset.priceMonth || 0);
    const h = Number(input.dataset.priceHour || 0);
    const monthAddon = Number($('[name="backups"]')?.dataset.addonMonth || (m * 0.2));
    return { m, h, monthAddon };
  }

  function updateBackupPriceDisplay(plan, months, discount) {
  const out = $('[data-backup-price]');
  if (!out || !plan) return;

  if (state.billing === 'hour') {
    const hoursInMonth = 30 * 24;
    const priceHour = plan.monthAddon / hoursInMonth;
    out.textContent = `${nf2.format(priceHour)} €/hour`;
  } else {
    const baseAddon = plan.monthAddon * months;
    const finalAddon = baseAddon;
    out.textContent = `${nf2.format(finalAddon)} €`;
  }
}

  function computeTotal() {
    const plan = getSelectedPlanNumbers();
    if (!plan) return;

    const monthsMap = { hour: 0, month: 1, '3months': 3, '6months': 6, year: 12 };
    const discount = Number(($(`[name="billing"][value="${state.billing}"]`)?.dataset.discount) || 0);
    const months = monthsMap[state.billing] ?? 1;

    let baseTotal = 0;
    let baseTotalOriginal = 0;
    let addonTotal = 0;

    if (state.billing === 'hour') {
      const hoursInMonth = 30 * 24;
      baseTotal = plan.h;
      addonTotal = state.backups ? (plan.monthAddon / hoursInMonth) : 0;
    } else {
      baseTotalOriginal = plan.m * months;
      baseTotal = baseTotalOriginal * (1 - discount / 100);
      addonTotal = state.backups ? (plan.monthAddon * months) : 0;
    }

    const totalPerUnit = (state.billing === 'hour')
      ? (baseTotal + addonTotal)
      : (baseTotal + addonTotal);

    const total = totalPerUnit * state.qty;

    const out = $('#total-price');
    if (!out) return;

    if (state.billing !== 'hour' && discount > 0 && months > 1) {
      const totalOriginal = (baseTotalOriginal + addonTotal) * state.qty;
      out.innerHTML =
        `<span class="price-old" style="opacity:.8;margin-inline-end:.5rem;"><s>${nf2.format(totalOriginal)} €</s></span>` +
        `<span class="price-new">${nf2.format(total)} €</span>`;
    } else {
      out.textContent = `${nf2.format(total)} €`;
    }
    updateBackupPriceDisplay(plan, months, discount);
  }
  
  const checkedPlan = ($('[name="plan"]:checked') || {}).closest?.('.plan-card');
  if (checkedPlan) setSelectedPlan(checkedPlan);

  refreshSelectedOSAndApps();
  computeTotal();
})();